<?php

declare(strict_types=1);

namespace Database\Seeders\Support;

use App\Enums\EventMode;
use App\Models\Competition;
use Database\Seeders\Support\Demo\DemoGuideline;
use GdImage;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Cover pictures ("sampul", the poster on a lomba card) of the local demo data, drawn here with GD (LOCAL ONLY:
 * the callers, DemoSeeder and DemoFileRefresher, refuse to run elsewhere, and render() refuses production). No
 * picture file is committed: a poster is a flat background, a few plain shapes of our own and text.
 *
 * - Portrait 4:5 (960 × 1200), JPEG. Every poster has its own colours and layout, on purpose not the palette of the
 *   app: they are meant to look like the work of different organizers.
 * - Limits of the demo files (D-106): no logo, emblem, stamp or signature of any real body, no person is named,
 *   and the word CONTOH is on every poster twice: as a stamp in the middle part and in the line at the bottom.
 * - Text is set in the DejaVu fonts of resources/fonts through FreeType. Without FreeType or the font files the
 *   built-in GD font is enlarged instead: plain, but nothing fails.
 * - Shapes are drawn at twice the size and scaled down, so their edges are smooth; text is set afterwards.
 * - The same data always gives the same bytes. Every picture carries the identifier of what it was drawn from in
 *   a JPEG comment (id() / hasId()), which is how a later refresh sees that a stored file is up to date, as
 *   DemoPdfRenderer does for the PDFs. A change in this file gives other identifiers.
 *
 * Lives next to DemoFiles and not in Support/Demo: DemoPdfRenderer::fingerprint() covers that whole folder, so a
 * change of a poster would make every demo PDF out of date.
 *
 * To look at all posters while working on them (rows of the local demo database give the dates):
 *
 *   docker compose exec -T app php artisan tinker --execute="Database\Seeders\Support\DemoCoverArt::write(storage_path('app/pdf-tmp/sampul'));"
 */
final class DemoCoverArt
{
    public const WIDTH = 960;

    public const HEIGHT = 1200;

    public const QUALITY = 86;

    /** What a refresh calls these files in its summary. */
    public const LABEL = 'Sampul lomba';

    public const STAMP = 'CONTOH';

    /** At the bottom of every poster. */
    public const FOOTER = 'CONTOH · data fiktif untuk uji SMAGA Talent Hub · bukan poster resmi';

    /**
     * The demo lomba that carry a cover: file name => [lomba as DemoSeeder names it, short name, line under it, look].
     * Seven of the eight published lomba of the demo catalog, over all four bidang; KRENOVA 2027 has none, so the
     * catalog also shows a card without a cover.
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const POSTERS = [
        'sampul-osn-kota-2026.jpg' => ['OSN Tingkat Kota Semarang 2026', 'OSN', 'Olimpiade Sains Nasional', 'orbit'],
        'sampul-lpb-2026.jpg' => ['Lomba Peneliti Belia (LPB) 2026', 'LPB', 'Lomba Peneliti Belia', 'lab'],
        'sampul-fls3n-kota-2026.jpg' => ['FLS3N Kota Semarang 2026', 'FLS3N', 'Festival Seni dan Sastra Siswa', 'festival'],
        'sampul-o2sn-bulu-tangkis-2026.jpg' => ['O2SN Kota Semarang 2026 · Bulu Tangkis', 'O2SN', 'Cabang Bulu Tangkis', 'smash'],
        'sampul-nsif-2026.jpg' => ['National Science and Invention Fair (NSIF) 2026', 'NSIF', 'National Science and Invention Fair', 'sirkuit'],
        'sampul-ldbi-kota-2026.jpg' => ['Lomba Debat Bahasa Indonesia (LDBI) Kota Semarang 2026', 'LDBI', 'Lomba Debat Bahasa Indonesia', 'debat'],
        'sampul-silat-pelajar-jateng-2026.jpg' => ['Kejuaraan Pencak Silat Pelajar Jawa Tengah 2026', 'PENCAK SILAT', 'Kejuaraan Pelajar Jawa Tengah', 'gelanggang'],
    ];

    private const LOOKS = ['orbit', 'lab', 'festival', 'smash', 'sirkuit', 'debat', 'gelanggang'];

    /** Start of the JPEG comment that carries the identifier. */
    private const MARKER = 'STH-DEMO-COVER id=';

    /** Shapes are drawn this many times larger and scaled down. */
    private const SCALE = 2;

