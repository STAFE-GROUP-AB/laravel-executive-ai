// The install sequence as a dependency graph, asserted instead of assumed (#124).
//
// `InstallDependencyOrderTests` proves the sequence *runs*. This file states *why* it can run, as
// a table: what each state needs, and what each state makes true. The one assertion that matters
// is that every requirement is produced by a strictly earlier state — which is exactly the
// property #122 violated and no test noticed, because the doubles handed `CREATE_OWNER` a paired
// session that nothing in the real installer had created.
//
// The table is a claim about the product, not a restatement of the code, so each capability names
// the document that makes it true. Editing the table to make a failure go away is therefore a
// visible act: it changes a documented claim, and the reviewer can check it against the citation.
//
// Scope: the nine core states. The optional path (§12.1) is post-READY and has no gate here.

import XCTest
@testable import LaravelExecutiveAI

/// Something a state needs, or makes true. One per real-world fact, not one per function call.
enum InstallCapability: String, CaseIterable, Sendable {
    /// A free loopback port recorded in the checkpoint (`preflight.port`). PREFLIGHT.
    case loopbackPort
    /// The bundled runtime package verified against its manifest and signature.
    case verifiedPackage
    /// `runtime/<version>/` installed, hashed, read-only, `current` pointing at it.
    case installedRuntime
    /// The data directory exists (`data/`, Logs, Caches).
    case dataDirectory
    /// The Supervisor LaunchAgent is registered and the Supervisor process is answering XPC.
    /// Only `SMAppService.register()` produces this (ADR-001 D3).
    case supervisor
    /// `APP_KEY` and `K` exist in the Keychain. The Supervisor mints them on its first boot and
    /// is the only writer (ADR-003 D1, D2; services.md §2 step 3).
    case secrets
    /// Every bundled migration has run (health-contract §2 `database_migrations`).
    case migratedDatabase
    /// The core answers on loopback at all: `/health/live`. Needs the Supervisor to have spawned
    /// it, which needs `secrets`, because the core refuses to boot without `APP_KEY`
    /// (`SecretsServiceProvider`, ADR-001 D4 — there is no `.env`).
    case servingCore
    /// A `menubar` pairing grant. Minted by the Supervisor over XPC, lazily, on first need
    /// (ipc-contract §3.2 as corrected in #124).
    case pairedSession
    /// An owner account exists. Required by `auth_login`, which gates `ready`
    /// (health-contract §2).
    case ownerAccount
    /// The person's AI choice is recorded; an own API key is parked for the one Keychain writer.
    case aiChoice
    /// `/health/ready` answers `ready: true`.
    case readyHealth

    /// The document that makes this capability a claim about the product rather than about code.
    var citation: String {
        switch self {
        case .loopbackPort: "support-matrix.md §6.6; installer.md §3 (`preflight.port`)"
        case .verifiedPackage: "blueprint §6 (verify before execute); installer.md §5"
        case .installedRuntime: "ADR-001 D4; runtime-layout.md"
        case .dataDirectory: "ADR-001 D4"
        case .supervisor: "ADR-001 D3 (one SMAppService LaunchAgent, the Supervisor)"
        case .secrets: "ADR-003 D1, D2; services.md §2 step 3"
        case .migratedDatabase: "health-contract.md §2 `database_migrations`; blueprint §12.2"
        case .servingCore: "ADR-001 D3 (the Supervisor owns the PHP processes), D4 (no .env)"
        case .pairedSession: "ipc-contract.md §3.2, §7.1"
        case .ownerAccount: "health-contract.md §2 `auth_login`; blueprint §12.3 screen 4"
        case .aiChoice: "blueprint §12.3 screen 5; ai-state.md §4"
        case .readyHealth: "health-contract.md §1; blueprint §12.2"
        }
    }
}

/// What one core state needs before it runs, and what is true once it has.
struct InstallStateContract: Sendable {
    let state: InstallState
    let requires: [InstallCapability]
    let provides: [InstallCapability]
    /// Why this state provides what it provides, where that is not obvious.
    let note: String

