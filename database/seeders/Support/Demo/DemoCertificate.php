<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\OrganizerType;
use App\Enums\ResultType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Builds the certificate or piagam of one award (LOCAL ONLY). Texts are our own wording; the look is chosen
 * from the lomba, so the demo data does not show one template everywhere:
 *
 * | theme         | when                                                         | look                                    |
 * |---------------|--------------------------------------------------------------|-----------------------------------------|
 * | internasional | level internasional                                          | English, blue waves, globe              |
 * | piagam        | bidang olahraga, or a file named "piagam-…" (portrait)       | formal frame, "PIAGAM PENGHARGAAN"      |
 * | kampus        | organizer is a university or a professional body             | patterned band on the left              |
 * | festival      | bidang seni budaya                                           | warm tone, diamond pattern strips       |
 * | inovasi       | bidang riset by a government body                            | green honeycomb corners                 |
 * | nasional      | any other government organizer (OSN, olimpiade sains)        | navy double frame, ribbons, shield      |
 * | emas          | everything else (yayasan, swasta)                            | black and gold corners, medal in centre |
 *
 * Every certificate number carries the word CONTOH and the committee stamp reads CONTOH.
 */
final class DemoCertificate
{
    public const THEMES = ['emas', 'nasional', 'kampus', 'internasional', 'festival', 'inovasi'];

    /** theme => [primary, accent, dark, soft] */
    private const PALETTES = [
        'emas' => ['#b8891a', '#c9a227', '#141414', '#f3e7c0'],
        'nasional' => ['#c62828', '#b8891a', '#14284b', '#efe6cf'],
        'kampus' => ['#0f4c5c', '#e0a526', '#0a3540', '#7fc0cf'],
        'internasional' => ['#0b3c8c', '#d4a72c', '#082a63', '#5b96e3'],
        'festival' => ['#7b1e2b', '#e08a1e', '#4a0f18', '#f4d9a8'],
        'inovasi' => ['#1e8a5a', '#f2b632', '#0f5c3c', '#bfe5cf'],
    ];

    /** piagam variant => [primary, accent, dark, soft] */
    private const PIAGAM_PALETTES = [
        'merah' => ['#a61b1b', '#b8891a', '#6e0f0f', '#f1e2cf'],
        'hijau' => ['#1b5e20', '#b8891a', '#0f3d14', '#dcebd6'],
        'biru' => ['#14284b', '#b8891a', '#0b1a33', '#d9e0ee'],
    ];

