<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Surat tugas (D-06). TU types `sequence`; (type, year, sequence) is unique and race-safe via the
     * index. One siswa letter (lists all members) + one guru letter per registration.
     */
    public function up(): void
    {
        Schema::create('letters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('registration_id')->constrained('registrations');
            $table->string('type', 10);                     // App\Enums\LetterType
            $table->unsignedInteger('sequence');
            $table->string('full_number', 100);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('issued_on');
            $table->string('basis_number', 150)->nullable(); // nomor surat undangan/dasar
            $table->date('basis_date')->nullable();
            $table->foreignUlid('file_id')->nullable()->constrained('files');
            $table->string('verification_code', 64)->unique(); // /verifikasi-surat/{code}
            $table->foreignId('generated_by')->nullable()->constrained('users');
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'year', 'sequence']);
            $table->unique(['registration_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letters');
    }
};
