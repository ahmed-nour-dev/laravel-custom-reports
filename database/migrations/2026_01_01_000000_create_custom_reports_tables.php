<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every table of the custom report engine: report types and their layouts,
 * folders, saved reports, background runs, dashboard pins and schedules.
 *
 * Users are referenced in `custom-reports.users_table`. Folder shares and
 * schedules store audience group ids (report_folder_shares.role_id,
 * report_subscriptions.role_ids) without a foreign key: the groups are the
 * app's (ReportAudience).
 */
return new class extends Migration
{
    public function up(): void
    {
        $users = (string) config('custom-reports.users_table', 'users');

        Schema::create('report_types', function (Blueprint $table) use ($users) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->boolean('auto_add_fields')->default(false);
            $table->json('known_fields')->nullable();
            $table->string('status')->default('development')->index();
            $table->string('primary_source');
            $table->foreignId('created_by')->nullable()->constrained($users)->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamp('deployed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // The A → B → C → D chain; A (level 0) is report_types.primary_source.
        Schema::create('report_type_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('level');
            $table->string('source');
            $table->string('parent_link');
            $table->string('join_type');
            $table->timestamps();
            $table->unique(['report_type_id', 'level']);
        });

        Schema::create('report_type_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_type_id')->constrained()->cascadeOnDelete();
            $table->string('name_en');
            $table->string('name_ar')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('report_type_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_type_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->unsignedTinyInteger('object_level');
            $table->string('lookup_path')->nullable();
            $table->string('field');
            $table->string('label_en')->nullable();
            $table->string('label_ar')->nullable();
            $table->foreignId('section_id')->nullable()->constrained('report_type_sections')->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_repeated')->default(false);
            $table->timestamps();
            $table->unique(['report_type_id', 'key']);
        });

        Schema::create('report_folders', function (Blueprint $table) use ($users) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->string('name');
            $table->foreignId('owner_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('report_folder_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_folder_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('role_id')->index(); // an audience group id (ReportAudience)
            $table->string('access');
            $table->timestamps();
            $table->unique(['report_folder_id', 'role_id']);
        });

        Schema::create('reports', function (Blueprint $table) use ($users) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->foreignId('report_type_id')->constrained();
            // NULL = private to the owner.
            $table->foreignId('report_folder_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('format')->default('tabular');
            $table->json('definition');
            $table->foreignId('owner_id')->nullable()->constrained($users)->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_subscriptions', function (Blueprint $table) use ($users) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->foreignId('report_id')->unique()->constrained('reports')->cascadeOnDelete();
            $table->foreignId('owner_id')->nullable()->constrained($users)->nullOnDelete();
            $table->string('frequency', 20);
            $table->unsignedTinyInteger('day')->nullable();
            $table->string('time', 5);
            $table->json('role_ids'); // audience group ids (ReportAudience)
            $table->json('user_ids');
            $table->timestamp('next_run_at')->nullable()->index();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_runs', function (Blueprint $table) use ($users) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->foreignId('report_id')->nullable()->constrained('reports')->nullOnDelete();
            $table->foreignId('report_type_id')->constrained('report_types')->cascadeOnDelete();
            $table->foreignId('report_subscription_id')->nullable()->constrained('report_subscriptions')->nullOnDelete();
            $table->foreignId('viewer_id')->constrained($users)->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('format', 20);
            $table->json('definition');
            $table->char('definition_hash', 64);
            $table->string('status', 20)->default('queued')->index();
            $table->string('result_path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->boolean('truncated')->default(false);
            $table->string('error_slug', 100)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->index(['report_id', 'viewer_id', 'kind', 'definition_hash'], 'report_runs_reuse_index');
        });

        Schema::create('report_pins', function (Blueprint $table) use ($users) {
            $table->id();
            $table->foreignId('user_id')->constrained($users)->cascadeOnDelete();
            $table->foreignId('report_id')->constrained('reports')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'report_id']);
        });
    }

    public function down(): void
    {
        foreach (['report_pins', 'report_runs', 'report_subscriptions', 'reports', 'report_folder_shares', 'report_folders',
            'report_type_fields', 'report_type_sections', 'report_type_objects', 'report_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
