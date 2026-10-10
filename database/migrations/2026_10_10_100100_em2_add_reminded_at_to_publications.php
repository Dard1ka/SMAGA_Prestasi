<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * EM2 (lead, 2026-10-10; D-122): the schedule of a publication is only a reminder for Humas. The scheduler no
     * longer sets `dipublikasikan`; it mails Humas once when `scheduled_at` arrives and writes that moment here.
     * `reminded_at` = when the reminder for the current `scheduled_at` went out, null = not reminded yet.
     * Set only by RemindDuePublication, emptied by SchedulePublication on every (re)schedule.
     *
     * No backfill: existing `terjadwal` rows start with null (their reminder is still due), and rows the old job
     * already set to `dipublikasikan` stay as they are (those students were already told).
     */
    public function up(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('publications', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
