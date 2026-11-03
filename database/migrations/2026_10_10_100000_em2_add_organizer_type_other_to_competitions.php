<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EM2 (the lead's mail test of 2026-10-09, D-125): "Jenis penyelenggara: Lainnya" in Kelola lomba said nothing about
 * the organizer, so the form now asks what kind it is.
 *
 * - `organizer_type_other` = the kind Kesiswaan or the Admin typed (e.g. "Sanggar seni"), at most 60 characters.
 *   Filled only while `organizer_type` = `lainnya`; CreateCompetition::normalize() sets it to null for every other
 *   type. It may stay empty while the lomba waits in the queue and is required before it is published
 *   (VerifyCompetition::missingFields()).
 * - Not a weight field: points come from tingkat × capaian × kategori bobot only.
 *
 * Plain nullable column, no backfill: existing "Lainnya" rows stay null and keep showing "Lainnya".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->string('organizer_type_other', 60)->nullable()->after('organizer_type');
        });
    }

    public function down(): void
    {
        Schema::table('competitions', function (Blueprint $table) {
            $table->dropColumn('organizer_type_other');
        });
    }
};
