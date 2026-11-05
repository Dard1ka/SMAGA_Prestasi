<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Actions\Competitions\CompetitionDates;
use App\Actions\Competitions\CreateCompetition;
use App\Actions\Competitions\VerifyCompetition;
use App\Domain\Competitions\OrganizerWeight;
use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\EventMode;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\Participation;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Competition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Demo\DemoEvent;
use Database\Seeders\Support\Demo\DemoGuideline;
use Database\Seeders\Support\Demo\DemoPdfRenderer;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * smaga_server (SV4, lead 2026-11-04): ONE published sample lomba that stays for showing the whole flow (register →
 * approval → surat tugas → laporan hasil → verifikasi) on the school's server, which runs APP_ENV=local with real data:
 *
 *     php artisan db:seed --class=LombaContohSeeder --force
 *
 * It looks like a lomba an Admin added with "Tambah lomba" (CreateCompetition) and published at once (VerifyCompetition):
 * the form values go through CreateCompetition's rules and normalize(), the kategori bobot and status ajang come from
 * OrganizerWeight (D-146), the juknis (D-147) goes through the upload path of the form (StoreUpload, private `local`
 * disk under lampiran/), and the two audit rows `competition.created` and `competition.published` are written with the
 * Actions' properties (plus `source` = seeder). Submitter and verifier: the first active Admin (by id). Unlike the
 * Actions it tells nobody: the verifiers' "Verifikasi usulan lomba" notice would point at a lomba that is already
 * published, and the submitter is the verifier.
 *
 * The juknis is a sample document of the demo set (DemoGuideline, "CONTOH" on every page), rendered from this lomba's
 * own data. It is stored as an upload, NOT under demo/: App\Support\DemoData treats any file there as the demo data
 * set and would show "Data contoh" on the dashboard of the server's real data.
 *
 * Dates follow the day it runs: registration opens today and closes in REGISTRATION_DAYS days, the one-day lomba is
 * LOMBA_DAY days from today. A second run finds the lomba by name and changes nothing. No Admin → nothing is written.
 * Fictional data only: no real organisation, no real person.
 */
final class LombaContohSeeder extends Seeder
{
    public const NAME = 'Lomba Karya Tulis Ilmiah Remaja 2026 (Contoh)';

    public const ORGANIZER = 'Yayasan Cendekia Muda (contoh)';

    public const JUKNIS_NAME = 'juknis-lomba-karya-tulis-ilmiah-remaja-2026-contoh.pdf';

    public const DESCRIPTION = 'Lomba contoh untuk mencoba alur STH dari awal sampai akhir: pendaftaran, persetujuan, surat tugas, laporan hasil, dan verifikasi. Data fiktif, bukan lomba sungguhan.';

    /** "Pendaftaran ditutup" = today + this many days. */
    public const REGISTRATION_DAYS = 30;

    /** "Tanggal lomba" (one day: starts_on = final_on) = today + this many days. */
    public const LOMBA_DAY = 45;

    public const NO_ADMIN = 'Lomba contoh tidak dibuat: belum ada akun Admin yang aktif. Aktifkan satu akun Admin dulu, lalu jalankan lagi.';

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Lomba contoh tidak dibuat di production.');

