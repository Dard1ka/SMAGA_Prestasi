<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QA retest 2026-10-06: the TU preview of an issued letter said "Isi PDF yang sudah dibuat" but was drawn again
 * from today's data (letter template, signer, classes, guru pembimbing), so after a change it showed text the
 * PDF does not have. `printed` keeps what the PDF was made from: {content: LetterContent, template:
 * {version, values}}, written once by IssueLetters / RevokeLetter in the same transaction as the PDF.
 *
 * Nullable, no backfill: what older PDFs printed is not known; their preview says it follows today's data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letters', function (Blueprint $table) {
            $table->json('printed')->nullable()->after('place');
        });
    }

    public function down(): void
    {
        Schema::table('letters', function (Blueprint $table) {
            $table->dropColumn('printed');
        });
    }
};
