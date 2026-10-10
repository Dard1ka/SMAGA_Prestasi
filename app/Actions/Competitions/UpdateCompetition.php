<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationType;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Competition;
use App\Models\User;
use App\Notifications\Competitions\BinaanCompetitionChanged;
use App\Notifications\Competitions\CompetitionChanged;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan/Admin edits a lomba (queued or published): same fields as CreateCompetition. Changes to the
 * weight fields (tingkat, kategori, status ajang) get their own audit event (D-02); existing achievements
 * keep their snapshot. A published lomba must stay complete (VerifyCompetition::REQUIRED_TO_PUBLISH).
 * Status is never changed here (VerifyCompetition / CloseCompetition).
 *
 * Dates (M2-B, Q1-04): "Tanggal mulai" before "Batas daftar" is refused; for a published lomba only when
 * one of the two dates changes. Dates in the past need no confirmation here: that question belongs to
 * publishing (VerifyCompetition), and a published lomba gets old by itself.
 *
 * QA10: an unchanged default guru pembimbing who is no longer active is kept; "individu" or a lower "Maks anggota"
 * is refused while team registrations still run (assertTeamsStillFit()); uploads of a refused save are discarded.
 *
 * Shared with CreateCompetition (prepare(), rules(), normalize()): the two link fields are cleaned at both ends
 * before the check, and the kind typed for "Jenis penyelenggara: Lainnya" (`organizer_type_other`, D-125) is kept
 * only for that type. It is not a weight field; a published "Lainnya" lomba cannot lose it (missingFields()).
 *
 * A published lomba whose facts change (batas daftar, tanggal, pelaksanaan, tempat, peserta, the two links, juknis)
 * tells the students of its running registrations and their guru pembimbing, one notice per save per person, and
 * writes `competition.facts_changed` (lead, 2026-10-10, D-126; the rules are in CompetitionChange). Never all
 * students, never for a lomba that still waits in the queue, never for a change of any other field.
 */
final class UpdateCompetition
{
    public const TEAMS_RUNNING_PARTICIPATION = 'Masih ada pendaftaran tim yang berjalan. Peserta baru bisa diubah ke individu setelah pendaftaran itu selesai, ditolak, atau dibatalkan.';

    public const TEAMS_RUNNING_MAX = 'Masih ada pendaftaran tim yang berjalan. Maks anggota baru bisa dikurangi setelah pendaftaran itu selesai, ditolak, atau dibatalkan.';

    private const WEIGHT_FIELDS = ['level', 'weight_category', 'event_status'];

