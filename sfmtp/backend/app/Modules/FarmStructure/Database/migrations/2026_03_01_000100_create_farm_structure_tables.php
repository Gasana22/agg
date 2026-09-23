<?php

use App\Modules\FarmStructure\Domain\Enums\Irrigation;
use App\Modules\FarmStructure\Domain\Enums\LandUse;
use App\Modules\FarmStructure\Domain\Enums\LocationKind;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Farm structure: blocks → sections → plots, and point / area locations
 * (docs/03 §3). Boundaries are GeoJSON with derived area, centroid and
 * bounding box columns (ADR-0009). Codes are unique per farm and never
 * reused, archived rows included, so old records stay unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 30);
            $table->string('name', 120);
            $table->text('description')->nullable();
        };
        $geometry = function (Blueprint $table) {
            $table->json('boundary')->nullable();                 // GeoJSON Polygon, WGS 84
            $table->decimal('area_ha', 12, 4)->nullable();        // computed from boundary
            $table->decimal('declared_area_ha', 12, 4)->nullable(); // entered by hand
            $table->decimal('centroid_lat', 10, 7)->nullable();
            $table->decimal('centroid_lng', 10, 7)->nullable();
            $table->decimal('bbox_min_lat', 10, 7)->nullable();
            $table->decimal('bbox_min_lng', 10, 7)->nullable();
            $table->decimal('bbox_max_lat', 10, 7)->nullable();
            $table->decimal('bbox_max_lng', 10, 7)->nullable();
        };
        $tail = function (Blueprint $table, string $name) {
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['farm_id', 'id']);                   // target for composite FKs
            $table->unique(['farm_id', 'code']);
            $table->index(['farm_id', 'deleted_at']);
            $table->index(['farm_id', 'bbox_min_lng', 'bbox_min_lat'], "{$name}_bbox_index");
        };

        Schema::create('farm_blocks', function (Blueprint $table) use ($common, $geometry, $tail) {
            $common($table);
            $geometry($table);
            $tail($table, 'farm_blocks');
        });

        Schema::create('farm_sections', function (Blueprint $table) use ($common, $geometry, $tail) {
            $common($table);
            $table->uuid('block_id');
            $geometry($table);
            $tail($table, 'farm_sections');
            $table->foreign(['farm_id', 'block_id'])->references(['farm_id', 'id'])->on('farm_blocks');
            $table->index(['farm_id', 'block_id']);
        });

        Schema::create('farm_plots', function (Blueprint $table) use ($common, $geometry, $tail) {
            $common($table);
            $table->uuid('section_id')->nullable();               // null: directly under the farm
            $table->string('land_use', 20)->default(LandUse::Crop->value);
            $table->string('irrigation', 20)->default(Irrigation::Rainfed->value);
            $table->json('soil_profile')->nullable();
            $table->timestamp('soil_updated_at')->nullable();
            $table->foreignUuid('soil_updated_by')->nullable()->constrained('users');
            $geometry($table);
            $tail($table, 'farm_plots');
            $table->foreign(['farm_id', 'section_id'])->references(['farm_id', 'id'])->on('farm_sections');
            $table->index(['farm_id', 'section_id']);
        });
        Ddl::checkIn('farm_plots', 'land_use', LandUse::values());
        Ddl::checkIn('farm_plots', 'irrigation', Irrigation::values());

        Schema::create('farm_locations', function (Blueprint $table) use ($common, $geometry, $tail) {
            $common($table);
            $table->string('kind', 20);
            $table->uuid('plot_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $geometry($table);
            $tail($table, 'farm_locations');
            $table->foreign(['farm_id', 'plot_id'])->references(['farm_id', 'id'])->on('farm_plots');
        });
        Ddl::checkIn('farm_locations', 'kind', LocationKind::values());

        foreach (['farm_blocks', 'farm_sections', 'farm_plots', 'farm_locations'] as $table) {
            Ddl::check($table, "{$table}_area_check", '(area_ha IS NULL OR area_ha >= 0) AND (declared_area_ha IS NULL OR declared_area_ha >= 0)');
            Ddl::tenantRls($table);
        }

        // Phase 1 left these plot references unconstrained until plots existed.
        Schema::table('trace_batches', function (Blueprint $table) {
            $table->foreign(['farm_id', 'origin_plot_id'], 'trace_batches_origin_plot_fk')->references(['farm_id', 'id'])->on('farm_plots');
        });
        Schema::table('trace_events', function (Blueprint $table) {
            $table->foreign(['farm_id', 'plot_id'], 'trace_events_plot_fk')->references(['farm_id', 'id'])->on('farm_plots');
        });
    }

    public function down(): void
    {
        Schema::table('trace_events', fn (Blueprint $table) => $table->dropForeign('trace_events_plot_fk'));
        Schema::table('trace_batches', fn (Blueprint $table) => $table->dropForeign('trace_batches_origin_plot_fk'));
        Schema::dropIfExists('farm_locations');
        Schema::dropIfExists('farm_plots');
        Schema::dropIfExists('farm_sections');
        Schema::dropIfExists('farm_blocks');
    }
};
