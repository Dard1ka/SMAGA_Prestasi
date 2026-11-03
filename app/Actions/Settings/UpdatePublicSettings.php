<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Enums\SettingKey;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Data\PublicSettings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Admin › Pengaturan › Halaman publik (`setting.manage`): allow search-engine indexing of the aggregate public
 * pages, and "Lihat sertifikat" links (every team member named; no consent since D-162). Both default off
 * (D-38, D-39, SECURITY §3m-7). Audited; public cache flushed.
 */
final class UpdatePublicSettings
{
    public function handle(User $actor, bool $indexing, bool $certificates): void
    {
        Gate::forUser($actor)->authorize('update', Setting::class);

        DB::transaction(function () use ($actor, $indexing, $certificates): void {
            Setting::query()->whereIn('key', [SettingKey::PublicIndexing->value, SettingKey::PublicCertificates->value])->lockForUpdate()->get();

            $from = ['indexing' => PublicSettings::indexingAllowed(), 'certificates' => PublicSettings::certificatesAllowed()];

            if ($from === ['indexing' => $indexing, 'certificates' => $certificates]) {
                return;
            }

            Setting::put(SettingKey::PublicIndexing, $indexing);
            Setting::put(SettingKey::PublicCertificates, $certificates);

            activity('setting')
                ->causedBy($actor)
                ->event('settings.public_updated')
                ->withProperties(['from' => $from, 'to' => ['indexing' => $indexing, 'certificates' => $certificates]])
                ->log('settings.public_updated');
        });

        PublicAchievements::flush();
    }
}
