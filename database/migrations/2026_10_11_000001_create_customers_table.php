<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers with a legal identity and an owner (blueprint §3.2). `email_domains`
     * is deliberately NOT unique: several customers may share one domain (§5.6), so
     * matching by domain alone is a weak reason the matcher must say so about.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->string('legal_name', 200);
            $table->string('display_name', 200);
            $table->char('country', 2);
            $table->string('registration_number', 50)->nullable();
            $table->json('email_domains');
            $table->string('website', 2000)->nullable();
            $table->string('status', 20);
            $table->char('owner_user_id', 26)->nullable();
            $table->json('tags');
            $table->text('notes');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('owner_user_id', 'customers_owner_user_fk')->references('id')->on('users');
            $table->index(['organization_id', 'status'], 'customers_organization_status_index');
            $table->index(['organization_id', 'display_name'], 'customers_organization_display_name_index');
            $table->index(['organization_id', 'country', 'registration_number'], 'customers_organization_registration_index');
        });
    }
};
