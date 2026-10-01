<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('registration_id')->unique()->constrained('registrations');
            $table->string('claimed_result', 30);           // App\Enums\ResultType
            $table->foreignId('submitted_by')->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->string('status', 20)->index();          // App\Enums\ReportStatus
            $table->string('final_result', 30)->nullable(); // set by Kesiswaan on verification
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->string('research_title')->nullable();
            $table->text('research_abstract')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('report_files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('report_id')->constrained('reports')->cascadeOnDelete();
            $table->string('kind', 20);                     // App\Enums\ReportFileKind
            $table->foreignUlid('file_id')->constrained('files');
            $table->timestamps();

            $table->index(['report_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_files');
        Schema::dropIfExists('reports');
    }
};
