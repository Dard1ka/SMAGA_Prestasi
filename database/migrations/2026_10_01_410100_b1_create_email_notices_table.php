<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B1 — K8 (docs/SECURITY.md §3a): one row per "email bound / changed" notice. The email carries a signed
 * "Bukan saya?" link with this row's ULID; the row makes the link single-use (used_at) and expires after 7 days.
 * Only a masked address is stored (n•••@gmail.com).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_notices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // bound | changed_new | changed_old
            $table->string('sent_to_masked', 255);
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_notices');
    }
};