    private const FONT_BOLD = 'fonts/DejaVuSans-Bold.ttf';

    private const FONT_REGULAR = 'fonts/DejaVuSans.ttf';

    // Which lomba, which file ------------------------------------------------------------------------------

    /** File name of the cover a demo lomba with this name carries; null = this lomba has no cover. */
    public static function fileNameFor(string $competition): ?string
    {
        foreach (self::POSTERS as $fileName => $poster) {
            if ($poster[0] === $competition) {
                return $fileName;
            }
        }

        return null;
    }

    /** @return list<string> names of the demo lomba that carry a cover */
    public static function competitions(): array
    {
        return array_column(self::POSTERS, 0);
    }

    /**
     * What the poster of a cover file says. Short name, line and look come from POSTERS (an unknown file name gets
     * them from the name itself); year, date, place and deadline from the lomba the file is the cover of, when
     * there is one. Empty strings are simply not printed.
     *
     * @return array{look: string, short: string, title: string, year: string, date: string, place: string, deadline: string}
     */
    public static function describe(string $fileName, ?Competition $competition = null): array
    {
        $poster = self::POSTERS[mb_strtolower($fileName)] ?? null;
        $starts = $competition?->starts_on;
        $final = $competition?->final_on;
        $closes = $competition?->registration_closes_at;
        $place = $competition?->mode === EventMode::Daring ? 'Daring' : (string) $competition?->location;

        return [
            'look' => $poster[3] ?? self::LOOKS[crc32($fileName) % count(self::LOOKS)],
            'short' => $poster[1] ?? self::shortName($fileName),
            'title' => $poster[2] ?? self::clip((string) ($competition?->name ?? 'Lomba pelajar'), 44),
            'year' => (string) ($starts?->year ?? (preg_match('/(?:19|20)\d{2}/', $fileName, $match) === 1 ? $match[0] : '2026')),
            'date' => $starts === null ? '' : DemoGuideline::range($starts, $final !== null && $final->gt($starts) ? $final : $starts),
            'place' => self::clip(trim($place), 34),
            'deadline' => $closes === null ? '' : 'Batas daftar '.$closes->locale('id')->translatedFormat('j F Y'),
        ];
    }

    // Identifier and placeholder ---------------------------------------------------------------------------

    /**
     * 32 hex characters that identify this exact picture: what it says and the code that draws it.
     *
     * @param  array{look: string, short: string, title: string, year: string, date: string, place: string, deadline: string}  $spec
     */
    public static function id(array $spec, ?bool $freeType = null): string
    {
        ksort($spec);

        return substr(hash('sha256', serialize([
            hash_file('sha256', __FILE__),
            self::WIDTH,
            self::HEIGHT,
            self::QUALITY,
            $freeType ?? self::freeType(),
            $spec,
        ])), 0, 32);
    }

    /** Was this JPEG drawn from the picture with that id? */
    public static function hasId(string $bytes, string $id): bool
    {
        return str_contains(substr($bytes, 0, 256), self::MARKER.$id.';');
    }

    /**
     * What a cover is until its poster is drawn (and what it stays in the test suite): a tiny flat grey JPEG of
     * the same 4:5 shape, well under 1 kB. Its comment says what it is, and never looks like an identifier.
     */
    public static function placeholder(): string
    {
        $image = imagecreatetruecolor(16, 20);
        imagefill($image, 0, 0, self::ink($image, '#cfd3d9'));

        return self::tag(self::jpeg($image, 70), 'STH-DEMO-COVER placeholder; CONTOH - data fiktif untuk uji SMAGA Talent Hub');
    }

    // Drawing ----------------------------------------------------------------------------------------------

