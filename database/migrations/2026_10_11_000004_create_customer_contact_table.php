<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * §3.1 `customer_contact`: a person can belong to more than one customer
     * (the fixture's Francesca Conti sits at a law firm and on a client's board).
     * A business object like every other row so the link carries provenance.
     */
    public function up(): void
    {
        Schema::create('customer_contact', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('customer_id', 26);
            $table->char('contact_id', 26);
            $table->string('role', 200)->nullable();
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('customer_id', 'customer_contact_customer_fk')->references('id')->on('customers');
            $table->foreign('contact_id', 'customer_contact_contact_fk')->references('id')->on('contacts');
            $table->unique(['customer_id', 'contact_id'], 'customer_contact_customer_contact_unique');
            $table->index('contact_id', 'customer_contact_contact_index');
        });
    }
};
