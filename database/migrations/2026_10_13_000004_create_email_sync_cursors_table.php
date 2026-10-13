<?php

use App\Integrations\Mail\Dto\SyncCursor;
use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §5.6 `email_sync_cursors`: **one row per `(connection_id, scope)`**, where
     * `scope` is the provider folder the position applies to or `*` for the whole
     * account (`SyncCursor::ACCOUNT_SCOPE`) — Gmail history ids are per mailbox,
     * Graph delta state is per folder, so the pair is the key rather than the
     * connection alone.
     *
     * `value` holds `SyncCursor::encode()` verbatim: sorted JSON whose `value` field
     * the connector owns, bounded by `SyncCursor::MAX_VALUE_LENGTH` (16 KiB) plus
     * the envelope's keys (module-contracts §6). `text` is the portable column for
     * that — 64 KiB on MySQL `TEXT`, unbounded on SQLite — so a Graph `@odata.deltaLink`
     * near the limit still fits with room for `kind`, `scope`, `parts` and
     * `issued_at`. `value_bytes` is recorded so a provider that starts returning
     * longer links is a visible health signal, not a truncated cursor.
     *
     * ADR-002 D3.4: the row is updated **in the same transaction as the batch it
     * covers**, never before — that, with the `(connection_id, provider_message_id)`
     * upsert in E3-2, is what makes "resume after interruption without duplicates or
     * data loss" (§5.6 criterion 3) hold under `synchronous = NORMAL`.
     */
    public function up(): void
    {
        Schema::create('email_sync_cursors', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('connection_id', 26);
            $table->string('scope', 200)->default(SyncCursor::ACCOUNT_SCOPE);
            $table->string('kind', 60);
            $table->text('value');
            $table->unsignedInteger('value_bytes');
            $table->unsignedInteger('cursor_version')->default(1);
            $table->dateTime('synced_through_at')->nullable();
            $table->dateTime('last_batch_at')->nullable();
            $table->unsignedInteger('last_batch_rows')->nullable();
            $table->boolean('full_resync_required')->default(false);
            $table->dateTime('invalidated_at')->nullable();
            $table->string('invalid_reason', 200)->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('connection_id', 'email_sync_cursors_connection_fk')->references('id')->on('email_connections');
            $table->unique(['connection_id', 'scope'], 'email_sync_cursors_connection_scope_unique');
            $table->index(['organization_id', 'full_resync_required'], 'email_sync_cursors_organization_resync_index');
        });
    }
};
