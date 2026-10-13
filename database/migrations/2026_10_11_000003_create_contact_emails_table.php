<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per address a contact is known by (§3.2 "flera e-postadresser").
     * Addresses are stored lower-cased and are unique per organization, which is
     * what makes "sender address is the primary address of contact X" (§5.6) a
     * deterministic rule. `is_primary` marks the one address shown first.
     */
    public function up(): void
    {
        Schema::create('contact_emails', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('contact_id', 26);
            $table->string('address', 320);
            $table->string('label', 20);
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('position');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('contact_id', 'contact_emails_contact_fk')->references('id')->on('contacts');
            $table->unique(['organization_id', 'address'], 'contact_emails_organization_address_unique');
            $table->unique(['contact_id', 'position'], 'contact_emails_contact_position_unique');
            $table->index(['contact_id', 'is_primary'], 'contact_emails_contact_primary_index');
        });
    }
};
