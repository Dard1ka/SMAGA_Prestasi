<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Domain\Files\UploadRules;
use App\Domain\Registrations\InvalidTransition;
use App\Domain\Registrations\RegistrationStateMachine;
use App\Enums\Bidang;
use App\Enums\FilePurpose;
use App\Enums\RegistrationStatus;
use App\Enums\ReportFileKind;
use App\Enums\ReportStatus;
use App\Models\File;
use App\Models\Registration;
use App\Models\Report;
use App\Models\User;
use App\Notifications\Reports\ReportSubmitted;
use App\Notifications\Verification\ReportSent;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The ketua sends the laporan hasil (UI-MAP #12/#13, ARCHITECTURE §6):
 * menunggu_laporan → laporan_dikirim, report status `dikirim`. The same row is re-used after
 * "perlu revisi" (reports.registration_id is unique). Evidence goes through StoreUpload (private disk,
 * sniffed MIME, images re-encoded without EXIF). D-151: a laporan hasil has no deadline, so a report is never
 * flagged `late` any more (the column stays; a sent report always gets false). Kesiswaan and Humas are notified after
 * commit (queued mail + database), and since D-160 the members too (ReportSent: a receipt for the ketua).
 *
 * Validation errors use the field names of the Livewire form (hasil, asli, sertifikat, foto, pengumuman,
 * judul, abstrak, laporan, artikel, poster).
 */
final class SubmitReport
{
    public const MAX_PHOTOS = 5;

    public const MAX_ABSTRACT_WORDS = 250;

    public const MAX_TITLE = 200;

    private const MB = 1024 * 1024;

    public function __construct(
        private readonly StoreUpload $store,
        private readonly RegistrationStateMachine $machine,
    ) {}

    /** Upload rules per field (also used by the Livewire form for its first check). */
    public static function rules(string $field): UploadRules
    {
        return match ($field) {
            'foto' => new UploadRules(5 * self::MB, ['jpg', 'jpeg', 'png', 'webp']),
            'laporan', 'artikel' => new UploadRules(10 * self::MB, ['pdf']),
            default => new UploadRules(5 * self::MB), // sertifikat, pengumuman, poster: pdf/jpg/png/webp
        };
    }

    public static function wordCount(?string $text): int
    {
        return count(preg_split('/\s+/u', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, Registration $registration, SubmitReportData $data): Report
    {
        Gate::forUser($actor)->authorize('create', [Report::class, $registration]);

        $registration->loadMissing(['competition', 'leader.user', 'report.files']);
        $existing = $registration->report;
        $isRiset = $registration->competition->bidang === Bidang::Riset;

        $this->validateInput($data, $existing, $isRiset);

        $stored = $this->storeFiles($actor, $data, $isRiset);
        $resubmitted = $existing !== null;

        try {
            $report = DB::transaction(fn (): Report => $this->persist($actor, $registration, $data, $stored, $isRiset));
        } catch (Throwable $e) {
            foreach ($stored as $files) {
                foreach ($files as $file) {
                    $this->store->discard($file);
                }
            }

            throw $e;
        }

        $this->notifyVerifiers($report, $registration, $resubmitted);
        $this->notifyMembers($report, $registration, $resubmitted);

        return $report;
    }

    /** @throws ValidationException */
    private function validateInput(SubmitReportData $data, ?Report $existing, bool $isRiset): void
    {
        $errors = [];
        $hasExisting = fn (ReportFileKind $kind): bool => $existing !== null
            && $existing->files->contains(fn ($f): bool => $f->kind === $kind);

        if (! $data->authentic) {
            $errors['asli'] = 'Centang pernyataan keaslian dokumen dulu.';
        }

        if ($data->certificate === null && ! $hasExisting(ReportFileKind::Sertifikat)) {
            $errors['sertifikat'] = 'Upload sertifikat atau piagam.';
        }

        if ($data->photos === [] && ! $hasExisting(ReportFileKind::Foto)) {
            $errors['foto'] = 'Tambahkan minimal 1 foto dokumentasi.';
        } elseif (count($data->photos) > self::MAX_PHOTOS) {
            $errors['foto'] = 'Maksimal '.self::MAX_PHOTOS.' foto.';
        }

        if ($isRiset) {
            $title = trim((string) $data->researchTitle);
            $abstract = trim((string) $data->researchAbstract);

            if ($title === '') {
                $errors['judul'] = 'Isi judul penelitian.';
            } elseif (mb_strlen($title) > self::MAX_TITLE) {
                $errors['judul'] = 'Judul maksimal '.self::MAX_TITLE.' karakter.';
            }

            if ($abstract === '') {
                $errors['abstrak'] = 'Isi abstrak penelitian.';
            } elseif (self::wordCount($abstract) > self::MAX_ABSTRACT_WORDS || mb_strlen($abstract) > 3000) {
                $errors['abstrak'] = 'Abstrak maksimal '.self::MAX_ABSTRACT_WORDS.' kata.';
            }

            if ($data->researchReport === null && ! $hasExisting(ReportFileKind::LaporanRiset)) {
                $errors['laporan'] = 'Upload laporan penelitian (PDF).';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, list<File>> stored files per ReportFileKind value
     *
     * @throws ValidationException
     */
    private function storeFiles(User $actor, SubmitReportData $data, bool $isRiset): array
    {
        $uploads = [
            [ReportFileKind::Sertifikat, 'sertifikat', $data->certificate === null ? [] : [$data->certificate]],
            [ReportFileKind::Foto, 'foto', $data->photos],
            [ReportFileKind::Pengumuman, 'pengumuman', $data->announcement === null ? [] : [$data->announcement]],
        ];

        if ($isRiset) {
            $uploads[] = [ReportFileKind::LaporanRiset, 'laporan', $data->researchReport === null ? [] : [$data->researchReport]];
            $uploads[] = [ReportFileKind::Artikel, 'artikel', $data->article === null ? [] : [$data->article]];
            $uploads[] = [ReportFileKind::Poster, 'poster', $data->poster === null ? [] : [$data->poster]];
        }

        $stored = [];

        foreach ($uploads as [$kind, $field, $files]) {
            foreach ($files as $upload) {
                /** @var UploadedFile $upload */
                try {
                    $stored[$kind->value][] = $this->store->store($upload, FilePurpose::ReportEvidence, $actor, self::rules($field));
                } catch (InvalidUpload $e) {
                    foreach ($stored as $done) {
                        foreach ($done as $file) {
                            $this->store->discard($file);
                        }
                    }

                    throw $e->toValidationException($field);
                }
            }
        }

        return $stored;
    }

    /**
     * @param  array<string, list<File>>  $stored
     *
     * @throws ValidationException
     */
    private function persist(User $actor, Registration $registration, SubmitReportData $data, array $stored, bool $isRiset): Report
    {
        /** @var Registration $locked */
        $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== RegistrationStatus::MenungguLaporan) {
            throw ValidationException::withMessages(['hasil' => 'Laporan untuk lomba ini tidak bisa dikirim sekarang.']);
        }

        /** @var Report|null $report */
        $report = Report::withTrashed()->where('registration_id', $locked->getKey())->lockForUpdate()->first();

        if ($report !== null && ! $report->trashed() && $report->status !== ReportStatus::PerluRevisi) {
            throw ValidationException::withMessages(['hasil' => 'Laporan sudah dikirim.']);
        }

        try {
            $this->machine->assertTransition($locked->status, RegistrationStatus::LaporanDikirim);
        } catch (InvalidTransition $e) {
            throw ValidationException::withMessages(['hasil' => $e->getMessage()]);
        }

        $report ??= new Report(['registration_id' => $locked->getKey()]);

        if ($report->trashed()) {
            $report->restore();
        }

        $report->fill([
            'claimed_result' => $data->result,
            'research_title' => $isRiset ? trim((string) $data->researchTitle) : null,
            'research_abstract' => $isRiset ? trim((string) $data->researchAbstract) : null,
        ]);
        $report->forceFill([
            'status' => ReportStatus::Dikirim,
            'submitted_by' => $actor->getKey(),
            'submitted_at' => now(),
            // D-151: no deadline, so a report is never late (also clears the flag of a report sent before D-151).
            'late' => false,
            'final_result' => null,
            'verified_by' => null,
            'verified_at' => null,
        ])->save();

        foreach ($stored as $kind => $files) {
            // A revision replaces the earlier files of every kind that got new uploads.
            $report->files()->where('kind', $kind)->delete();

            foreach ($files as $file) {
                $report->files()->create(['kind' => $kind, 'file_id' => $file->getKey()]);
            }
        }

        if (! $isRiset) {
            $report->files()->whereIn('kind', [ReportFileKind::LaporanRiset, ReportFileKind::Artikel, ReportFileKind::Poster])->delete();
        }

        $from = $locked->status;
        $locked->forceFill(['status' => RegistrationStatus::LaporanDikirim])->save();

        activity('report')
            ->performedOn($report)
            ->causedBy($actor)
            ->event('report.submitted')
            ->withProperties([
                'registration_id' => $locked->getKey(),
                'claimed_result' => $data->result->value,
                'resubmitted' => $report->wasRecentlyCreated === false,
                'files' => array_map(count(...), $stored),
            ])
            ->log('report.submitted');

        activity('registration')
            ->performedOn($locked)
            ->causedBy($actor)
            ->event('registration.laporan_dikirim')
            ->withProperties(['from' => $from->value, 'to' => RegistrationStatus::LaporanDikirim->value])
            ->log('registration.laporan_dikirim');

        $registration->setRawAttributes($locked->getAttributes(), true);

        return $report->refresh();
    }

    private function notifyVerifiers(Report $report, Registration $registration, bool $resubmitted): void
    {
        $recipients = ReportVerifiers::recipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, ReportSubmitted::for(
            $report,
            (string) $registration->competition->name,
            (string) ($registration->leader?->user?->name ?? 'Ketua tim'),
            $resubmitted,
        ));
    }

    /**
     * D-160: the confirmed members who can log in hear that the report went out (the ketua as a receipt), the same
     * members "Minta revisi" and the verification tell (RequestReportRevision::memberUsers()).
     */
    private function notifyMembers(Report $report, Registration $registration, bool $resubmitted): void
    {
        $members = RequestReportRevision::memberUsers($report);

        if ($members === []) {
            return;
        }

        Notification::send($members, ReportSent::for(
            $report,
            (string) $registration->competition->name,
            $registration->leader?->user,
            $resubmitted,
        ));
    }
}