    init(_ state: InstallState, requires: [InstallCapability] = [], provides: [InstallCapability] = [], note: String = "") {
        self.state = state
        self.requires = requires
        self.provides = provides
        self.note = note
    }
}

enum InstallGraph {
    /// One entry per core state, in sequence order.
    static let contracts: [InstallStateContract] = [
        InstallStateContract(.welcome, note: "A screen. Needs nothing, provides nothing."),
        InstallStateContract(
            .preflight,
            provides: [.loopbackPort],
            note: "Ten checks; the free port is the one fact later states read out of the checkpoint."
        ),
        InstallStateContract(
            .packageVerify,
            provides: [.verifiedPackage],
            note: "Manifest, SHA256SUMS, every file digest, the Team-ID signature."
        ),
        InstallStateContract(
            .installCore,
            requires: [.verifiedPackage, .loopbackPort],
            provides: [.installedRuntime, .dataDirectory, .supervisor, .secrets, .migratedDatabase, .servingCore],
            note: """
            Two steps. The runtime install, then `SMAppService.register()`, whose consequences are \
            the rest of this list: launchd starts the Supervisor, which mints APP_KEY and `K`, \
            serves the broker, migrates the database and spawns the core. The port is required \
            because the Supervisor reads it from the checkpoint and exits 78 without it \
            (services.md §2 step 2). Everything after this state needs a core, and only the \
            Supervisor can make one — that is the whole reason the registration is here (#124).
            """
        ),
        InstallStateContract(
            .createOwner,
            requires: [.servingCore, .migratedDatabase, .supervisor],
            provides: [.pairedSession, .ownerAccount],
            note: """
            `POST /ipc/v1/owner`. It needs the Supervisor to pair *with* and a migrated, serving \
            core to talk *to*; the grant itself is minted lazily here, so `pairedSession` is \
            provided by this state rather than required by it.
            """
        ),
        InstallStateContract(
            .selectAI,
            provides: [.aiChoice],
            note: """
            Requires nothing: the choice is recorded in the checkpoint and an own API key is \
            parked in memory (`PendingSecretHandoff`) for the one Keychain writer. Audited in \
            #124 for the #122 class of defect and found clean — it touches no service.
            """
        ),
        InstallStateContract(
            .bootServices,
            requires: [.supervisor, .aiChoice],
            note: """
            What is left of this state once the registration moved: confirm supervision is still \
            live and hand the SELECT_AI key over XPC. That hand-off is the only part that could \
            not have run earlier, which is why this is the state that keeps it.
            """
        ),
        InstallStateContract(
            .healthCheck,
            requires: [.servingCore, .migratedDatabase, .ownerAccount, .pairedSession],
            provides: [.readyHealth],
            note: """
            `/health/ready` is signed, so it needs a session; `ready` needs the migrations and an \
            owner (`database_migrations`, `auth_login`). The two checks that age in — \
            `scheduler_selftest` and `queue_worker` — are satisfied by the children the Supervisor \
            spawned at INSTALL_CORE, which is why moving the registration earlier also buys them \
            the two screens' worth of wall-clock they need.
            """
        ),
        InstallStateContract(.ready, requires: [.readyHealth], note: "The Done screen."),
    ]

    static func contract(for state: InstallState) -> InstallStateContract? {
        contracts.first { $0.state == state }
    }

    /// The first state in sequence order that makes `capability` true.
    static func provider(of capability: InstallCapability) -> InstallState? {
        contracts.first { $0.provides.contains(capability) }?.state
    }
}

