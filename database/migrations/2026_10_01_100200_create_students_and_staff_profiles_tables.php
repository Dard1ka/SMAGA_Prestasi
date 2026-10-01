<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('nis', 20)->unique();
            $table->string('nisn', 10)->unique();
            $table->date('birth_date');
            $table->foreignUlid('classroom_id')->constrained('classrooms');
            $table->string('import_school_year', 9);        // e.g. 2026/2027
            $table->date('grade12_start_date')->nullable();  // computed (App\Domain\Archive)
            $table->date('archive_at')->nullable()->index(); // computed; daily archive job
            $table->text('phone')->nullable();               // `encrypted` cast → ciphertext needs TEXT
            $table->boolean('photo_consent')->default(false);
            $table->boolean('publish_consent')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('honorific', 10)->nullable();     // Bu / Pak / Mas — UI salutation
            $table->string('nip', 30)->nullable();
            $table->string('rank', 100)->nullable();         // pangkat/golongan
            $table->string('position', 150)->nullable();     // jabatan
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_profiles');
        Schema::dropIfExists('students');
    }
};
