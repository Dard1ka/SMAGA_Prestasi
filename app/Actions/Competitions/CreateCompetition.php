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
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Throwable;

/**
 * Admin "Tambah lomba" (UI-MAP #16, D-145: Admin only; Kesiswaan checks and corrects): the full catalog form incl. the
 * weight fields (D-02). Even this entry starts at `menunggu_verifikasi` and appears in the verification queue (D-27);
 * VerifyCompetition publishes it. The juknis/brosur/undangan is required (D-147); it and the cover go through
 * App\Domain\Files (private disk). A student's usulan uses SubmitCompetition, which shares these rules.
 *
 * Dates (M2-B, Q1-04): besides the per-field rules, "Tanggal mulai" may not lie before "Pendaftaran ditutup"
 * (CompetitionDates). Dates in the past are accepted here, since old lomba are entered for the record;
 * VerifyCompetition asks for a confirmation before such an entry is published.
 */
final class CreateCompetition
{
    /** Longest "Jenis penyelenggara lainnya" (`competitions.organizer_type_other`, D-125). */
    public const ORGANIZER_TYPE_OTHER_MAX = 60;

    /** "Website resmi" and "Link registrasi": cleaned in prepare(), checked by ExternalUrl. */
    private const LINK_FIELDS = ['website_url', 'registration_url'];

    /**
     * Kategori bobot + status ajang: not mass-assignable (CLAUDE §3.6). The Actions split them out of the validated data
     * and set them with forceFill(); SubmitCompetition never takes them from the input (D-146).
     */
    public const WEIGHT_FIELDS = ['weight_category', 'event_status'];

    /** Every lomba carries its juknis, brosur or undangan (D-147): new ones, re-saved ones and published ones. */
    public const JUKNIS_MISSING = 'Upload juknis, brosur, atau undangan.';

