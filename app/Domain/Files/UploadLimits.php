<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Enums\RoleName;
use App\Models\File;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Upload quotas (SECURITY §3d "Kuota & DoS", §3m-2), enforced by StoreUpload::store() for every user upload:
 * - at most 30 stored uploads per user per rolling hour (RateLimiter bucket `uploads:<user id>`; every stored
 *   file counts, also when the surrounding form later fails, so upload+delete loops cannot reset it);
 * - at most 200 MB stored per student: SUM(files.size) of the files the student owns (re-encoded bytes).
 * System-generated files (letters) and uploads without an owner are not limited here.
 * Messages are short Indonesian texts for the form field (thrown as InvalidUpload).
 */
final class UploadLimits
{
    public const MAX_PER_HOUR = 30;

    public const DECAY_SECONDS = 3600;

    public const STUDENT_QUOTA_BYTES = 200 * 1024 * 1024;

    /**
     * @throws InvalidUpload when the user already stored MAX_PER_HOUR files in the last hour
     */
    public function ensureCanUpload(?User $owner): void
    {
        if ($owner === null) {
            return;
        }

        if (RateLimiter::tooManyAttempts(self::key($owner), self::MAX_PER_HOUR)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn(self::key($owner)) / 60));

            throw new InvalidUpload([self::rateMessage($minutes)]);
        }
    }

    /**
     * @throws InvalidUpload when a student's stored files plus $bytes would exceed STUDENT_QUOTA_BYTES
     */
    public function ensureFitsQuota(?User $owner, int $bytes): void
    {
        if ($owner === null || ! $this->isStudent($owner)) {
            return;
        }

        $used = $this->usedBytes($owner);

        if ($used + $bytes > self::STUDENT_QUOTA_BYTES) {
            throw new InvalidUpload([self::quotaMessage(max(0, self::STUDENT_QUOTA_BYTES - $used))]);
        }
    }

    /** Count one stored upload against the hourly limit. */
    public function recordUpload(?User $owner): void
    {
        if ($owner !== null) {
            RateLimiter::hit(self::key($owner), self::DECAY_SECONDS);
        }
    }

    /** Bytes currently stored for files owned by this user. */
    public function usedBytes(User $owner): int
    {
        return (int) File::query()->where('owner_user_id', $owner->getKey())->sum('size');
    }

    public function remainingUploads(User $owner): int
    {
        return RateLimiter::remaining(self::key($owner), self::MAX_PER_HOUR);
    }

    public static function key(User $owner): string
    {
        return 'uploads:'.$owner->getKey();
    }

    public static function rateMessage(int $minutes): string
    {
        return 'Batas upload tercapai (maks. '.self::MAX_PER_HOUR.' file per jam). Coba lagi dalam '.$minutes.' menit.';
    }

    public static function quotaMessage(int $remainingBytes): string
    {
        $limit = (int) (self::STUDENT_QUOTA_BYTES / (1024 * 1024));

        return $remainingBytes < 1024 * 1024 / 10
            ? 'Ruang berkasmu sudah penuh (maks. '.$limit.' MB per siswa). Hubungi Kesiswaan bila perlu ruang tambahan.'
            : 'File ini melebihi sisa ruang berkasmu (sisa '.self::megabytes($remainingBytes).' MB dari '.$limit.' MB). Perkecil file atau hubungi Kesiswaan.';
    }

    private function isStudent(User $owner): bool
    {
        return $owner->hasRoleName(RoleName::Siswa);
    }

    /** One decimal, decimal comma (UI rule: "1,5"). */
    private static function megabytes(int $bytes): string
    {
        return str_replace('.', ',', number_format($bytes / (1024 * 1024), 1, '.', ''));
    }
}
