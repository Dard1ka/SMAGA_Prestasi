<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\ResultType;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * One fixed sample of every document kind and look, built from the fictional demo set without a database
 * (LOCAL ONLY). The tests render each of them once and feed it to the upload scanner; `write()` puts them in a
 * folder to look at while working on a template:
 *
 *   docker compose exec -T app php artisan tinker --execute="Database\Seeders\Support\Demo\DemoSamples::write(storage_path('app/pdf-tmp/contoh'));"
 */
final class DemoSamples
{
    /** @return array<string, DemoDocument> file name without extension => document */
    public static function documents(): array
    {
        $documents = [];

        foreach (self::awards() as $name => $award) {
            $documents[$name] = DemoCertificate::document($award);
        }

        $documents['laporan-penelitian'] = DemoResearchReport::document(
            DemoResearchReport::BIOPLASTIC_TITLE,
            null,
            'Lomba Karya Tulis Ilmiah Remaja Jawa Tengah 2026',
            [['name' => 'Keisha Larasati', 'nis' => '225226166', 'class' => 'XI-6']],
            'Retno Wulandari, S.Pd.',
            CarbonImmutable::parse('2026-09-10'),
        );
        $documents['laporan-penelitian-umum'] = DemoResearchReport::document(
            'Alat penyiram tanaman otomatis hemat air untuk kebun sekolah',
            'Penelitian contoh (fiktif): perbandingan tiga cara penyiraman kebun sekolah.',
            'KRENOVA Kota Semarang 2026',
            [
                ['name' => 'Nadia Putri Anindya', 'nis' => '225226201', 'class' => 'XI-4'],
                ['name' => 'Dimas Arya Nugroho', 'nis' => '225226204', 'class' => 'XI-4'],
            ],
            'Retno Wulandari, S.Pd.',
            CarbonImmutable::parse('2026-02-17'),
        );

        foreach (self::events() as $name => $event) {
            $documents[$name] = match (true) {
                str_starts_with($name, 'brosur') => DemoGuideline::brosur($event),
                str_starts_with($name, 'poster') => DemoGuideline::poster($event),
                default => DemoGuideline::juknis($event),
            };
        }

        $documents['umum'] = DemoGuideline::umum('Pengumuman hasil lomba', 'Bukti laporan hasil', 'Nadia Putri Anindya');

        return $documents;
    }

    /** @return array<string, DemoEvent> */
    public static function events(): array
    {
        $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);
        [$opens, $closes, $starts, $final] = DemoEvent::datesFrom($day('2026-09-27'));

