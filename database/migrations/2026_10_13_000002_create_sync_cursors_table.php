<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `sync_cursors`: the resumable position of one sync scope on a non-mail
     * provider account — `email_sync_cursors` is the mail equivalent, and both keep
     * the same shape so one rule covers both (ADR-002 D3.4: the cursor is committed
     * in the same transaction as the batch it covers, never before).
     *
     * `value` is the provider's own opaque position (`SyncCursor::encode()`, sorted
     * JSON, ≤ 16 KiB plus envelope — module-contracts §6), read by nothing but the
     * adapter that issued it. `full_resync_required` is how an invalidated cursor
     * (Gmail 404 on an old `startHistoryId`, a GitHub cursor the API no longer
     * accepts) survives a restart: the row is kept with its reason rather than
     * deleted, so the UI can say why a full pass is running.
     */
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('external_account_id', 26);
            $table->string('source_type', 40);
            $table->string('scope', 200);
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

            $table->foreign('external_account_id', 'sync_cursors_external_account_fk')->references('id')->on('external_accounts');
            $table->unique(['external_account_id', 'source_type', 'scope'], 'sync_cursors_account_scope_unique');
            $table->index(['organization_id', 'full_resync_required'], 'sync_cursors_organization_resync_index');
        });
    }
};
