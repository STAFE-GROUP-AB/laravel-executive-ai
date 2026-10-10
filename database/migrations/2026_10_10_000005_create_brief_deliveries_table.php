<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only delivery attempt log (brief schema §2 "Idempotent delivery").
     * `position` is the row's index in the document's deliveries[] so the log
     * order survives a round trip.
     *
     * Two indexes make a double send impossible to record, not just detectable:
     *  - (idempotency_key, channel, recipient, attempt) is unique, so a retry
     *    that re-runs attempt N cannot insert a second row;
     *  - `sent_marker` is a generated column that is 1 when status = sent and
     *    NULL otherwise; the unique index over (idempotency_key, channel,
     *    recipient, sent_marker) therefore allows any number of queued /
     *    failed / skipped rows but exactly one `sent` per target. This is the
     *    portable form of the partial unique index the schema document asks
     *    for (SQLite and MySQL both treat NULLs as distinct in unique indexes).
     */
    public function up(): void
    {
        Schema::create('brief_deliveries', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('brief_id', 26);
            $table->char('idempotency_key', 26);
            $table->unsignedInteger('position');
            $table->string('channel', 20);
            $table->string('recipient', 320);
            $table->unsignedInteger('attempt');
            $table->string('status', 20);
            $table->string('message_id', 998)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('sent_marker')->nullable()
                ->virtualAs("case when status = 'sent' then 1 else null end");
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table, softDeletes: false);

            $table->foreign('brief_id', 'brief_deliveries_brief_fk')->references('id')->on('briefs');
            $table->unique(
                ['idempotency_key', 'channel', 'recipient', 'attempt'],
                'brief_deliveries_idempotency_attempt_unique'
            );
            $table->unique(
                ['idempotency_key', 'channel', 'recipient', 'sent_marker'],
                'brief_deliveries_single_sent_unique'
            );
            $table->unique(['brief_id', 'position'], 'brief_deliveries_brief_position_unique');
            $table->index(['brief_id', 'channel'], 'brief_deliveries_brief_channel_index');
        });
    }
};
