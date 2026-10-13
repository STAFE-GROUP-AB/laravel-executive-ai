Migrations for the §3.1 schema; every business object carries ULID, organization_id, retention, provenance, sensitivity class.

Conventions: `up()` only, ULIDs as `char(26)`, UTC `dateTime`, string + PHP enum instead of `enum`
columns, every index named. Add the §3.1 common columns with `App\Support\Database\BusinessObjectColumns`.
A shipped install is rolled back by restoring a verified snapshot (ADR-002 D5), never by a `down()`
that drops the owner's tables — `MigrationsContractTest` asserts that none of the migrations here
defines one (Laravel's bundled `cache` and `jobs` migrations keep the `down()` they ship with).
Table-by-table documentation: docs/architecture/data-model.md.
