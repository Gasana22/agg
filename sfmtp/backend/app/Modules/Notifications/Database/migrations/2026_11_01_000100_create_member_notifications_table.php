<?php

use App\Support\Database\Schema as Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications for farm members (Phase 11, ADR-0015): the web and
 * the phone read the same inbox; phones also get a push when one is set up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('farm_id')->constrained();
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('kind', 40);                            // task_assigned, task_rejected, sync_conflict …
            $table->string('title', 150);
            $table->string('body', 500)->nullable();
            $table->string('link', 300)->nullable();               // web path, e.g. /farms/…/tasks?task=…
            $table->json('data')->nullable();                      // ids the app opens
            $table->timestamp('read_at')->nullable();
            $table->timestamp('pushed_at')->nullable();
            $table->timestamp('created_at', 6);
            $table->index(['farm_id', 'user_id', 'created_at']);
        });
        Ddl::tenantRls('member_notifications');

        Schema::table('user_devices', function (Blueprint $table) {
            $table->string('push_platform', 10)->nullable()->after('push_token');   // fcm | apns
            $table->timestamp('push_token_at')->nullable()->after('push_platform');
        });
    }

    public function down(): void
    {
        Schema::table('user_devices', fn (Blueprint $t) => $t->dropColumn(['push_platform', 'push_token_at']));
        Schema::dropIfExists('member_notifications');
    }
};
