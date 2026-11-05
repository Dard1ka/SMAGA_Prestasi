<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SV6, D-188 (the lead, 2026-11-05): a letter number may be used again once the letter that carried it is revoked, so a
 * "Koreksi" can keep the old number. A number stays unique per letter type among the letters that are NOT revoked.
 * - new generated column `active_full_number` = the number in the form the app compares (LetterNumber::key(): no
 *   spaces, lower case) while `revoked_at` is empty, NULL once revoked (NULLs never collide in a unique index);
 * - the unique index (type, full_number) of M1-A is replaced by (type, active_full_number).
 * VIRTUAL, not STORED: SQLite (tests) cannot add a stored column with ALTER TABLE; MySQL 8.4 indexes a virtual column
 * the same way. No row changes; the new index is created before the old one is dropped. Forward-only in production.
 */
return new class extends Migration
{
    private const EXPRESSION = "CASE WHEN revoked_at IS NULL THEN LOWER(REPLACE(full_number, ' ', '')) ELSE NULL END";

    public function up(): void
    {
        $duplicates = DB::table('letters')
            ->whereNull('revoked_at')
            ->selectRaw("type, LOWER(REPLACE(full_number, ' ', '')) AS number_key, COUNT(*) AS total")
            ->groupByRaw("type, LOWER(REPLACE(full_number, ' ', ''))")
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('letters: nomor surat aktif ganda per jenis, perbaiki dulu sebelum migrasi: '
                .$duplicates->map(fn (object $row): string => $row->type.' '.$row->number_key)->implode('; '));
        }

        Schema::table('letters', function (Blueprint $table) {
            $table->string('active_full_number', 100)->nullable()->virtualAs(self::EXPRESSION);
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->unique(['type', 'active_full_number']);
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->dropUnique(['type', 'full_number']);
        });
    }

    public function down(): void
    {
        // The old index forbids every repeated number, revoked ones too: it can only come back while no number was
        // used again after D-188.
        $repeated = DB::table('letters')
            ->select('type', 'full_number')
            ->groupBy('type', 'full_number')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($repeated) {
            throw new RuntimeException('letters: sebuah nomor surat sudah dipakai lagi setelah dicabut (D-188); indeks lama (type, full_number) tidak bisa dikembalikan.');
        }

        Schema::table('letters', function (Blueprint $table) {
            $table->unique(['type', 'full_number']);
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->dropUnique(['type', 'active_full_number']);
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->dropColumn('active_full_number');
        });
    }
};
