<?php

use App\Support\Database\BusinessObjectColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * user <-> organization with a role (owner, admin, member). The health
     * check `auth_login` counts an owner membership as "owner account exists".
     */
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table): void {
            BusinessObjectColumns::ulid($table);
            BusinessObjectColumns::organization($table);
            $table->char('user_id', 26);
            $table->string('role', 20);
            BusinessObjectColumns::lineage($table);
            BusinessObjectColumns::timestamps($table);

            $table->foreign('user_id', 'memberships_user_fk')->references('id')->on('users');
            $table->unique(['organization_id', 'user_id'], 'memberships_organization_user_unique');
            $table->index(['user_id', 'role'], 'memberships_user_role_index');
            $table->index(['organization_id', 'role'], 'memberships_organization_role_index');
        });
    }
};
