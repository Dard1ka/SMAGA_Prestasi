<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lomba catalog. Every create starts at `menunggu_verifikasi` (D-27). Weight-relevant fields are
     * nullable because a student proposal (usulan, D-04) only carries name/organizer/link/brochure;
     * Kesiswaan fills them before publishing (enforced in the publish Action, not in the schema).
     */
    public function up(): void
    {
        Schema::create('competitions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->string('bidang', 20)->nullable()->index();
            $table->string('organizer_name', 200)->nullable();
            $table->string('organizer_type', 30)->nullable();
            $table->char('weight_category', 1)->nullable();
            $table->string('event_status', 30)->nullable();
            $table->string('level', 20)->nullable();
            $table->string('mode', 10)->nullable();
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('final_on')->nullable();            // report deadline base (D-25)
            $table->string('location', 200)->nullable();
            $table->string('participation', 10)->nullable();
            $table->unsignedTinyInteger('max_team_size')->nullable();
            $table->foreignId('default_mentor_id')->nullable()->constrained('users');
            $table->string('website_url', 500)->nullable();
            $table->string('registration_url', 500)->nullable(); // D-28
            $table->foreignUlid('juknis_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignUlid('cover_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->string('status', 30)->default('menunggu_verifikasi')->index();
            $table->foreignId('submitted_by')->constrained('users');
            $table->foreignId('verified_by')->nullable()->constrained('users');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'registration_closes_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competitions');
    }
};