    /**
     * The poster as JPEG bytes.
     *
     * @param  array{look: string, short: string, title: string, year: string, date: string, place: string, deadline: string}  $spec
     * @param  bool|null  $freeType  false sets the text in the built-in GD font (tests); null = FreeType when it is there
     */
    public static function render(array $spec, ?bool $freeType = null): string
    {
        // Second lock behind the callers' APP_ENV=local checks: these sample posters never exist in production.
        if (app()->isProduction()) {
            throw new RuntimeException('Sampul contoh tidak dibuat di production.');
        }

        $freeType ??= self::freeType();
        $shapes = imagecreatetruecolor(self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);
        $texts = [];

        match ($spec['look']) {
            'lab' => self::lab($shapes, $texts, $spec, $freeType),
            'festival' => self::festival($shapes, $texts, $spec, $freeType),
            'smash' => self::smash($shapes, $texts, $spec, $freeType),
            'sirkuit' => self::sirkuit($shapes, $texts, $spec, $freeType),
            'debat' => self::debat($shapes, $texts, $spec, $freeType),
            'gelanggang' => self::gelanggang($shapes, $texts, $spec, $freeType),
            default => self::orbit($shapes, $texts, $spec, $freeType),
        };

        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($image, $shapes, 0, 0, 0, 0, self::WIDTH, self::HEIGHT, self::WIDTH * self::SCALE, self::HEIGHT * self::SCALE);

        foreach ($texts as $text) {
            self::set($image, $text, $freeType);
        }

        return self::tag(
            self::jpeg($image, self::QUALITY),
            self::MARKER.self::id($spec, $freeType).'; CONTOH - data fiktif untuk uji SMAGA Talent Hub, bukan poster resmi',
        );
    }