        return [
            'juknis-sains' => new DemoEvent(
                name: 'OSN Tingkat Kota Semarang 2026', organizer: 'Puspresnas',
                opensOn: $day('2026-09-08'), closesOn: $day('2026-10-08'), startsOn: $day('2026-10-14'), finalOn: $day('2026-10-14'),
                bidang: Bidang::Sains, level: Level::Kota, organizerType: OrganizerType::PemerintahPusat, location: 'Kota Semarang',
            ),
            'juknis-riset' => new DemoEvent(
                name: 'Lomba Peneliti Belia (LPB) 2026', organizer: 'Center for Young Scientists',
                opensOn: $day('2026-09-01'), closesOn: $day('2026-10-10'), startsOn: $day('2026-10-17'), finalOn: $day('2026-10-17'),
                bidang: Bidang::Riset, level: Level::Nasional, organizerType: OrganizerType::YayasanLembaga, online: true, location: 'Daring',
                team: true, individual: false, maxTeam: 3,
            ),
            'juknis-olahraga' => new DemoEvent(
                name: 'Kejuaraan Pencak Silat Pelajar Jawa Tengah 2026', organizer: 'IPSI Jawa Tengah',
                opensOn: $day('2026-09-15'), closesOn: $day('2026-10-15'), startsOn: $day('2026-11-01'), finalOn: $day('2026-11-01'),
                bidang: Bidang::Olahraga, level: Level::Provinsi, organizerType: OrganizerType::FederasiOlahraga, location: 'Jawa Tengah',
            ),
            'juknis-debat' => new DemoEvent(
                name: 'Lomba Debat Bahasa Indonesia (LDBI) Kota Semarang 2026', organizer: 'Puspresnas',
                opensOn: $day('2026-09-12'), closesOn: $day('2026-10-12'), startsOn: $day('2026-10-24'), finalOn: $day('2026-10-24'),
                bidang: Bidang::SeniBudaya, level: Level::Kota, organizerType: OrganizerType::PemerintahPusat, location: 'Kota Semarang',
                team: true, individual: false, maxTeam: 3,
            ),
            'brosur-riset' => new DemoEvent(
                name: 'International Science Project Olympiad (ISPO) 2027', organizer: 'Panitia ISPO',
                opensOn: $opens, closesOn: $closes, startsOn: $starts, finalOn: $final, bidang: Bidang::Riset, team: true, maxTeam: 3,
            ),
            'poster-film' => new DemoEvent(
                name: 'Lomba Film Pendek Pelajar Semarang', organizer: 'Komunitas Film Pelajar Semarang',
                opensOn: $opens, closesOn: $closes, startsOn: $starts, finalOn: $final, bidang: Bidang::SeniBudaya, team: true, maxTeam: 3,
            ),
        ];
    }

    /** @return array<string, DemoAward> */
    public static function awards(): array
    {
        $day = fn (string $date): CarbonImmutable => CarbonImmutable::parse($date);

        return [
            'sertifikat-emas' => new DemoAward(
                recipient: 'Yoga Pratama', competition: 'LPB 2025', organizer: 'Center for Young Scientists', result: ResultType::Juara1,
                organizerType: OrganizerType::YayasanLembaga, bidang: Bidang::Riset, level: Level::Nasional,
                startsOn: $day('2025-10-25'), finalOn: $day('2025-10-25'), location: 'Daring', online: true, team: ['Dimas Arya Nugroho'],
            ),
            'sertifikat-nasional' => new DemoAward(
                recipient: 'Raka Pradipta', competition: 'OSN Provinsi Jawa Tengah 2024', organizer: 'Puspresnas', result: ResultType::Juara1,
                organizerType: OrganizerType::PemerintahPusat, bidang: Bidang::Sains, level: Level::Provinsi, branch: 'Informatika',
                startsOn: $day('2024-09-17'), finalOn: $day('2024-09-18'), location: 'Kota Semarang',
            ),
            'sertifikat-kampus' => new DemoAward(
                recipient: 'Rizky Maulana', competition: 'Olimpiade Kimia Nasional Universitas Diponegoro 2026', organizer: 'Universitas Diponegoro', result: ResultType::Juara3,
                organizerType: OrganizerType::Ptn, bidang: Bidang::Sains, level: Level::Nasional,
                startsOn: $day('2026-09-19'), finalOn: $day('2026-09-20'), location: 'Kota Semarang',
            ),
            'sertifikat-internasional' => new DemoAward(
                recipient: 'Raka Pradipta', competition: 'Asian Young Researchers Contest 2025', organizer: 'Asian Young Researchers Network', result: ResultType::MedaliEmas,
                organizerType: OrganizerType::YayasanLembaga, bidang: Bidang::Riset, level: Level::Internasional,
                startsOn: $day('2025-11-22'), finalOn: $day('2025-11-22'), location: 'Daring', online: true,
            ),
            'sertifikat-festival' => new DemoAward(
                recipient: 'Citra Ayu Lestari', competition: 'Festival Tari Pelajar Semarang 2026', organizer: 'Sanggar Seni Pelajar Semarang', result: ResultType::Juara1,
                organizerType: OrganizerType::Lainnya, bidang: Bidang::SeniBudaya, level: Level::Kota,
                startsOn: $day('2026-09-05'), finalOn: $day('2026-09-05'), location: 'Kota Semarang',
            ),
            'sertifikat-inovasi' => new DemoAward(
                recipient: 'Nadia Putri Anindya', competition: 'KRENOVA Kota Semarang 2026', organizer: 'Pemerintah Kota Semarang', result: ResultType::Juara1,
                organizerType: OrganizerType::PemerintahDaerah, bidang: Bidang::Riset, level: Level::Kota,
                startsOn: $day('2026-02-24'), finalOn: $day('2026-02-24'), location: 'Kota Semarang',
            ),
            'sertifikat-peserta' => new DemoAward(
                recipient: 'Aurelia Maharani', competition: 'OSEBI 2026', organizer: 'Eduversal', result: ResultType::Finalis,
                organizerType: OrganizerType::PerusahaanSwasta, bidang: Bidang::Sains, level: Level::Nasional,
                startsOn: $day('2026-09-13'), finalOn: $day('2026-09-13'), location: 'Daring', online: true,
            ),
            'piagam-merah' => new DemoAward(
                recipient: 'Nadia Putri Anindya', competition: 'O2SN Kota Semarang 2026', organizer: 'Puspresnas', result: ResultType::Peserta,
                organizerType: OrganizerType::PemerintahPusat, bidang: Bidang::Olahraga, level: Level::Kota, branch: 'Atletik',
                startsOn: $day('2026-08-29'), finalOn: $day('2026-08-29'), location: 'Kota Semarang',
            ),
            'piagam-hijau' => new DemoAward(
                recipient: 'Bima Satria Wicaksono', competition: 'Popda Kota Semarang 2026', organizer: 'Dinas Kepemudaan dan Olahraga Kota Semarang', result: ResultType::MedaliPerak,
                organizerType: OrganizerType::PemerintahDaerah, bidang: Bidang::Olahraga, level: Level::Kota, branch: 'Atletik',
                startsOn: $day('2026-09-12'), finalOn: $day('2026-09-12'), location: 'Kota Semarang',
            ),
            'piagam-biru' => new DemoAward(
                recipient: 'Fajar Ramadhan', competition: 'Kejuaraan Pencak Silat Pelajar Jawa Tengah 2025', organizer: 'IPSI Jawa Tengah', result: ResultType::Juara1,
                organizerType: OrganizerType::FederasiOlahraga, bidang: Bidang::Olahraga, level: Level::Provinsi,
                startsOn: $day('2025-10-31'), finalOn: $day('2025-11-01'), location: 'Jawa Tengah',
            ),
        ];
    }

    /**
     * Render every sample into a folder (for a look in a PDF viewer). Local only.
     *
     * @return array<string, array{bytes: int, pages: int}>
     */
    public static function write(string $folder): array
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('Dokumen contoh hanya untuk APP_ENV=local.');
        }

        $renderer = app(DemoPdfRenderer::class);
        $written = [];

        if (! is_dir($folder)) {
            mkdir($folder, 0775, true);
        }

        foreach (self::documents() as $name => $document) {
            $pdf = $renderer->render($document);
            file_put_contents(rtrim($folder, '/').'/'.$name.'.pdf', $pdf->bytes);
            $written[$name] = ['bytes' => $pdf->size(), 'pages' => $pdf->pageCount];
        }

        return $written;
    }
}
