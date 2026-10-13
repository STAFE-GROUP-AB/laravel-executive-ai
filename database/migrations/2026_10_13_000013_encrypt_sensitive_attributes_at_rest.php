<?php

use App\Security\Secrets\BlindIndex;
use App\Security\Secrets\EncryptedAttributeInventory;
use App\Security\Secrets\SensitiveAttributeRewriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * S2-04 (#87): the blueprint §3.1 sensitive attributes become encrypted at rest.
     * `App\Security\Secrets\EncryptedAttributeInventory` is the list and the argument
     * for it; `docs/architecture/data-model.md` §"Encryption at rest" records which
     * columns were left plaintext and why.
     *
     * This migration is the half that brings an **existing** database forward. Local
     * installs hold plaintext rows written before the casts existed, `migrate` never
     * re-runs an applied migration (`Migrator::pendingMigrations()`), and this project
     * never rebuilds a database (`CLAUDE.md`, ALDRIG TÖMMA EN DATABAS; ADR-002 D5 rolls
     * a shipped install back by restoring a verified snapshot, never by a `down()`).
     * Unlike #117 there is no second half amending the `create` migrations: a cast is
     * not a schema property, so a fresh database gets the right shape from the create
     * migrations plus this one, and every step here is guarded so the fresh path is a
     * no-op over empty tables.
     *
     * ## This runs on `core.app-key`, and A25 is why that is safe
     *
     * From this migration on, a lost `core.app-key` means unreadable rows. `A25` in
     * `docs/OPEN-QUESTIONS.md` decided (option **a**) that a keep-data uninstall keeps
     * `core.app-key` **and only it**, so the key survives exactly the uninstall that
     * keeps the data; a delete-everything uninstall takes both. ADR-003 D8 and
     * `docs/operations/uninstall.md` §5 are amended in the same pull request, and
     * `tests/Feature/Install/ReinstallPreservesDataTest.php` boots twice over one data
     * directory to prove the encrypted rows come back.
     *
     * ## Three things had to be settled before a cast could be added
     *
     * **1. Search.** The §3.1 corpus is an FTS5 external-content table per entity on
     * the `local` profile and an InnoDB `FULLTEXT` index on `server`, over the columns
     * `App\CRM\Search\SearchableEntity` names: `customers.display_name/legal_name/notes`,
     * `contacts.display_name/given_name/family_name/title/notes`,
     * `communications.subject/excerpt`. Ciphertext tokenises to noise, so **not one of
     * those columns is in the inventory** and
     * `tests/Integration/BackendContract/CrmSearchContractTest.php` — the frozen hit
     * set on both profiles — passes unchanged. `EncryptedAttributeInventoryTest`
     * asserts the exclusion structurally, so a later item cannot encrypt an indexed
     * column by accident.
     *
     * **2. Uniqueness.** Laravel's encrypter is randomised, so a unique index over
     * ciphertext constrains nothing and an equality lookup never hits. One inventoried
     * column was unique — `contact_emails.address`, under
     * `contact_emails_organization_address_unique` — so the constraint moves onto
     * `address_hash`, a keyed HMAC of the lower-cased address, exactly as the identity
     * of a passkey moved onto `passkey_credentials.credential_id_hash` (#117). The
     * index keeps its name, so S2-01's frozen contract test still finds it. It is
     * *keyed*, where the passkey hash is not, because a credential id is not a secret
     * and an email address is: the set of plausible addresses is small enough that an
     * unkeyed digest would be reversible by dictionary and would hand back the value
     * the ciphertext beside it exists to protect. `App\Security\Secrets\BlindIndex`
     * carries that argument in full.
     *
     * **3. Width, and the `json` type.** A Laravel payload is base64 of a JSON envelope
     * around the padded ciphertext — roughly 2.4× the input — and MySQL runs
     * `STRICT_TRANS_TABLES` here, so an overflow is a hard error, not a silent
     * truncation (#117 measured exactly that). Worse, an `encrypted:array` cast writes
     * a base64 *string*, which MySQL's `json` type rejects outright. So every
     * inventoried column becomes `mediumtext` on the `server` profile, which also
     * moves `varchar(320)`/`varchar(255)` payloads off-page and takes pressure off
     * MySQL's 65 535-byte row-definition limit rather than adding to it.
     *
     * **And none of that touches SQLite.** Measured, not assumed, and the same two
     * facts #117 recorded: SQLite's grammar emits no `VARCHAR` length and enforces
     * none, and its `json()` type already compiles to `text`. So every column in the
     * inventory is already unbounded `text` on the `local` profile, the widening is
     * skipped there, and the one step that would cost something — Laravel's SQLite
     * grammar implements `->change()` by **rebuilding the table** — never runs on the
     * profile the owner actually uses. Adding a column and moving an index are plain
     * statements there (`compileUnique()` is `create unique index`, not a table
     * constraint), so the only writes on `local` are the row rewrites themselves.
     *
     * ## Order, and why it is this order
     *
     * `address_hash` is added and backfilled from the plaintext address **before** the
     * index moves, because a unique index cannot be created over a column that is still
     * all-defaults; the old index is dropped **before** `address` is widened, because
     * MySQL refuses to convert an indexed `varchar` to `TEXT` (a `TEXT` column cannot
     * carry an unprefixed key at all); and the rows are encrypted **last**, because
     * encrypting first would overflow the columns that have not been widened yet. The
     * hash backfill is nonetheless written to work on either form — it decrypts first
     * if it finds ciphertext — so an interrupted run that is replayed cannot produce a
     * hash of a ciphertext.
     *
     * On `local`, rewriting `contacts` and `communications` rows fires the FTS5
     * `AFTER UPDATE` triggers. That is harmless and worth stating: the triggers
     * re-publish the row's *indexed* columns, none of which this migration changes, so
     * each one deletes and reinserts identical index content. The search contract test
     * runs after the migration and proves it.
     */
    public function up(): void
    {
        $this->addTheContactEmailHashColumn();
        $this->backfillTheContactEmailHashes();
        $this->moveTheAddressUniqueIndexOntoTheHash();
        $this->widenEveryEncryptedColumn();
        $this->encryptTheRowsAlreadyThere();
    }

    private function addTheContactEmailHashColumn(): void
    {
        if (Schema::hasColumn('contact_emails', 'address_hash')) {
            return;
        }

        Schema::table('contact_emails', function (Blueprint $table): void {
            // Not nullable: a nullable column under a unique index constrains nothing,
            // because both engines treat NULL as distinct. The default exists only so
            // `ADD COLUMN` is legal against rows already there; the backfill below
            // replaces it and `ContactEmail`'s saving hook keeps the column in step
            // with `address` on every write from here on.
            $table->char('address_hash', BlindIndex::width())->default('')->after('address');
        });
    }

    /**
     * Only rows still carrying the `ADD COLUMN` default are touched, so this is a
     * single empty `SELECT` on a database that already has the column — and on a fresh
     * install, where `contact_emails` is empty, it is one empty `SELECT` either way.
     */
    private function backfillTheContactEmailHashes(): void
    {
        app(SensitiveAttributeRewriter::class)->rewriteContactEmailHashes(onlyMissing: true);
    }

    /**
     * The constraint keeps its name and changes its columns — and on MySQL that cannot
     * be done by dropping first.
     *
     * `contact_emails_organization_address_unique` is `(organization_id, address)`, and
     * `contact_emails_organization_fk` references `organizations.id` from
     * `organization_id`. InnoDB requires an index whose **leftmost** column is the
     * referencing column, and because Laravel adds the foreign key *after* creating the
     * table's indexes, MySQL found that unique index already sufficient and created no
     * index of its own for the constraint. Dropping it is therefore refused outright:
     *
     *     SQLSTATE[HY000]: 1553 Cannot drop index
     *     'contact_emails_organization_address_unique': needed in a foreign key constraint
     *
     * Measured, on a clean MySQL 8.4 database — the `server-profile` CI lane is exactly
     * the lane that catches this class of thing, and #117 is the precedent for it being
     * invisible to every SQLite-only check (SQLite enforces no index for a foreign key
     * at all, so the drop succeeds there).
     *
     * So the order is inverted: a scaffold index over the *new* columns goes up first,
     * which satisfies the foreign key — its leftmost column is `organization_id` too —
     * and only then can the old index come down. The real index is created under the
     * frozen name once `address` has been widened, and the scaffold comes back out,
     * which is safe because by then the named unique index is serving the foreign key.
     * Two indexes over the same pair of columns coexist for three statements; both
     * engines allow that, and the final state is identical on each.
     *
     * **The scaffold is not redundant — do not delete it as dead weight.** Without it
     * this migration cannot run on MySQL at all. And `contact_emails` is not the only
     * table in that shape: nine foreign keys in the schema are served by exactly one
     * index and it is a unique one, so the next migration to touch any of those unique
     * indexes needs the same inversion. They are listed in
     * `docs/architecture/data-model.md` §1 "Foreign keys with no index of their own",
     * and this is the query that finds them — re-run it after any migration that adds a
     * unique index starting with a foreign-key column:
     *
     *     SELECT fk.TABLE_NAME, fk.CONSTRAINT_NAME, fk.fk_columns, s.names, s.n
     *     FROM (SELECT k.TABLE_NAME, k.CONSTRAINT_NAME,
     *                  GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION) AS fk_columns
     *           FROM information_schema.key_column_usage k
     *           JOIN information_schema.table_constraints t
     *             ON t.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
     *            AND t.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND t.TABLE_NAME = k.TABLE_NAME
     *           WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND t.CONSTRAINT_TYPE = 'FOREIGN KEY'
     *           GROUP BY k.TABLE_NAME, k.CONSTRAINT_NAME) AS fk
     *     JOIN (SELECT TABLE_NAME, fk_columns, COUNT(*) AS n, SUM(NON_UNIQUE) AS non_unique,
     *                  GROUP_CONCAT(CONCAT(INDEX_NAME, ' = ', cols) SEPARATOR ' | ') AS names
     *           FROM (SELECT i.TABLE_NAME, i.INDEX_NAME, i.NON_UNIQUE, i.cols, f.fk_columns
     *                 FROM (SELECT TABLE_NAME, INDEX_NAME, MAX(NON_UNIQUE) AS NON_UNIQUE,
     *                              GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols
     *                       FROM information_schema.statistics WHERE TABLE_SCHEMA = DATABASE()
     *                       GROUP BY TABLE_NAME, INDEX_NAME) AS i
     *                 JOIN (…the same fk subquery…) AS f ON f.TABLE_NAME = i.TABLE_NAME
     *                 WHERE i.cols = f.fk_columns
     *                    OR i.cols LIKE CONCAT(f.fk_columns, ',%')) AS matched
     *           GROUP BY TABLE_NAME, fk_columns) AS s
     *       ON s.TABLE_NAME = fk.TABLE_NAME AND s.fk_columns = fk.fk_columns
     *     WHERE s.non_unique = 0;
     */
    private function moveTheAddressUniqueIndexOntoTheHash(): void
    {
        $name = 'contact_emails_organization_address_unique';
        $scaffold = 'contact_emails_address_hash_scaffold_index';

        if (! $this->indexCovers('contact_emails', $name, ['organization_id', 'address_hash'])) {
            if (! $this->hasIndex('contact_emails', $scaffold)) {
                Schema::table('contact_emails', function (Blueprint $table) use ($scaffold): void {
                    $table->index(['organization_id', 'address_hash'], $scaffold);
                });
            }

            if ($this->hasIndex('contact_emails', $name)) {
                Schema::table('contact_emails', function (Blueprint $table) use ($name): void {
                    $table->dropUnique($name);
                });
            }

            $this->widen('contact_emails', 'address');

            Schema::table('contact_emails', function (Blueprint $table) use ($name): void {
                // The same name the S2-01 contract test asserts, over the column that
                // can actually carry it. "Address" still describes what is constrained:
                // one address per workspace, now identified by its keyed hash.
                $table->unique(['organization_id', 'address_hash'], $name);
            });
        }

        // Unconditional, so a run interrupted between the two statements above does not
        // leave the scaffold behind on the next pass.
        if ($this->hasIndex('contact_emails', $scaffold)) {
            Schema::table('contact_emails', function (Blueprint $table) use ($scaffold): void {
                $table->dropIndex($scaffold);
            });
        }
    }

    private function widenEveryEncryptedColumn(): void
    {
        foreach (EncryptedAttributeInventory::columnsByTable() as $table => $columns) {
            foreach ($columns as $column) {
                $this->widen($table, $column);
            }
        }
    }

    /**
     * `mediumtext`, with the column's existing nullability — read from the schema
     * rather than restated here, because `->change()` re-declares the whole column and
     * a hand-written list is exactly the kind of thing that silently flips a
     * `NOT NULL` to nullable years later. None of these columns has a default.
     */
    private function widen(string $table, string $column): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $existing = $this->column($table, $column);

        if ($existing === null || mb_strtolower((string) $existing['type_name']) === 'mediumtext') {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $existing): void {
            $definition = $blueprint->mediumText($column);

            if ((bool) $existing['nullable']) {
                $definition->nullable();
            }

            $definition->change();
        });
    }

    private function encryptTheRowsAlreadyThere(): void
    {
        app(SensitiveAttributeRewriter::class)->encryptPlaintext();

        // The hashes were derived before the ciphertext pass; recomputing them now is
        // a no-op that costs one pass and proves it (`rewriteContactEmailHashes()`
        // writes only rows whose stored hash disagrees with the derived one), so an
        // interrupted earlier step cannot leave an address indexed under the wrong key.
        app(SensitiveAttributeRewriter::class)->rewriteContactEmailHashes(onlyMissing: false);
    }

    private function hasIndex(string $table, string $name): bool
    {
        return $this->index($table, $name) !== null;
    }

    /** @param  list<string>  $columns */
    private function indexCovers(string $table, string $name, array $columns): bool
    {
        $index = $this->index($table, $name);

        return $index !== null && array_map(mb_strtolower(...), (array) $index['columns']) === $columns;
    }

    /** @return ?array<string, mixed> */
    private function index(string $table, string $name): ?array
    {
        foreach (Schema::getIndexes($table) as $index) {
            if ($index['name'] === $name) {
                return $index;
            }
        }

        return null;
    }

    /** @return ?array<string, mixed> */
    private function column(string $table, string $name): ?array
    {
        foreach (Schema::getColumns($table) as $column) {
            if ($column['name'] === $name) {
                return $column;
            }
        }

        return null;
    }
};
