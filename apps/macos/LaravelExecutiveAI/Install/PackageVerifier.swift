// PACKAGE_VERIFY (S1-05; blueprint §6 "verify vendor checksum and signature"; threat model T-47).
//
// Verifies a bundled runtime directory before anything in it executes:
//
//   1. `manifest.json` parses against the schema below (ADR-001 D4: the copy of the
//      `runtime.json` entry that produced the directory — S1-06 owns `runtime.json`);
//   2. `SHA256SUMS` lists exactly the manifest's files with the same digests (the two must agree);
//   3. every listed file exists as a regular file (no symlinks), has the stated size and digest;
//   4. no unlisted regular file exists anywhere in the directory;
//   5. the runtime executable passes `SecStaticCodeCheckValidityWithErrors` under strict flags
//      against a signing requirement.
//
// Every failure is an error; there is no "warn and continue". The verifier never launches the
// binary — validity is checked on the static code, which is what makes it safe to call first.

import CryptoKit
import Foundation
import Security

struct RuntimeManifest: Codable, Equatable, Sendable {
    struct Entry: Codable, Equatable, Sendable {
        let path: String
        let sha256: String
        let bytes: Int64
    }

    static let supportedSchemaVersion = 1

    let schemaVersion: Int
    let runtimeVersion: String
    /// Relative path of the signed runtime binary (ADR-001 D2), listed in `files`.
    let executable: String
    let files: [Entry]

    enum CodingKeys: String, CodingKey {
        case schemaVersion = "schema_version"
        case runtimeVersion = "runtime_version"
        case executable
        case files
    }

    var totalBytes: Int64 {
        files.reduce(0) { $0 + $1.bytes }
    }

    /// Structural checks that do not touch the filesystem.
    func validate() throws(PackageVerificationError) {
        guard schemaVersion == Self.supportedSchemaVersion else {
            throw .manifestInvalid("schema_version \(schemaVersion) is not supported")
        }

        guard Self.isVersion(runtimeVersion) else {
            throw .manifestInvalid("runtime_version is not a version string")
        }

        guard !files.isEmpty else {
            throw .manifestInvalid("files is empty")
        }

        var seen = Set<String>()

        for entry in files {
            guard Self.isSafeRelativePath(entry.path) else {
                throw .manifestInvalid("unsafe path \(entry.path)")
            }

            guard Self.isSHA256Hex(entry.sha256) else {
                throw .manifestInvalid("sha256 for \(entry.path) is not 64 lowercase hex characters")
            }

            guard entry.bytes >= 0 else {
                throw .manifestInvalid("negative size for \(entry.path)")
            }

            guard seen.insert(entry.path).inserted else {
                throw .manifestInvalid("duplicate path \(entry.path)")
            }
        }

        guard seen.contains(executable) else {
            throw .manifestInvalid("executable \(executable) is not listed in files")
        }
    }

    static func isSafeRelativePath(_ path: String) -> Bool {
        guard !path.isEmpty, !path.hasPrefix("/"), !path.hasPrefix("~") else {
            return false
        }

        let components = path.split(separator: "/", omittingEmptySubsequences: false)

        return components.allSatisfy { component in
            !component.isEmpty && component != "." && component != ".."
        }
    }

    static func isSHA256Hex(_ value: String) -> Bool {
        value.count == 64 && value.allSatisfy { ("0"..."9").contains($0) || ("a"..."f").contains($0) }
    }

    static func isVersion(_ value: String) -> Bool {
        let parts = value.split(separator: ".", omittingEmptySubsequences: false)

        guard parts.count == 3 else {
            return false
        }

        return parts.allSatisfy { part in
            !part.isEmpty && part.allSatisfy { ("0"..."9").contains($0) }
        }
    }
}

