<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * M1-C (lead, 2026-10-04): the yearly student file also moves registered students to their current class.
     * A queued import (App\Jobs\ImportStudentsJob) counts them here, next to `created_count`. Counts only.
     */
    public function up(): void
    {
        Schema::table('student_imports', function (Blueprint $table) {
            $table->unsignedInteger('updated_count')->default(0)->after('created_count');
        });
    }

    public function down(): void
    {
        Schema::table('student_imports', function (Blueprint $table) {
            $table->dropColumn('updated_count');
        });
    }
};
