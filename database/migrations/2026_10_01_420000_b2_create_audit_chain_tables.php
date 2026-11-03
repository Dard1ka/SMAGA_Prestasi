<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * B2 (backlog #16, SECURITY §3m-9): hash chain over activity_log, see App\Support\Audit\AuditChain.
     * activity_log itself is not changed (it stays INSERT/SELECT only for the app DB user).
     * - audit_seals: one seal per committed activity row, in chain order (id); hash = sha256(prev_hash|payload).
     * - audit_chain_heads: the single head row (id 1) that serialises sealing (locked FOR UPDATE).
     * Existing rows are sealed by `php artisan sth:audit:seal` (also scheduled), not here.
     */
    public function up(): void
    {
        Schema::create('audit_seals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('activity_id')->unique();
            $table->char('prev_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_seal_id')->nullable();
            $table->char('last_hash', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        DB::table('audit_chain_heads')->insert(['id' => 1, 'last_seal_id' => null, 'last_hash' => null, 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
        Schema::dropIfExists('audit_seals');
    }
};