enum PackageVerificationError: Error, Equatable, Sendable {
    case directoryMissing
    case manifestMissing
    case manifestInvalid(String)
    case checksumFileMissing
    case checksumFileInvalid(String)
    case checksumFileDisagreesWithManifest(path: String)
    case fileMissing(path: String)
    case notARegularFile(path: String)
    case sizeMismatch(path: String)
    case checksumMismatch(path: String)
    case unlistedFile(path: String)
    case signatureInvalid(reason: String)
    case noSigningPolicy(String)

    /// Short, sanitised text for the error screen. No file paths that could reveal a user name.
    var userFacingReason: String {
        switch self {
        case .directoryMissing: return "The runtime package is missing from this copy of the app."
        case .manifestMissing: return "The runtime manifest is missing."
        case .manifestInvalid(let reason): return "The runtime manifest is invalid (\(reason))."
        case .checksumFileMissing: return "The checksum file is missing."
        case .checksumFileInvalid(let reason): return "The checksum file is invalid (\(reason))."
        case .checksumFileDisagreesWithManifest(let path): return "Checksum file and manifest disagree about \(path)."
        case .fileMissing(let path): return "\(path) is missing from the package."
        case .notARegularFile(let path): return "\(path) is not a regular file."
        case .sizeMismatch(let path): return "\(path) has an unexpected size."
        case .checksumMismatch(let path): return "\(path) does not match its checksum."
        case .unlistedFile(let path): return "\(path) is not listed in the manifest."
        case .signatureInvalid(let reason): return "The runtime binary is not correctly signed (\(reason))."
        case .noSigningPolicy(let reason): return "No signing policy is available (\(reason))."
        }
    }
}

struct VerifiedPackage: Equatable, Sendable {
    let manifest: RuntimeManifest
    let directory: URL

    var version: String { manifest.runtimeVersion }
    var totalBytes: Int64 { manifest.totalBytes }
}

// MARK: - Code signature policy

protocol CodeSignatureChecking: Sendable {
    /// Throws `PackageVerificationError.signatureInvalid` unless the code at `url` is validly
    /// signed and satisfies the policy's requirement.
    func validate(executableAt url: URL) throws
}

/// `SecStaticCode` validity with strict flags against a requirement string.
///
/// The product requirement is `anchor apple generic and certificate leaf[subject.OU] = "<TEAMID>"`
/// with the Team ID read from the running app's own signature (support matrix §6.10), so a Team
/// ID change (A12) is one constant. An app without a Team ID — an ad-hoc dev build, the unsigned
/// CI build — has no policy, and `fromRunningApp()` throws rather than inventing one.
struct SecStaticCodeSignatureChecker: CodeSignatureChecking {
    let requirement: String

    static let strictFlags = SecCSFlags(rawValue: kSecCSCheckAllArchitectures | kSecCSStrictValidate | kSecCSCheckNestedCode)

    static func teamRequirement(teamIdentifier: String) -> String {
        "anchor apple generic and certificate leaf[subject.OU] = \"\(teamIdentifier)\""
    }

    static func fromRunningApp() throws(PackageVerificationError) -> SecStaticCodeSignatureChecker {
        var code: SecCode?

        guard SecCodeCopySelf([], &code) == errSecSuccess, let code else {
            throw .noSigningPolicy("the app could not read its own signature")
        }

        var staticCode: SecStaticCode?

        guard SecCodeCopyStaticCode(code, [], &staticCode) == errSecSuccess, let staticCode else {
            throw .noSigningPolicy("the app could not read its own static code")
        }

        var info: CFDictionary?
        let status = SecCodeCopySigningInformation(staticCode, SecCSFlags(rawValue: kSecCSSigningInformation), &info)

        guard status == errSecSuccess, let dictionary = info as? [String: Any] else {
            throw .noSigningPolicy("the app's signing information is unreadable")
        }

        guard let team = dictionary[kSecCodeInfoTeamIdentifier as String] as? String, !team.isEmpty else {
            throw .noSigningPolicy("this copy of the app carries no Team ID")
        }

        return SecStaticCodeSignatureChecker(requirement: teamRequirement(teamIdentifier: team))
    }

