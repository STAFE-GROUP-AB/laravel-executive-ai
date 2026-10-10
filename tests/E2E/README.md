End-to-end slices and the tri-surface brief parity gate (§16.3, §16.8). Pest suite `E2E`
(`phpunit.xml`), `DatabaseTransactions` only. `BriefParityTest.php` (S1-10) renders every brief
fixture through the mail renderer and the PWA (page object and the owner route
`GET /briefs/{brief}/parity.json`) and compares the RFC 8785 bytes of the parity payload with each
other, with the fixture's own projection and with the SwiftUI menu bar export that
`apps/macos/LaravelExecutiveAITests/MenuBar/BriefParitySnapshotTests.swift` writes to
`build/parity/`; set `LXAI_SWIFT_PARITY_EXPORT_DIR` to that directory to compare against the live
Swift export (the `parity` CI job), leave it unset to fall back to the committed snapshot for the
canonical fixture (the `php` lane). Oracle, lanes and the recorded deliberate break are documented
in `docs/architecture/brief-parity.md` §3.