    /** Measures (mm, pt) and text colours of the landscape template per theme: database/seeders/demo/sertifikat.blade.php. */
    private const LAYOUTS = [
        'emas' => [
            'left' => 44, 'right' => 44, 'top' => 12, 'align' => 'center', 'emblem' => 0,
            'titleFont' => 'Times, serif', 'titleSize' => 50, 'titleSpacing' => 5, 'titleColor' => '#b8891a', 'titleGap' => 4,
            'gap' => 5.5, 'nameSize' => 34, 'nameColor' => '#b8891a', 'rule' => 150, 'resultColor' => '#b8891a', 'text' => 196,
            'dateLeft' => 0, 'dateRight' => 0, 'dateBottom' => 50, 'signBottom' => 15, 'sign1' => 54, 'sign2' => 157, 'signColor' => '#9a7212',
            'medalLeft' => 135.5, 'medalBottom' => 11, 'footLeft' => 0, 'footBottom' => 3.2, 'footColor' => '#555555',
        ],
        'nasional' => [
            'left' => 46, 'right' => 46, 'top' => 14, 'align' => 'center', 'emblem' => 13,
            'titleFont' => 'Times, serif', 'titleSize' => 44, 'titleSpacing' => 4, 'titleColor' => '#14284b', 'titleGap' => 2.5,
            'gap' => 4.5, 'nameSize' => 31, 'nameColor' => '#14284b', 'rule' => 140, 'resultColor' => '#b3261e', 'text' => 190,
            'dateLeft' => 0, 'dateRight' => 0, 'dateBottom' => 54, 'signBottom' => 19, 'sign1' => 46, 'sign2' => 173, 'signColor' => '#14284b',
            'medalLeft' => 135.5, 'medalBottom' => 15, 'footLeft' => 0, 'footBottom' => 11.4, 'footColor' => '#555555',
        ],
        'kampus' => [
            'left' => 80, 'right' => 20, 'top' => 16, 'align' => 'left', 'emblem' => 0,
            'titleFont' => 'Helvetica, Arial, sans-serif', 'titleSize' => 42, 'titleSpacing' => 3, 'titleColor' => '#0f4c5c', 'titleGap' => 6,
            'gap' => 5, 'nameSize' => 32, 'nameColor' => '#0a3540', 'rule' => 150, 'resultColor' => '#0f4c5c', 'text' => 190,
            'dateLeft' => 80, 'dateRight' => 20, 'dateBottom' => 50, 'signBottom' => 15, 'sign1' => 84, 'sign2' => 185, 'signColor' => '#0a3540',
            'medalLeft' => 19, 'medalBottom' => 30, 'footLeft' => 66, 'footBottom' => 3.2, 'footColor' => '#555555',
        ],
        'internasional' => [
            'left' => 44, 'right' => 44, 'top' => 25, 'align' => 'center', 'emblem' => 11,
            'titleFont' => 'Times, serif', 'titleSize' => 42, 'titleSpacing' => 4, 'titleColor' => '#0b3c8c', 'titleGap' => 1.5,
            'gap' => 4, 'nameSize' => 30, 'nameColor' => '#082a63', 'rule' => 140, 'resultColor' => '#9a7410', 'text' => 196,
            'dateLeft' => 0, 'dateRight' => 0, 'dateBottom' => 63, 'signBottom' => 29, 'sign1' => 38, 'sign2' => 181, 'signColor' => '#082a63',
            'medalLeft' => 135.5, 'medalBottom' => 21, 'footLeft' => 0, 'footBottom' => 3, 'footColor' => '#ffffff',
        ],
        'festival' => [
            'left' => 44, 'right' => 44, 'top' => 20, 'align' => 'center', 'emblem' => 12,
            'titleFont' => 'Times, serif', 'titleSize' => 42, 'titleSpacing' => 4, 'titleColor' => '#7b1e2b', 'titleGap' => 2,
            'gap' => 4, 'nameSize' => 30, 'nameColor' => '#7b1e2b', 'rule' => 140, 'resultColor' => '#a85a08', 'text' => 190,
            'dateLeft' => 0, 'dateRight' => 0, 'dateBottom' => 59, 'signBottom' => 25, 'sign1' => 38, 'sign2' => 181, 'signColor' => '#4a0f18',
            'medalLeft' => 135.5, 'medalBottom' => 21, 'footLeft' => 0, 'footBottom' => 18.6, 'footColor' => '#7b1e2b',
        ],
        'inovasi' => [
            'left' => 44, 'right' => 44, 'top' => 16, 'align' => 'center', 'emblem' => 12,
            'titleFont' => 'Helvetica, Arial, sans-serif', 'titleSize' => 40, 'titleSpacing' => 6, 'titleColor' => '#0f5c3c', 'titleGap' => 2.5,
            'gap' => 4.5, 'nameSize' => 31, 'nameColor' => '#1e8a5a', 'rule' => 140, 'resultColor' => '#0f5c3c', 'text' => 190,
            'dateLeft' => 0, 'dateRight' => 0, 'dateBottom' => 52, 'signBottom' => 17, 'sign1' => 44, 'sign2' => 175, 'signColor' => '#0f5c3c',
            'medalLeft' => 135.5, 'medalBottom' => 12, 'footLeft' => 0, 'footBottom' => 3.2, 'footColor' => '#555555',
        ],
    ];

