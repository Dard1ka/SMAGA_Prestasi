<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M1-A (lead, 2026-10-04): Tata Usaha types the COMPLETE letter number, and every letter carries its own data.
 * - `sequence` becomes nullable: old rows keep theirs, new rows leave it NULL;
 * - the unique index (type, year, sequence) is replaced by (type, full_number): a number is never used twice
 *   for the same letter type, revoked versions included (they stay in the table);
 * - new per-letter texts: `basis_from` ("Surat dari …"), `basis_subject` ("Hal …", guru letter) and
 *   `schedule_date` ("Tanggal …"; `schedule_day` now only holds the day name).
 * Additive for existing rows (NULL in the new columns; their PDFs were rendered at issue time). Works on SQLite
 * (tests) and MySQL 8.4. The new index is created before the old one is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('letters')
            ->select('type', 'full_number')
            ->groupBy('type', 'full_number')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('letters: nomor surat ganda per jenis, perbaiki dulu sebelum migrasi: '
                .$duplicates->map(fn (object $row): string => $row->type.' '.$row->full_number)->implode('; '));
        }

        Schema::table('letters', function (Blueprint $table) {
            $table->string('basis_from', 200)->nullable()->after('issued_on');
            $table->string('basis_subject', 150)->nullable()->after('basis_date');
            $table->string('schedule_date', 150)->nullable()->after('schedule_day');

            $table->unique(['type', 'full_number']);
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->dropUnique(['type', 'year', 'sequence']);
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows issued after this migration have no sequence, so the old unique index cannot come back.
        Schema::table('letters', function (Blueprint $table) {
            $table->dropUnique(['type', 'full_number']);
            $table->dropColumn(['basis_from', 'basis_subject', 'schedule_date']);
        });
    }
};
