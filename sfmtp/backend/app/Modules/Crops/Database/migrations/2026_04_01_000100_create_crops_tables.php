<?php

use App\Modules\Crops\Domain\Enums\CloseReason;
use App\Modules\Crops\Domain\Enums\CycleStage;
use App\Modules\Crops\Domain\Enums\ObservationKind;
use App\Modules\Crops\Domain\Enums\ObservationStatus;
use App\Modules\Crops\Domain\Enums\OperationStatus;
use App\Modules\Crops\Domain\Enums\OperationType;
use App\Modules\Crops\Domain\Enums\PlanStatus;
use App\Modules\Crops\Domain\Enums\PlantingMethod;
use App\Modules\Crops\Domain\Enums\Severity;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crop management (docs/03 §4). Every reference between crop tables, and to
 * plots and trace batches, is a composite (farm_id, …) foreign key.
 * Harvests are append-only: a wrong harvest is corrected in traceability.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);

        // The farm's own crop list, usually picked from the global catalogue.
        Schema::create('crops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->foreignUuid('global_crop_id')->nullable()->constrained('global_crops');
            $table->foreignUuid('global_variety_id')->nullable()->constrained('global_crop_varieties');
            $table->string('name', 120);
            $table->string('variety', 120)->nullable();
            $table->unsignedSmallInteger('maturity_days')->nullable();
            $table->string('yield_unit', 20)->default('kg');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->foreign('yield_unit')->references('code')->on('units');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'name']);
        });

        Schema::create('crop_seasons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('name', 80);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'name']);
        });
        Ddl::check('crop_seasons', 'crop_seasons_dates_check', 'ends_on >= starts_on');

        Schema::create('crop_plans', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->uuid('season_id');
            $table->uuid('crop_id');
            $table->decimal('planned_area_ha', 12, 4);
            $table->decimal('expected_yield', 14, 3)->nullable();
            $table->string('yield_unit', 20)->default('kg');
            $table->decimal('budget_amount', 16, 2)->nullable();    // farm currency; money
            $table->string('status', 20)->default(PlanStatus::Draft->value);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->foreignUuid('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'season_id', 'crop_seasons');
            $fk($table, 'crop_id', 'crops');
            $table->foreign('yield_unit')->references('code')->on('units');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
        });
        Ddl::checkIn('crop_plans', 'status', PlanStatus::values());
        Ddl::check('crop_plans', 'crop_plans_numbers_check', 'planned_area_ha > 0 AND (expected_yield IS NULL OR expected_yield >= 0) AND (budget_amount IS NULL OR budget_amount >= 0)');

        Schema::create('crop_cycles', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->uuid('plan_id')->nullable();
            $table->uuid('season_id')->nullable();
            $table->uuid('plot_id');
            $table->uuid('crop_id');
            $table->string('planting_method', 20)->default(PlantingMethod::Direct->value);
            $table->string('stage', 20);
            $table->decimal('area_ha', 12, 4);
            $table->date('sown_on')->nullable();                   // nursery sowing
            $table->date('planted_on')->nullable();                // in the field
            $table->date('expected_harvest_on')->nullable();
            $table->decimal('expected_yield', 14, 3)->nullable();
            $table->string('yield_unit', 20)->default('kg');
            $table->unsignedInteger('seeds_sown')->nullable();
            $table->unsignedInteger('seedlings_germinated')->nullable();
            $table->unsignedInteger('seedlings_transplanted')->nullable();
            $table->date('safe_harvest_on')->nullable();           // end of the latest withholding period
            $table->uuid('seed_batch_id')->nullable();
            $table->uuid('nursery_batch_id')->nullable();
            $table->uuid('crop_lot_batch_id')->nullable();
            $table->date('closed_on')->nullable();
            $table->string('close_reason', 20)->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'plan_id', 'crop_plans');
            $fk($table, 'season_id', 'crop_seasons');
            $fk($table, 'plot_id', 'farm_plots');
            $fk($table, 'crop_id', 'crops');
            $fk($table, 'seed_batch_id', 'trace_batches', 'crop_cycles_seed_batch_fk');
            $fk($table, 'nursery_batch_id', 'trace_batches', 'crop_cycles_nursery_batch_fk');
            $fk($table, 'crop_lot_batch_id', 'trace_batches', 'crop_cycles_crop_lot_batch_fk');
            $table->foreign('yield_unit')->references('code')->on('units');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'stage']);
            $table->index(['farm_id', 'plot_id', 'stage']);
            $table->index(['farm_id', 'expected_harvest_on']);
        });
        Ddl::checkIn('crop_cycles', 'stage', CycleStage::values());
        Ddl::checkIn('crop_cycles', 'planting_method', PlantingMethod::values());
        Ddl::check('crop_cycles', 'crop_cycles_close_reason_check', "close_reason IS NULL OR close_reason IN ('".implode("','", CloseReason::values())."')");
        Ddl::check('crop_cycles', 'crop_cycles_area_check', 'area_ha > 0');
        Ddl::check('crop_cycles', 'crop_cycles_closed_check', "(stage = 'closed') = (closed_on IS NOT NULL)");

        Schema::create('crop_observations', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('cycle_id');
            $table->string('kind', 20);
            $table->string('severity', 10);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->decimal('affected_pct', 5, 2)->nullable();
            $table->dateTime('observed_at');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 20)->default(ObservationStatus::Open->value);
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'cycle_id', 'crop_cycles');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'status', 'kind']);
            $table->index(['farm_id', 'cycle_id', 'observed_at']);
        });
        Ddl::checkIn('crop_observations', 'kind', ObservationKind::values());
        Ddl::checkIn('crop_observations', 'severity', Severity::values());
        Ddl::checkIn('crop_observations', 'status', ObservationStatus::values());
        Ddl::check('crop_observations', 'crop_observations_affected_check', 'affected_pct IS NULL OR (affected_pct >= 0 AND affected_pct <= 100)');

        Schema::create('crop_operations', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('cycle_id');
            $table->uuid('observation_id')->nullable();            // the problem this operation treats
            $table->string('type', 30);
            $table->dateTime('occurred_at');
            $table->string('status', 20);
            $table->text('notes')->nullable();
            $table->decimal('labour_hours', 8, 2)->nullable();
            $table->decimal('cost_amount', 16, 2)->nullable();     // money
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->uuid('worker_id')->nullable();                 // FK in Phase 6 (workers)
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->foreignUuid('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'cycle_id', 'crop_cycles');
            $fk($table, 'observation_id', 'crop_observations');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'cycle_id', 'occurred_at']);
            $table->index(['farm_id', 'status']);
        });
        Ddl::checkIn('crop_operations', 'type', OperationType::values());
        Ddl::checkIn('crop_operations', 'status', OperationStatus::values());
        Ddl::check('crop_operations', 'crop_operations_numbers_check', '(labour_hours IS NULL OR labour_hours >= 0) AND (cost_amount IS NULL OR cost_amount >= 0)');

        Schema::create('crop_operation_inputs', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->uuid('farm_id');
            $table->uuid('operation_id');
            $table->uuid('input_batch_id')->nullable();            // the input lot, when known
            $table->string('product_name', 150);                   // until inventory items exist (Phase 7)
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 20);
            $table->unsignedSmallInteger('withholding_days')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->foreign(['farm_id', 'operation_id'])->references(['farm_id', 'id'])->on('crop_operations')->cascadeOnDelete();
            $fk($table, 'input_batch_id', 'trace_batches', 'crop_operation_inputs_batch_fk');
            $table->foreign('farm_id')->references('id')->on('farms');
            $table->foreign('unit')->references('code')->on('units');
            $table->index(['farm_id', 'operation_id']);
        });
        Ddl::check('crop_operation_inputs', 'crop_operation_inputs_quantity_check', 'quantity > 0');

        Schema::create('crop_harvests', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('cycle_id');
            $table->date('harvested_on');
            $table->decimal('quantity', 14, 3);
            $table->string('unit', 20);
            $table->string('quality_grade', 20)->nullable();
            $table->decimal('moisture_pct', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('trace_batch_id');
            $table->string('withholding_override_reason', 500)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $fk($table, 'cycle_id', 'crop_cycles');
            $fk($table, 'trace_batch_id', 'trace_batches', 'crop_harvests_batch_fk');
            $table->foreign('unit')->references('code')->on('units');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'cycle_id']);
            $table->index(['farm_id', 'harvested_on']);
        });
        Ddl::check('crop_harvests', 'crop_harvests_quantity_check', 'quantity > 0 AND (moisture_pct IS NULL OR (moisture_pct >= 0 AND moisture_pct <= 100))');
        Ddl::appendOnly('crop_harvests');

        foreach (['crops', 'crop_seasons', 'crop_plans', 'crop_cycles', 'crop_observations', 'crop_operations', 'crop_operation_inputs', 'crop_harvests'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        foreach (['crop_harvests', 'crop_operation_inputs', 'crop_operations', 'crop_observations', 'crop_cycles', 'crop_plans', 'crop_seasons', 'crops'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
