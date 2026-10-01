<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned point weights (ARCHITECTURE §7). The active version is the latest one with
     * effective_at <= now; achievements snapshot the version they were computed with.
     */
    public function up(): void
    {
        Schema::create('weight_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestamp('effective_at')->index();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('weight_values', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('weight_version_id')->constrained('weight_versions')->cascadeOnDelete();
            $table->string('group', 20);                    // App\Enums\WeightGroup
            $table->string('key', 30);                      // Level / ResultType / WeightCategory value
            $table->decimal('value', 6, 2);
            $table->timestamps();

            $table->unique(['weight_version_id', 'group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_values');
        Schema::dropIfExists('weight_versions');
    }
};
