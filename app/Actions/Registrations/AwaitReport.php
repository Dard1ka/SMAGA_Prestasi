<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Deadlines\ReportDeadline;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Notifications\Reports\ReportWindowOpened;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tracker phase 5 "Laporan hasil": berlangsung → menunggu_laporan once today > competition.final_on
 * (ARCHITECTURE §6). System transition run by `sth:registrations:advance`. After commit the ketua
 * (the only member who can report) gets ReportWindowOpened with the D-25 due date.
 */
final class AwaitReport extends TimelineTransition
{
    public function from(): RegistrationStatus
    {
        return RegistrationStatus::Berlangsung;
    }

    public function to(): RegistrationStatus
    {
        return RegistrationStatus::MenungguLaporan;
    }

    protected function constrainDue(Builder $competition, string $today): void
    {
        $competition->whereNotNull('final_on')->whereDate('final_on', '<', $today);
    }

    protected function isDue(Competition $competition, string $today): bool
    {
        return $competition->final_on !== null && $competition->final_on->toDateString() < $today;
    }

    protected function afterMove(Registration $registration): void
    {
        $registration->loadMissing('leader.user');
        $user = $registration->leader?->user;
        $finalOn = $registration->competition?->final_on;

        if ($user === null || $finalOn === null || ! $user->canLogIn()) {
            return;
        }

        $user->notify(new ReportWindowOpened(
            (string) $registration->getKey(),
            (string) $registration->competition->name,
            app(ReportDeadline::class)->dueDate($finalOn)->locale('id')->translatedFormat('j M Y'),
        ));
    }
}