    func validate(executableAt url: URL) throws {
        var staticCode: SecStaticCode?
        let createStatus = SecStaticCodeCreateWithPath(url as CFURL, [], &staticCode)

        guard createStatus == errSecSuccess, let staticCode else {
            throw PackageVerificationError.signatureInvalid(reason: Self.describe(createStatus))
        }

        var secRequirement: SecRequirement?
        let requirementStatus = SecRequirementCreateWithString(requirement as CFString, [], &secRequirement)

        guard requirementStatus == errSecSuccess, let secRequirement else {
            throw PackageVerificationError.noSigningPolicy("the signing requirement does not parse")
        }

        var error: Unmanaged<CFError>?
        let validity = SecStaticCodeCheckValidityWithErrors(staticCode, Self.strictFlags, secRequirement, &error)

        if let error = error?.takeRetainedValue() {
            throw PackageVerificationError.signatureInvalid(reason: Self.describe(validity, error: error))
        }

        guard validity == errSecSuccess else {
            throw PackageVerificationError.signatureInvalid(reason: Self.describe(validity))
        }
    }

    private static func describe(_ status: OSStatus, error: CFError? = nil) -> String {
        if let message = SecCopyErrorMessageString(status, nil) as String? {
            return "\(message) (\(status))"
        }

        if let error {
            return "\(CFErrorGetCode(error))"
        }

        return "OSStatus \(status)"
    }
}

// MARK: - The verifier

struct PackageVerifier: Sendable {
    static let manifestFileName = "manifest.json"
    static let checksumFileName = "SHA256SUMS"

    let signatureChecker: any CodeSignatureChecking

    func verify(runtimeDirectory directory: URL) throws -> VerifiedPackage {
        let fileManager = FileManager.default
        var isDirectory: ObjCBool = false

        guard fileManager.fileExists(atPath: directory.path, isDirectory: &isDirectory), isDirectory.boolValue else {
            throw PackageVerificationError.directoryMissing
        }

        let manifest = try loadManifest(in: directory)
        try manifest.validate()
        try checkChecksumFile(in: directory, against: manifest)
        try checkFiles(in: directory, against: manifest)
        try checkForUnlistedFiles(in: directory, manifest: manifest)
        try signatureChecker.validate(executableAt: directory.appending(path: manifest.executable))

        return VerifiedPackage(manifest: manifest, directory: directory)
    }

    private func loadManifest(in directory: URL) throws -> RuntimeManifest {
        let url = directory.appending(path: Self.manifestFileName)

        guard FileManager.default.fileExists(atPath: url.path) else {
            throw PackageVerificationError.manifestMissing
        }

        let data = try Data(contentsOf: url)

        do {
            return try JSONDecoder().decode(RuntimeManifest.self, from: data)
        } catch {
            throw PackageVerificationError.manifestInvalid("not a valid manifest document")
        }
    }

    /// `SHA256SUMS` in `sha256sum` format: `<64 hex>  <path>` per line.
    static func parseChecksumFile(_ text: String) throws(PackageVerificationError) -> [String: String] {
        var digests: [String: String] = [:]

        for (index, rawLine) in text.split(separator: "\n", omittingEmptySubsequences: false).enumerated() {
            let line = rawLine.trimmingCharacters(in: .whitespaces)

            if line.isEmpty || line.hasPrefix("#") {
                continue
            }

            guard let separator = line.firstIndex(of: " ") else {
                throw .checksumFileInvalid("line \(index + 1) has no separator")
            }

            let digest = String(line[..<separator])
            var path = String(line[separator...]).trimmingCharacters(in: .whitespaces)

            if path.hasPrefix("*") {
                path.removeFirst()
            }

            guard RuntimeManifest.isSHA256Hex(digest), RuntimeManifest.isSafeRelativePath(path) else {
                throw .checksumFileInvalid("line \(index + 1) is malformed")
            }

            guard digests.updateValue(digest, forKey: path) == nil else {
                throw .checksumFileInvalid("duplicate entry for \(path)")
            }
        }

        guard !digests.isEmpty else {
            throw .checksumFileInvalid("no entries")
        }

        return digests
    }

