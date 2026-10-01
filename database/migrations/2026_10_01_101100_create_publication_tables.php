<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Humas publication queue and appreciation (apresiasi) events. */
    public function up(): void
    {
        Schema::create('publications', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('achievement_id')->unique()->constrained('achievements');
            $table->string('status', 20)->index();          // App\Enums\PublicationStatus
            $table->json('channels')->nullable();           // list of App\Enums\PublicationChannel values
            $table->timestamp('scheduled_at')->nullable();
            $table->text('caption')->nullable();
            $table->foreignUlid('flyer_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });

        Schema::create('appreciation_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 200);
            $table->date('held_on');
            $table->timestamps();
        });

        Schema::create('appreciations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('achievement_id')->constrained('achievements');
            $table->foreignUlid('appreciation_event_id')->nullable()->constrained('appreciation_events')->nullOnDelete();
            $table->string('status', 20);                   // App\Enums\AppreciationStatus
            $table->timestamps();

            $table->unique(['achievement_id', 'appreciation_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appreciations');
        Schema::dropIfExists('appreciation_events');
        Schema::dropIfExists('publications');
    }
};
