ADR-002 D10's contract-test matrix: the assertions that must hold on **both** backend profiles —
`local` (SQLite in WAL mode) and `server` (MySQL 8.4). Everything here carries the
`backend-contract` group; the `search` tests also carry `search`.

Tests run inside `DatabaseTransactions`. The refresh-database trait is forbidden in this repository
and the schema is built once per process by `Tests\TestCase` with a plain `migrate` (ADR-002 D10).
Migrations are `up()`-only, so the group asserts `migrate` from zero rather than a rollback. The one
exception is `CrmSearchContractTest`, which has to commit its corpus because InnoDB publishes rows
to a FULLTEXT index only at commit; it imports the fixture pack under mirrored ULIDs and deletes it
again per test, so no other test ever sees a row it did not create (ADR-002 D4, Evidence E6).

Running the `server` leg locally — against a database that exists only for this, never one with
data in it, and never with `migrate:fresh`, `migrate:refresh` or `db:wipe`:

```bash
mysql -h127.0.0.1 -uroot -e "CREATE DATABASE IF NOT EXISTS lxai_contract_test CHARACTER SET utf8mb4"
export LEA_BACKEND_PROFILE=server DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 \
       DB_DATABASE=lxai_contract_test DB_USERNAME=root DB_PASSWORD=
php artisan migrate --force
vendor/bin/pest tests/Integration/BackendContract tests/Feature/CRM
```

Without those variables the `server`-profile tests skip themselves, so a developer with no MySQL
stays green. See `docs/architecture/data-model.md` §5 for why the *whole* suite is not expected to
pass on a MySQL connection.

| Group | File | Landed by |
|---|---|---|
| `migrations` | `MigrationsContractTest.php` | S1-01 |
| `migrations` (CRM) | `CrmSchemaContractTest.php` | S2-01 |
| `search` | `CrmSearchContractTest.php` | S2-01 |

`queue`, `repository`, `sync-idempotency`, `cross-account`, `backup-restore` and
`profile-migration` land with their own backlog items.