    private func checkChecksumFile(in directory: URL, against manifest: RuntimeManifest) throws {
        let url = directory.appending(path: Self.checksumFileName)

        guard FileManager.default.fileExists(atPath: url.path) else {
            throw PackageVerificationError.checksumFileMissing
        }

        guard let text = String(data: try Data(contentsOf: url), encoding: .utf8) else {
            throw PackageVerificationError.checksumFileInvalid("not UTF-8")
        }

        let digests = try Self.parseChecksumFile(text)

        for entry in manifest.files {
            guard digests[entry.path] == entry.sha256 else {
                throw PackageVerificationError.checksumFileDisagreesWithManifest(path: entry.path)
            }
        }

        if let extra = digests.keys.first(where: { path in !manifest.files.contains { $0.path == path } }) {
            throw PackageVerificationError.checksumFileDisagreesWithManifest(path: extra)
        }
    }

    private func checkFiles(in directory: URL, against manifest: RuntimeManifest) throws {
        for entry in manifest.files {
            let url = directory.appending(path: entry.path)

            guard let attributes = try? FileManager.default.attributesOfItem(atPath: url.path) else {
                throw PackageVerificationError.fileMissing(path: entry.path)
            }

            guard let type = attributes[.type] as? FileAttributeType, type == .typeRegular else {
                throw PackageVerificationError.notARegularFile(path: entry.path)
            }

            guard let size = attributes[.size] as? Int64 ?? (attributes[.size] as? Int).map(Int64.init), size == entry.bytes else {
                throw PackageVerificationError.sizeMismatch(path: entry.path)
            }

            guard try Self.sha256Hex(of: url) == entry.sha256 else {
                throw PackageVerificationError.checksumMismatch(path: entry.path)
            }
        }
    }

    private func checkForUnlistedFiles(in directory: URL, manifest: RuntimeManifest) throws {
        let listed = Set(manifest.files.map(\.path) + [Self.manifestFileName, Self.checksumFileName])

        for path in try Self.regularFiles(under: directory) where !listed.contains(path) {
            throw PackageVerificationError.unlistedFile(path: path)
        }
    }

    /// Relative paths of every regular file under `directory`, sorted. Symlinks are reported as
    /// files so that a symlink standing in for a listed file is caught by `checkFiles`, and an
    /// unlisted symlink is caught here.
    static func regularFiles(under directory: URL) throws -> [String] {
        let keys: [URLResourceKey] = [.isRegularFileKey, .isSymbolicLinkKey, .isDirectoryKey]

        guard let enumerator = FileManager.default.enumerator(at: directory, includingPropertiesForKeys: keys, options: []) else {
            throw PackageVerificationError.directoryMissing
        }

        let base = directory.standardizedFileURL.path
        var paths: [String] = []

        for case let url as URL in enumerator {
            let values = try url.resourceValues(forKeys: Set(keys))

            if values.isSymbolicLink == true || values.isRegularFile == true {
                let full = url.standardizedFileURL.path
                var relative = String(full.dropFirst(base.count))

                if relative.hasPrefix("/") {
                    relative.removeFirst()
                }

                paths.append(relative)
            }
        }

        return paths.sorted()
    }

    static func sha256Hex(of url: URL) throws -> String {
        let handle = try FileHandle(forReadingFrom: url)
        defer { try? handle.close() }

        var hasher = SHA256()

        while let chunk = try handle.read(upToCount: 1 << 20), !chunk.isEmpty {
            hasher.update(data: chunk)
        }

        return hasher.finalize().map { String(format: "%02x", $0) }.joined()
    }
}
