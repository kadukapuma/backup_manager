<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->boolean('ssh_enabled')->default(false)->after('socket');
            $table->string('ssh_host')->nullable()->after('ssh_enabled');
            $table->unsignedInteger('ssh_port')->default(22)->after('ssh_host');
            $table->string('ssh_user', 100)->nullable()->after('ssh_port');
            $table->text('ssh_private_key')->nullable()->after('ssh_user');
            $table->text('ssh_public_key')->nullable()->after('ssh_private_key');
            $table->text('ssh_host_key')->nullable()->after('ssh_public_key');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['ssh_enabled', 'ssh_host', 'ssh_port', 'ssh_user', 'ssh_private_key', 'ssh_public_key', 'ssh_host_key']);
        });
    }
};
