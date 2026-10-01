<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-A2: one row per reminder that was sent for a registration (e.g. report_due_14, report_due_3).
 * The unique key makes the daily reminder job idempotent: insertOrIgnore() wins once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('registration_id')->constrained('registrations')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->timestamp('sent_at');

            $table->unique(['registration_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_reminders');
    }
};
