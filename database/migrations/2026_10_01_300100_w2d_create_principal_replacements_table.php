<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Ganti akun Kepala Sekolah" (D-01, SECURITY §3m-9): Admin requests, it takes effect after a 24 h delay
     * unless the current Kepala Sekolah or another Admin cancels it. Rows are never deleted (audit trail).
     */
    public function up(): void
    {
        Schema::create('principal_replacements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('from_user_id')->nullable()->constrained('users'); // current Kepala Sekolah (null: none)
            $table->foreignId('to_user_id')->constrained('users');
            $table->foreignId('requested_by')->constrained('users');
            $table->text('reason');
            $table->timestamp('effective_at')->index();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('principal_replacements');
    }
};
