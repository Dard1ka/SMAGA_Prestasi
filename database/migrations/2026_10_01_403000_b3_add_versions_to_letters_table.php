<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3 (backlog #11, SECURITY §3g/§3m-5): letter correction = cabut (revoked_at, revoked_by, reason) +
 * a new version with a NEW number; the old number is never reused because (type, year, sequence)
 * stays unique. Each letter also stores the signature image it was signed with and the schedule it
 * printed (until now only in the audit log). One current letter per (registration, type) is enforced
 * in the Actions; the DB keeps (registration_id, type, version) unique.
 * Additive: existing rows become version 1 with NULL schedule/signature (the audit log has them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letters', function (Blueprint $table) {
            $table->unsignedSmallInteger('version')->default(1)->after('type');
            $table->foreignUlid('replaces_letter_id')->nullable()->after('version')->constrained('letters');
            $table->string('schedule_day', 150)->nullable()->after('basis_date');
            $table->string('schedule_time', 150)->nullable()->after('schedule_day');
            $table->string('place', 150)->nullable()->after('schedule_time');
            $table->foreignUlid('signature_file_id')->nullable()->after('file_id')->constrained('files')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('sent_at');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users');
            $table->string('revoke_reason', 500)->nullable()->after('revoked_by');

            // New unique first, so MySQL always has an index that starts with registration_id for the FK.
            $table->unique(['registration_id', 'type', 'version']);
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->dropUnique(['registration_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('letters', function (Blueprint $table) {
            $table->unique(['registration_id', 'type']);
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->dropUnique(['registration_id', 'type', 'version']);
            $table->dropForeign(['replaces_letter_id']);
            $table->dropForeign(['signature_file_id']);
            $table->dropForeign(['revoked_by']);
            $table->dropColumn([
                'version', 'replaces_letter_id', 'schedule_day', 'schedule_time', 'place',
                'signature_file_id', 'revoked_at', 'revoked_by', 'revoke_reason',
            ]);
        });
    }
};
