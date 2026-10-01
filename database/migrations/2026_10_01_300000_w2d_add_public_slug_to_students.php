<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Random public URL key for /prestasi/siswa/{slug} (SECURITY §3m-7: never NIS or a sequential id).
     * 20 hex chars = 80 random bits. Existing rows get one now; new rows get one lazily from
     * App\Livewire\Publik\Data\PublicAchievements (only students with publish consent are ever linked).
     */
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('public_slug', 32)->nullable()->unique()->after('publish_consent');
        });

        DB::table('students')->whereNull('public_slug')->orderBy('id')->select('id')->chunk(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('students')->where('id', $row->id)->update(['public_slug' => bin2hex(random_bytes(10))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropUnique(['public_slug']);
            $table->dropColumn('public_slug');
        });
    }
};
