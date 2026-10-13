<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\RegistrationStatus;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What ResumeWaitingRegistrations did to the `menunggu_lomba` registrations of a lomba Kesiswaan just decided (D-148),
 * so the notices go out after the commit: the teams whose anggota are invited now, the individu registrations that
 * wait for the approvers now, and the new status of the submitter's own registration (null when the submitter had none
 * waiting), which the submitter's decision notice names. A cancel tells nobody else (ResumeWaitingRegistrations).
 */
final readonly class WaitingOutcome
{
    /**
     * @param  list<array{registration: Registration, users: Collection<int, User>}>  $invited
     * @param  list<Registration>  $toApprovers
     */
    public function __construct(
        public array $invited,
        public array $toApprovers,
        public ?RegistrationStatus $submitterNow,
    ) {}

    public static function none(): self
    {
        return new self([], [], null);
    }
}
