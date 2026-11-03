<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M1-E (lead, 2026-10-04, item 12): an appreciation event is confirmed as held ("Tandai sudah
     * dilaksanakan") on the event itself, so a confirmed event is a record that can no longer be deleted and
     * leaves the list of upcoming events at once. Before this the state only existed on the rows
     * (`appreciations.status`), which an empty or re-opened event could not express.
     *
     * Backfill: an event whose rows are all `selesai` was confirmed with the old button; it gets the time its
     * rows were last changed. `completed_by` stays null for those (the audit log has the actor).
     */
    public function up(): void
    {
        Schema::table('appreciation_events', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('held_on')->index();
            $table->foreignId('completed_by')->nullable()->after('completed_at')->constrained('users')->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Stamps the events that were confirmed before the column existed. Public so the test can run it on
     * prepared rows. Plain builder calls only (SQLite + MySQL 8.4); a school has a few dozen events at most.
     */
    public function backfill(): void
    {
        $eventIds = DB::table('appreciations')
            ->whereNotNull('appreciation_event_id')
            ->distinct()
            ->pluck('appreciation_event_id');

        foreach ($eventIds as $eventId) {
            $rows = DB::table('appreciations')->where('appreciation_event_id', $eventId);

            if ((clone $rows)->where('status', '!=', 'selesai')->exists()) {
                continue;
            }

            DB::table('appreciation_events')
                ->where('id', $eventId)
                ->whereNull('completed_at')
                ->update(['completed_at' => (clone $rows)->max('updated_at') ?? now()]);
        }
    }

    public function down(): void
    {
        Schema::table('appreciation_events', function (Blueprint $table) {
            $table->dropForeign(['completed_by']);
            $table->dropIndex(['completed_at']);
            $table->dropColumn(['completed_at', 'completed_by']);
        });
    }
};
