<?php

use App\Modules\Livestock\Domain\Enums\AnimalStatus;
use App\Modules\Livestock\Domain\Enums\BreedingMethod;
use App\Modules\Livestock\Domain\Enums\BreedingStatus;
use App\Modules\Livestock\Domain\Enums\GroupPurpose;
use App\Modules\Livestock\Domain\Enums\HealthKind;
use App\Modules\Livestock\Domain\Enums\Origin;
use App\Modules\Livestock\Domain\Enums\ProductKind;
use App\Modules\Livestock\Domain\Enums\SaleStatus;
use App\Modules\Livestock\Domain\Enums\Sex;
use App\Modules\Livestock\Domain\Enums\WeightMethod;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livestock (docs/03 §5). Animal records (health, feeding, weights,
 * production, movements) are append-only: a mistake is voided by an
 * `animal_record_voids` row, never edited, so each animal's history stays
 * complete. Every reference is a composite (farm_id, …) foreign key.
 */
return new class extends Migration
{
    private const RECORD_TABLES = ['animal_health_records', 'animal_feedings', 'animal_weights', 'animal_production_records', 'animal_movements'];

    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table, ?string $name = null) => $t
            ->foreign(['farm_id', $column], $name)->references(['farm_id', 'id'])->on($table);
        $in = fn (string $table, string $column, array $values, bool $nullable = false) => Ddl::check($table, "{$table}_{$column}_check",
            ($nullable ? "{$column} IS NULL OR " : '')."{$column} IN ('".implode("','", $values)."')");

        Schema::create('animal_groups', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->foreignUuid('species_id')->constrained('global_animal_species');
            $table->string('purpose', 20)->default(GroupPurpose::Mixed->value);
            $table->uuid('location_id')->nullable();
            $table->unsignedInteger('flock_size')->nullable();     // for flocks kept without individual records
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'location_id', 'farm_locations');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
        });
        $in('animal_groups', 'purpose', GroupPurpose::values());

        Schema::create('animals', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('animal_code', 20);                    // farm-unique, e.g. CAT-007
            $table->string('tag_number', 40)->nullable();
            $table->string('rfid', 40)->nullable();
            $table->string('name', 80)->nullable();
            $table->foreignUuid('species_id')->constrained('global_animal_species');
            $table->foreignUuid('breed_id')->nullable()->constrained('global_animal_breeds');
            $table->string('breed_note', 120)->nullable();         // crossbreds, unlisted breeds
            $table->string('sex', 10);
            $table->date('birth_date')->nullable();
            $table->boolean('birth_date_estimated')->default(false);
            $table->string('origin', 20);
            $table->date('acquired_on')->nullable();
            $table->uuid('dam_id')->nullable();
            $table->uuid('sire_id')->nullable();
            $table->string('parentage_note', 200)->nullable();     // parents off-farm
            $table->uuid('group_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->string('status', 20)->default(AnimalStatus::Active->value);
            $table->date('exited_on')->nullable();
            $table->string('exit_reason', 500)->nullable();
            $table->decimal('last_weight_kg', 10, 2)->nullable();
            $table->date('last_weighed_on')->nullable();
            $table->date('meat_withdrawal_until')->nullable();
            $table->date('milk_withdrawal_until')->nullable();
            $table->uuid('trace_batch_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'animal_code']);
            $table->index(['farm_id', 'status', 'species_id']);
            $table->index(['farm_id', 'group_id']);
            $table->index(['farm_id', 'tag_number']);
            $fk($table, 'group_id', 'animal_groups');
            $fk($table, 'location_id', 'farm_locations');
            $fk($table, 'trace_batch_id', 'trace_batches', 'animals_trace_batch_fk');
        });
        Schema::table('animals', function (Blueprint $table) use ($fk) {
            $fk($table, 'dam_id', 'animals', 'animals_dam_fk');
            $fk($table, 'sire_id', 'animals', 'animals_sire_fk');
        });
        $in('animals', 'sex', Sex::values());
        $in('animals', 'origin', Origin::values());
        $in('animals', 'status', AnimalStatus::values());
        Ddl::check('animals', 'animals_exit_check', "(status = 'active') = (exited_on IS NULL)");

        // Records: about one animal, or a whole group.
        $subject = function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('animal_id')->nullable();
            $table->uuid('group_id')->nullable();
            $fk($table, 'animal_id', 'animals');
            $fk($table, 'group_id', 'animal_groups');
        };
        $tail = function (Blueprint $table) {
            $table->text('notes')->nullable();
            $table->uuid('worker_id')->nullable();                 // FK in Phase 6 (workers)
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
        };

        Schema::create('animal_health_records', function (Blueprint $table) use ($subject, $tail, $fk) {
            $subject($table);
            $table->string('kind', 20);
            $table->date('given_on');
            $table->string('diagnosis', 200)->nullable();
            $table->string('product_name', 150)->nullable();
            $table->decimal('dose', 12, 3)->nullable();
            $table->string('dose_unit', 20)->nullable();
            $table->uuid('input_batch_id')->nullable();
            $table->unsignedSmallInteger('meat_withdrawal_days')->nullable();
            $table->unsignedSmallInteger('milk_withdrawal_days')->nullable();
            $table->date('next_due_on')->nullable();              // booster, next deworming
            $table->string('given_by', 120)->nullable();          // vet or staff name
            $tail($table);
            $fk($table, 'input_batch_id', 'trace_batches', 'animal_health_input_batch_fk');
            $table->foreign('dose_unit')->references('code')->on('units');
            $table->index(['farm_id', 'animal_id', 'given_on']);
            $table->index(['farm_id', 'next_due_on']);
        });
        $in('animal_health_records', 'kind', HealthKind::values());

        Schema::create('animal_feedings', function (Blueprint $table) use ($subject, $tail, $fk) {
            $subject($table);
            $table->date('fed_on');
            $table->string('feed_name', 150);
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 20);
            $table->uuid('input_batch_id')->nullable();
            $tail($table);
            $fk($table, 'input_batch_id', 'trace_batches', 'animal_feedings_input_batch_fk');
            $table->foreign('unit')->references('code')->on('units');
            $table->index(['farm_id', 'fed_on']);
        });

        Schema::create('animal_weights', function (Blueprint $table) use ($subject, $tail) {
            $subject($table);
            $table->date('weighed_on');
            $table->decimal('weight_kg', 10, 2);
            $table->string('method', 10)->default(WeightMethod::Scale->value);
            $tail($table);
            $table->index(['farm_id', 'animal_id', 'weighed_on']);
        });
        $in('animal_weights', 'method', WeightMethod::values());

        Schema::create('animal_production_records', function (Blueprint $table) use ($subject, $tail, $fk) {
            $subject($table);
            $table->string('product', 10);
            $table->date('produced_on');
            $table->string('session', 10)->nullable();            // am / pm / day
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 20);
            $table->boolean('discarded')->default(false);         // e.g. milk under withdrawal
            $table->uuid('trace_batch_id')->nullable();            // the day's product lot
            $tail($table);
            $fk($table, 'trace_batch_id', 'trace_batches', 'animal_production_batch_fk');
            $table->foreign('unit')->references('code')->on('units');
            $table->index(['farm_id', 'product', 'produced_on']);
        });
        $in('animal_production_records', 'product', ProductKind::values());

        Schema::create('animal_movements', function (Blueprint $table) use ($subject, $tail, $fk) {
            $subject($table);
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id')->nullable();
            $table->dateTime('moved_at');
            $table->string('reason', 200)->nullable();
            $tail($table);
            $fk($table, 'from_location_id', 'farm_locations', 'animal_movements_from_fk');
            $fk($table, 'to_location_id', 'farm_locations', 'animal_movements_to_fk');
            $table->index(['farm_id', 'moved_at']);
        });

        foreach (self::RECORD_TABLES as $t) {
            Ddl::check($t, "{$t}_subject_check", 'animal_id IS NOT NULL OR group_id IS NOT NULL');
            Ddl::appendOnly($t);
        }
        Ddl::check('animal_feedings', 'animal_feedings_quantity_check', 'quantity > 0');
        Ddl::check('animal_weights', 'animal_weights_weight_check', 'weight_kg > 0');
        Ddl::check('animal_production_records', 'animal_production_quantity_check', 'quantity >= 0');

        Schema::create('animal_record_voids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('record_type', 40);
            $table->uuid('record_id');
            $table->string('reason', 500);
            $table->foreignUuid('voided_by')->nullable()->constrained('users');
            $table->timestamp('created_at', 6);
            $table->unique(['record_type', 'record_id']);
            $table->index('farm_id');
        });
        Ddl::appendOnly('animal_record_voids');

        Schema::create('animal_breedings', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('dam_id');
            $table->uuid('sire_id')->nullable();
            $table->string('sire_note', 150)->nullable();         // AI straw, borrowed bull
            $table->string('method', 10);
            $table->date('served_on');
            $table->date('expected_due_on')->nullable();
            $table->string('status', 20)->default(BreedingStatus::Served->value);
            $table->date('outcome_on')->nullable();
            $table->unsignedSmallInteger('offspring_count')->nullable();
            $table->text('notes')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'dam_id', 'animals', 'animal_breedings_dam_fk');
            $fk($table, 'sire_id', 'animals', 'animal_breedings_sire_fk');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'status', 'expected_due_on']);
        });
        $in('animal_breedings', 'method', BreedingMethod::values());
        $in('animal_breedings', 'status', BreedingStatus::values());

        Schema::create('animal_sale_requests', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->uuid('animal_id');
            $table->string('reason', 500)->nullable();
            $table->string('buyer', 150)->nullable();
            $table->decimal('expected_price', 16, 2)->nullable();   // money
            $table->decimal('sale_price', 16, 2)->nullable();       // money
            $table->string('status', 20)->default(SaleStatus::Requested->value);
            $table->foreignUuid('requested_by')->nullable()->constrained('users');
            $table->foreignUuid('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->date('sold_on')->nullable();
            $table->string('withdrawal_override_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'animal_id', 'animals');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'status']);
        });
        $in('animal_sale_requests', 'status', SaleStatus::values());

        foreach (['animal_groups', 'animals', ...self::RECORD_TABLES, 'animal_record_voids', 'animal_breedings', 'animal_sale_requests'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        foreach (['animal_sale_requests', 'animal_breedings', 'animal_record_voids', ...array_reverse(self::RECORD_TABLES)] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('animals', function (Blueprint $table) {
            $table->dropForeign('animals_dam_fk');
            $table->dropForeign('animals_sire_fk');
        });
        Schema::dropIfExists('animals');
        Schema::dropIfExists('animal_groups');
    }
};
