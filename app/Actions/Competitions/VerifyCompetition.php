<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Actions\Registrations\ResumeWaitingRegistrations;
use App\Domain\Competitions\OrganizerWeight;
use App\Enums\CompetitionStatus;
use App\Enums\EventStatus;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Models\Competition;
use App\Models\User;
use App\Notifications\Competitions\CompetitionPublished;
use App\Notifications\Competitions\CompetitionRejected;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Kesiswaan/Admin decides on a queued lomba (D-27): every upload from any role, Kesiswaan's own included,
 * waits in `menunggu_verifikasi` until this Action publishes it (`dipublikasikan`) or rejects it (`ditolak`).
 * Publishing needs the fields that drive points and deadlines (a siswa usulan may lack
 * them; Kesiswaan fills them with UpdateCompetition first). Verifier + time are stored and audited; the
 * submitter is notified (queued) unless they decided themselves. UI-MAP's RejectCompetition = Ditolak here.
 *
 * M3 (the lead, 2026-10-06, D-103): the rejection reason is optional. No reason and a short one are both
 * accepted (this takes back the "note required" rule and the 10-character minimum of D-95 for a lomba
 * rejection); the submitter is told either way, with the reason only when one was written.
 *
 * M2-B (QA 2026-10-05): publishing refuses "Tanggal mulai" before "Pendaftaran ditutup", and a lomba whose registration
 * deadline or final date has already passed is published only with `$confirmPastDates` (Q1-04: old lomba are
 * entered for the record, so the answer is a question, not a ban). The refusal carries the key
 * CompetitionDates::PAST_KEY; the confirmation is audited (`past_dates`).
 *
 * D-125 (the lead, 2026-10-09): a lomba whose "Jenis penyelenggara" is "Lainnya" is published only once the kind
 * was typed (`organizer_type_other`); the queue may hold it without.
 *
 * FS1 Paket B: publishing needs the juknis (D-147), and both decisions audit `weight_override` when the kategori
 * bobot or status ajang differs from what the jenis penyelenggara gives (OrganizerWeight, D-146; the spec's event name
 * "competition.verified" is these two events).
 *
 * D-148: the submitter's registrations that waited for this lomba (`menunggu_lomba`) move in the same transaction
 * (ResumeWaitingRegistrations): published → on to the approvers or the team invitations, rejected → cancelled. The
 * submitter's one decision notice names what happened to the own registration; the other notices follow the commit.
 */
final class VerifyCompetition
{
    public function __construct(private readonly ResumeWaitingRegistrations $resume) {}

    /** Field → label for the "Lengkapi dulu" message (order = form order). */
    public const REQUIRED_TO_PUBLISH = [
        'name' => 'Nama lomba',
        'bidang' => 'Bidang',
        'organizer_name' => 'Penyelenggara',
        'organizer_type' => 'Jenis penyelenggara',
        'weight_category' => 'Kategori bobot',
        'event_status' => 'Status ajang',
        'mode' => 'Pelaksanaan',
        'level' => 'Tingkat',
        'registration_closes_at' => 'Pendaftaran ditutup',
        'starts_on' => 'Tanggal mulai',
        'final_on' => 'Tanggal selesai',
        'location' => 'Tempat',
        'participation' => 'Peserta',
        // D-147: every published lomba carries its juknis, brosur or undangan.
        'juknis_file_id' => 'Juknis / brosur / undangan',
    ];

    /**
     * Label of `organizer_type_other`: required only while "Jenis penyelenggara" is "Lainnya" (D-125), so it is not
     * in REQUIRED_TO_PUBLISH; missingFields() adds it right after the type.
     */
    public const OTHER_TYPE_LABEL = 'Jenis penyelenggara lainnya';