    private const EMBLEMS = [
        'nasional' => 'perisai',
        'kampus' => 'buku',
        'internasional' => 'bola-dunia',
        'festival' => 'bunga',
        'inovasi' => 'lampu',
    ];

    private const CLOSINGS = [
        'emas' => 'Semoga capaian ini menjadi penyemangat untuk terus belajar, berkarya, dan berprestasi.',
        'nasional' => 'Sertifikat ini diberikan sebagai bentuk penghargaan atas ketekunan dan capaian yang diraih.',
        'kampus' => 'Terima kasih atas partisipasi dan semangat belajar yang ditunjukkan selama kegiatan berlangsung.',
        'festival' => 'Teruslah berkarya dan menjaga kecintaan pada seni serta budaya bangsa.',
        'inovasi' => 'Semoga gagasan dan karya yang dihasilkan terus dikembangkan dan bermanfaat bagi masyarakat.',
        'internasional' => 'Awarded in recognition of the dedication, creativity and scientific spirit shown throughout the event.',
    ];

    private const RESULTS_EN = [
        'juara_1' => 'FIRST PLACE WINNER',
        'juara_2' => 'SECOND PLACE WINNER',
        'juara_3' => 'THIRD PLACE WINNER',
        'medali_emas' => 'GOLD MEDALIST',
        'medali_perak' => 'SILVER MEDALIST',
        'medali_perunggu' => 'BRONZE MEDALIST',
        'finalis' => 'FINALIST',
        'semifinalis' => 'SEMIFINALIST',
        'penghargaan_khusus' => 'SPECIAL AWARD RECIPIENT',
        'peserta' => 'PARTICIPANT',
    ];

    public static function document(DemoAward $award): DemoDocument
    {
        return $award->isPiagam() ? self::piagam($award) : self::sertifikat($award);
    }

    public static function theme(DemoAward $award): string
    {
        return match (true) {
            $award->level === Level::Internasional => 'internasional',
            in_array($award->organizerType, [OrganizerType::Ptn, OrganizerType::Pts, OrganizerType::OrganisasiProfesi], true) => 'kampus',
            $award->bidang === Bidang::SeniBudaya => 'festival',
            in_array($award->organizerType, [OrganizerType::PemerintahPusat, OrganizerType::PemerintahDaerah, OrganizerType::BumnBumd], true) => $award->bidang === Bidang::Riset ? 'inovasi' : 'nasional',
            default => 'emas',
        };
    }

    public static function piagamVariant(DemoAward $award): string
    {
        return match ($award->organizerType) {
            OrganizerType::PemerintahDaerah => 'hijau',
            OrganizerType::FederasiOlahraga, OrganizerType::OrganisasiProfesi => 'biru',
            default => 'merah',
        };
    }