    /**
     * @param  array<string, mixed>  $input  form fields (see rules())
     */
    public function handle(User $actor, array $input, ?UploadedFile $juknis = null, ?UploadedFile $cover = null): Competition
    {
        Gate::forUser($actor)->authorize('create', Competition::class);
        Gate::forUser($actor)->authorize('manage', Competition::class);

        $validator = Validator::make(self::prepare($input), self::rules(), self::messages());
        // "Tanggal mulai" before "Pendaftaran ditutup" is refused together with the other field errors (Q1-04), and so is
        // a lomba without its juknis (D-147).
        $validator->after(CompetitionDates::checkOrder(...));
        $validator->after(self::requireJuknis($juknis));
        $data = self::normalize($validator->validate());

        $files = self::storeFiles($actor, $juknis, $cover);

        $competition = self::discardOnFailure($files, fn (): Competition => DB::transaction(function () use ($actor, $data, $files): Competition {
            $competition = new Competition([
                ...Arr::except($data, self::WEIGHT_FIELDS),
                'juknis_file_id' => $files['juknis']?->getKey(),
                'cover_file_id' => $files['cover']?->getKey(),
            ]);

            $competition->forceFill([
                ...Arr::only($data, self::WEIGHT_FIELDS),
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
        }));

        // Same as a siswa usulan (SubmitCompetition): the entry waits in the queue, so the other verifiers
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
     * The "Upload juknis, brosur, atau undangan." check for a new lomba, as a validator `after` hook, so it is named
     * together with the other field errors (D-147).
     *
     * @return Closure(ValidatorInstance): void
     */
    public static function requireJuknis(?UploadedFile $juknis): Closure
    {
        return function (ValidatorInstance $validator) use ($juknis): void {
            if ($juknis === null) {
                $validator->errors()->add('juknis', self::JUKNIS_MISSING);
            }
        };
    }

    /**
     * Juknis/brosur/undangan (PDF or image, 5 MB) and cover (image, 3 MB) through App\Domain\Files onto the private
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
     * Both uploads of the form, stored before the transaction (StoreUpload writes to the disk). A refused cover
     * takes the juknis stored a moment before with it (QA10).
     *
     * @return array{juknis: ?File, cover: ?File}
     */
    public static function storeFiles(User $actor, ?UploadedFile $juknis, ?UploadedFile $cover): array
    {
        $juknisFile = $juknis !== null ? self::storeFile($actor, $juknis, FilePurpose::Juknis) : null;

        try {
            $coverFile = $cover !== null ? self::storeFile($actor, $cover, FilePurpose::Cover) : null;
        } catch (Throwable $e) {
            self::discardFiles(['juknis' => $juknisFile, 'cover' => null]);

            throw $e;
        }

        return ['juknis' => $juknisFile, 'cover' => $coverFile];
    }

    /**
     * Runs the save; when it fails (a refused field, a lomba changed meanwhile, a database error) the files stored
     * for it are discarded, as SubmitCompetition does. Before QA10 they stayed on the disk without a lomba, and the
     * drawer stored one more copy on every retry.
     *
     * @template T
     *
     * @param  array{juknis: ?File, cover: ?File}  $files
     * @param  Closure(): T  $save
     * @return T
     */
    public static function discardOnFailure(array $files, Closure $save): mixed
    {
        try {
            return $save();
        } catch (Throwable $e) {
            self::discardFiles($files);

            throw $e;
        }
    }

    /** @param array{juknis: ?File, cover: ?File} $files */
    private static function discardFiles(array $files): void
    {
        foreach ($files as $file) {
            if ($file instanceof File) {
                app(StoreUpload::class)->discard($file);
            }
        }
    }

    /**
     * The form as the browser sends it, before validation: a cleared field arrives as '' and the date rules
     * (`after_or_equal`) read '' as "now", so "Batas daftar" of an old lomba was refused once "Daftar dibuka" was
     * emptied (QA10). Empty and blank strings become null; everything else is left to the rules.
     *
     * The two link fields are cleaned first (ExternalUrl::normalize(): spaces, line breaks and invisible characters
     * a paste brings along), so the rule checks the text that is stored (lead's mail test, 2026-10-09).
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function prepare(array $input): array
    {
        foreach (self::LINK_FIELDS as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = ExternalUrl::normalize($input[$field]);
            }
        }

        return array_map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : $value, $input);
    }

    /**
     * Field rules shared by Create/UpdateCompetition and the Livewire drawer (prefix them with "form.").
     * Everything except name + bidang may stay empty while queued; VerifyCompetition::REQUIRED_TO_PUBLISH
     * decides what publishing needs.
     *
     * The default guru pembimbing must be an active guru, except the one the lomba already has: a guru who was
     * disabled or left after he was chosen no longer blocks every save and publish of the lomba (QA10, like the
     * guru pembina in Talenta, QA9). `$keepMentorId` = the lomba's current default guru (UpdateCompetition).
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(?int $keepMentorId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:200', new NoWebAddress],
            'description' => ['nullable', 'string', 'max:5000'],
            'bidang' => ['required', Rule::enum(Bidang::class)],
            'organizer_name' => ['nullable', 'string', 'max:200'],
            'organizer_type' => ['nullable', Rule::enum(OrganizerType::class)],
            // The kind typed for "Lainnya" (D-125). Kept only for that type (normalize()); VerifyCompetition asks
            // for it before publishing. Never a weight field.
            'organizer_type_other' => ['nullable', 'string', 'max:'.self::ORGANIZER_TYPE_OTHER_MAX],
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
            'default_mentor_id' => ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail) use ($keepMentorId): void {
                if ($keepMentorId !== null && (int) $value === $keepMentorId) {
                    return;
                }

                if (! User::query()->whereKey($value)->role(RoleName::Guru->value)->canLogIn()->exists()) {
                    $fail('Pilih guru pembimbing dari daftar.');
                }
            }],
            // No "max" here: ExternalUrl names every reason itself, the length included (ExternalUrl::TOO_LONG).
            'website_url' => ['nullable', 'string', new ExternalUrl],
            'registration_url' => ['nullable', 'string', new ExternalUrl],
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
            'registration_closes_at.after_or_equal' => 'Pendaftaran ditutup tidak boleh sebelum pendaftaran dibuka.',
            'final_on.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.',
            'integer' => 'Isi dengan angka.',
            'max_team_size.min' => 'Minimal 2 anggota.',
            'max_team_size.max' => 'Maksimal 20 anggota.',
            'organizer_type_other.max' => 'Maksimal '.self::ORGANIZER_TYPE_OTHER_MAX.' karakter.',
        ];
    }

    /**
     * Validated form values → model attributes (empty strings → null, dates → timestamps, individu → no team size,
     * a type other than "Lainnya" → no typed kind).
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

        // The typed kind belongs to "Lainnya" only (D-125): any other choice drops it, whatever the form still holds
        // (the drawer hides the field but keeps its text until the save).
        if (array_key_exists('organizer_type', $data)) {
            $type = $data['organizer_type'] instanceof OrganizerType
                ? $data['organizer_type']
                : OrganizerType::tryFrom((string) $data['organizer_type']);

            if ($type !== OrganizerType::Lainnya) {
                $data['organizer_type_other'] = null;
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