final class InstallPrerequisiteGraphTests: XCTestCase {
    /// The property. Every requirement of every state is produced by a strictly earlier state.
    ///
    /// This is the assertion that fails if the sequence is reordered, if a capability moves to a
    /// later state, or if a new state is given a dependency nothing ahead of it provides. Run it
    /// against #122's ordering — `servingCore` provided by BOOT_SERVICES (index 6) and required by
    /// CREATE_OWNER (index 4) — and it fails here instead of on the owner's Mac.
    func testEveryRequirementIsProvidedByAStrictlyEarlierState() throws {
        for contract in InstallGraph.contracts {
            let index = try XCTUnwrap(contract.state.coreIndex, contract.state.rawValue)

            for capability in contract.requires {
                guard let provider = InstallGraph.provider(of: capability) else {
                    XCTFail("""
                    \(contract.state.rawValue) requires \(capability.rawValue), which no state provides.
                    Either a state has to start providing it, or the requirement is wrong.
                    What makes it a requirement: \(capability.citation)
                    """)
                    continue
                }

                let providerIndex = try XCTUnwrap(provider.coreIndex, provider.rawValue)

                XCTAssertLessThan(providerIndex, index, """
                \(contract.state.rawValue) requires \(capability.rawValue), but the earliest state that \
                provides it is \(provider.rawValue), which runs \(providerIndex == index ? "in the same state" : "later"). \
                The installer would reach \(contract.state.rawValue) without it — which is #124: \
                CREATE_OWNER needed a running core and nothing had started one.
                What makes it a requirement: \(capability.citation)
                """)
            }
        }
    }

    /// The table has to describe the real sequence, so a tenth state cannot be added without
    /// declaring what it needs and what it provides.
    func testEveryCoreStateIsInTheTableInSequenceOrder() {
        XCTAssertEqual(InstallGraph.contracts.map(\.state), InstallState.coreSequence)
    }

    /// No capability is claimed by two states: "who makes this true" must have one answer, or
    /// the ordering assertion above can be satisfied by the wrong one.
    func testEachCapabilityHasExactlyOneProvider() {
        for capability in InstallCapability.allCases {
            let providers = InstallGraph.contracts.filter { $0.provides.contains(capability) }.map(\.state.rawValue)

            XCTAssertEqual(providers.count, 1, "\(capability.rawValue) is provided by \(providers)")
        }
    }

    /// Nothing is produced that nothing needs, and nothing is needed that is never produced —
    /// an unused capability is usually a requirement someone forgot to write down.
    func testEveryCapabilityIsBothProvidedAndRequiredSomewhere() {
        let required = Set(InstallGraph.contracts.flatMap(\.requires))
        let provided = Set(InstallGraph.contracts.flatMap(\.provides))

        XCTAssertEqual(provided.subtracting(required).map(\.rawValue).sorted(), ["dataDirectory", "installedRuntime", "secrets"],
                       "the only capabilities nothing declares a need for are the ones every later state uses implicitly")
        XCTAssertEqual(required.subtracting(provided), [], "required but never produced")
    }

    /// The two facts #122 got wrong, pinned on their own so the diff shows them.
    func testTheSupervisorAndTheCoreAreProvidedBeforeTheOwnerScreen() throws {
        let createOwner = try XCTUnwrap(InstallState.createOwner.coreIndex)
        let supervisor = try XCTUnwrap(InstallGraph.provider(of: .supervisor)?.coreIndex)
        let core = try XCTUnwrap(InstallGraph.provider(of: .servingCore)?.coreIndex)
        let migrations = try XCTUnwrap(InstallGraph.provider(of: .migratedDatabase)?.coreIndex)

        XCTAssertLessThan(supervisor, createOwner)
        XCTAssertLessThan(core, createOwner)
        XCTAssertLessThan(migrations, createOwner)
        XCTAssertEqual(InstallGraph.provider(of: .supervisor), .installCore)
        XCTAssertGreaterThan(try XCTUnwrap(InstallState.bootServices.coreIndex), createOwner,
                             "BOOT_SERVICES is after CREATE_OWNER — the fact #121's premise got wrong")
    }

    /// Blueprint §12.1's sequence is unchanged by #124: only which state provides `supervisor`
    /// moved, and that was ADR-001 D3's sentence, not the blueprint's.
    func testTheBlueprintSequenceIsUnchanged() {
        XCTAssertEqual(InstallState.coreSequence.map(\.rawValue), [
            "WELCOME", "PREFLIGHT", "PACKAGE_VERIFY", "INSTALL_CORE", "CREATE_OWNER",
            "SELECT_AI", "BOOT_SERVICES", "HEALTH_CHECK", "READY",
        ])
    }
}
