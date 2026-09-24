<?php

use App\Modules\Workforce\Domain\Enums\ActivityStatus;
use App\Modules\Workforce\Domain\Enums\AttendanceSource;
use App\Modules\Workforce\Domain\Enums\EmploymentType;
use App\Modules\Workforce\Domain\Enums\LeaveKind;
use App\Modules\Workforce\Domain\Enums\LeaveStatus;
use App\Modules\Workforce\Domain\Enums\Priority;
use App\Modules\Workforce\Domain\Enums\SubjectType;
use App\Modules\Workforce\Domain\Enums\TaskEvent;
use App\Modules\Workforce\Domain\Enums\TaskStatus;
use App\Modules\Workforce\Domain\Enums\WorkerStatus;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workers and activities (docs/03 §6).
 *
 * An activity is a piece of work on a subject (a crop cycle, a plot, an
 * animal or group, a location, or general work); a task assigns it to one
 * worker. Task logs, GPS points and task photos are append-only, so field
 * data synced from phones never conflicts (docs/08 §4). Coordinates are
 * plain latitude / longitude columns: points are only stored and listed,
 * never queried spatially, and the columns work on both engines.
 *
 * Crop operations, animal records and trace events already carried a
 * `worker_id`; they now get their foreign keys, and the operational records
 * the activity they were done under.
 */
