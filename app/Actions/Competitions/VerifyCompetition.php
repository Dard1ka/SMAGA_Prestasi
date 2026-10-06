<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Enums\CompetitionStatus;
use App\Enums\Participation;
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
 * M2-B (QA 2026-10-05): publishing refuses "Tanggal mulai" before "Batas daftar", and a lomba whose registration
 * deadline or final date has already passed is published only with `$confirmPastDates` (Q1-04: old lomba are
 * entered for the record, so the answer is a question, not a ban). The refusal carries the key
 * CompetitionDates::PAST_KEY; the confirmation is audited (`past_dates`).
 */
final class VerifyCompetition
{
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
        'registration_closes_at' => 'Batas daftar',
        'starts_on' => 'Tanggal mulai',
        'final_on' => 'Tanggal final',
        'location' => 'Tempat',
        'participation' => 'Peserta',
    ];

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

        $result = DB::transaction(function () use ($actor, $competition, $decision, $note, $confirmPastDates): Competition {
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
                ])
                ->log($decision === CompetitionStatus::Dipublikasikan ? 'competition.published' : 'competition.rejected');

            return $locked;
        });

        $competition->setRawAttributes($result->getAttributes(), true);

        $submitter = $result->submitter()->first();

        if ($submitter !== null && ! $submitter->is($actor) && $submitter->canLogIn()) {
            $submitter->notify($decision === CompetitionStatus::Dipublikasikan
                ? CompetitionPublished::for($result, $submitter->hasRole(RoleName::Siswa->value))
                : CompetitionRejected::for($result, $note));
        }

        return $result;
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

    private static function cleanNote(?string $note): ?string
    {
        $note = trim((string) $note);

        if (mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => 'Catatan maksimal 1.000 karakter.']);
        }

        return $note === '' ? null : $note;
    }
}
