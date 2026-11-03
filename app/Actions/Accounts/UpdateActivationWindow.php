<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Enums\Permission;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * K1 window length (docs/SECURITY.md §3a): days an imported initial password stays valid, 1–30 (default 14).
 * Admin only (`setting.manage`), audited (`setting.activation_window`, plus the Setting model's own log).
 * Applies to the next imports; already imported students keep their date (use K2 "Buka ulang aktivasi").
 */
final class UpdateActivationWindow
{
    /**
     * @throws ValidationException
     */
    public function handle(User $actor, int $days): void
    {
        Gate::forUser($actor)->authorize(Permission::SettingManage->value);

        if (! $actor->canLogIn()) {
            abort(403);
        }

        if ($days < 1 || $days > AccountControls::MAX_WINDOW_DAYS) {
            throw ValidationException::withMessages(['windowDays' => 'Isi 1 sampai '.AccountControls::MAX_WINDOW_DAYS.' hari.']);
        }

        DB::transaction(function () use ($actor, $days): void {
            // The row exists once SettingSeeder ran (W4: enum case with default 14); lock it for an exact "from".
            Setting::query()->whereKey(SettingKey::ActivationWindowDays->value)->lockForUpdate()->first();
            $from = (int) Setting::get(SettingKey::ActivationWindowDays, AccountControls::DEFAULT_WINDOW_DAYS);

            Setting::put(SettingKey::ActivationWindowDays, $days);

            activity('setting')
                ->causedBy($actor)
                ->event('setting.activation_window')
                ->withProperties(['from' => $from, 'to' => $days])
                ->log('setting.activation_window');
        });
    }
}
