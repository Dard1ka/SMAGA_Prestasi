<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\RegistrationStatus;
use App\Models\Competition;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tracker phase 4 "Lomba": surat_terbit → berlangsung once today ≥ competition.starts_on
 * (ARCHITECTURE §6). System transition run by `sth:registrations:advance`; no notification.
 */
final class StartCompetition extends TimelineTransition
{
    public function from(): RegistrationStatus
    {
        return RegistrationStatus::SuratTerbit;
    }

    public function to(): RegistrationStatus
    {
        return RegistrationStatus::Berlangsung;
    }

    protected function constrainDue(Builder $competition, string $today): void
    {
        $competition->whereNotNull('starts_on')->whereDate('starts_on', '<=', $today);
    }

    protected function isDue(Competition $competition, string $today): bool
    {
        return $competition->starts_on !== null && $competition->starts_on->toDateString() <= $today;
    }
}
