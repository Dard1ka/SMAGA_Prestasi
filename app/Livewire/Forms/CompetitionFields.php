<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Domain\Competitions\OrganizerWeight;
use App\Domain\Competitions\OrganizerWeightResult;
use App\Domain\Files\StoreUpload;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\PointFormatter;
use App\Domain\Points\WeightTable;
use App\Enums\Bidang;
use App\Enums\EventMode;
use App\Enums\EventStatus;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\ResultType;
use App\Enums\WeightCategory;
use App\Models\Competition;
use App\Models\WeightVersion;
use App\Rules\ExternalUrl;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Form;

/**
 * Every field of a lomba, shared by Siswa › Usulkan lomba (`Siswa\Lomba\Usulkan`) and the Kelola lomba drawer
 * (`Kesiswaan\Lomba\Form`), drawn by `livewire/forms/competition-fields.blade.php` (D-145…D-147).
 *
 * One source of rules: the field rules stay in the Actions (CreateCompetition::rules(), CompetitionDates,
 * SubmitCompetition), so this object validates only what the Actions never see: the duration choice (with the
 * "Tanggal mulai" it asks for) and the two uploads (StoreUpload::rulesFor()). Action errors come back through
 * addActionErrors().
 *
 * "Durasi lomba" is screen state only (D-147): one day writes one date ("Tanggal lomba" = `final_on`, and `starts_on`
 * the same day); more days write "Tanggal mulai" and "Tanggal selesai". The schema does not change.
 *
 * Weight (D-146): picking the jenis penyelenggara or ticking "Ajang Puspresnas" fills kategori bobot and status ajang
 * from OrganizerWeight. A student never sends them (SubmitCompetition recomputes them); Kesiswaan may change them,
 * which VerifyCompetition audits as an override.
 */
final class CompetitionFields extends Form
{
    public const DURATION_ONE_DAY = 'satu_hari';

    public const DURATION_MULTI_DAY = 'lebih_dari_satu_hari';

    /** The lomba attributes handed to the Actions (= CreateCompetition::rules() keys); files and screen state aside. */
    public const FIELDS = [
        'name', 'description', 'bidang', 'organizer_name', 'organizer_type', 'organizer_type_other', 'weight_category',
        'event_status', 'level', 'mode', 'location', 'registration_opens_at', 'registration_closes_at', 'starts_on',
        'final_on', 'participation', 'max_team_size', 'default_mentor_id', 'website_url', 'registration_url',
    ];

    /** Error keys of the Actions that belong to another field of this form. */
    private const ERROR_FIELDS = ['brosur' => 'juknis', 'juknis_file_id' => 'juknis', 'cover_file_id' => 'cover'];

    public ?string $name = null;

    public ?string $description = null;

    public ?string $bidang = null;

    public ?string $organizer_name = null;

    public ?string $organizer_type = null;

    public ?string $organizer_type_other = null;

    public ?string $weight_category = null;

    public ?string $event_status = null;

    public ?string $level = null;

    public ?string $mode = null;

    public ?string $location = null;

    public ?string $registration_opens_at = null;

    public ?string $registration_closes_at = null;

    public ?string $starts_on = null;

    public ?string $final_on = null;

    public ?string $participation = null;

    public ?string $website_url = null;

    public ?string $registration_url = null;

    /** "Ajang Puspresnas": any jenis penyelenggara becomes kategori A · Puspresnas (D-146). */
    public bool $puspresnas = false;

    /** "Durasi lomba" (screen state only, D-147). */
    public string $duration = self::DURATION_ONE_DAY;

    /**
     * Number fields take what the browser sends: an emptied field arrives as '' (a strict ?int would refuse it before
     * the Action could say "Isi dengan angka."). CreateCompetition::prepare() turns '' into null and checks the rest.
     */
    public int|string|null $max_team_size = null;

    /** The select sends '' for "Belum ditentukan" (see max_team_size). */
    public int|string|null $default_mentor_id = null;

    /** @var TemporaryUploadedFile|null juknis, brosur or undangan (required, D-147) */
    public $juknis = null;

    /** @var TemporaryUploadedFile|null poster (optional, D-118) */
    public $cover = null;

    /** Kelola lomba only: "Hapus poster, pakai gambar bidang" on a lomba that has one. */
    public bool $remove_cover = false;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $juknis = StoreUpload::rulesFor(FilePurpose::Juknis);
        $cover = StoreUpload::rulesFor(FilePurpose::Cover);

