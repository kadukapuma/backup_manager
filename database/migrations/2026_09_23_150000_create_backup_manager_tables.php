<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('driver', 20)->default('mariadb');
            $table->string('host')->default('127.0.0.1');
            $table->unsignedInteger('port')->default(3306);
            $table->string('username');
            $table->text('password')->nullable();
            $table->string('socket')->nullable();
            $table->string('new_database_policy', 20)->default('pending');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->text('last_test_message')->nullable();
            $table->timestamp('last_discovered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('databases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('state', 20)->default('pending');
            $table->string('state_source', 20)->default('policy');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('table_count')->default(0);
            $table->string('default_charset', 64)->nullable();
            $table->string('default_collation', 64)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('missing_since')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'name']);
            $table->index('state');
        });

        Schema::create('selection_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('pattern', 64);
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['connection_id', 'priority']);
        });

        Schema::create('destinations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 20);
            $table->text('config');
            $table->string('base_path')->default('');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status', 20)->nullable();
            $table->text('last_test_message')->nullable();
            $table->unsignedBigInteger('free_space_bytes')->nullable();
            $table->unsignedBigInteger('used_bytes')->nullable();
            $table->timestamps();
        });

        Schema::create('backup_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('connection_id')->constrained('connections')->cascadeOnDelete();
            $table->string('cron_expression', 100);
            $table->string('timezone', 64)->default('Asia/Colombo');
            $table->string('compression', 20)->default('zstd');
            $table->boolean('encrypt')->default(true);
            $table->json('retention');
            $table->boolean('all_included_databases')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('backup_plan_database', function (Blueprint $table) {
            $table->foreignId('backup_plan_id')->constrained('backup_plans')->cascadeOnDelete();
            $table->foreignId('database_id')->constrained('databases')->cascadeOnDelete();
            $table->primary(['backup_plan_id', 'database_id']);
        });

        Schema::create('backup_plan_destination', function (Blueprint $table) {
            $table->foreignId('backup_plan_id')->constrained('backup_plans')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('destinations')->cascadeOnDelete();
            $table->primary(['backup_plan_id', 'destination_id']);
        });

        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_plan_id')->nullable()->constrained('backup_plans')->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('connections')->nullOnDelete();
            $table->string('trigger', 20);
            $table->string('status', 20)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('backup_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_run_id')->constrained('backup_runs')->cascadeOnDelete();
            $table->foreignId('database_id')->constrained('databases')->restrictOnDelete();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->string('md5', 32)->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('status', 20)->default('queued');
            $table->text('error')->nullable();
            $table->json('manifest')->nullable();
            $table->text('log')->nullable();
            $table->timestamps();

            $table->index(['database_id', 'status', 'created_at']);
            $table->index('sha256');
        });

        Schema::create('backup_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_file_id')->constrained('backup_files')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('destinations')->restrictOnDelete();
            $table->string('remote_path');
            $table->string('status', 20)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['backup_file_id', 'destination_id']);
            $table->index(['destination_id', 'status']);
        });

        Schema::create('restore_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_file_id')->constrained('backup_files')->restrictOnDelete();
            $table->foreignId('source_destination_id')->nullable()->constrained('destinations')->nullOnDelete();
            $table->foreignId('target_connection_id')->constrained('connections')->restrictOnDelete();
            $table->string('target_database', 64);
            $table->string('mode', 20);
            $table->foreignId('safety_backup_file_id')->nullable()->constrained('backup_files')->nullOnDelete();
            $table->string('status', 20)->default('queued');
            $table->string('progress_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error')->nullable();
            $table->json('post_check')->nullable();
            $table->text('age_identity')->nullable();
            $table->text('log')->nullable();
            $table->timestamps();
        });

        Schema::create('verification_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_file_id')->constrained('backup_files')->cascadeOnDelete();
            $table->foreignId('backup_copy_id')->nullable()->constrained('backup_copies')->cascadeOnDelete();
            $table->string('level', 20);
            $table->string('status', 20);
            $table->json('details')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->text('config');
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64);
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['action', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'settings', 'audit_logs', 'notification_channels', 'verification_runs', 'restore_jobs',
            'backup_copies', 'backup_files', 'backup_runs', 'backup_plan_destination', 'backup_plan_database',
            'backup_plans', 'destinations', 'selection_rules', 'databases', 'connections',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
