<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IoT extension point (ADR-0018): sensors placed on plots or at locations
 * send readings with their own token. Readings are append-only farm data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fk = fn (Blueprint $t, string $column, string $table) => $t->foreign(['farm_id', $column])->references(['farm_id', 'id'])->on($table);

        Schema::create('iot_devices', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->string('code', 20);
            $table->string('name', 100);
            $table->string('kind', 30);
            $table->uuid('plot_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->char('token_hash', 64)->unique();
            $table->string('token_hint', 8);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $fk($table, 'plot_id', 'farm_plots');
            $fk($table, 'location_id', 'farm_locations');
            $table->unique(['farm_id', 'id']);
            $table->unique(['farm_id', 'code']);
        });
        Ddl::checkIn('iot_devices', 'kind', ['weather_station', 'soil_probe', 'water_meter', 'tank_level', 'cold_room', 'other']);

        Schema::create('sensor_readings', function (Blueprint $table) use ($fk) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->uuid('device_id');
            $table->string('metric', 40);
            $table->decimal('value', 14, 4);
            $table->timestamp('recorded_at', 6);
            $table->timestamp('received_at', 6);
            $fk($table, 'device_id', 'iot_devices');
            $table->unique(['farm_id', 'id']);
            $table->index(['farm_id', 'device_id', 'metric', 'recorded_at']);
        });
        Ddl::appendOnly('sensor_readings');

        foreach (['iot_devices', 'sensor_readings'] as $t) {
            Ddl::tenantRls($t);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
        Schema::dropIfExists('iot_devices');
    }
};
