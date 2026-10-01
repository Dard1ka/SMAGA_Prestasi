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
 * waits in `menunggu_verifikasi` until this Action publishes it (`dipublikasikan`) or rejects it (`ditolak`,
 * note required). Publishing needs the fields that drive points and deadlines (a siswa usulan may lack
 * them; Kesiswaan fills them with UpdateCompetition first). Verifier + time are stored and audited; the
 * submitter is notified (queued) unless they decided themselves. UI-MAP's RejectCompetition = Ditolak here.
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

    public function handle(User $actor, Competition $competition, CompetitionStatus $decision, ?string $note = null): Competition
    {
        Gate::forUser($actor)->authorize('verify', $competition);

        if (! in_array($decision, [CompetitionStatus::Dipublikasikan, CompetitionStatus::Ditolak], true)) {
            throw ValidationException::withMessages(['decision' => 'Keputusan tidak valid.']);
        }

        $note = self::cleanNote($note);

        if ($decision === CompetitionStatus::Ditolak && $note === null) {
            throw ValidationException::withMessages(['note' => 'Tulis alasan penolakan.']);
        }

        $result = DB::transaction(function () use ($actor, $competition, $decision, $note): Competition {
            /** @var Competition $locked */
            $locked = Competition::query()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== CompetitionStatus::MenungguVerifikasi) {
                throw ValidationException::withMessages(['decision' => 'Lomba ini sudah diputuskan.']);
            }

            if ($decision === CompetitionStatus::Dipublikasikan) {
                $missing = self::missingFields($locked);

                if ($missing !== []) {
                    throw ValidationException::withMessages([
                        'decision' => 'Lengkapi dulu: '.implode(', ', $missing).'.',
                    ]);
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
                ])
                ->log($decision === CompetitionStatus::Dipublikasikan ? 'competition.published' : 'competition.rejected');

            return $locked;
        });

        $competition->setRawAttributes($result->getAttributes(), true);

        $submitter = $result->submitter()->first();

        if ($submitter !== null && ! $submitter->is($actor) && $submitter->canLogIn()) {
            $submitter->notify($decision === CompetitionStatus::Dipublikasikan
                ? CompetitionPublished::for($result, $submitter->hasRole(RoleName::Siswa->value))
                : CompetitionRejected::for($result, (string) $note));
        }

        return $result;
    }

    /**
     * Labels of the fields that still block publishing (empty = ready).
     *
     * @return list<string>
     */
    public static function missingFields(Competition $competition): array
    {
        $missing = [];

        foreach (self::REQUIRED_TO_PUBLISH as $field => $label) {
            $value = $competition->getAttribute($field);

            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $label;
            }
        }

        if ($competition->participation instanceof Participation
            && $competition->participation !== Participation::Individu
            && ($competition->max_team_size === null || $competition->max_team_size < 2)) {
            $missing[] = 'Maks anggota';
        }

        return $missing;
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
