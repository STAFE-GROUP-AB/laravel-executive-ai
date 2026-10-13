<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Natural persons at one or more customers (§3.2). Email addresses live in
     * `contact_emails` so the §5.6 exact-address match is an indexed lookup;
     * phone numbers stay a JSON list because nothing matches on them.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->string('given_name', 200);
            $table->string('family_name', 200);
            $table->string('display_name', 200);
            $table->string('title', 200)->nullable();
            $table->json('phones');
            $table->string('language', 35);
            $table->text('notes');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->index(['organization_id', 'display_name'], 'contacts_organization_display_name_index');
            $table->index(['organization_id', 'family_name', 'given_name'], 'contacts_organization_name_index');
        });
    }
};
