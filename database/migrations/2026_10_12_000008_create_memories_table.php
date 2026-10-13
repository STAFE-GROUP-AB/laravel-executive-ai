<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App-owned long-term memory (§3.1 `memories`, blueprint §4: "Konversation, minne
     * och användarprofil är appägda. LLM kan inte uppdatera permanent minne utan
     * annoterad, granskad operation").
     *
     * **The review is the point.** A row the model proposes is written with status
     * `proposed`, carries `written_by` in the provenance actor grammar
     * (`ai:<provider>/<model>`), and only a reviewed operation — a person, recorded in
     * `reviewed_by_user_id` / `reviewed_at` — moves it to `active`. `active` is the
     * only status the agent reads (`App\AI\Memory\MemoryStatus`).
     *
     * **One active memory per key**, enforced by an index rather than a check:
     * `active_marker` is a generated column that is `1` while the row is active and
     * `NULL` otherwise, so the unique index over
     * `(organization_id, scope_key, memory_key, active_marker)` admits any number of
     * proposed, superseded or rejected rows and at most one active row — the
     * `brief_deliveries_single_sent_unique` idiom, and the reason a contradictory
     * memory cannot be *recorded* rather than merely being wrong once written.
     *
     * `scope_key` is the canonical, non-null scope identity (`organization`,
     * `user:<ulid>`, `customer:<ulid>`, `project:<ulid>`). The nullable
     * `subject_type` / `subject_id` / `user_id` columns are what queries and the
     * foreign key use; they cannot carry the unique index, because both engines treat
     * `NULL` as distinct and an index over them would constrain nothing for the
     * organization- and user-scoped rows.
     *
     * `content` is text, passes the `Redactor` on write, and is never a blob or a
     * path. A memory about a natural person is `sensitivity_class = personal`, so the
     * §3.2 GDPR export and erasure have one place to look.
     */
    public function up(): void
    {
        Schema::create('memories', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);

            $table->string('scope', 20);
            $table->string('scope_key', 130);
            $table->string('subject_type', 100)->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->char('user_id', 26)->nullable();

            $table->string('kind', 40);
            $table->string('memory_key', 200);
            $table->text('content');
            $table->decimal('confidence', 4, 3)->nullable();

            // §4: nothing the model writes becomes permanent without a review.
            $table->string('status', 20)->default('proposed');
            $table->string('written_by', 200);
            $table->dateTime('reviewed_at')->nullable();
            $table->char('reviewed_by_user_id', 26)->nullable();
            $table->string('review_note', 200)->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->char('supersedes_id', 26)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->unsignedBigInteger('use_count')->default(0);

            $table->unsignedTinyInteger('active_marker')->nullable()
                ->virtualAs("case when status = 'active' then 1 else null end");

            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('user_id', 'memories_user_fk')->references('id')->on('users');
            $table->foreign('reviewed_by_user_id', 'memories_reviewed_by_user_fk')->references('id')->on('users');
            $table->foreign('supersedes_id', 'memories_supersedes_fk')->references('id')->on('memories');

            $table->unique(
                ['organization_id', 'scope_key', 'memory_key', 'active_marker'],
                'memories_single_active_unique'
            );
            $table->index(['organization_id', 'status', 'kind'], 'memories_organization_status_kind_index');
            $table->index(['organization_id', 'scope_key'], 'memories_organization_scope_index');
            $table->index(['subject_type', 'subject_id'], 'memories_subject_index');
            $table->index(['user_id', 'status'], 'memories_user_status_index');
            $table->index(['organization_id', 'expires_at'], 'memories_organization_expires_at_index');
        });
    }
};
