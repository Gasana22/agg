<?php

use Illuminate\Support\Facades\Schedule;

// Nightly tamper check of every farm's traceability hash chain (docs/07 §3).
Schedule::command('trace:verify-chain')->dailyAt('02:00')->onOneServer()->withoutOverlapping();

// Subscription lifecycle: trial/period end → grace → suspended (requirements §35).
Schedule::command('billing:advance-subscriptions')->dailyAt('00:30')->onOneServer()->withoutOverlapping();
