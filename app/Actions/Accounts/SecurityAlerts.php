<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Users\AdminRecipients;
use App\Enums\RoleName;
use App\Models\User;
use App\Notifications\Accounts\AccountAlert;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * Alerts of the account controls (docs/SECURITY.md §3a, B1). Called after the state change has committed.
 * - K3: an account frozen after 10 failed logins → every Admin + Kesiswaan (they can reopen it, K2).
 * - K8: "Bukan saya?" freeze → every Admin (the recovery "ticket").
 * - K10: more than 10 account resets by one Admin within an hour → every Admin (once per Admin per hour).
 */
final class SecurityAlerts
{
    public const RESET_ALERT_THRESHOLD = 10;

    /** Audit events that count as an Admin account reset. */
    public const RESET_EVENTS = ['account.reset', 'account.staff_reset'];

    public function __construct(private readonly AdminRecipients $recipients) {}

    public function accountFrozen(User $user, string $reason): void
    {
        $who = self::describe($user);

        $notification = $reason === AccountControls::FROZEN_NOT_ME
            ? new AccountAlert('account.reported_not_me', 'Laporan "Bukan saya": akun dibekukan', [
                $who.' dibekukan lewat link "Bukan saya?" di email pemberitahuan.',
                'Temui pemiliknya, lalu reset kata sandi & email atau buka pembekuan dari Status aktivasi.',
            ])
            : new AccountAlert('account.frozen', 'Akun dibekukan setelah '.AccountControls::MAX_FAILED_ATTEMPTS.' kali gagal masuk', [
                $who.' dibekukan setelah '.AccountControls::MAX_FAILED_ATTEMPTS.' kali gagal masuk sebelum aktivasi.',
                'Pastikan pemiliknya, lalu buka ulang aktivasi (72 jam) dari Status aktivasi.',
            ]);

        $recipients = $reason === AccountControls::FROZEN_NOT_ME
            ? $this->recipients->admins()
            : $this->recipients->admins()->merge($this->kesiswaan())->unique(fn (User $u) => $u->getKey())->values();

        Notification::send($recipients, $notification);
    }

    /** K10: alert when one Admin resets more than 10 accounts within the last hour (once per hour). */
    public function resetPerformed(User $actor): void
    {
        $count = Activity::query()
            ->whereIn('event', self::RESET_EVENTS)
            ->where('causer_type', $actor->getMorphClass())
            ->where('causer_id', $actor->getKey())
            ->where('created_at', '>=', now()->subHour())
            ->count();

        if ($count <= self::RESET_ALERT_THRESHOLD) {
            return;
        }

        if (! Cache::add('sth:reset-volume-alert:'.$actor->getKey(), true, 3600)) {
            return;
        }

        Log::warning('security.reset_volume', ['user_id' => $actor->getKey(), 'resets_last_hour' => $count]);

        activity('security')
            ->causedBy($actor)
            ->event('security.reset_volume')
            ->withProperties(['resets_last_hour' => $count])
            ->log('security.reset_volume');

        Notification::send($this->recipients->admins(), new AccountAlert(
            'account.reset_volume',
            'Reset akun melebihi batas',
            [
                $actor->name.' melakukan '.$count.' reset akun dalam 1 jam terakhir (batas '.self::RESET_ALERT_THRESHOLD.').',
                'Periksa log audit dan pastikan setiap reset sudah diverifikasi tatap muka.',
            ],
            AccountAlert::TARGET_SYSTEM,
        ));
    }

    /** "Nadia Putri Anindya (XI-D)" for students, "Nama (staf)" otherwise. */
    public static function describe(User $user): string
    {
        $student = $user->student()->with('classroom:id,name')->first();

        return $student !== null
            ? $user->name.' ('.($student->classroom?->name ?? 'siswa').')'
            : $user->name.' (staf)';
    }

    /** @return Collection<int, User> */
    private function kesiswaan(): Collection
    {
        return User::query()->role(RoleName::Kesiswaan->value)->canLogIn()->get();
    }
}
