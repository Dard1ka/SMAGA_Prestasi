<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shared engine of the date-driven registration timeline (ARCHITECTURE §6, B3 / backlog #14). A system
 * transition, so there is no user actor: the scheduler command `sth:registrations:advance` lists the
 * due rows with due() and calls handle() per row.
 *
 * handle() re-reads the registration under lockForUpdate(), re-checks its status and the lomba dates,
 * moves it through RegistrationStateMachine::assertTransition(), writes the activity log
 * (`registration.<to>`, by = scheduler, no causer) and runs afterMove() (notifications) after commit.
 * Idempotent: a row that already moved, was cancelled or deleted is skipped (null).
 */
abstract class TimelineTransition
{
    public function __construct(protected readonly RegistrationStateMachine $machine) {}

    abstract public function from(): RegistrationStatus;

    abstract public function to(): RegistrationStatus;

    /** Same rule as isDue(), as a query on the competition. @param Builder<Competition> $competition */
    abstract protected function constrainDue(Builder $competition, string $today): void;

    /** Do the lomba dates make this step due on $today (Y-m-d)? */
    abstract protected function isDue(Competition $competition, string $today): bool;

    /** Runs after the transaction committed (notifications). */
    protected function afterMove(Registration $registration): void {}

    /** Registrations in from() whose lomba makes the step due on $today, oldest first. @return Builder<Registration> */
    public function due(CarbonImmutable $today): Builder
    {
        $day = $today->toDateString();

        return Registration::query()
            ->where('status', $this->from())
            ->whereHas('competition', fn (Builder $c) => $this->constrainDue($c->withTrashed(), $day))
            ->orderBy('id');
    }

    /** @return Registration|null the moved registration, null when nothing changed */
    public function handle(Registration|string $registration, CarbonImmutable $today): ?Registration
    {
        $id = $registration instanceof Registration ? (string) $registration->getKey() : $registration;
        $day = $today->toDateString();
        $from = $this->from();
        $to = $this->to();

        $moved = DB::transaction(function () use ($id, $day, $from, $to): ?Registration {
            /** @var Registration|null $locked */
            $locked = Registration::query()->whereKey($id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== $from) {
                return null; // already moved by another run, cancelled, or deleted
            }

            $competition = Competition::withTrashed()->find($locked->competition_id);

            if ($competition === null || ! $this->isDue($competition, $day)) {
                return null;
            }

            $this->machine->assertTransition($locked->status, $to);
            $locked->forceFill(['status' => $to])->save();

            activity('registration')
                ->performedOn($locked)
                ->event('registration.'.$to->value)
                ->withProperties(['from' => $from->value, 'to' => $to->value, 'by' => 'scheduler'])
                ->log('registration.'.$to->value);

            return $locked->setRelation('competition', $competition);
        });

        if ($moved !== null) {
            $this->afterMove($moved);
        }

        return $moved;
    }
}
