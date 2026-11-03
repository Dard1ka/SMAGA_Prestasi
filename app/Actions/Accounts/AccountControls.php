<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Read side of the account controls of docs/SECURITY.md §3a (B1):
 * - K1 activation window: the initial password (import) or a temporary password (K10 reset, K2 reopen) only
 *   works until `users.initial_password_expires_at`; null = no window (accounts created before B1, staff).
 * - K3/K8 freeze: `users.frozen_at` + `frozen_reason` (failed_attempts | not_me); a frozen account cannot log in.
 * - K9 status of a student account for the activation report.
 *
 * Reads the raw attributes so it also works on models that were created in memory (strict mode); User casts the
 * B1 columns too (immutable_datetime / int, W4), so both ways give the same values.
 */
final class AccountControls
{
    /** Default of SettingKey::ActivationWindowDays (W4 replaced B1's string key with the enum case). */
    public const DEFAULT_WINDOW_DAYS = 14;

    public const MAX_WINDOW_DAYS = 30;

    /** K2 reopened window and K10 temporary password lifetime. */
    public const REOPEN_HOURS = 72;

    public const TEMPORARY_PASSWORD_HOURS = 72;

    /** K3: cumulative failed logins before a not-yet-activated student account is frozen. */
    public const MAX_FAILED_ATTEMPTS = 10;

    public const FROZEN_FAILED_ATTEMPTS = 'failed_attempts';

    public const FROZEN_NOT_ME = 'not_me';

    // K9 report states (value => label, tone) ------------------------------------------------------
    public const STATE_ACTIVE = 'aktif';

    public const STATE_PENDING = 'belum';

    public const STATE_EXPIRED = 'kedaluwarsa';

    public const STATE_FROZEN = 'dibekukan';

    public const STATE_DISABLED = 'nonaktif';

    public const STATE_ARCHIVED = 'arsip';

    /** @var array<string, array{label: string, tone: string}> */
    public const STATES = [
        self::STATE_PENDING => ['label' => 'Belum aktivasi', 'tone' => 'gray'],
        self::STATE_ACTIVE => ['label' => 'Aktif', 'tone' => 'green'],
        self::STATE_EXPIRED => ['label' => 'Kedaluwarsa', 'tone' => 'orange'],
        self::STATE_FROZEN => ['label' => 'Dibekukan', 'tone' => 'red'],
        self::STATE_DISABLED => ['label' => 'Nonaktif', 'tone' => 'gray'],
        self::STATE_ARCHIVED => ['label' => 'Diarsipkan', 'tone' => 'gray'],
    ];

    /** Days an imported initial password stays valid (setting, Admin-editable 1–30, default 14). */
    public function windowDays(): int
    {
        $days = (int) rescue(
            fn () => Setting::get(SettingKey::ActivationWindowDays, self::DEFAULT_WINDOW_DAYS),
            self::DEFAULT_WINDOW_DAYS,
            false,
        );

        return $days >= 1 && $days <= self::MAX_WINDOW_DAYS ? $days : self::DEFAULT_WINDOW_DAYS;
    }

    public function initialWindowEnd(?DateTimeInterface $from = null): CarbonImmutable
    {
        return CarbonImmutable::instance($from ?? now())->addDays($this->windowDays());
    }

    public static function date(User $user, string $column): ?CarbonImmutable
    {
        $value = $user->getAttributes()[$column] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse((string) $value);
    }

    public static function isFrozen(User $user): bool
    {
        return self::date($user, 'frozen_at') !== null;
    }

    public static function frozenReason(User $user): ?string
    {
        $reason = $user->getAttributes()['frozen_reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    public static function failedAttempts(User $user): int
    {
        return (int) ($user->getAttributes()['failed_activation_attempts'] ?? 0);
    }

    /** K1: the user still has to change an initial/temporary password whose window has passed. */
    public static function initialPasswordExpired(User $user, ?DateTimeInterface $now = null): bool
    {
        $expires = self::date($user, 'initial_password_expires_at');

        return (bool) ($user->getAttributes()['must_change_password'] ?? false)
            && $expires !== null
            && $expires->lessThanOrEqualTo($now ?? now());
    }

    /** K3 applies to student accounts that have not finished activation. */
    public static function countsFailedLogins(User $user): bool
    {
        return $user->status === UserStatus::PendingActivation && $user->student()->exists();
    }

    /** K9 state of an account: arsip > nonaktif > dibekukan > aktif > kedaluwarsa > belum. */
    public static function state(User $user, ?DateTimeInterface $now = null): string
    {
        return match (true) {
            $user->status === UserStatus::Archived => self::STATE_ARCHIVED,
            $user->status === UserStatus::Disabled => self::STATE_DISABLED,
            self::isFrozen($user) => self::STATE_FROZEN,
            $user->status === UserStatus::Active => self::STATE_ACTIVE,
            self::initialPasswordExpired($user, $now) => self::STATE_EXPIRED,
            default => self::STATE_PENDING,
        };
    }

    public static function stateLabel(string $state): string
    {
        return self::STATES[$state]['label'] ?? $state;
    }

    public static function stateTone(string $state): string
    {
        return self::STATES[$state]['tone'] ?? 'gray';
    }

    /** "nadia.anindya@gmail.com" → "n•••@gmail.com" */
    public static function maskEmail(?string $email): ?string
    {
        if ($email === null || $email === '') {
            return null;
        }

        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }
}