    /**
     * @param  array<string, mixed>  $input  form fields (CreateCompetition::rules())
     */
    public function handle(
        User $actor,
        Competition $competition,
        array $input,
        ?UploadedFile $juknis = null,
        ?UploadedFile $cover = null,
        bool $removeCover = false,
    ): Competition {
        Gate::forUser($actor)->authorize('update', $competition);

        // An unchanged default guru who is no longer active stays accepted (QA10); '' from a cleared field is null.
        $data = CreateCompetition::normalize(
            Validator::make(
                CreateCompetition::prepare($input),
                CreateCompetition::rules($competition->default_mentor_id),
                CreateCompetition::messages(),
            )->validate(),
        );

        $files = CreateCompetition::storeFiles($actor, $juknis, $cover);

        [$result, $audience] = CreateCompetition::discardOnFailure($files, fn (): array => DB::transaction(function () use ($actor, $competition, $data, $files, $removeCover): array {
            /** @var Competition $locked */
            $locked = Competition::query()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [CompetitionStatus::MenungguVerifikasi, CompetitionStatus::Dipublikasikan], true)) {
                throw ValidationException::withMessages(['status' => 'Lomba ini tidak bisa diubah lagi.']);
            }

            $factsBefore = CompetitionChange::snapshot($locked);

            $before = array_map(
                fn (string $field): ?string => $locked->getAttribute($field)?->value,
                array_combine(self::WEIGHT_FIELDS, self::WEIGHT_FIELDS),
            );

            $datesBefore = self::orderedDays($locked);

            self::assertTeamsStillFit($locked, $data);

            $locked->fill($data);

            // "Tanggal mulai" before "Batas daftar" is refused (Q1-04). A lomba that is already in the catalog
            // keeps whatever order it has while those two dates are left alone, so editing its other fields
            // is never blocked by old data.
            if (($locked->status !== CompetitionStatus::Dipublikasikan || self::orderedDays($locked) !== $datesBefore)
                && CompetitionDates::startsBeforeDeadline($locked->starts_on, $locked->registration_closes_at)) {
                throw ValidationException::withMessages(['starts_on' => CompetitionDates::STARTS_BEFORE_DEADLINE]);
            }

            if ($files['juknis'] !== null) {
                $locked->juknis_file_id = $files['juknis']->getKey();
            }

            if ($files['cover'] !== null) {
                $locked->cover_file_id = $files['cover']->getKey();
            } elseif ($removeCover) {
                $locked->cover_file_id = null;
            }

            if ($locked->status === CompetitionStatus::Dipublikasikan) {
                $missing = VerifyCompetition::missingFields($locked);

                if ($missing !== []) {
                    throw ValidationException::withMessages(VerifyCompetition::blockingMessages(
                        $missing,
                        'status',
                        'Lomba yang sudah tampil di katalog harus lengkap: '.implode(', ', $missing).'.',
                    ));
                }
            }

            $locked->save();

            $after = array_map(
                fn (string $field): ?string => $locked->getAttribute($field)?->value,
                array_combine(self::WEIGHT_FIELDS, self::WEIGHT_FIELDS),
            );

            if ($before !== $after) {
                activity('competition')
                    ->performedOn($locked)
                    ->causedBy($actor)
                    ->event('competition.weights_changed')
                    ->withProperties(['old' => $before, 'attributes' => $after, 'status' => $locked->status->value])
                    ->log('competition.weights_changed');
            }

            return [$locked, self::factsChanged($actor, $locked, $factsBefore)];
        }));

        $competition->setRawAttributes($result->getAttributes(), true);

        // The public pages show the name, bidang, penyelenggara and school year (final_on) of a lomba with verified
        // achievements and count lomba per school year: drop their cached results, or a correction stayed hidden for as
        // long as the cache lives (QA9). Cheap, so after every update.
        PublicAchievements::flush();

        self::tell($result, $audience);

        return $result;
    }

    /**
     * A published lomba whose facts changed (batas daftar, tanggal, pelaksanaan, tempat, peserta, links, juknis): who
     * has to hear about it, and the audit row of the change with counts only, no names or account ids. The row is
     * written with zero counts when nobody is attached, so the log shows that nobody needed telling. Null = nothing
     * to tell: the lomba still waits in the queue, or no fact changed (a correction of another field).
     *
     * @param  array<string, string|null>  $factsBefore  CompetitionChange::snapshot() of the locked row before the save
     * @return array{groups: list<array{registration: string, users: Collection<int, User>, lines: list<string>}>, mentors: list<array{user: User, lines: list<string>, registration: ?string}>, students: int, mentorCount: int, mail: bool}|null
     */
    private static function factsChanged(User $actor, Competition $locked, array $factsBefore): ?array
    {
        if ($locked->status !== CompetitionStatus::Dipublikasikan) {
            return null;
        }

        $factsAfter = CompetitionChange::snapshot($locked);
        $facts = CompetitionChange::changed($factsBefore, $factsAfter);

        if ($facts === []) {
            return null;
        }

        $audience = CompetitionChange::audience($locked, $facts, $actor, CompetitionChange::finalChanged($factsBefore, $factsAfter));

        activity('competition')
            ->performedOn($locked)
            ->causedBy($actor)
            ->event('competition.facts_changed')
            ->withProperties([
                'changed' => $facts,
                'students' => $audience['students'],
                'mentors' => $audience['mentorCount'],
                'mail' => $audience['mail'],
            ])
            ->log('competition.facts_changed');

        return $audience;
    }

    /**
     * After the commit: one notice per student of each running registration and one per guru pembimbing, by mail
     * and bell, or by the bell alone above CompetitionChange::MAIL_LIMIT recipients. A refused save never gets here.
     *
     * @param  array{groups: list<array{registration: string, users: Collection<int, User>, lines: list<string>}>, mentors: list<array{user: User, lines: list<string>, registration: ?string}>, students: int, mentorCount: int, mail: bool}|null  $audience
     */
    private static function tell(Competition $competition, ?array $audience): void
    {
        if ($audience === null) {
            return;
        }

        foreach ($audience['groups'] as $group) {
            Notification::send($group['users'], CompetitionChanged::for($competition, $group['registration'], $group['lines'], $audience['mail']));
        }

        foreach ($audience['mentors'] as $mentor) {
            $mentor['user']->notify(BinaanCompetitionChanged::for($competition, $mentor['lines'], $mentor['registration'], $audience['mail']));
        }
    }

    /**
     * Teams that are still running keep the rules they registered under (QA10): while a team registration of this
     * lomba is not final (not ditolak, selesai or dibatalkan), the lomba cannot become "individu" and "Maks anggota"
     * cannot go down (or get a limit where it had none). Before, such a change left teams in an individu lomba or
     * teams larger than the new limit. Individu registrations are not affected by either change.
     *
     * @param  array<string, mixed>  $data  normalized form values
     */
    private static function assertTeamsStillFit(Competition $locked, array $data): void
    {
        $oldParticipation = $locked->participation;
        $newParticipation = Participation::tryFrom((string) ($data['participation'] ?? ''));
        $toIndividu = $newParticipation === Participation::Individu && $oldParticipation !== Participation::Individu;

        $oldMax = $locked->max_team_size;
        $newMax = $data['max_team_size'] ?? null;
        $lowerMax = ! $toIndividu && $newMax !== null && ($oldMax === null || $newMax < $oldMax);

        if (! $toIndividu && ! $lowerMax) {
            return;
        }

        $runningTeams = $locked->registrations()
            ->nonFinal()
            ->where('type', RegistrationType::Tim)
            ->exists();

        if ($runningTeams) {
            throw ValidationException::withMessages($toIndividu
                ? ['participation' => self::TEAMS_RUNNING_PARTICIPATION]
                : ['max_team_size' => self::TEAMS_RUNNING_MAX]);
        }
    }

    /** @return array{0: ?string, 1: ?string} the days of "Tanggal mulai" and "Batas daftar" (the time of day is not edited) */
    private static function orderedDays(Competition $competition): array
    {
        return [$competition->starts_on?->toDateString(), $competition->registration_closes_at?->toDateString()];
    }
}
