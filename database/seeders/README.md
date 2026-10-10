Seeders for local development and fixtures; never production data.

`DatabaseSeeder` is deliberately empty: the owner is created by the installer (CREATE_OWNER) and
logs in with an emailed one-time code (S1-02). No seeded user, no password anywhere. Load
`packaging/fixtures/briefs/*.json` through `App\Briefs\BriefDocumentMapper` when you need data.