    private static function sertifikat(DemoAward $award): DemoDocument
    {
        $theme = self::theme($award);
        $c = self::palette(self::PALETTES[$theme]);
        $english = $theme === 'internasional';
        $competition = self::clip($award->competition);
        $organizer = self::clip($award->organizer);
        $signers = DemoPeople::signers($competition, $english ? ['Chairperson, Organizing Committee', 'Head of the Jury'] : ['Ketua Panitia', 'Ketua Dewan Juri'], $english);
        $period = self::period($award, $english);

        if ($english) {
            $where = $award->online ? ' online' : ($award->location !== null ? ' in '.self::clip($award->location, 60) : '');
            $body = sprintf('in the %s, organized by %s and held%s%s.', $competition, $organizer, $where, $period !== null ? ' on '.$period : '');
            $team = $award->team === [] ? null : 'Team members: '.implode(', ', $award->team);
        } else {
            $where = $award->online ? ' secara daring' : ($award->location !== null ? ' di '.self::clip($award->location, 60) : '');
            $body = sprintf(
                'dalam %s%s yang diselenggarakan oleh %s%s%s.',
                $competition,
                self::levelPart($award),
                $organizer,
                $period !== null ? ' pada '.$period : '',
                $where,
            );
            $team = $award->team === [] ? null : 'Bersama tim: '.self::sentenceList($award->team);
        }

        $participation = $award->result === ResultType::Peserta;
        $images = [
            'bg.svg' => DemoArt::certificateBackground($theme, $c),
            'medali.svg' => self::medal($award->result, match ($theme) {
                'emas' => [$c['dark'], $c['accent']],
                'kampus' => ['#ffffff', $c['accent']],
                default => [$c['primary'], $c['accent']],
            }),
            'stempel.svg' => DemoArt::seal($theme === 'festival' ? '#7a2a5c' : '#3b3f9c'),
            'ttd-1.svg' => DemoArt::penStroke($signers[0]['name']),
            'ttd-2.svg' => DemoArt::penStroke($signers[1]['name'], '#1c1c1c'),
        ];

        if (isset(self::EMBLEMS[$theme])) {
            $images['lambang.svg'] = DemoArt::emblem(self::EMBLEMS[$theme], $theme === 'kampus'
                ? ['primary' => '#ffffff', 'accent' => $c['accent'], 'dark' => $c['soft'], 'soft' => '#ffffff']
                : $c);
        }

        return new DemoDocument(
            kind: DemoDocumentKind::Sertifikat,
            view: 'sertifikat',
            data: [
                'theme' => $theme,
                'c' => $c,
                'l' => self::LAYOUTS[$theme],
                'organizer' => mb_strtoupper($organizer),
                'committee' => ($english ? 'Organizing Committee of the ' : 'Panitia ').$competition,
                'heading' => $english ? 'CERTIFICATE' : 'SERTIFIKAT',
                'subheading' => $english
                    ? ($participation ? 'OF PARTICIPATION' : 'OF ACHIEVEMENT')
                    : ($participation ? 'PARTISIPASI' : 'PENGHARGAAN'),
                'number' => ($english ? 'No. ' : 'Nomor: ').self::number($award),
                'presented' => $english ? 'This certificate is proudly presented to' : ($theme === 'emas' ? 'Dengan bangga diberikan kepada' : 'Diberikan kepada'),
                'name' => $award->recipient,
                'affiliation' => $award->affiliation ?? ($english ? DemoPeople::SCHOOL.', Indonesia' : 'Siswa '.DemoPeople::SCHOOL),
                'team' => $team,
                'as' => $english ? 'as' : 'sebagai',
                'result' => $english ? self::RESULTS_EN[$award->result->value] : self::resultLabel($award->result),
                'branch' => self::branch($award, $english),
                'body' => $body,
                'closing' => self::CLOSINGS[$theme],
                'placeDate' => self::placeDate($award, $english),
                'signers' => $signers,
            ],
            images: $images,
            orientation: 'landscape',
            title: ($english ? 'Certificate - ' : 'Sertifikat - ').$competition.' - '.$award->recipient,
        );
    }