        return [
            'duration' => ['required', Rule::in([self::DURATION_ONE_DAY, self::DURATION_MULTI_DAY])],
            // "Lebih dari 1 hari" needs its first day: the Actions would store a lomba without one, which reopens as a
            // one-day lomba (fillFrom()). Screen state, so it is checked here and not in the Actions.
            'starts_on' => ['nullable', 'required_if:duration,'.self::DURATION_MULTI_DAY],
            'juknis' => [
                'nullable', 'file', 'max:'.$juknis->maxKilobytes(),
                'extensions:'.implode(',', $juknis->extensions()),
                'mimetypes:'.implode(',', $juknis->mimeTypes()),
            ],
            'cover' => [
                'nullable', 'file', 'max:'.$cover->maxKilobytes(),
                'extensions:'.implode(',', $cover->extensions()),
                'mimetypes:'.implode(',', $cover->mimeTypes()),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'duration.required' => 'Pilih durasi lomba.',
            'duration.in' => 'Pilih durasi lomba.',
            'starts_on.required_if' => 'Wajib diisi.',
            'juknis.file' => 'File gagal di-upload. Coba lagi.',
            'juknis.max' => 'Ukuran maksimal 5 MB.',
            'juknis.extensions' => 'Pilih PDF, JPG, PNG, atau WEBP.',
            'juknis.mimetypes' => 'Pilih PDF, JPG, PNG, atau WEBP.',
            'cover.file' => 'File gagal di-upload. Coba lagi.',
            'cover.max' => 'Ukuran maksimal 3 MB.',
            'cover.extensions' => 'Pilih gambar JPG, PNG, atau WEBP.',
            'cover.mimetypes' => 'Pilih gambar JPG, PNG, atau WEBP.',
        ];
    }

    /**
     * The lomba as the Actions take it (CreateCompetition::rules() keys plus `puspresnas`). One day writes the one date
     * into both `starts_on` and `final_on`.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $attributes = [];

        foreach (self::FIELDS as $field) {
            $attributes[$field] = $this->{$field};
        }

        if ($this->isOneDay()) {
            $attributes['starts_on'] = $this->final_on;
        }

        $attributes['puspresnas'] = $this->puspresnas;

        return $attributes;
    }

    public function fillFrom(Competition $competition): void
    {
        foreach (self::FIELDS as $field) {
            $value = $competition->getAttribute($field);

            $this->{$field} = match (true) {
                $value instanceof BackedEnum => (string) $value->value,
                $value instanceof DateTimeInterface => $value->format('Y-m-d'),
                default => $value,
            };
        }

        $this->puspresnas = $competition->event_status === EventStatus::Puspresnas;
        $this->duration = $competition->starts_on === null
            || ($competition->final_on !== null && $competition->starts_on->toDateString() === $competition->final_on->toDateString())
            ? self::DURATION_ONE_DAY
            : self::DURATION_MULTI_DAY;
    }

    /**
     * Text as the Actions store it, written back so the fields show it: spaces around the typed text go, the two links
     * are cleaned like ExternalUrl::normalize() does (line breaks and invisible characters of a paste).
     */
    public function cleanText(): void
    {
        foreach (['name', 'description', 'organizer_name', 'organizer_type_other', 'location'] as $field) {
            if (is_string($this->{$field})) {
                $this->{$field} = trim($this->{$field});
            }
        }

        foreach (['website_url', 'registration_url'] as $field) {
            if (is_string($this->{$field})) {
                $this->{$field} = ExternalUrl::normalize($this->{$field});
            }
        }
    }

    public function isOneDay(): bool
    {
        return $this->duration !== self::DURATION_MULTI_DAY;
    }

    /** "Jenis penyelenggara lainnya" shows only for "Lainnya" (D-125); the Action drops it for any other type. */
    public function showsOtherType(): bool
    {
        return $this->organizer_type === OrganizerType::Lainnya->value;
    }

    /** "Maks anggota" shows once a team may register. */
    public function allowsTeams(): bool
    {
        return $this->participation !== null && $this->participation !== '' && $this->participation !== Participation::Individu->value;
    }

    /** Kategori bobot + status ajang from the map, when a jenis penyelenggara is chosen (D-146). */
    public function applyOrganizerWeight(): void
    {
        $mapped = $this->mapped();

        if ($mapped === null) {
            return;
        }

        $this->weight_category = $mapped->category->value;
        $this->event_status = $mapped->eventStatus->value;
    }

    /** What the map gives the chosen jenis penyelenggara and tick (null without a type). */
    public function mapped(): ?OrganizerWeightResult
    {
        $type = OrganizerType::tryFrom((string) $this->organizer_type);

        return $type !== null ? OrganizerWeight::for($type, $this->puspresnas) : null;
    }

