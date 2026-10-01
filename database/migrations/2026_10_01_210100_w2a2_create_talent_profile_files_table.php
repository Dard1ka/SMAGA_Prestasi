<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-A2: "Sertifikat pendukung" of Riwayat talenta (Profil-Talenta board, max 2 files per student).
 * Files are stored through App\Domain\Files with purpose talent_evidence on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('talent_profile_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('talent_profile_id')->constrained('talent_profiles')->cascadeOnDelete();
            $table->foreignUlid('file_id')->constrained('files');
            $table->timestamps();

            $table->unique(['talent_profile_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('talent_profile_files');
    }
};
