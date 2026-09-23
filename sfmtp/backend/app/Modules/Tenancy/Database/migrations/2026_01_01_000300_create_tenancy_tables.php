<?php

use App\Modules\Tenancy\Domain\Enums\FarmStatus;
use App\Modules\Tenancy\Domain\Enums\MembershipStatus;
use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('owner_user_id')->constrained('users');
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->index('owner_user_id');
        });
        Ddl::checkIn('organizations', 'status', ['active', 'suspended', 'closed']);

        Schema::create('farms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('status', 20)->default(FarmStatus::Pending->value);
            $table->string('district', 120)->nullable();
            $table->string('village', 120)->nullable();
            $table->char('country', 2)->default('UG');
            $table->decimal('size_ha', 12, 4)->nullable();
            $table->string('timezone', 64)->default('Africa/Kampala');
            $table->char('currency', 3)->default('UGX');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });
        Ddl::checkIn('farms', 'status', FarmStatus::values());
        Ddl::check('farms', 'farms_size_ha_check', 'size_ha IS NULL OR size_ha >= 0');

        Schema::create('farm_settings', function (Blueprint $table) {
            $table->foreignUuid('farm_id')->primary()->constrained()->cascadeOnDelete();
            $table->json('settings');
            $table->timestamps();
        });

        Schema::create('farm_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->foreignUuid('user_id')->constrained();
            $table->string('status', 20)->default(MembershipStatus::Active->value);
            $table->boolean('is_owner')->default(false);
            $table->foreignUuid('invited_by')->nullable()->constrained('users');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['farm_id', 'user_id']);
            $table->unique(['farm_id', 'id']);   // target for composite FKs
            $table->index(['user_id', 'status']);
        });
        Ddl::checkIn('farm_users', 'status', MembershipStatus::values());

        // Exactly one owner per farm.
        if (Ddl::isPgsql()) {
            DB::statement('CREATE UNIQUE INDEX farm_users_one_owner ON farm_users (farm_id) WHERE is_owner');
        } elseif (Ddl::isMysql()) {
            DB::statement('ALTER TABLE farm_users ADD COLUMN owner_marker TINYINT AS (IF(is_owner, 1, NULL)) STORED');
            DB::statement('CREATE UNIQUE INDEX farm_users_one_owner ON farm_users (farm_id, owner_marker)');
        }

        // Only `member` users can join farms: never platform admins or portal
        // parties (docs/02-tenant-isolation.md §5).
        $message = 'SFMTP_MEMBER_ONLY: only member users can belong to a farm';
        if (Ddl::isPgsql()) {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION sfmtp_farm_users_member_only() RETURNS trigger AS \$\$
                BEGIN
                    IF (SELECT user_type FROM users WHERE id = NEW.user_id) <> 'member' THEN
                        RAISE EXCEPTION '{$message}' USING ERRCODE = 'P0001';
                    END IF;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER farm_users_member_only BEFORE INSERT OR UPDATE ON farm_users
                    FOR EACH ROW EXECUTE FUNCTION sfmtp_farm_users_member_only();
            SQL);
        } elseif (Ddl::isMysql()) {
            foreach (['INSERT', 'UPDATE'] as $op) {
                $name = 'farm_users_member_only_'.strtolower($op);
                DB::unprepared(<<<SQL
                    CREATE TRIGGER {$name} BEFORE {$op} ON farm_users FOR EACH ROW
                    BEGIN
                        IF (SELECT user_type FROM users WHERE id = NEW.user_id) <> 'member' THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}';
                        END IF;
                    END
                SQL);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('farm_users');
        Schema::dropIfExists('farm_settings');
        Schema::dropIfExists('farms');
        Schema::dropIfExists('organizations');
    }
};
