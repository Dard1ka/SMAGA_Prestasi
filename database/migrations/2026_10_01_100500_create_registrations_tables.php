<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('competition_id')->constrained('competitions');
            $table->foreignUlid('leader_student_id')->constrained('students');
            $table->string('type', 10);                     // App\Enums\RegistrationType
            $table->string('branch', 100)->nullable();       // cabang lomba (e.g. "Baca Puisi"), optional
            $table->foreignId('mentor_id')->nullable()->constrained('users');
            $table->string('status', 30)->index();           // App\Enums\RegistrationStatus (§6)
            $table->foreignId('principal_decision_by')->nullable()->constrained('users');
            $table->timestamp('principal_decided_at')->nullable();
            $table->text('principal_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['competition_id', 'status']);
        });

        Schema::create('registration_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->foreignUlid('student_id')->constrained('students');
            $table->string('role', 10);                     // App\Enums\MemberRole
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            // "One student, one non-final registration per competition" is checked in the Action.
            $table->unique(['registration_id', 'student_id']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_members');
        Schema::dropIfExists('registrations');
    }
};