    /**
     * Writes every poster of POSTERS into a folder, to look at them. Dates come from the lomba rows of the
     * database when they are there.
     *
     * @return list<string> the files written
     */
    public static function write(string $folder): array
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Sampul contoh hanya dibuat pada APP_ENV=local.');
        }

        (new Filesystem)->ensureDirectoryExists($folder);
        $written = [];

        foreach (self::POSTERS as $fileName => $poster) {
            $path = rtrim($folder, '/\\').'/'.$fileName;
            file_put_contents($path, self::render(self::describe($fileName, Competition::query()->where('name', $poster[0])->first())));
            $written[] = $path;
        }

        return $written;
    }

    // The seven looks (coordinates on the 960 × 1200 poster) ------------------------------------------------

    /**
     * Sains: a dark planet with two orbits on yellow.
     *
     * @param  list<array<int, mixed>>  $texts
     * @param  array<string, string>  $spec
     */
    private static function orbit(GdImage $g, array &$texts, array $spec, bool $freeType): void
    {
        [$bg, $ink, $paper, $hot] = ['#f2b705', '#1b1b1b', '#fffaf0', '#c62f25'];

        self::rect($g, 0, 0, self::WIDTH, self::HEIGHT, $bg);
        self::ring($g, 730, 270, 1130, 5, $ink, 60);
        self::ring($g, 730, 270, 900, 8, $ink);
        self::disc($g, 730, 270, 640, $ink);
        self::ring($g, 730, 270, 520, 3, $bg, 70);
        self::disc($g, 297, 394, 68, $hot);
        self::disc($g, 190, 440, 38, $paper);
        self::disc($g, 222, 118, 22, $ink);

        self::say($texts, $spec['year'], 88, 716, 286, $bg, true, 'middle', 400);
        self::stamp($g, $texts, 262, 556, 8, $hot, $freeType, $bg);

        self::say($texts, $spec['short'], 190, 56, 868, $ink, true, 'left', 640);
        self::say($texts, $spec['title'], 27, 62, 930, $ink, true, 'left', 830);
        self::rect($g, 62, 956, 222, 964, $hot);
        self::facts($texts, $spec, 62, 1018, 'left', $ink, $ink, $hot);
        self::footer($g, $texts, $ink, $bg);
    }

    /**
     * Riset: a honeycomb of molecules on dark green.
     *
     * @param  list<array<int, mixed>>  $texts
     * @param  array<string, string>  $spec
     */
    private static function lab(GdImage $g, array &$texts, array $spec, bool $freeType): void
    {
        [$bg, $lime, $mint, $mid, $deep] = ['#0e3b2e', '#c8f04a', '#eaf7ee', '#1c6b52', '#0a2c22'];

        self::rect($g, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        for ($row = 0; $row < 7; $row++) {
            for ($col = 0; $col < 9; $col++) {
                self::disc($g, 70 + $col * 38, 84 + $row * 38, 7, $lime, 70);
            }
        }

        $cells = [[640, 190, $mid, null], [806, 190, null, $lime], [723, 334, $lime, null], [557, 334, $deep, $lime], [889, 334, $mid, null], [806, 478, $deep, $lime], [640, 478, $mid, null]];

        foreach ($cells as [$x, $y, $fill, $line]) {
            if ($fill !== null) {
                self::poly($g, self::sides($x, $y, 88, 6, 30), $fill);
            }

            if ($line !== null) {
                self::outline($g, self::sides($x, $y, 88, 6, 30), 5, $line);
            }
        }

        self::say($texts, $spec['year'], 37, 723, 336, $bg, true, 'middle', 140);
        self::say($texts, mb_strtoupper($spec['title']), 23, 62, 640, $lime, true, 'left', 560);
        self::say($texts, $spec['short'], 178, 54, 862, $mint, true, 'left', 520);
        self::stamp($g, $texts, 752, 772, -7, $lime, $freeType, $bg);
        self::rect($g, 62, 906, 302, 914, $lime);
        self::facts($texts, $spec, 62, 986, 'left', $mint, $mint, $lime);
        self::footer($g, $texts, $lime, $bg);
    }

    /**
     * Seni budaya: overlapping lanterns and a patterned band on cream.
     *
     * @param  list<array<int, mixed>>  $texts
     * @param  array<string, string>  $spec
     */
    private static function festival(GdImage $g, array &$texts, array $spec, bool $freeType): void
    {
        [$bg, $clay, $sea, $plum, $gold] = ['#fbeed3', '#d1492a', '#12908e', '#5b2245', '#f0a81c'];

        self::rect($g, 0, 0, self::WIDTH, self::HEIGHT, $bg);
        self::disc($g, 290, 300, 500, $clay);
        self::disc($g, 640, 250, 400, $sea, 25);
        self::disc($g, 520, 470, 250, $gold, 20);
        self::ring($g, 290, 300, 580, 4, $clay);
        self::disc($g, 874, 92, 46, $plum);
        self::disc($g, 110, 600, 26, $sea);

        self::rect($g, 0, 660, self::WIDTH, 716, $plum);

        for ($x = 30; $x < self::WIDTH; $x += 60) {
            self::poly($g, self::sides($x, 688, 22, 4), $gold);
            self::poly($g, self::sides($x, 688, 9, 4), $plum);
            self::disc($g, $x + 30, 688, 8, $bg);
        }

        self::say($texts, $spec['year'], 96, 290, 306, $bg, true, 'middle', 380);
        self::stamp($g, $texts, 688, 566, 9, $plum, $freeType, $bg);
        self::say($texts, $spec['short'], 138, 480, 884, $plum, true, 'center', 840);
        self::say($texts, $spec['title'], 25, 480, 942, $clay, true, 'center', 840);
        self::facts($texts, $spec, 480, 1010, 'center', $plum, $plum, $sea, 840, 40);
        self::footer($g, $texts, $plum, $bg);
    }

    /**
     * Olahraga (bulu tangkis): diagonal bands and a shuttlecock.
     *
     * @param  list<array<int, mixed>>  $texts
     * @param  array<string, string>  $spec
     */
    private static function smash(GdImage $g, array &$texts, array $spec, bool $freeType): void
    {
        [$bg, $red, $ink, $sun] = ['#f6f5ef', '#e02424', '#141414', '#ffd21f'];

        self::rect($g, 0, 0, self::WIDTH, self::HEIGHT, $bg);
        self::poly($g, [[0, 0], [960, 0], [960, 330], [0, 640]], $red);
        self::poly($g, [[0, 640], [960, 330], [960, 384], [0, 694]], $ink);
        self::poly($g, [[0, 716], [960, 406], [960, 424], [0, 734]], $sun);

        // Shuttlecock: a skirt of feathers with ribs and a round cork.
        self::poly($g, [[716, 250], [808, 62], [924, 148], [748, 286]], $bg);
        self::line($g, 732, 268, 836, 82, 4, $red);
        self::line($g, 732, 268, 866, 105, 4, $red);
        self::line($g, 732, 268, 896, 127, 4, $red);
        self::line($g, 786, 170, 880, 222, 4, $red);
        self::disc($g, 716, 284, 78, $sun);
        self::ring($g, 716, 284, 78, 6, $ink);

        self::say($texts, $spec['short'], 150, 52, 330, $bg, true, 'left', 600);
        self::say($texts, $spec['year'], 100, 904, 640, $ink, true, 'right', 420);
        self::stamp($g, $texts, 286, 806, 8, $red, $freeType, $bg);
        self::say($texts, $spec['title'], 30, 904, 706, $ink, true, 'right', 520);

        self::rect($g, 0, 944, self::WIDTH, 1136, $red);
        self::facts($texts, $spec, 62, 1006, 'left', $bg, $bg, $sun, 840, 42);
        self::footer($g, $texts, $ink, $bg);
    }

    /**
     * Riset (pameran inovasi): circuit tracks on charcoal.
     *
     * @param  list<array<int, mixed>>  $texts
     * @param  array<string, string>  $spec
     */
    private static function sirkuit(GdImage $g, array &$texts, array $spec, bool $freeType): void
    {
        [$bg, $cyan, $amber, $grey, $white] = ['#15171c', '#27d3f5', '#ff7a1a', '#98a2b3', '#f4f6f8'];

        self::rect($g, 0, 0, self::WIDTH, self::HEIGHT, $bg);

        for ($i = 1; $i < 16; $i++) {
            self::line($g, $i * 60, 0, $i * 60, 600, 1, $white, 116);
        }

        for ($i = 1; $i < 10; $i++) {
            self::line($g, 0, $i * 60, self::WIDTH, $i * 60, 1, $white, 116);
        }

        // Tracks: straight runs with one bend, a pad at each end.
        $tracks = [
            [[60, 120], [300, 120], [360, 180], [470, 180]],
            [[60, 240], [200, 240], [260, 300], [440, 300]],
            [[120, 420], [320, 420], [380, 360], [470, 360]],
            [[180, 540], [420, 540], [480, 480], [560, 480]],
            [[900, 560], [760, 560], [700, 500]],
        ];

        foreach ($tracks as $n => $track) {
            $color = $n % 2 === 0 ? $cyan : $grey;

            for ($i = 1; $i < count($track); $i++) {
                self::line($g, $track[$i - 1][0], $track[$i - 1][1], $track[$i][0], $track[$i][1], 6, $color);
            }

            foreach ([$track[0], $track[count($track) - 1]] as [$x, $y]) {
                self::disc($g, $x, $y, 24, $color);
                self::disc($g, $x, $y, 10, $bg);
            }
        }

        self::ring($g, 706, 268, 400, 26, $amber);
        self::ring($g, 706, 268, 300, 4, $cyan);
        self::rect($g, 846, 60, 900, 114, $amber);
        self::rect($g, 864, 78, 882, 96, $bg);

        self::say($texts, $spec['year'], 66, 706, 272, $white, true, 'middle', 250);
        self::say($texts, $spec['short'], 160, 54, 812, $white, true, 'left', 540);
        self::stamp($g, $texts, 762, 716, -8, $amber, $freeType, $bg);
        self::rect($g, 62, 846, 362, 856, $amber);
        self::say($texts, $spec['title'], 25, 62, 912, $cyan, true, 'left', 830);
        self::facts($texts, $spec, 62, 992, 'left', $white, $grey, $cyan);
        self::footer($g, $texts, $cyan, $bg);
    }

    /**
     * Seni budaya (debat): two speech bubbles on paper white.
     *
     * @param  list<array<int, mixed>>  $texts
     * @param  array<string, string>  $spec
     */
    private static function debat(GdImage $g, array &$texts, array $spec, bool $freeType): void
    {
        [$bg, $tomato, $pine, $ink, $sun] = ['#f3efe6', '#e4572e', '#17635b', '#1e1e24', '#f6c445'];

        self::rect($g, 0, 0, self::WIDTH, self::HEIGHT, $bg);
        self::disc($g, 846, 150, 150, $sun);
        self::disc($g, 110, 640, 60, $sun);

        self::rounded($g, 64, 96, 590, 430, 64, $tomato);
        self::poly($g, [[160, 420], [262, 420], [142, 528]], $tomato);
        self::rounded($g, 384, 356, 896, 664, 64, $pine);
        self::poly($g, [[700, 654], [800, 654], [832, 756]], $pine);

        self::say($texts, $spec['short'], 112, 327, 268, $bg, true, 'middle', 440);
        self::say($texts, $spec['year'], 100, 640, 514, $bg, true, 'middle', 430);
        self::stamp($g, $texts, 248, 760, 8, $ink, $freeType, $bg);

        self::say($texts, $spec['title'], 34, 64, 912, $ink, true, 'left', 830);
        self::rect($g, 64, 936, 224, 944, $tomato);
        self::facts($texts, $spec, 64, 1004, 'left', $ink, $ink, $tomato, 830, 42);
        self::footer($g, $texts, $ink, $bg);
    }

    /**
     * Olahraga (bela diri): a gold arena with a sash on maroon.
     *
     * @param  list<array<int, mixed>>  $texts
     * @param  array<string, string>  $spec
     */
    private static function gelanggang(GdImage $g, array &$texts, array $spec, bool $freeType): void
    {
        [$bg, $gold, $night, $ivory] = ['#5e0f1c', '#e2b33c', '#14090c', '#f6ead0'];

        self::rect($g, 0, 0, self::WIDTH, self::HEIGHT, $bg);
        self::ring($g, 480, 350, 760, 6, $gold, 40);
        self::disc($g, 480, 350, 620, $gold);
        self::outline($g, self::sides(480, 350, 250, 8, 22.5), 10, $bg);
        self::poly($g, [[0, 470], [960, 250], [960, 336], [0, 556]], $night);
        self::poly($g, [[0, 572], [960, 352], [960, 366], [0, 586]], $ivory);
        self::poly($g, [[0, 1136], [0, 1010], [150, 1136]], $night);
        self::poly($g, [[960, 1136], [960, 1010], [810, 1136]], $night);

        self::say($texts, $spec['year'], 92, 470, 232, $bg, true, 'middle', 380);
        self::stamp($g, $texts, 480, 690, -7, $ivory, $freeType, $bg);
        self::say($texts, $spec['short'], 84, 480, 872, $ivory, true, 'center', 840);
        self::say($texts, $spec['title'], 26, 480, 930, $gold, true, 'center', 840);
        self::facts($texts, $spec, 480, 1002, 'center', $ivory, $ivory, $gold, 640, 40);
        self::footer($g, $texts, $gold, $bg);
    }

    // Parts every poster has --------------------------------------------------------------------------------

    /**
     * Date, place and deadline, one under the other.
     *
     * @param  list<array<int, mixed>>  $texts
     * @param  array<string, string>  $spec
     */
    private static function facts(array &$texts, array $spec, float $x, float $y, string $align, string $strong, string $soft, string $accent, float $max = 830, float $step = 44): void
    {
        foreach ([[$spec['date'], 29, $strong, true], [$spec['place'], 21, $soft, false], [$spec['deadline'], 19, $accent, true]] as [$line, $size, $color, $bold]) {
            if ($line === '') {
                continue;
            }

            self::say($texts, $line, $size, $x, $y, $color, $bold, $align, $max);
            $y += $step;
        }
    }

    /**
     * The band at the bottom with the CONTOH line.
     *
     * @param  list<array<int, mixed>>  $texts
     */
    private static function footer(GdImage $g, array &$texts, string $band, string $ink): void
    {
        self::rect($g, 0, 1136, self::WIDTH, self::HEIGHT, $band);
        self::say($texts, self::FOOTER, 15, self::WIDTH / 2, 1168, $ink, true, 'middle', 890);
    }

    /**
     * A turned frame that reads CONTOH, like a rubber stamp. It sits in the middle part of every poster, so it stays
     * in sight when a card shows only part of the picture.
     *
     * @param  list<array<int, mixed>>  $texts
     */
    private static function stamp(GdImage $g, array &$texts, float $cx, float $cy, float $angle, string $color, bool $freeType, string $fill): void
    {
        // The built-in font cannot be turned.
        $angle = $freeType ? $angle : 0.0;

        self::poly($g, self::frame($cx, $cy, 336, 110, $angle), $fill);
        self::outline($g, self::frame($cx, $cy, 328, 102, $angle), 9, $color);
        self::outline($g, self::frame($cx, $cy, 298, 72, $angle), 3, $color);
        self::say($texts, self::STAMP, 40, $cx, $cy, $color, true, 'middle', 262, $angle);
    }

    // Shapes (on the enlarged layer) ------------------------------------------------------------------------

    private static function rect(GdImage $g, float $x1, float $y1, float $x2, float $y2, string $hex, int $alpha = 0): void
    {
        self::poly($g, [[$x1, $y1], [$x2, $y1], [$x2, $y2], [$x1, $y2]], $hex, $alpha);
    }

    /** @param list<array{0: float|int, 1: float|int}> $points */
    private static function poly(GdImage $g, array $points, string $hex, int $alpha = 0): void
    {
        $flat = [];

        foreach ($points as [$x, $y]) {
            $flat[] = (int) round($x * self::SCALE);
            $flat[] = (int) round($y * self::SCALE);
        }

        imagefilledpolygon($g, $flat, self::ink($g, $hex, $alpha));
    }

    /** A filled circle of diameter $d. */
    private static function disc(GdImage $g, float $cx, float $cy, float $d, string $hex, int $alpha = 0): void
    {
        self::poly($g, self::sides($cx, $cy, $d / 2, 96), $hex, $alpha);
    }

    /** A circle line of diameter $d (outer edge) and the given thickness. */
    private static function ring(GdImage $g, float $cx, float $cy, float $d, float $thickness, string $hex, int $alpha = 0): void
    {
        $outer = self::sides($cx, $cy, $d / 2, 120);
        $inner = self::sides($cx, $cy, $d / 2 - $thickness, 120);

        // Out along the outer edge, back along the inner one: one polygon with a hole.
        self::poly($g, [...$outer, $outer[0], $inner[0], ...array_reverse($inner)], $hex, $alpha);
    }

    private static function line(GdImage $g, float $x1, float $y1, float $x2, float $y2, float $thickness, string $hex, int $alpha = 0): void
    {
        $length = hypot($x2 - $x1, $y2 - $y1);

        if ($length <= 0.0) {
            return;
        }

        $nx = -($y2 - $y1) / $length * $thickness / 2;
        $ny = ($x2 - $x1) / $length * $thickness / 2;

        self::poly($g, [[$x1 + $nx, $y1 + $ny], [$x2 + $nx, $y2 + $ny], [$x2 - $nx, $y2 - $ny], [$x1 - $nx, $y1 - $ny]], $hex, $alpha);
    }

    /**
     * The edges of a polygon as lines with round joints.
     *
     * @param  list<array{0: float|int, 1: float|int}>  $points
     */
    private static function outline(GdImage $g, array $points, float $thickness, string $hex): void
    {
        $count = count($points);

        foreach ($points as $i => [$x, $y]) {
            [$nextX, $nextY] = $points[($i + 1) % $count];
            self::line($g, $x, $y, $nextX, $nextY, $thickness, $hex);
            self::disc($g, $x, $y, $thickness, $hex);
        }
    }

    private static function rounded(GdImage $g, float $x1, float $y1, float $x2, float $y2, float $radius, string $hex): void
    {
        self::rect($g, $x1 + $radius, $y1, $x2 - $radius, $y2, $hex);
        self::rect($g, $x1, $y1 + $radius, $x2, $y2 - $radius, $hex);

        foreach ([[$x1 + $radius, $y1 + $radius], [$x2 - $radius, $y1 + $radius], [$x1 + $radius, $y2 - $radius], [$x2 - $radius, $y2 - $radius]] as [$cx, $cy]) {
            self::disc($g, $cx, $cy, $radius * 2, $hex);
        }
    }

    /**
     * Corners of a regular polygon; many sides make a circle. $turn 0 puts the first corner at the top.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function sides(float $cx, float $cy, float $radius, int $sides, float $turn = 0.0): array
    {
        $points = [];

        for ($i = 0; $i < $sides; $i++) {
            $angle = deg2rad(-90 + $turn + $i * 360 / $sides);
            $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
        }

        return $points;
    }

    /**
     * Corners of a rectangle around its centre, turned counter-clockwise like imagettftext() turns text.
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function frame(float $cx, float $cy, float $width, float $height, float $angle): array
    {
        $cos = cos(deg2rad($angle));
        $sin = sin(deg2rad($angle));
        $points = [];

        foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$sx, $sy]) {
            $dx = $sx * $width / 2;
            $dy = $sy * $height / 2;
            $points[] = [$cx + $dx * $cos + $dy * $sin, $cy - $dx * $sin + $dy * $cos];
        }

        return $points;
    }

    // Text (on the finished picture) ------------------------------------------------------------------------

    /**
     * Remember a line of text; it is set after the shapes are scaled down. $align: left | center | right put $x at
     * that side and $y on the baseline; middle puts ($x, $y) in the centre of the text (the only one that turns).
     *
     * @param  list<array<int, mixed>>  $texts
     */
    private static function say(array &$texts, string $text, float $size, float $x, float $y, string $hex, bool $bold = true, string $align = 'left', ?float $max = null, float $angle = 0.0): void
    {
        if (trim($text) !== '') {
            $texts[] = [$text, $size, $x, $y, $hex, $bold, $align, $max, $angle];
        }
    }

    /** @param array<int, mixed> $job */
    private static function set(GdImage $image, array $job, bool $freeType): void
    {
        [$text, $size, $x, $y, $hex, $bold, $align, $max, $angle] = $job;

        if (! $freeType) {
            self::setBuiltIn($image, $text, $size, $x, $y, $hex, $align, $max);

            return;
        }

        $font = resource_path($bold ? self::FONT_BOLD : self::FONT_REGULAR);
        $box = (array) imagettfbbox($size, 0, $font, $text);
        $width = abs($box[2] - $box[0]);

        if ($max !== null && $width > $max) {
            $size = floor($size * $max / $width * 10) / 10;
            $box = (array) imagettfbbox($size, 0, $font, $text);
            $width = abs($box[2] - $box[0]);
        }

        $color = self::ink($image, $hex);

        if ($align === 'middle') {
            // Centre of the (turned) box of the text, measured from the point it is set at.
            $turned = (array) imagettfbbox($size, $angle, $font, $text);
            imagettftext($image, $size, $angle, (int) round($x - ($turned[0] + $turned[4]) / 2), (int) round($y - ($turned[1] + $turned[5]) / 2), $color, $font, $text);

            return;
        }

        $left = match ($align) {
            'center' => $x - $width / 2,
            'right' => $x - $width,
            default => $x,
        };

        imagettftext($image, $size, 0, (int) round($left - $box[0]), (int) round($y), $color, $font, $text);
    }

    /** Without FreeType: the largest built-in font, enlarged with hard pixels. ASCII only, never turned. */
    private static function setBuiltIn(GdImage $image, string $text, float $size, float $x, float $y, string $hex, string $align, ?float $max): void
    {
        $ascii = trim((string) preg_replace('/[^\x20-\x7E]+/', '-', $text));

        if ($ascii === '') {
            return;
        }

        $width = imagefontwidth(5) * strlen($ascii);
        $height = imagefontheight(5);
        $scale = max(1.0, $size * 1.7 / $height);

        if ($max !== null && $width * $scale > $max) {
            $scale = $max / $width;
        }

        $glyphs = imagecreatetruecolor($width, $height);
        imagealphablending($glyphs, false);
        imagefill($glyphs, 0, 0, self::ink($glyphs, '#000000', 127));
        imagestring($glyphs, 5, 0, 0, $ascii, self::ink($glyphs, $hex));

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $left = match ($align) {
            'center', 'middle' => $x - $targetWidth / 2,
            'right' => $x - $targetWidth,
            default => $x,
        };
        $top = $align === 'middle' ? $y - $targetHeight / 2 : $y - $targetHeight * 0.82;

        imagecopyresized($image, $glyphs, (int) round($left), (int) round($top), 0, 0, $targetWidth, $targetHeight, $width, $height);
    }

    // Helpers -----------------------------------------------------------------------------------------------

    private static function freeType(): bool
    {
        return function_exists('imagettftext')
            && is_file(resource_path(self::FONT_BOLD))
            && is_file(resource_path(self::FONT_REGULAR));
    }

    /** A colour of the picture; $alpha 0 = solid … 127 = invisible. */
    private static function ink(GdImage $image, string $hex, int $alpha = 0): int
    {
        [$red, $green, $blue] = array_map(hexdec(...), str_split(ltrim($hex, '#'), 2));

        return (int) imagecolorallocatealpha($image, (int) $red, (int) $green, (int) $blue, $alpha);
    }

    private static function jpeg(GdImage $image, int $quality): string
    {
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** Puts a comment into the JPEG, after the JFIF header that must come first. */
    private static function tag(string $jpeg, string $comment): string
    {
        // SOI (2 bytes), then the JFIF segment: marker FF E0 + its length, which counts the two length bytes.
        $at = substr($jpeg, 2, 2) === "\xFF\xE0" ? 4 + (ord($jpeg[4]) << 8 | ord($jpeg[5])) : 2;

        return substr($jpeg, 0, $at)."\xFF\xFE".pack('n', strlen($comment) + 2).$comment.substr($jpeg, $at);
    }

    /** "sampul-lomba-cerdas-cermat-2026.jpg" → "CERDAS CERMAT". */
    private static function shortName(string $fileName): string
    {
        $words = array_filter(
            explode('-', mb_strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', pathinfo($fileName, PATHINFO_FILENAME)))),
            fn (string $word): bool => $word !== '' && ! in_array($word, ['sampul', 'cover', 'poster', 'lomba'], true) && preg_match('/^\d{4}$/', $word) !== 1,
        );

        return $words === [] ? 'LOMBA' : mb_strtoupper(implode(' ', array_slice($words, 0, 2)));
    }

    private static function clip(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : rtrim(mb_substr($text, 0, $max - 1)).'…';
    }
}