    private static function piagam(DemoAward $award): DemoDocument
    {
        $variant = self::piagamVariant($award);
        $c = self::palette(self::PIAGAM_PALETTES[$variant]);
        $competition = self::clip($award->competition);
        $organizer = self::clip($award->organizer);
        $signers = DemoPeople::signers($competition, ['Ketua Panitia Pelaksana', $award->bidang === Bidang::Olahraga ? 'Koordinator Pertandingan' : 'Ketua Dewan Juri']);
        $period = self::period($award, false);
        $where = $award->online ? ' secara daring' : ($award->location !== null ? ' di '.self::clip($award->location, 60) : '');

        $rows = [
            ['Nama', $award->recipient],
            ['Asal sekolah', $award->affiliation ?? DemoPeople::SCHOOL],
        ];

        if ($award->branch !== null && trim($award->branch) !== '') {
            $rows[] = [$award->bidang === Bidang::Olahraga ? 'Cabang olahraga' : 'Kategori', trim($award->branch)];
        }

        if ($award->team !== []) {
            $rows[] = ['Anggota tim', implode(', ', $award->team)];
        }

        return new DemoDocument(
            kind: DemoDocumentKind::Piagam,
            view: 'piagam',
            data: [
                'c' => $c,
                'organizer' => mb_strtoupper($organizer),
                'committee' => mb_strtoupper('Panitia Pelaksana '.$competition),
                'number' => 'Nomor: '.self::number($award),
                'rows' => $rows,
                'result' => self::resultLabel($award->result),
                'resultSize' => match (true) {
                    mb_strlen(self::resultLabel($award->result)) <= 14 => 18,
                    mb_strlen(self::resultLabel($award->result)) <= 22 => 14,
                    default => 11.5,
                },
                'body' => sprintf(
                    'pada %s%s yang diselenggarakan oleh %s%s%s.',
                    $competition,
                    self::levelPart($award),
                    $organizer,
                    $period !== null ? ' pada '.$period : '',
                    $where,
                ),
                'closing' => $award->bidang === Bidang::Olahraga
                    ? 'Semoga prestasi ini menjadi pendorong untuk terus berlatih dan menjunjung tinggi sportivitas.'
                    : 'Semoga penghargaan ini menjadi pendorong untuk terus berkarya dan berprestasi.',
                'placeDate' => self::placeDate($award, false),
                'signers' => $signers,
            ],
            images: [
                'bg.svg' => DemoArt::piagamBackground($c),
                'lambang.svg' => DemoArt::emblem($award->bidang === Bidang::Olahraga ? 'obor' : 'perisai', $c),
                'pita.svg' => DemoArt::banner($c['primary'], $c['dark']),
                'medali.svg' => self::medal($award->result, [$c['primary'], $c['accent']]),
                'stempel.svg' => DemoArt::seal('#3b3f9c'),
                'ttd-1.svg' => DemoArt::penStroke($signers[0]['name']),
                'ttd-2.svg' => DemoArt::penStroke($signers[1]['name'], '#1c1c1c'),
            ],
            title: 'Piagam penghargaan - '.$competition.' - '.$award->recipient,
        );
    }

    // Wording -----------------------------------------------------------------------------------------

    /** "JUARA I", "PERAIH MEDALI EMAS", "FINALIS" … */
    public static function resultLabel(ResultType $result): string
    {
        return match ($result) {
            ResultType::MedaliEmas, ResultType::MedaliPerak, ResultType::MedaliPerunggu => 'PERAIH '.mb_strtoupper($result->label()),
            ResultType::PenghargaanKhusus => 'PENERIMA PENGHARGAAN KHUSUS',
            default => mb_strtoupper($result->label()),
        };
    }

    public static function levelPhrase(Level $level): string
    {
        return 'tingkat '.($level === Level::Kota ? 'kota' : mb_strtolower($level->label()));
    }

    /** " tingkat nasional" after the lomba name, unless the name already says it ("FLS3N Nasional 2025", "OSN Tingkat Kota …"). */
    private static function levelPart(DemoAward $award): string
    {
        if ($award->level === null) {
            return '';
        }

        $name = mb_strtolower($award->competition);
        $word = $award->level === Level::Kota ? 'kota' : mb_strtolower($award->level->label());

        return str_contains($name, 'tingkat') || str_contains($name, $word) ? '' : ' '.self::levelPhrase($award->level);
    }

