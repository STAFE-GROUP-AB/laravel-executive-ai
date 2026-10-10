<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            $table->string('name', 200);
            $table->string('slug', 100)->unique('organizations_slug_unique');
            $table->string('timezone', 64)->default('UTC');
            $table->string('language', 35)->default('en');
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);
        });
    }
};
