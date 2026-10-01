<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B2 (backlog #19): one row per queued student import (App\Jobs\ImportStudentsJob), polled by the import screen.
     * Holds counts only, never student data. The source file (files.purpose = import) is deleted when the job ends;
     * file_sha256 stays (also in the audit entry).
     */
    public function up(): void
    {
        Schema::create('student_imports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('status', 20);
            $table->string('extension', 8);
            $table->string('school_year', 9);
            $table->string('file_name', 150);
            $table->char('file_sha256', 64);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('sanitized_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->string('error', 255)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_imports');
    }
};