    /** "17 September 2026", "9 s.d. 10 September 2026", "30 September s.d. 2 Oktober 2026"; English: "21-24 September 2026". */
    public static function period(DemoAward $award, bool $english): ?string
    {
        $start = $award->startsOn ?? $award->finalOn;
        $end = $award->finalOn ?? $award->startsOn;

        if ($start === null || $end === null) {
            return null;
        }

        $locale = $english ? 'en' : 'id';
        $last = $end->locale($locale)->translatedFormat('j F Y');

        if ($start->isSameDay($end)) {
            return $last;
        }

        $join = $english ? '-' : ' s.d. ';

        return match (true) {
            $start->isSameMonth($end) => $start->format('j').$join.$last,
            $start->isSameYear($end) => $start->locale($locale)->translatedFormat('j F').($english ? ' - ' : $join).$last,
            default => $start->locale($locale)->translatedFormat('j F Y').($english ? ' - ' : $join).$last,
        };
    }

    /** "Jakarta, 5 September 2026"; only the date for an online event without a place. */
    public static function placeDate(DemoAward $award, bool $english): string
    {
        $date = ($award->finalOn ?? $award->startsOn ?? CarbonImmutable::create(2026, 9, 30))
            ->locale($english ? 'en' : 'id')->translatedFormat('j F Y');
        $city = self::city($award);

        return $city === null ? $date : $city.', '.$date;
    }

    /** The town a place name stands for: "Kota Semarang" → "Semarang", "SEGi University, Kuala Lumpur" → "Kuala Lumpur". */
    public static function city(DemoAward $award): ?string
    {
        $location = trim((string) $award->location);

        if ($location === '' || mb_strtolower($location) === 'daring') {
            return null;
        }

        if (str_contains($location, ',')) {
            $location = trim((string) Str::afterLast($location, ','));
        }

        if (mb_strtolower($location) === 'jawa tengah') {
            return 'Semarang';
        }

        $location = trim((string) preg_replace('/^(kota|kabupaten)\s+/iu', '', $location));

        return $location === '' ? null : self::clip($location, 40);
    }

    /** "027/CONTOH/OSN/IX/2024": the word CONTOH is part of every number. */
    public static function number(DemoAward $award): string
    {
        $date = $award->finalOn ?? $award->startsOn ?? CarbonImmutable::create(2026, 9, 30);
        $code = mb_strtoupper(Str::limit((string) preg_replace('/[^A-Za-z0-9]/', '', Str::ascii((string) Str::before($award->competition.' ', ' '))), 10, ''));
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$date->month - 1];

        return sprintf('%03d/CONTOH/%s/%s/%d', 1 + DemoPeople::pick($award->competition.'|'.$award->recipient, 480), $code !== '' ? $code : 'LOMBA', $roman, $date->year);
    }

    private static function branch(DemoAward $award, bool $english): ?string
    {
        $branch = trim((string) $award->branch);

        if ($branch === '') {
            return null;
        }

        if ($english) {
            return 'Category: '.$branch;
        }

        return match ($award->bidang) {
            Bidang::Sains => 'Bidang '.$branch,
            Bidang::Olahraga => 'Cabang '.$branch,
            default => 'Kategori '.$branch,
        };
    }

    /**
     * @param  array{0: string, 1: string}  $ribbons
     */
    private static function medal(ResultType $result, array $ribbons): string
    {
        $metal = match ($result->tier()) {
            1 => 'emas',
            2 => 'perak',
            3 => 'perunggu',
            default => 'pita',
        };

        $mark = match ($result) {
            ResultType::Juara1 => '1',
            ResultType::Juara2 => '2',
            ResultType::Juara3 => '3',
            default => null,
        };

        return DemoArt::medal($metal, $ribbons, $mark);
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string}  $colors
     * @return array{primary: string, accent: string, dark: string, soft: string}
     */
    private static function palette(array $colors): array
    {
        return ['primary' => $colors[0], 'accent' => $colors[1], 'dark' => $colors[2], 'soft' => $colors[3]];
    }

    /** @param list<string> $items */
    private static function sentenceList(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' dan '.$last;
    }

    private static function clip(string $text, int $max = 110): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/u', ' ', $text)), $max, '…');
    }
}
