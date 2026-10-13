// CREATE_OWNER hand-off (S1-05 defines, S1-02 implements the account, #121 wires the two).
//
// The installer captures a name and an e-mail address on screen 4 and hands them to the core
// over `POST /ipc/v1/owner` (ipc-contract §6.9, `IpcOwnerCreator`). There is no credential step:
// sign-in is a one-time code to that address, and a passkey can be added later. The "no second
// owner on a repeated install" guarantee (T-72) is the core's, and reaches the installer as
// `OwnerHandoff(alreadyExisted: true)`. Nothing here is persisted or logged.

import Foundation

struct OwnerDraft: Equatable, Sendable {
    enum ValidationError: Error, Equatable, Sendable {
        case nameMissing
        case emailMissing
        case emailInvalid
    }

    var name: String
    var email: String

    init(name: String = "", email: String = "") {
        self.name = name
        self.email = email
    }

    var trimmedName: String {
        name.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    var trimmedEmail: String {
        email.trimmingCharacters(in: .whitespacesAndNewlines)
    }

    func validate() -> [ValidationError] {
        var errors: [ValidationError] = []

        if trimmedName.isEmpty {
            errors.append(.nameMissing)
        }

        if trimmedEmail.isEmpty {
            errors.append(.emailMissing)
        } else if !Self.looksLikeEmail(trimmedEmail) {
            errors.append(.emailInvalid)
        }

        return errors
    }

    var isValid: Bool {
        validate().isEmpty
    }

    /// Deliberately loose: one `@`, something on both sides, a dot in the domain, no whitespace.
    /// The core validates for real (S1-02); this only stops obvious typos before the hand-off.
    static func looksLikeEmail(_ value: String) -> Bool {
        let parts = value.split(separator: "@", omittingEmptySubsequences: false)

        guard parts.count == 2 else {
            return false
        }

        let local = parts[0]
        let domain = parts[1]

        guard !local.isEmpty, domain.contains("."), !domain.hasPrefix("."), !domain.hasSuffix(".") else {
            return false
        }

        return value.rangeOfCharacter(from: .whitespacesAndNewlines) == nil
    }
}

/// What the core returns once the owner exists. The installer needs nothing but the fact.
struct OwnerHandoff: Equatable, Sendable {
    /// True when the owner already existed (data-preserving reinstall) and no account was created.
    let alreadyExisted: Bool
}

enum OwnerCreationError: Error, Equatable, Sendable {
    case unavailable(String)
    case rejected(String)
}

protocol OwnerCreating: Sendable {
    func createOwner(_ draft: OwnerDraft) async throws -> OwnerHandoff
}

final class ScriptedOwnerCreator: OwnerCreating, @unchecked Sendable {
    private let lock = NSLock()
    private let result: Result<OwnerHandoff, OwnerCreationError>
    private(set) var drafts: [OwnerDraft] = []

    init(result: Result<OwnerHandoff, OwnerCreationError> = .success(OwnerHandoff(alreadyExisted: false))) {
        self.result = result
    }

    func createOwner(_ draft: OwnerDraft) async throws -> OwnerHandoff {
        lock.withLock {
            drafts.append(draft)
        }

        return try result.get()
    }
}
