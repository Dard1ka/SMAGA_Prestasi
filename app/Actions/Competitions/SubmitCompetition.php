<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Domain\Competitions\OrganizerWeight;
use App\Enums\CompetitionStatus;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\Permission;
use App\Models\Competition;
use App\Models\User;
use App\Notifications\Competitions\CompetitionSubmitted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Usulan lomba by a student (Siswa › Usulkan lomba, D-145; Admin uses CreateCompetition). The whole lomba, with the
 * same field rules as the Kelola lomba drawer (CreateCompetition::rules(), CompetitionDates, D-98), plus what Kesiswaan
 * needs to check and publish it (REQUIRED) and the juknis/brosur/undangan (D-147). Always lands in
 * menunggu_verifikasi (D-27) with submitted_by = actor.
 *
 * Kategori bobot and status ajang are computed from the jenis penyelenggara and the tick "Ajang Puspresnas"
 * (OrganizerWeight, D-146), never taken from the input; the default guru pembimbing is Kesiswaan's to choose.
 * Files go through App\Domain\Files (private, never public before verification) and are discarded when the save
 * fails. Limit: 5 per 24 h per user (SECURITY §3d/§3m-2). The links are cleaned at both ends, validated (a refusal
 * names its reason, ExternalUrl::problem()) and never fetched (§3m-1).
 */
final class SubmitCompetition
{
    public const DAILY_LIMIT = 5;

    /**
     * What a student must fill: what Kesiswaan checks a lomba against and publishing needs. Also required: the typed
     * kind for "Lainnya" (D-125) and "Maks anggota" when teams may register.
     */
    public const REQUIRED = [
        'name', 'bidang', 'level', 'organizer_name', 'organizer_type', 'mode', 'location', 'registration_closes_at',
        'final_on', 'participation', 'website_url',
    ];

    /** Input a student never sets: the weights (computed, D-146) and the default guru pembimbing (Kesiswaan's). */
    private const NOT_FROM_STUDENT = [...CreateCompetition::WEIGHT_FIELDS, 'default_mentor_id', 'puspresnas'];

    /**
     * @param  array<string, mixed>  $data  CompetitionFields::toAttributes() shape (+ `puspresnas`)
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data, ?UploadedFile $juknis = null, ?UploadedFile $cover = null): Competition
    {
        Gate::forUser($actor)->authorize('create', Competition::class);

        $puspresnas = filter_var($data['puspresnas'] ?? false, FILTER_VALIDATE_BOOL);
        $input = CreateCompetition::prepare(Arr::except($data, self::NOT_FROM_STUDENT));

        // A lomba without a start date is a one-day lomba (D-147: "1 hari" writes one date into both fields).
        if (($input['starts_on'] ?? null) === null && ($input['final_on'] ?? null) !== null) {
            $input['starts_on'] = $input['final_on'];
        }

        $validator = Validator::make($input, self::rules($input), CreateCompetition::messages());
        // D-98 and the juknis are named together with the other field errors.
        $validator->after(CompetitionDates::checkOrder(...));
        $validator->after(CreateCompetition::requireJuknis($juknis));
        $clean = CreateCompetition::normalize($validator->validate());

        $this->assertBelowDailyLimit($actor);

        $weight = OrganizerWeight::for(OrganizerType::from((string) $clean['organizer_type']), $puspresnas);
        $files = CreateCompetition::storeFiles($actor, $juknis, $cover);

        $competition = CreateCompetition::discardOnFailure($files, fn (): Competition => DB::transaction(function () use ($actor, $clean, $files, $weight): Competition {
            // Serialise concurrent submissions of one user, then re-check the limit.
            User::query()->whereKey($actor->getKey())->lockForUpdate()->first();
            $this->assertBelowDailyLimit($actor);

            $competition = new Competition([
                ...Arr::except($clean, CreateCompetition::WEIGHT_FIELDS),
                'juknis_file_id' => $files['juknis']?->getKey(),
                'cover_file_id' => $files['cover']?->getKey(),
            ]);
            $competition->forceFill([
                'weight_category' => $weight->category,
                'event_status' => $weight->eventStatus,
                'status' => CompetitionStatus::MenungguVerifikasi,
                'submitted_by' => $actor->getKey(),
            ])->save();

            activity('competition')
                ->performedOn($competition)
                ->causedBy($actor)
                ->event('competition.submitted')
                ->withProperties([
                    'has_brochure' => true,
                    'weight_category' => $weight->category->value,
                    'event_status' => $weight->eventStatus->value,
                ])
                ->log('competition.submitted');

            return $competition;
        }));

        $verifiers = User::query()
            ->canLogIn()
            ->permission(Permission::CompetitionVerify->value)
            ->whereKeyNot($actor->getKey())
            ->get();

        Notification::send($verifiers, new CompetitionSubmitted($competition, (string) $actor->name));

        return $competition;
    }

    /**
     * CreateCompetition::rules() with `required` for every field a student must fill (REQUIRED), the typed kind for
     * "Lainnya" and "Maks anggota" for a lomba teams may join.
     *
     * @param  array<string, mixed>  $input  the prepared input
     * @return array<string, list<mixed>>
     */
    private static function rules(array $input): array
    {
        $rules = Arr::except(CreateCompetition::rules(), self::NOT_FROM_STUDENT);
        $required = self::REQUIRED;

        if (($input['organizer_type'] ?? null) === OrganizerType::Lainnya->value) {
            $required[] = 'organizer_type_other';
        }

        if (in_array($input['participation'] ?? null, [Participation::Tim->value, Participation::Keduanya->value], true)) {
            $required[] = 'max_team_size';
        }

        foreach ($required as $field) {
            $rules[$field] = ['required', ...array_values(array_filter($rules[$field], fn (mixed $rule): bool => ! in_array($rule, ['nullable', 'required'], true)))];
        }

        return $rules;
    }

    private function assertBelowDailyLimit(User $actor): void
    {
        $recent = Competition::withTrashed()
            ->where('submitted_by', $actor->getKey())
            ->where('created_at', '>=', now()->subDay())
            ->count();

        if ($recent >= self::DAILY_LIMIT) {
            throw ValidationException::withMessages(['name' => 'Sudah '.self::DAILY_LIMIT.' kali mengirim hari ini. Coba lagi besok.']);
        }
    }
}