    /** Kesiswaan chose another weight than the map (shown as "Diubah dari hasil peta."). */
    public function isOverride(): bool
    {
        return OrganizerWeight::isOverride(
            OrganizerType::tryFrom((string) $this->organizer_type),
            WeightCategory::tryFrom((string) $this->weight_category),
            EventStatus::tryFrom((string) $this->event_status),
        );
    }

    /** "Kategori B · bobot 0,80" from the map and the active weights (null without a type). */
    public function weightLine(WeightTable $weights, PointFormatter $formatter): ?string
    {
        $mapped = $this->mapped();

        return $mapped !== null
            ? $mapped->category->label().' · bobot '.$formatter->formatWeight($weights->organizer($mapped->category))
            : null;
    }

    /**
     * The points of a Juara I with the tingkat and kategori bobot on the form ("Perkiraan poin jika Juara I").
     *
     * @return array{tingkat: ?string, capaian: string, penyelenggara: ?string, total: ?string}
     */
    public function rumus(WeightTable $weights, PointCalculator $calculator, PointFormatter $formatter): array
    {
        $level = Level::tryFrom((string) $this->level);
        $category = WeightCategory::tryFrom((string) $this->weight_category);

        return [
            'tingkat' => $level !== null ? $weights->level($level) : null,
            'capaian' => $weights->result(ResultType::Juara1),
            'penyelenggara' => $category !== null ? $weights->organizer($category) : null,
            'total' => $level !== null && $category !== null
                ? $formatter->format($calculator->breakdown($weights, $level, ResultType::Juara1, $category)->points)
                : null,
        ];
    }

    /**
     * Everything the shared partial draws besides the fields: options of the selects, the weight line, the map, the
     * legend under "Kategori bobot" and the rumus, from the active weight version.
     *
     * @return array{options: array<string, array<int|string, mixed>>, weightLine: ?string, mapped: ?string, weightLegend: string, overridden: bool, rumus: array{tingkat: ?string, capaian: string, penyelenggara: ?string, total: ?string}}
     */
    public function presentation(PointCalculator $calculator, PointFormatter $formatter): array
    {
        $weights = WeightVersion::active()?->toWeightTable() ?? WeightTable::defaults();
        $mapped = $this->mapped();
        $weightLine = $this->weightLine($weights, $formatter);

        return [
            'options' => [
                'bidang' => collect(Bidang::cases())->mapWithKeys(fn (Bidang $b): array => [$b->value => ['label' => $b->label(), 'icon' => $b->cssKey()]])->all(),
                'organizer_type' => OrganizerType::options(),
                'weight_category' => collect(WeightCategory::cases())->mapWithKeys(fn (WeightCategory $w): array => [
                    $w->value => ['label' => $w->value, 'sub' => $formatter->formatWeight($weights->organizer($w))],
                ])->all(),
                'event_status' => EventStatus::options(),
                'mode' => EventMode::options(),
                'level' => collect(array_reverse(Level::cases()))->mapWithKeys(fn (Level $l): array => [$l->value => $l->shortLabel()])->all(),
                'participation' => Participation::options(),
                'duration' => [self::DURATION_ONE_DAY => '1 hari', self::DURATION_MULTI_DAY => 'Lebih dari 1 hari'],
            ],
            'weightLine' => $weightLine,
            // "Kategori C · bobot 0,60 · Non-pemerintah": the staff line "Hasil peta".
            'mapped' => $mapped !== null ? $weightLine.' · '.$mapped->eventStatus->label() : null,
            // Which jenis penyelenggara lands in which category (D-146), the same words as Pengaturan › Bobot poin.
            'weightLegend' => WeightCategory::legend(),
            'overridden' => $this->isOverride(),
            'rumus' => $this->rumus($weights, $calculator, $formatter),
        ];
    }

    /**
     * Puts the errors of an Action on the fields of this form ("form.<field>"): the old usulan key `brosur` and the
     * publish key `juknis_file_id` on the juknis, and in one-day mode a `starts_on` error on the one date shown.
     * Errors that belong to no field (a decision, a status, a question) are returned for the screen to place.
     *
     * @return array<string, list<string>>
     */
    public function addActionErrors(ValidationException $e): array
    {
        $rest = [];
        $fields = [...self::FIELDS, 'juknis', 'cover', 'duration', 'puspresnas'];

        foreach ($e->errors() as $key => $messages) {
            $field = self::ERROR_FIELDS[$key] ?? $key;

            if ($field === 'starts_on' && $this->isOneDay()) {
                $field = 'final_on';
            }

            if (! in_array($field, $fields, true)) {
                $rest[$key] = array_values(array_map('strval', $messages));

                continue;
            }

            $this->addError($field, (string) ($messages[0] ?? 'Periksa isian ini.'));
        }

        return $rest;
    }
}
