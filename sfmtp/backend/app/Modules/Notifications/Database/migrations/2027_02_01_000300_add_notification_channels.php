<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which copies of important notices a person wants beside the inbox and
 * push (ADR-0018): {"email": true, "sms": false}. Null means the defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_channels')->nullable();
        });
        Schema::table('member_notifications', function (Blueprint $table) {
            $table->timestamp('copied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('member_notifications', fn (Blueprint $table) => $table->dropColumn('copied_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('notification_channels'));
    }
};
