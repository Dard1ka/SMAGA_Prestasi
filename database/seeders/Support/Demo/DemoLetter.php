<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Actions\Letters\LetterContent;
use App\Actions\Letters\LetterDraft;
use App\Actions\Templates\LetterTemplate;
use App\Domain\Letters\PdfQrCode;
use App\Enums\LetterType;
use App\Models\Letter;
use Carbon\CarbonImmutable;
use Database\Seeders\SignatureSeeder;
use Throwable;

/**
 * The PDF of a demo surat tugas, made with the school's real letter templates (LOCAL ONLY), so a demo letter
 * looks like the one "Buat PDF" produces: same content builder (LetterContent from the registration and the
 * letter's own columns, the way the TU preview shows an old letter), same template texts, the letter's own number
 * and verification code in the QR. Differences, on purpose:
 *
 * - the wrapper templates (database/seeders/demo/surat-*.blade.php) add the CONTOH footer line to every page;
 * - the signature picture is always the sample of the repo (database/seeders/assets, "CONTOH TTD + STEMPEL"),
 *   never the image an Admin uploaded;
 * - the signer is printed from the settings only when it is the fictional Kepala Sekolah of the demo set;
 *   any other name is replaced by DemoPeople::PRINCIPAL;
 * - a letter that names someone outside the demo set is not rendered at all (null: the caller falls back).
 *
 * Nothing is written: no `letters.printed`, no audit entry, no number check.
 */
final class DemoLetter
{
    public const VIEWS = [
        'siswa' => 'surat-siswa',
        'guru' => 'surat-guru',
    ];

    public static function document(Letter $letter): ?DemoDocument
    {
        $letter->loadMissing([
            'replaces',
            'registration.competition',
            'registration.mentor.staffProfile',
            'registration.members.student.user',
            'registration.members.student.classroom',
        ]);

        $registration = $letter->registration;

        if ($registration === null || $registration->competition === null || ($letter->type === LetterType::Guru && $registration->mentor === null)) {
            return null;
        }

        try {
            $form = LetterDraft::fromLetter($letter, $registration);
            $content = LetterContent::build($registration, new LetterDraft(
                number: $form['nomor'],
                issuedOn: CarbonImmutable::parse($form['tanggalSurat']),
                basisFrom: $form['dasarDari'],
                basisNumber: $form['dasarNomor'] !== '' ? $form['dasarNomor'] : null,
                basisDate: $form['dasarTanggal'] !== '' ? CarbonImmutable::parse($form['dasarTanggal']) : null,
                scheduleDay: $form['hari'],
                scheduleDate: $form['tanggal'],
                scheduleTime: $form['pukul'],
                place: $form['tempat'],
                basisSubject: $form['dasarHal'],
            ));
        } catch (Throwable) {
            return null;
        }

        if ($content->students === [] || ! self::onlyDemoPeople($content)) {
            return null;
        }

        if (! DemoPeople::isFictional($content->signer['name'])) {
            $content = LetterContent::fromArray(['signer' => DemoPeople::PRINCIPAL] + $content->toArray()) ?? $content;
        }

        $url = route('public.surat.verifikasi', ['code' => $letter->verification_code]);

        return new DemoDocument(
            kind: $letter->type === LetterType::Guru ? DemoDocumentKind::SuratGuru : DemoDocumentKind::SuratSiswa,
            view: self::VIEWS[$letter->type->value],
            data: [
                'content' => $content,
                'number' => $letter->full_number,
                'verification' => ['code' => $letter->verification_code, 'url' => $url],
                'replaces' => $letter->replaces?->full_number,
                'letterTemplate' => LetterTemplate::current(),
            ],
            images: [
                'ttd.png' => self::sampleSignature(),
                'qr.png' => app(PdfQrCode::class)->png($url, 6),
            ],
            title: ($letter->type === LetterType::Guru ? 'Surat Perintah Tugas ' : 'Surat Tugas ').$letter->full_number,
        );
    }

    /** The sample signature + stamp of the repo; drawn again when the asset is missing. */
    public static function sampleSignature(): string
    {
        $asset = database_path(SignatureSeeder::ASSET);
        $bytes = is_file($asset) ? (string) file_get_contents($asset) : '';

        return $bytes !== '' ? $bytes : SignatureSeeder::generatePng();
    }

    private static function onlyDemoPeople(LetterContent $content): bool
    {
        $names = array_map(mb_strtoupper(...), DemoPeople::names());

        foreach ($content->students as $student) {
            if (! in_array($student['name'], $names, true)) {
                return false;
            }
        }

        return $content->mentor === null || DemoPeople::isFictional($content->mentor['name']);
    }
}
