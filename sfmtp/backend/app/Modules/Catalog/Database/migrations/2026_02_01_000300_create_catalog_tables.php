<?php

use App\Modules\Catalog\Application\Catalogs;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global catalogues (requirements §7): platform-owned reference data that
 * farms pick from. Farms add farm-local entries in later phases.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('global_crops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('scientific_name', 160)->nullable();
            $table->string('category', 30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Ddl::checkIn('global_crops', 'category', Catalogs::CROP_CATEGORIES);

        Schema::create('global_crop_varieties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('crop_id')->constrained('global_crops');
            $table->string('code', 60);
            $table->string('name', 120);
            $table->unsignedSmallInteger('maturity_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['crop_id', 'code']);
        });

        Schema::create('global_animal_species', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('global_animal_breeds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('species_id')->constrained('global_animal_species');
            $table->string('code', 60);
            $table->string('name', 120);
            $table->string('purpose', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['species_id', 'code']);
        });
        Ddl::check('global_animal_breeds', 'global_animal_breeds_purpose_check', "purpose IS NULL OR purpose IN ('".implode("','", Catalogs::BREED_PURPOSES)."')");

        Schema::create('units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->string('dimension', 20);
            $table->decimal('to_base', 20, 8);   // multiply to convert into the dimension's base unit
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Ddl::checkIn('units', 'dimension', Catalogs::UNIT_DIMENSIONS);
        Ddl::check('units', 'units_to_base_check', 'to_base > 0');

        Schema::create('global_inventory_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('kind', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Ddl::checkIn('global_inventory_categories', 'kind', Catalogs::INVENTORY_KINDS);

        Schema::create('global_activity_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name', 120);
            $table->string('module', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Ddl::checkIn('global_activity_types', 'module', Catalogs::ACTIVITY_MODULES);
    }

    public function down(): void
    {
        foreach (['global_activity_types', 'global_inventory_categories', 'units', 'global_animal_breeds', 'global_animal_species', 'global_crop_varieties', 'global_crops'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
