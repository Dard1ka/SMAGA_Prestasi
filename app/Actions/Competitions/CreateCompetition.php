<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Models\Competition;
use App\Models\File;
use App\Models\User;
use App\Notifications\Competitions\CompetitionSubmitted;
use App\Rules\ExternalUrl;
use App\Rules\NoWebAddress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Kesiswaan/Admin "Tambah lomba" (UI-MAP #16): the full catalog form incl. the weight fields (D-02).
 * Even this entry starts at `menunggu_verifikasi` and appears in the verification queue (D-27);
 * VerifyCompetition publishes it. Juknis/brosur and cover go through App\Domain\Files (private disk).
 * Siswa/guru usulan use W2-A1's SubmitCompetition instead.
 *
 * Dates (M2-B, Q1-04): besides the per-field rules, "Tanggal mulai" may not lie before "Batas daftar"
 * (CompetitionDates). Dates in the past are accepted here, since old lomba are entered for the record;
 * VerifyCompetition asks for a confirmation before such an entry is published.
 */
final class CreateCompetition
{
    /**
     * @param  array<string, mixed>  $input  form fields (see rules())
     */
    public function handle(User $actor, array $input, ?UploadedFile $juknis = null, ?UploadedFile $cover = null): Competition
    {
        Gate::forUser($actor)->authorize('create', Competition::class);
        Gate::forUser($actor)->authorize('manage', Competition::class);

        $validator = Validator::make($input, self::rules(), self::messages());
        // "Tanggal mulai" before "Batas daftar" is refused together with the other field errors (Q1-04).
        $validator->after(CompetitionDates::checkOrder(...));
        $data = self::normalize($validator->validate());

        $juknisFile = $juknis !== null ? self::storeFile($actor, $juknis, FilePurpose::Juknis) : null;
        $coverFile = $cover !== null ? self::storeFile($actor, $cover, FilePurpose::Cover) : null;

        $competition = DB::transaction(function () use ($actor, $data, $juknisFile, $coverFile): Competition {
            $competition = new Competition([
                ...$data,
                'juknis_file_id' => $juknisFile?->getKey(),
                'cover_file_id' => $coverFile?->getKey(),
            ]);

            $competition->forceFill([
                'status' => CompetitionStatus::MenungguVerifikasi,
                'submitted_by' => $actor->getKey(),
            ])->save();

            activity('competition')
                ->performedOn($competition)
                ->causedBy($actor)
                ->event('competition.created')
                ->withProperties([
                    'level' => $data['level'] ?? null,
                    'weight_category' => $data['weight_category'] ?? null,
                    'event_status' => $data['event_status'] ?? null,
                ])
                ->log('competition.created');

            return $competition;
        });

        // Same as a siswa/guru usulan (SubmitCompetition): the entry waits in the queue, so the other verifiers
        // hear about it (e.g. Kesiswaan when the Admin adds a lomba). The actor is not mailed.
        $verifiers = User::query()
            ->canLogIn()
            ->permission(Permission::CompetitionVerify->value)
            ->whereKeyNot($actor->getKey())
            ->get();

        Notification::send($verifiers, new CompetitionSubmitted($competition, (string) $actor->name));

        return $competition;
    }

    /**
     * Juknis/brosur (PDF or image, 5 MB) and cover (image, 3 MB) through App\Domain\Files onto the private
     * disk. Upload problems become validation errors on "juknis" / "cover".
     */
    public static function storeFile(User $actor, UploadedFile $upload, FilePurpose $purpose): File
    {
        try {
            return app(StoreUpload::class)->store($upload, $purpose, $actor);
        } catch (InvalidUpload $e) {
            throw $e->toValidationException($purpose === FilePurpose::Cover ? 'cover' : 'juknis');
        }
    }

    /**
     * Field rules shared by Create/UpdateCompetition and the Livewire drawer (prefix them with "form.").
     * Everything except name + bidang may stay empty while queued; VerifyCompetition::REQUIRED_TO_PUBLISH
     * decides what publishing needs.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200', new NoWebAddress],
            'description' => ['nullable', 'string', 'max:5000'],
            'bidang' => ['required', Rule::enum(Bidang::class)],
            'organizer_name' => ['nullable', 'string', 'max:200'],
            'organizer_type' => ['nullable', Rule::enum(OrganizerType::class)],
            'weight_category' => ['nullable', Rule::enum(WeightCategory::class)],
            'event_status' => ['nullable', Rule::enum(EventStatus::class)],
            'level' => ['nullable', Rule::enum(Level::class)],
            'mode' => ['nullable', Rule::enum(EventMode::class)],
            'registration_opens_at' => ['nullable', 'date_format:Y-m-d'],
            'registration_closes_at' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:registration_opens_at'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'final_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'location' => ['nullable', 'string', 'max:200'],
            'participation' => ['nullable', Rule::enum(Participation::class)],
            'max_team_size' => ['nullable', 'integer', 'min:2', 'max:20'],
            'default_mentor_id' => ['nullable', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! User::query()->whereKey($value)->role(RoleName::Guru->value)->canLogIn()->exists()) {
                    $fail('Pilih guru pembimbing dari daftar.');
                }
            }],
            'website_url' => ['nullable', 'string', 'max:'.ExternalUrl::MAX_LENGTH, new ExternalUrl],
            'registration_url' => ['nullable', 'string', 'max:'.ExternalUrl::MAX_LENGTH, new ExternalUrl],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'required' => 'Wajib diisi.',
            'max' => 'Terlalu panjang.',
            'enum' => 'Pilih salah satu opsi.',
            'date_format' => 'Tanggal tidak valid.',
            'registration_closes_at.after_or_equal' => 'Batas daftar tidak boleh sebelum tanggal dibuka.',
            'final_on.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'integer' => 'Isi dengan angka.',
            'max_team_size.min' => 'Minimal 2 anggota.',
            'max_team_size.max' => 'Maksimal 20 anggota.',
            'url' => 'Isi alamat lengkap yang diawali https://.',
        ];
    }

    /**
     * Validated form values → model attributes (empty strings → null, dates → timestamps, individu → no team size).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $data[$key] = $value === '' ? null : $value;
            }
        }

        if (($data['registration_opens_at'] ?? null) !== null) {
            $data['registration_opens_at'] .= ' 00:00:00';
        }

        if (($data['registration_closes_at'] ?? null) !== null) {
            $data['registration_closes_at'] .= ' 23:59:59';
        }

        if (($data['participation'] ?? null) === Participation::Individu->value) {
            $data['max_team_size'] = null;
        }

        if (array_key_exists('max_team_size', $data) && $data['max_team_size'] !== null) {
            $data['max_team_size'] = (int) $data['max_team_size'];
        }

        if (array_key_exists('default_mentor_id', $data) && $data['default_mentor_id'] !== null) {
            $data['default_mentor_id'] = (int) $data['default_mentor_id'];
        }

        return array_intersect_key($data, array_flip(array_keys(self::rules())));
    }
}
