<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Staff role changes to a privileged role (admin, tata_usaha, kesiswaan; kepala_sekolah moves through
     * principal_replacements): a second Admin approves, or — when the requester is the only Admin — the change
     * applies after a 24 h delay (`effective_at`) unless an Admin cancels it (lead decision, backlog #13,
     * SECURITY §3m-9). Rows are never deleted (audit trail).
     */
    public function up(): void
    {
        Schema::create('role_change_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained('users');          // account whose role changes
            $table->json('from_roles');                                   // role names at request time
            $table->string('to_role', 32);                                // App\Enums\RoleName
            $table->foreignId('requested_by')->constrained('users');
            $table->text('reason');
            $table->timestamp('effective_at')->nullable()->index();       // set only in delay mode (single Admin)
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'applied_at', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_change_requests');
    }
};
