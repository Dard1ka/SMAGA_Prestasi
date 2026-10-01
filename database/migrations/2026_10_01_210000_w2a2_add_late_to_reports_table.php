<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * W2-A2: a report sent after final_on + report_deadline_days is still accepted but flagged (D-25).
 * Set only by App\Actions\Reports\SubmitReport (not mass-assignable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->boolean('late')->default(false)->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('late');
        });
    }
};
