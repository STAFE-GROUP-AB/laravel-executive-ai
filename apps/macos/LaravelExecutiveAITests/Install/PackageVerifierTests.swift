// PACKAGE_VERIFY fails closed: a tampered file, a tampered checksum file, an unlisted file, an
// unsigned binary and a binary that does not meet the Team ID requirement are all refused, and
// a consistent package with a validly signed binary passes.

import Foundation
import XCTest
@testable import LaravelExecutiveAI

final class PackageVerifierTests: XCTestCase {
    private static let identifier = "se.stafe.executive-ai.test-runtime"
    private var root: URL!

    override func setUpWithError() throws {
        root = FileManager.default.temporaryDirectory.appending(path: "lxai-verify-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
    }

    override func tearDown() {
        try? FileManager.default.removeItem(at: root)
    }

    /// A package whose executable is a real Mach-O: `/usr/bin/true`, stripped and re-signed ad hoc
    /// with a known identifier (or left unsigned).
    private func makePackage(signed: Bool) throws -> URL {
        let binary = root.appending(path: "true-\(UUID().uuidString)")
        try FileManager.default.copyItem(at: URL(fileURLWithPath: "/usr/bin/true"), to: binary)
        try TestFiles.run("/usr/bin/codesign", ["--remove-signature", binary.path])

        if signed {
            let result = try TestFiles.run("/usr/bin/codesign", ["-s", "-", "-i", Self.identifier, binary.path])
            XCTAssertEqual(result.status, 0, result.stderr)
        }

        return try SimulatedRuntimePackage.write(under: root.appending(path: "package"), files: [
            ("frankenphp", try Data(contentsOf: binary)),
            ("Caddyfile", Data("bind 127.0.0.1\n".utf8)),
            ("app/public/index.php", Data("<?php echo 'ok';\n".utf8)),
        ])
    }

    private var identifierPolicy: PackageVerifier {
        PackageVerifier(signatureChecker: SecStaticCodeSignatureChecker(requirement: "identifier \"\(Self.identifier)\""))
    }

    private func assertFails(_ package: URL, verifier: PackageVerifier? = nil, file: StaticString = #filePath, line: UInt = #line, _ check: (PackageVerificationError) -> Void) {
        XCTAssertThrowsError(try (verifier ?? identifierPolicy).verify(runtimeDirectory: package), file: file, line: line) { error in
            guard let error = error as? PackageVerificationError else {
                return XCTFail("unexpected error \(error)", file: file, line: line)
            }

            check(error)
        }
    }

    func testConsistentPackageWithASignedBinaryPasses() throws {
        let package = try makePackage(signed: true)
        let verified = try identifierPolicy.verify(runtimeDirectory: package)

        XCTAssertEqual(verified.version, "0.1.0")
        XCTAssertEqual(verified.manifest.executable, "frankenphp")
        XCTAssertEqual(verified.manifest.files.count, 3)
        XCTAssertGreaterThan(verified.totalBytes, 0)
    }

    func testTamperedFileFailsOnItsChecksum() throws {
        let package = try makePackage(signed: true)
        let target = package.appending(path: "Caddyfile")
        try Data("bind 127.0.0.2\n".utf8).write(to: target)

        assertFails(package) { XCTAssertEqual($0, .checksumMismatch(path: "Caddyfile")) }
    }

    func testTamperedBinaryFailsOnItsChecksumBeforeTheSignatureIsEvenChecked() throws {
        let package = try makePackage(signed: true)
        let binary = package.appending(path: "frankenphp")
        var bytes = try Data(contentsOf: binary)
        bytes.append(0x00)
        try bytes.write(to: binary)

        assertFails(package) { XCTAssertEqual($0, .sizeMismatch(path: "frankenphp")) }
    }

    func testTamperedChecksumFileIsCaughtAgainstTheManifest() throws {
        let package = try makePackage(signed: true)
        let sums = package.appending(path: PackageVerifier.checksumFileName)
        var text = try String(contentsOf: sums, encoding: .utf8)
        let first = text.removeFirst()
        text.insert(first == "0" ? "1" : "0", at: text.startIndex)
        try Data(text.utf8).write(to: sums)

        assertFails(package) { error in
            guard case .checksumFileDisagreesWithManifest = error else {
                return XCTFail("expected a manifest/SHA256SUMS disagreement, got \(error)")
            }
        }
    }

    func testUnsignedBinaryFailsSignatureValidation() throws {
        let package = try makePackage(signed: false)

        assertFails(package) { error in
            guard case .signatureInvalid(let reason) = error else {
                return XCTFail("expected signatureInvalid, got \(error)")
            }

            XCTAssertFalse(reason.isEmpty)
        }
    }

    func testAdHocSignatureDoesNotSatisfyTheTeamRequirement() throws {
        let package = try makePackage(signed: true)
        let verifier = PackageVerifier(signatureChecker: SecStaticCodeSignatureChecker(requirement: SecStaticCodeSignatureChecker.teamRequirement(teamIdentifier: "TEAMID0000")))

        assertFails(package, verifier: verifier) { error in
            guard case .signatureInvalid = error else {
                return XCTFail("expected signatureInvalid, got \(error)")
            }
        }
    }

    func testUnsignedAppHasNoPolicyAndFailsClosed() throws {
        // The test host is ad hoc signed: no Team ID, no policy, nothing verifies.
        XCTAssertThrowsError(try SecStaticCodeSignatureChecker.fromRunningApp())

        let package = try makePackage(signed: true)
        let verifier = PackageVerifier(signatureChecker: FailClosedSignatureChecker(reason: "test"))
        assertFails(package, verifier: verifier) { XCTAssertEqual($0, .noSigningPolicy("test")) }
    }

    func testUnlistedFileIsRefused() throws {
        let package = try makePackage(signed: true)
        try Data("<?php system($_GET['c']);".utf8).write(to: package.appending(path: "app/public/shell.php"))

        assertFails(package) { XCTAssertEqual($0, .unlistedFile(path: "app/public/shell.php")) }
    }

    func testSymlinkInPlaceOfAListedFileIsRefused() throws {
        let package = try makePackage(signed: true)
        let target = package.appending(path: "Caddyfile")
        try FileManager.default.removeItem(at: target)
        try FileManager.default.createSymbolicLink(at: target, withDestinationURL: URL(fileURLWithPath: "/etc/hosts"))

        assertFails(package) { XCTAssertEqual($0, .notARegularFile(path: "Caddyfile")) }
    }

    func testMissingFileManifestAndChecksumFileAreRefused() throws {
        let package = try makePackage(signed: true)

        try FileManager.default.removeItem(at: package.appending(path: "app/public/index.php"))
        assertFails(package) { XCTAssertEqual($0, .fileMissing(path: "app/public/index.php")) }

        try FileManager.default.removeItem(at: package.appending(path: PackageVerifier.checksumFileName))
        assertFails(package) { XCTAssertEqual($0, .checksumFileMissing) }

        try FileManager.default.removeItem(at: package.appending(path: PackageVerifier.manifestFileName))
        assertFails(package) { XCTAssertEqual($0, .manifestMissing) }

        assertFails(root.appending(path: "nowhere")) { XCTAssertEqual($0, .directoryMissing) }
    }

    func testManifestStructureIsValidated() throws {
        func manifest(_ files: [RuntimeManifest.Entry], executable: String = "frankenphp", schema: Int = 1, version: String = "0.1.0") -> RuntimeManifest {
            RuntimeManifest(schemaVersion: schema, runtimeVersion: version, executable: executable, files: files)
        }

        let good = RuntimeManifest.Entry(path: "frankenphp", sha256: String(repeating: "a", count: 64), bytes: 1)

        XCTAssertNoThrow(try manifest([good]).validate())
        XCTAssertThrowsError(try manifest([good], schema: 2).validate())
        XCTAssertThrowsError(try manifest([good], version: "1.0").validate())
        XCTAssertThrowsError(try manifest([]).validate())
        XCTAssertThrowsError(try manifest([good], executable: "missing").validate())
        XCTAssertThrowsError(try manifest([good, good]).validate(), "duplicate path")
        XCTAssertThrowsError(try manifest([RuntimeManifest.Entry(path: "../escape", sha256: good.sha256, bytes: 1)]).validate())
        XCTAssertThrowsError(try manifest([RuntimeManifest.Entry(path: "/abs", sha256: good.sha256, bytes: 1)]).validate())
        XCTAssertThrowsError(try manifest([RuntimeManifest.Entry(path: "frankenphp", sha256: "ABC", bytes: 1)]).validate())
        XCTAssertThrowsError(try manifest([RuntimeManifest.Entry(path: "frankenphp", sha256: good.sha256, bytes: -1)]).validate())
    }

    func testChecksumFileParsing() throws {
        let digest = String(repeating: "0", count: 64)
        let parsed = try PackageVerifier.parseChecksumFile("# comment\n\(digest)  frankenphp\n\(digest) *Caddyfile\n\n")
        XCTAssertEqual(parsed, ["frankenphp": digest, "Caddyfile": digest])

        XCTAssertThrowsError(try PackageVerifier.parseChecksumFile(""))
        XCTAssertThrowsError(try PackageVerifier.parseChecksumFile("nonsense"))
        XCTAssertThrowsError(try PackageVerifier.parseChecksumFile("\(digest)  a\n\(digest)  a\n"), "duplicate")
        XCTAssertThrowsError(try PackageVerifier.parseChecksumFile("\(digest)  ../x\n"))
    }

    func testSha256MatchesAKnownVector() throws {
        let url = root.appending(path: "abc")
        try Data("abc".utf8).write(to: url)
        XCTAssertEqual(try PackageVerifier.sha256Hex(of: url), "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad")
    }
}
