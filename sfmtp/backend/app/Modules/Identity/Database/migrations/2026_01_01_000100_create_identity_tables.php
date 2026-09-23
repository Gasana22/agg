<?php

use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\Enums\UserType;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_type', 20);
            $table->string('name');
            $table->string('email')->unique();          // stored lower-cased by the model
            $table->string('phone', 32)->nullable()->unique();
            $table->string('password');
            $table->string('status', 20)->default(UserStatus::Active->value);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('mfa_enabled_at')->nullable();
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
        Ddl::checkIn('users', 'user_type', UserType::values());
        Ddl::checkIn('users', 'status', UserStatus::values());

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_mfa_factors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10);
            $table->text('secret');                      // encrypted at rest (model cast)
            $table->unsignedBigInteger('last_used_timestep')->nullable(); // TOTP replay protection
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'type']);
        });
        Ddl::checkIn('user_mfa_factors', 'type', ['totp']);

        Schema::create('mfa_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['user_id', 'code_hash']);
        });

        Schema::create('user_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('client', 10);                // web | mobile
            $table->string('name', 120)->nullable();
            $table->string('platform', 20)->nullable();  // android | ios | web
            $table->text('push_token')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });
        Ddl::checkIn('user_devices', 'client', ['web', 'mobile']);

        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained('user_devices')->nullOnDelete();
            $table->uuid('family_id');                    // one login session; revoked as a unit
            $table->string('token_hash', 64)->unique();
            $table->string('client', 10);
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('replaced_by')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['family_id', 'revoked_at']);
            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('user_devices');
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('user_mfa_factors');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
