<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FS1 Paket B (D-148, lead 2026-10-12): a team whose registration waited for its lomba (`menunggu_lomba`) and was
     * resumed after "Pendaftaran ditutup", or less than three days before it, may answer its invitations for three days
     * from the moment Kesiswaan published the lomba. `answer_until` = that moment + 3 days, written only by
     * ResumeWaitingRegistrations (not mass-assignable) and read only through Registration::answersCloseAt(), which takes
     * the later of it and the lomba's `registration_closes_at`.
     *
     * No backfill: NULL = the normal rule (answers close at the lomba's registration deadline).
     */
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->timestamp('answer_until')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropColumn('answer_until');
        });
    }
};