            return;
        }

        $existing = Competition::query()->where('name', self::NAME)->first();

        if ($existing !== null) {
            $this->command?->info(sprintf('Lomba contoh sudah ada (status: %s). Tidak ada yang diubah.', $existing->status?->label() ?? '–'));

            return;
        }

        $admin = self::firstActiveAdmin();

        if ($admin === null) {
            $this->command?->error(self::NO_ADMIN);

            return;
        }

        $today = CarbonImmutable::today();
        $competition = $this->publish(
            $admin,
            self::NAME,
            self::JUKNIS_NAME,
            $today,
            $today->addDays(self::REGISTRATION_DAYS),
            $today->addDays(self::LOMBA_DAY),
            'seeder',
        );

        $this->command?->info(sprintf(
            'Lomba contoh dibuat dan dipublikasikan: %s. Pendaftaran %s sampai %s, lomba %s.',
            $competition->name,
            $competition->registration_opens_at?->format('d-m-Y'),
            $competition->registration_closes_at?->format('d-m-Y'),
            $competition->starts_on?->format('d-m-Y'),
        ));
    }

    /** The first active Admin by id: submitter and verifier of a sample lomba (null = none, nothing is written). */
    public static function firstActiveAdmin(): ?User
    {
        return User::query()
            ->role(RoleName::Admin->value)
            ->where('status', UserStatus::Active)
            ->orderBy('id')
            ->first();
    }

    /**
     * Creates and publishes one fictional one-day individual lomba of this shape (Provinsi · Daring, organiser
     * self::ORGANIZER, bidang Riset unless $bidang says otherwise) as "Tambah lomba" + publish store it. Also used by
     * `sth:lomba:demo` (DemoLombaCommand) for the presentation lomba whose registration and lomba day are both today,
     * in bidang Sains: its laporan hasil needs only the certificate and photos, no research fields. $source goes into
     * both audit rows.
     */
    public function publish(
        User $admin,
        string $name,
        string $juknisName,
        CarbonImmutable $opens,
        CarbonImmutable $closes,
        CarbonImmutable $lombaDay,
        string $source,
        Bidang $bidang = Bidang::Riset,
    ): Competition {
        $input = [
            'name' => $name,
            'description' => self::DESCRIPTION,
            'bidang' => $bidang->value,
            'organizer_name' => self::ORGANIZER,
            'organizer_type' => OrganizerType::YayasanLembaga->value,
            'level' => Level::Provinsi->value,
            'mode' => EventMode::Daring->value,
            'registration_opens_at' => $opens->toDateString(),
            'registration_closes_at' => $closes->toDateString(),
            'starts_on' => $lombaDay->toDateString(),
            'final_on' => $lombaDay->toDateString(),
            'location' => 'Daring',
            'participation' => Participation::Individu->value,
        ];

        // The rules and the cleaning of "Tambah lomba", so the row is stored as that form stores it.
        $validator = Validator::make(CreateCompetition::prepare($input), CreateCompetition::rules(), CreateCompetition::messages());
        $validator->after(CompetitionDates::checkOrder(...));
        $data = CreateCompetition::normalize($validator->validate());
        $weight = OrganizerWeight::for(OrganizerType::YayasanLembaga, puspresnas: false);

        $upload = $this->juknis($data, $juknisName);

        try {
            $files = ['juknis' => CreateCompetition::storeFile($admin, $upload, FilePurpose::Juknis), 'cover' => null];
        } finally {
            @unlink((string) $upload->getRealPath());
        }

        return CreateCompetition::discardOnFailure($files, fn (): Competition => DB::transaction(function () use ($admin, $data, $weight, $files, $source): Competition {
            $competition = new Competition([
                ...Arr::except($data, CreateCompetition::WEIGHT_FIELDS),
                'juknis_file_id' => $files['juknis']->getKey(),
            ]);

            // As CreateCompetition: weights, status and submitter are not fillable.
            $competition->forceFill([
                'weight_category' => $weight->category,
                'event_status' => $weight->eventStatus,
                'status' => CompetitionStatus::MenungguVerifikasi,
                'submitted_by' => $admin->getKey(),
            ])->save();

            activity('competition')
                ->performedOn($competition)
                ->causedBy($admin)
                ->event('competition.created')
                ->withProperties([
                    'level' => $competition->level?->value,
                    'weight_category' => $competition->weight_category?->value,
                    'event_status' => $competition->event_status?->value,
                    'source' => $source,
                ])
                ->log('competition.created');

            $missing = VerifyCompetition::missingFields($competition);

            if ($missing !== []) {
                throw new RuntimeException('Lomba contoh belum lengkap: '.implode(', ', $missing).'.');
            }

            // As VerifyCompetition publishing it (the Admin holds competition.verify).
            $competition->forceFill([
                'status' => CompetitionStatus::Dipublikasikan,
                'verified_by' => $admin->getKey(),
                'verified_at' => now(),
                'verification_note' => null,
            ])->save();

            activity('competition')
                ->performedOn($competition)
                ->causedBy($admin)
                ->event('competition.published')
                ->withProperties([
                    'submitted_by' => $competition->submitted_by,
                    'level' => $competition->level?->value,
                    'weight_category' => $competition->weight_category?->value,
                    'event_status' => $competition->event_status?->value,
                    'note' => null,
                    'past_dates' => [],
                    'weight_override' => null,
                    'source' => $source,
                ])
                ->log('competition.published');

            return $competition;
        }));
    }

    /**
     * The juknis as the PDF an Admin would upload: the demo set's juknis template filled with this lomba's data
     * (fictional organiser, invented committee signers, "CONTOH" on every page), in a temporary file for StoreUpload.
     *
     * @param  array<string, mixed>  $data  normalized form values
     */
    private function juknis(array $data, string $juknisName): UploadedFile
    {
        $opens = CarbonImmutable::parse((string) $data['registration_opens_at'])->startOfDay();
        $closes = CarbonImmutable::parse((string) $data['registration_closes_at'])->startOfDay();
        $starts = CarbonImmutable::parse((string) $data['starts_on']);

        $event = new DemoEvent(
            name: (string) $data['name'],
            organizer: self::ORGANIZER,
            opensOn: $opens,
            closesOn: $closes,
            startsOn: $starts,
            finalOn: CarbonImmutable::parse((string) $data['final_on']),
            bidang: Bidang::from((string) $data['bidang']),
            level: Level::Provinsi,
            organizerType: OrganizerType::YayasanLembaga,
            online: true,
            location: 'Daring',
            team: false,
            individual: true,
            maxTeam: null,
        );

        $pdf = app(DemoPdfRenderer::class)->render(DemoGuideline::juknis($event));
        $path = (string) tempnam(sys_get_temp_dir(), 'sth-lomba-contoh-');
        file_put_contents($path, $pdf->bytes);

        return new UploadedFile($path, $juknisName, 'application/pdf', null, true);
    }
}
