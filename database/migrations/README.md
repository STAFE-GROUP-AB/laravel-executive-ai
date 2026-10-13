Migrations for the §3.1 schema; every business object carries ULID, organization_id, retention, provenance, sensitivity class.

Conventions: `up()` only, ULIDs as `char(26)`, UTC `dateTime`, string + PHP enum instead of `enum`
columns, every index named. Add the §3.1 common columns with `App\Support\Database\BusinessObjectColumns`.
A shipped install is rolled back by restoring a verified snapshot (ADR-002 D5), never by a `down()`
that drops the owner's tables — `MigrationsContractTest` asserts that none of the migrations here
defines one (Laravel's bundled `cache` and `jobs` migrations keep the `down()` they ship with).
Two things to know before touching an **index** or an **encrypted column**:

- **Dropping a unique index on MySQL can be refused.** Where a `<table>_<columns>_unique` index starts
  with a foreign key's column, InnoDB adopted it for that constraint — Laravel adds foreign keys after
  the table's indexes — and refuses to drop it (`SQLSTATE[HY000] 1553`). SQLite enforces no index for a
  foreign key, so this only ever appears in the `server-profile` lane. Nine foreign keys are in that
  shape today; they are listed in docs/architecture/data-model.md §1 "Foreign keys with no index of
  their own", with the audit query and the additive fix (a scaffold index first) in
  `2026_10_13_000013_encrypt_sensitive_attributes_at_rest`.
- **An `encrypted:` cast is not added in a migration, and not in a model either.** The §3.1 sensitive
  attributes are declared in `App\Security\Secrets\EncryptedAttributeInventory` (S2-04), which drives
  both the casts and ADR-003 D8's re-encryption pass; `tests/Security/EncryptedAttributeInventoryTest.php`
  fails on a cast that is not in it. An encrypted column cannot be indexed, matched or searched, and is
  `mediumtext` on MySQL because ciphertext is ~2.4× its plaintext.

Table-by-table documentation: docs/architecture/data-model.md.
