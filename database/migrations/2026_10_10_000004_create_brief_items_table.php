<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ordered items of one brief version. `item_id` is the document's item id
     * (stable so approvals can point at it); `position` is the semantic order
     * every renderer must preserve (brief schema §6).
     */
    public function up(): void
    {
        Schema::create('brief_items', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('brief_id', 26);
            $table->char('item_id', 26);
            $table->unsignedInteger('position');
            $table->string('kind', 20);
            $table->string('title', 200);
            $table->text('body');
            $table->string('priority', 20);
            $table->dateTime('due_at')->nullable();
            $table->json('source_refs')->nullable();
            $table->json('uncertainty')->nullable();
            $table->json('action_ids')->nullable();
            $table->string('dedupe_key', 200)->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('brief_id', 'brief_items_brief_fk')->references('id')->on('briefs');
            $table->unique(['brief_id', 'item_id'], 'brief_items_brief_item_unique');
            $table->unique(['brief_id', 'position'], 'brief_items_brief_position_unique');
            $table->index(['organization_id', 'dedupe_key'], 'brief_items_organization_dedupe_key_index');
        });
    }
};