return new class extends Migration
{
    private const WORKER_LINKED = ['crop_operations', 'animal_health_records', 'animal_feedings', 'animal_weights', 'animal_production_records', 'animal_movements'];

    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);
        $in = fn (string $table, string $column, array $values, bool $nullable = false) => Ddl::check($table, "{$table}_{$column}_check",
            ($nullable ? "{$column} IS NULL OR " : '')."{$column} IN ('".implode("','", $values)."')");
        $point = function (Blueprint $table, string $prefix = '') {
            $table->decimal("{$prefix}lat", 9, 6)->nullable();
            $table->decimal("{$prefix}lng", 9, 6)->nullable();
            $table->decimal("{$prefix}accuracy_m", 8, 2)->nullable();
        };
        $pointCheck = fn (string $table, string $prefix = '') => Ddl::check($table, "{$table}_{$prefix}point_check",
            "({$prefix}lat IS NULL) = ({$prefix}lng IS NULL) AND ({$prefix}lat IS NULL OR ({$prefix}lat BETWEEN -90 AND 90 AND {$prefix}lng BETWEEN -180 AND 180))");

        Schema::create('workers', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('worker_code', 20);                     // WRK-001
            $table->uuid('farm_user_id')->nullable();              // not every worker signs in
            $table->string('full_name', 150);
            $table->string('phone', 30)->nullable();
            $table->string('national_id', 40)->nullable();
            $table->string('job_title', 80)->nullable();
            $table->string('employment_type', 20);
            $table->decimal('daily_rate', 12, 2)->nullable();      // money
            $table->date('started_on')->nullable();
            $table->date('left_on')->nullable();
            $table->string('status', 20)->default(WorkerStatus::Active->value);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'farm_user_id', 'farm_users');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'worker_code']);
            $table->unique(['farm_id', 'farm_user_id']);
            $table->index(['farm_id', 'status']);
        });
        $in('workers', 'employment_type', EmploymentType::values());
        $in('workers', 'status', WorkerStatus::values());
        Ddl::check('workers', 'workers_rate_check', 'daily_rate IS NULL OR daily_rate >= 0');

        Schema::create('activities', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                            // ACT-001
            $table->foreignUuid('activity_type_id')->constrained('global_activity_types');
            $table->string('module', 20);                          // from the type; decides who sees it
            $table->string('title', 150);
            $table->text('instructions')->nullable();
            $table->string('subject_type', 20);
            $table->uuid('subject_id')->nullable();
            $table->string('subject_label', 150)->nullable();      // kept for lists and offline phones
            $table->uuid('plot_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->date('planned_on');
            $table->date('due_on')->nullable();
            $table->string('priority', 10)->default(Priority::Normal->value);
            $table->decimal('target_quantity', 12, 3)->nullable();
            $table->string('target_unit', 20)->nullable();
            $table->string('status', 20)->default(ActivityStatus::Open->value);
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'plot_id', 'farm_plots');
            $fk($table, 'location_id', 'farm_locations');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status', 'planned_on']);
            $table->index(['farm_id', 'subject_type', 'subject_id']);
        });
        $in('activities', 'subject_type', SubjectType::values());
        $in('activities', 'priority', Priority::values());
        $in('activities', 'status', ActivityStatus::values());
        Ddl::check('activities', 'activities_subject_check', "(subject_type = 'general') = (subject_id IS NULL)");
        Ddl::check('activities', 'activities_dates_check', 'due_on IS NULL OR due_on >= planned_on');

        Schema::create('worker_tasks', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);                            // TSK-001
            $table->uuid('activity_id');
            $table->uuid('worker_id');
            $table->foreignUuid('assigned_by')->nullable()->constrained('users');
            $table->date('due_on')->nullable();
            $table->string('status', 20)->default(TaskStatus::Assigned->value);
            $table->timestamp('started_at', 6)->nullable();
            $table->timestamp('submitted_at', 6)->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->decimal('quantity', 12, 3)->nullable();        // work done, e.g. rows weeded
            $table->string('unit', 20)->nullable();
            $table->text('submit_note')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->string('review_note', 500)->nullable();        // verification note or rejection reason
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'activity_id', 'activities');
            $fk($table, 'worker_id', 'workers');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'worker_id', 'status']);
            $table->index(['farm_id', 'status', 'due_on']);
        });
        $in('worker_tasks', 'status', TaskStatus::values());

        Schema::create('worker_task_logs', function (Blueprint $table) use ($fk, $point) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('task_id');
            $table->string('event', 20);
            $table->string('from_status', 20);
            $table->string('to_status', 20)->nullable();
            $table->boolean('applied')->default(true);             // false: kept as evidence of a refused offline action
            $table->timestamp('occurred_at', 6);                   // device time for offline actions
            $point($table);
            $table->decimal('quantity', 12, 3)->nullable();
            $table->string('unit', 20)->nullable();
            $table->text('note')->nullable();
            $table->uuid('device_id')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $fk($table, 'task_id', 'worker_tasks');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'task_id', 'occurred_at']);
        });
        $in('worker_task_logs', 'event', TaskEvent::values());
        $pointCheck('worker_task_logs');

        Schema::create('worker_task_photos', function (Blueprint $table) use ($fk, $point) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('task_id');
            $table->uuid('media_id');
            $table->timestamp('taken_at', 6)->nullable();
            $point($table);
            $table->string('caption', 200)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $fk($table, 'task_id', 'worker_tasks');
            $fk($table, 'media_id', 'media');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'task_id', 'media_id']);
        });
        $pointCheck('worker_task_photos');

        Schema::create('worker_attendance', function (Blueprint $table) use ($fk, $point) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('worker_id');
            $table->date('work_date');
            $table->timestamp('check_in_at', 6);
            $point($table, 'check_in_');
            $table->uuid('check_in_photo_id')->nullable();
            $table->timestamp('check_out_at', 6)->nullable();
            $point($table, 'check_out_');
            $table->uuid('check_out_photo_id')->nullable();
            $table->string('source', 10)->default(AttendanceSource::Mobile->value);
            $table->string('note', 500)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'worker_id', 'workers');
            $fk($table, 'check_in_photo_id', 'media', 'worker_attendance_in_photo_fk');
            $fk($table, 'check_out_photo_id', 'media', 'worker_attendance_out_photo_fk');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'worker_id', 'work_date']);
            $table->index(['farm_id', 'work_date']);
        });
        $in('worker_attendance', 'source', AttendanceSource::values());
        $pointCheck('worker_attendance', 'check_in_');
        $pointCheck('worker_attendance', 'check_out_');
        Ddl::check('worker_attendance', 'worker_attendance_times_check', 'check_out_at IS NULL OR check_out_at >= check_in_at');

        Schema::create('worker_gps_points', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('worker_id');
            $table->uuid('task_id')->nullable();
            $table->timestamp('recorded_at', 6);
            $table->decimal('lat', 9, 6);
            $table->decimal('lng', 9, 6);
            $table->decimal('accuracy_m', 8, 2)->nullable();
            $table->uuid('device_id')->nullable();
            $table->timestamp('created_at', 6);
            $fk($table, 'worker_id', 'workers');
            $fk($table, 'task_id', 'worker_tasks');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'worker_id', 'recorded_at']);
        });
        Ddl::check('worker_gps_points', 'worker_gps_points_point_check', 'lat BETWEEN -90 AND 90 AND lng BETWEEN -180 AND 180');

        Schema::create('worker_leave', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('worker_id');
            $table->string('kind', 20);
            $table->date('from_on');
            $table->date('to_on');
            $table->string('reason', 500)->nullable();
            $table->string('status', 20)->default(LeaveStatus::Requested->value);
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'worker_id', 'workers');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'status', 'from_on']);
            $table->index(['farm_id', 'worker_id', 'from_on']);
        });
        $in('worker_leave', 'kind', LeaveKind::values());
        $in('worker_leave', 'status', LeaveStatus::values());
        Ddl::check('worker_leave', 'worker_leave_dates_check', 'to_on >= from_on');

        foreach (self::WORKER_LINKED as $t) {
            Schema::table($t, function (Blueprint $table) use ($t, $fk) {
                $table->uuid('activity_id')->nullable()->after('worker_id');
                $fk($table, 'worker_id', 'workers', "{$t}_worker_fk");
                $fk($table, 'activity_id', 'activities', "{$t}_activity_fk");
            });
        }

        Schema::table('trace_events', function (Blueprint $table) use ($fk) {
            $fk($table, 'worker_id', 'workers', 'trace_events_worker_fk');
        });

        foreach (['worker_task_logs', 'worker_task_photos', 'worker_gps_points'] as $t) {
            Ddl::appendOnly($t);
        }
        foreach (['workers', 'activities', 'worker_tasks', 'worker_task_logs', 'worker_task_photos', 'worker_attendance', 'worker_gps_points', 'worker_leave'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        Schema::table('trace_events', fn (Blueprint $table) => $table->dropForeign('trace_events_worker_fk'));
        foreach (self::WORKER_LINKED as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                $table->dropForeign("{$t}_worker_fk");
                $table->dropForeign("{$t}_activity_fk");
                $table->dropColumn('activity_id');
            });
        }
        foreach (['worker_leave', 'worker_gps_points', 'worker_attendance', 'worker_task_photos', 'worker_task_logs', 'worker_tasks', 'activities', 'workers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
