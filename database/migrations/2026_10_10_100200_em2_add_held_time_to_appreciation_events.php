<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * EM2 (lead, 2026-10-10; D-123): an appreciation event has a start time. The notice is the only place a
     * student or a guru pembimbing learns when to show up, and it said the date alone.
     *
     * `held_time` = the time of day the event starts (app timezone, WIB), written as 'HH:MM:00' by
     * CreateAppreciationEvent and read through AppreciationEvent::heldTime(). A separate column next to `held_on`,
     * not one datetime: every rule about an event is day-level (D-87) and keeps reading `held_on`.
     *
     * No backfill: there is nothing to derive a time from. NULL = made before events had a time; such an event
     * prints its date only.
     */
    public function up(): void
    {
        Schema::table('appreciation_events', function (Blueprint $table) {
            $table->time('held_time')->nullable()->after('held_on');
        });
    }

    public function down(): void
    {
        Schema::table('appreciation_events', function (Blueprint $table) {
            $table->dropColumn('held_time');
        });
    }
};
