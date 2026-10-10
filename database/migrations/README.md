Migrations for the §3.1 minimum schema; every business object carries ULID, organization_id, retention, provenance, sensitivity class.

Conventions: `up()` only, ULIDs as `char(26)`, UTC `dateTime`, string + PHP enum instead of `enum`
columns, every index named. Add the §3.1 common columns with `App\Support\Database\BusinessObjectColumns`.
Table-by-table documentation: docs/architecture/data-model.md.
