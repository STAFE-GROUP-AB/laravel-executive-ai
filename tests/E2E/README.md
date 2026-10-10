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

`VerticalSliceTest.php` (S1-15, blueprint §16 step 8) is the gate before Sprint 3: *Install → Create
owner → Open PWA → Generate seeded brief → Show same brief in menu bar → Preview exact email* as one
run against a freshly installed core — a second application booted on a throwaway `LEA_DATA_DIR`
(`tests/Support/FreshInstance.php`), migrated from nothing, never the suite's database — with the
same menu bar oracle as above, `Http::preventStrayRequests()` armed and a failing mail transport as
the default mailer. It writes `build/e2e/vertical-slice.json` (`tests/Support/VerticalSliceReport.php`),
which `packaging/fixtures/e2e/vertical-slice.mjs` validates and summarises for the `parity` CI job.
How to run and read it: `docs/operations/vertical-slice.md`.