    public function handle(
        User $actor,
        Competition $competition,
        CompetitionStatus $decision,
        ?string $note = null,
        bool $confirmPastDates = false,
    ): Competition {
        Gate::forUser($actor)->authorize('verify', $competition);

        if (! in_array($decision, [CompetitionStatus::Dipublikasikan, CompetitionStatus::Ditolak], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak valid.']);
        }

        $note = self::cleanNote($note);

        [$result, $outcome] = DB::transaction(function () use ($actor, $competition, $decision, $note, $confirmPastDates): array {
            /** @var Competition $locked */
            $locked = Competition::query()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== CompetitionStatus::MenungguVerifikasi) {
                throw ValidationException::withMessages(['decision' => 'Lomba ini sudah diputuskan.']);
            }

            $pastDates = [];

            if ($decision === CompetitionStatus::Dipublikasikan) {
                $missing = self::missingFields($locked);

                if ($missing !== []) {
                    throw ValidationException::withMessages(self::blockingMessages(
                        $missing,
                        'decision',
                        'Lengkapi dulu: '.implode(', ', $missing).'.',
                    ));
                }

                // Entries queued before the rule existed can still carry this order.
                if (CompetitionDates::startsBeforeDeadline($locked->starts_on, $locked->registration_closes_at)) {
                    throw ValidationException::withMessages(['starts_on' => CompetitionDates::STARTS_BEFORE_DEADLINE]);
                }

                $pastDates = CompetitionDates::past($locked->registration_closes_at, $locked->final_on);

                if ($pastDates !== [] && ! $confirmPastDates) {
                    throw ValidationException::withMessages([CompetitionDates::PAST_KEY => CompetitionDates::pastMessage($pastDates)]);
                }
            }

            $locked->forceFill([
                'status' => $decision,
                'verified_by' => $actor->getKey(),
                'verified_at' => now(),
                'verification_note' => $note,
            ])->save();

            activity('competition')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event($decision === CompetitionStatus::Dipublikasikan ? 'competition.published' : 'competition.rejected')
                ->withProperties([
                    'submitted_by' => $locked->submitted_by,
                    'level' => $locked->level?->value,
                    'weight_category' => $locked->weight_category?->value,
                    'event_status' => $locked->event_status?->value,
                    'note' => $note,
                    // Published although these dates had passed: the verifier confirmed it (empty = no question asked).
                    'past_dates' => array_keys($pastDates),
                    // D-146: a weight that differs from what the jenis penyelenggara gives (null = it follows the map).
                    'weight_override' => self::weightOverride($locked),
                ])
                ->log($decision === CompetitionStatus::Dipublikasikan ? 'competition.published' : 'competition.rejected');

            return [$locked, $this->resume->handle($actor, $locked, $decision)];
        });

        $competition->setRawAttributes($result->getAttributes(), true);

        $submitter = $result->submitter()->first();

        if (self::notifiesSubmitter($submitter, $actor)) {
            $submitter->notify($decision === CompetitionStatus::Dipublikasikan
                ? CompetitionPublished::for($result, $submitter->hasRole(RoleName::Siswa->value), $outcome->submitterNow)
                : CompetitionRejected::for($result, $note, $outcome->submitterNow === RegistrationStatus::Dibatalkan));
        }

        $this->resume->notify($outcome, $actor);

        return $result;
    }

    /**
     * Is the submitter told about the decision? Not the verifier's own entry, not an account that can no longer log
     * in. The screen asks the same before its toast says "Pengirim diberi tahu" (QA5).
     */
    public static function notifiesSubmitter(?User $submitter, User $actor): bool
    {
        return $submitter !== null && ! $submitter->is($actor) && $submitter->canLogIn();
    }

    /**
     * Fields that still block publishing, as field => label (empty = ready). Keyed so the caller can mark
     * each input, not just name it in a sentence the reader then has to match against the form by eye.
     *
     * @return array<string, string>
     */
    public static function missingFields(Competition $competition): array
    {
        $missing = [];

        foreach (self::REQUIRED_TO_PUBLISH as $field => $label) {
            $value = $competition->getAttribute($field);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[$field] = $label;
            }

            // "Lainnya" alone says nothing about the organizer: the kind has to be typed (D-125).
            if ($field === 'organizer_type' && $value === OrganizerType::Lainnya
                && trim((string) $competition->getAttribute('organizer_type_other')) === '') {
                $missing['organizer_type_other'] = self::OTHER_TYPE_LABEL;
            }
        }

        if ($competition->participation instanceof Participation
            && $competition->participation !== Participation::Individu
            && ($competition->max_team_size === null || $competition->max_team_size < 2)) {
            $missing['max_team_size'] = 'Maks anggota';
        }

        return $missing;
    }

    /**
     * Summary message plus one message per missing field, so the form can mark every input it names.
     *
     * @param  array<string, string>  $missing
     * @return array<string, string>
     */
    public static function blockingMessages(array $missing, string $summaryKey, string $summary): array
    {
        $messages = [$summaryKey => $summary];

        foreach ($missing as $field => $label) {
            $messages[$field] = 'Wajib diisi sebelum tampil di katalog.';
        }

        return $messages;
    }

    /**
     * The weight Kesiswaan chose next to the one the map gives (OrganizerWeight, D-146), for the audit row of the
     * decision; null when they agree, the lomba has no jenis penyelenggara or no weight was chosen yet (an incomplete
     * entry that is rejected). A lomba stored before D-146 is not recomputed, so its hand-typed weight may show here
     * as a difference from the map.
     *
     * @return array{computed: array{weight_category: string, event_status: string}, chosen: array{weight_category: ?string, event_status: ?string}}|null
     */
    private static function weightOverride(Competition $competition): ?array
    {
        $type = $competition->organizer_type;

        if (! $type instanceof OrganizerType
            || $competition->weight_category === null
            || $competition->event_status === null
            || ! OrganizerWeight::isOverride($type, $competition->weight_category, $competition->event_status)) {
            return null;
        }

        $mapped = OrganizerWeight::for($type, $competition->event_status === EventStatus::Puspresnas);

        return [
            'computed' => ['weight_category' => $mapped->category->value, 'event_status' => $mapped->eventStatus->value],
            'chosen' => ['weight_category' => $competition->weight_category?->value, 'event_status' => $competition->event_status?->value],
        ];
    }

    private static function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);

        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 1.000 karakter.']);
        }

        return $note === '' ? null : $note;
    }
}
