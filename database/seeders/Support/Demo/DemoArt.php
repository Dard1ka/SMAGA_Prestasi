<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

/**
 * Decoration of the local demo documents, drawn here as small SVG files (LOCAL ONLY, see DemoFileRefresher).
 * Everything is made of plain shapes of our own: corner bands, frames, pattern strips, medals, a committee
 * seal that says CONTOH, pen strokes, neutral emblems. No logo, emblem, stamp or signature of any real
 * institution or person, and nothing copied from a template.
 *
 * Only the SVG subset dompdf's php-svg-lib draws well is used: polygon, rect, circle, ellipse, line, path and
 * short ASCII text in a core font; no gradients, filters, masks, CSS or text on a path. Text of a document
 * (names, titles) never goes into an SVG: it is printed by the Blade template with {{ }}.
 */
final class DemoArt
{
    /** Metal colours of a medal: [rim, face, centre, shine]. */
    private const METALS = [
        'emas' => ['#b8891a', '#f3dd8e', '#d9b23c', '#fff6cf'],
        'perak' => ['#8b9299', '#e9ecee', '#bfc5ca', '#ffffff'],
        'perunggu' => ['#96592a', '#e7bd94', '#c98a52', '#fbe6d2'],
    ];

    /**
     * Letters and digits are drawn as strokes, not as SVG text: php-svg-lib only finds a font for <text> when its
     * metrics happen to be in the font cache, so text would make the output depend on what was rendered before.
     * The word CONTOH on the stamp, each letter in a box of 3.2 × 5.
     */
    private const CONTOH = [
        'M3.1,0.9 C2.4,-0.3 0.2,-0.3 0.2,2.5 C0.2,5.3 2.4,5.3 3.1,4.1',
        'M1.6,0 C3.5,0 3.5,5 1.6,5 C-0.3,5 -0.3,0 1.6,0 Z',
        'M0.2,5 V0 L3,5 V0',
        'M0,0 H3.2 M1.6,0 V5',
        'M1.6,0 C3.5,0 3.5,5 1.6,5 C-0.3,5 -0.3,0 1.6,0 Z',
        'M0.2,0 V5 M3,0 V5 M0.2,2.5 H3',
    ];

    /** The place on a medal, around the centre (0, 0), 16 high. */
    private const DIGITS = [
        '1' => 'M-3.2,-4.6 L0.6,-8 V8 M-3.6,8 H4.6',
        '2' => 'M-4.4,-4.4 C-4.4,-9.4 4.4,-9.4 4.4,-4.4 C4.4,-0.6 -4.4,3 -4.6,8 H4.8',
        '3' => 'M-4.2,-5.8 C-2.4,-9.4 4.2,-8.8 4.2,-4.4 C4.2,-1 0.6,-0.4 -0.8,-0.4 C1.2,-0.4 4.8,0.6 4.8,4 C4.8,8.8 -2.6,9.4 -4.8,6',
    ];

    // Certificate backgrounds (landscape, 297 × 210) -----------------------------------------------

    /**
     * @param  array{primary: string, accent: string, dark: string, soft: string}  $c
     */
    public static function certificateBackground(string $theme, array $c): string
    {
        $body = match ($theme) {
            'nasional' => self::backgroundNasional($c),
            'kampus' => self::backgroundKampus($c),
            'internasional' => self::backgroundInternasional($c),
            'festival' => self::backgroundFestival($c),
            'inovasi' => self::backgroundInovasi($c),
            default => self::backgroundEmas($c),
        };

        return self::svg(297, 210, $body);
    }

    /** Black and gold diagonal bands in the four corners, white centre. */
    private static function backgroundEmas(array $c): string
    {
        $gold = $c['accent'];
        $dark = $c['dark'];
        $shapes = [
            // top left
            [[0, 0], [86, 0], [0, 86], $gold],
            [[0, 0], [70, 0], [0, 70], $dark],
            [[0, 52], [52, 0], [47, 0], [0, 47], '#ffffff'],
            [[0, 33], [33, 0], [29.5, 0], [0, 29.5], $gold],
            // top right
            [[297, 0], [252, 0], [297, 45], $gold],
            [[236, 0], [229, 0], [297, 68], [297, 61], $dark],
            // bottom left
            [[0, 210], [45, 210], [0, 165], $gold],
            [[61, 210], [68, 210], [0, 142], [0, 149], $dark],
        ];

        $out = '';

        foreach ($shapes as $shape) {
            $fill = array_pop($shape);
            $out .= self::polygon($shape, $fill);
        }

        // bottom right = top left turned around
        foreach (array_slice($shapes, 0, 4) as $shape) {
            $fill = array_pop($shape);
            $out .= self::polygon(array_map(fn (array $p): array => [297 - $p[0], 210 - $p[1]], $shape), $fill);
        }

        return $out;
    }

    /** Formal double frame, corner blocks and two diagonal ribbons. */
    private static function backgroundNasional(array $c): string
    {
        $out = self::circle(148.5, 108, 64, null, $c['soft'], 1.4).self::circle(148.5, 108, 59, null, $c['soft'], 0.5);

        foreach ([[0, 0], [1, 1]] as [$mx, $my]) {
            $flip = fn (array $p): array => [$mx === 1 ? 297 - $p[0] : $p[0], $my === 1 ? 210 - $p[1] : $p[1]];
            $out .= self::polygon(array_map($flip, [[6, 46], [46, 6], [58, 6], [6, 58]]), $c['primary']);
            $out .= self::polygon(array_map($flip, [[6, 62], [62, 6], [66, 6], [6, 66]]), $c['primary']);
        }

        $out .= self::rect(6, 6, 285, 198, null, $c['dark'], 2.6).self::rect(9.6, 9.6, 277.8, 190.8, null, $c['accent'], 0.7);

        foreach ([[3, 3], [283, 3], [3, 196], [283, 196]] as [$x, $y]) {
            $out .= self::rect($x, $y, 11, 11, $c['dark']);
            $out .= self::polygon([[$x + 5.5, $y + 1.6], [$x + 9.4, $y + 5.5], [$x + 5.5, $y + 9.4], [$x + 1.6, $y + 5.5]], $c['accent']);
        }

        return $out;
    }

    /** A patterned band on the left, a thin accent stripe and a dot grid. */
    private static function backgroundKampus(array $c): string
    {
        $out = self::rect(0, 0, 64, 210, $c['primary']);

        for ($row = 0; $row < 10; $row++) {
            for ($col = 0; $col < 3; $col++) {
                $x = $col * 21.34;
                $y = $row * 21;
                $down = ($row + $col) % 2 === 0;
                $points = $down
                    ? [[$x, $y], [$x + 21.34, $y], [$x, $y + 21]]
                    : [[$x + 21.34, $y], [$x + 21.34, $y + 21], [$x, $y + 21]];
                $out .= self::polygon($points, ($row * 2 + $col) % 3 === 0 ? $c['dark'] : $c['soft'], ($row + $col) % 4 === 1 ? 0.35 : 0.2);
            }
        }

        $out .= self::rect(64, 0, 2.4, 210, $c['accent']);

        for ($i = 0; $i < 6; $i++) {
            for ($j = 0; $j < 4; $j++) {
                $out .= self::circle(258 + $i * 5.6, 12 + $j * 5.6, 0.8, $c['accent']);
            }
        }

        return $out.self::polygon([[297, 210], [297, 176], [263, 210]], $c['primary'])
            .self::polygon([[297, 170], [297, 164], [251, 210], [257, 210]], $c['accent']);
    }

    /** Two waves at the top and at the bottom with a thin gold line. */
    private static function backgroundInternasional(array $c): string
    {
        return self::path('M0,0 L297,0 L297,18 C226,36 118,4 0,28 Z', $c['soft'])
            .self::path('M0,0 L297,0 L297,10 C216,27 108,0 0,18 Z', $c['primary'])
            .self::path('M0,31 C118,7 226,39 297,21', null, $c['accent'], 0.9)
            .self::path('M297,210 L0,210 L0,192 C71,174 179,206 297,182 Z', $c['soft'])
            .self::path('M297,210 L0,210 L0,200 C81,183 189,210 297,192 Z', $c['primary'])
            .self::path('M297,179 C179,203 71,171 0,189', null, $c['accent'], 0.9);
    }

    /** Warm paper tone with a diamond pattern strip above and below. */
    private static function backgroundFestival(array $c): string
    {
        $out = self::rect(0, 0, 297, 210, '#fff9ee');

        foreach ([0, 195] as $top) {
            $out .= self::rect(0, $top, 297, 15, $c['primary']);
            $mid = $top + 7.5;

            for ($x = 7.5; $x < 297; $x += 15) {
                $out .= self::polygon([[$x, $mid - 5.6], [$x + 5.6, $mid], [$x, $mid + 5.6], [$x - 5.6, $mid]], $c['accent']);
                $out .= self::polygon([[$x, $mid - 2.4], [$x + 2.4, $mid], [$x, $mid + 2.4], [$x - 2.4, $mid]], '#fff9ee');
                $out .= self::circle($x + 7.5, $mid, 1, '#fff9ee');
            }
        }

        $out .= self::rect(0, 17.2, 297, 0.6, $c['primary']).self::rect(0, 192.2, 297, 0.6, $c['primary']);

        foreach ([10, 286.5] as $x) {
            $out .= self::rect($x, 24, 0.5, 162, $c['accent']);
            $out .= self::polygon([[$x + 0.25, 100], [$x + 3.4, 105], [$x + 0.25, 110], [$x - 2.9, 105]], $c['accent']);
        }

        return $out;
    }

    /** Honeycomb clusters in two corners and two thin lines. */
    private static function backgroundInovasi(array $c): string
    {
        $radius = 11.0;
        $stepX = sqrt(3) * $radius;
        $stepY = 1.5 * $radius;
        // [column, row, style]: 0 primary, 1 dark, 2 soft, 3 outline only
        $cells = [[1, 0, 0], [2, 0, 1], [3, 0, 0], [2, 1, 2], [3, 1, 0], [3, 2, 1], [2, 2, 3], [0, 0, 3]];
        $out = '';

        foreach ([false, true] as $turned) {
            foreach ($cells as [$col, $row, $style]) {
                $cx = 232 + $col * $stepX + ($row % 2) * ($stepX / 2);
                $cy = 2 + $row * $stepY;

                if ($turned) {
                    [$cx, $cy] = [297 - $cx, 210 - $cy];
                }

                $points = self::hexagon($cx, $cy, $radius - 0.9);
                $out .= $style === 3
                    ? self::polygon($points, null, 1, $c['primary'], 0.8)
                    : self::polygon($points, [$c['primary'], $c['dark'], $c['soft']][$style]);
            }
        }

        return $out.self::rect(18, 11, 190, 0.7, $c['primary']).self::rect(18, 13, 120, 0.35, $c['accent'])
            .self::rect(89, 198.3, 190, 0.7, $c['primary']).self::rect(159, 196.6, 120, 0.35, $c['accent']);
    }

    // Piagam (portrait, 210 × 297) -----------------------------------------------------------------

    /** @param array{primary: string, accent: string, dark: string, soft: string} $c */
    public static function piagamBackground(array $c): string
    {
        $out = self::rect(0, 0, 210, 297, '#fffdf6');
        $out .= self::rect(8, 8, 194, 281, null, $c['primary'], 2.4);
        $out .= self::rect(11.4, 11.4, 187.2, 274.2, null, $c['accent'], 0.8);
        $out .= self::rect(13.2, 13.2, 183.6, 270.6, null, $c['primary'], 0.3);

        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$mx, $my]) {
            $flip = fn (array $p): array => [$mx === 1 ? 210 - $p[0] : $p[0], $my === 1 ? 297 - $p[1] : $p[1]];
            $out .= self::polygon(array_map($flip, [[5, 5], [27, 5], [27, 9], [9, 9], [9, 27], [5, 27]]), $c['primary']);
            $out .= self::polygon(array_map($flip, [[14.5, 10.5], [18.5, 14.5], [14.5, 18.5], [10.5, 14.5]]), $c['accent']);
            $out .= self::polygon(array_map($flip, [[24, 13], [26.4, 15.4], [24, 17.8], [21.6, 15.4]]), $c['accent']);
            $out .= self::polygon(array_map($flip, [[15.4, 21.6], [17.8, 24], [15.4, 26.4], [13, 24]]), $c['accent']);
        }

        // A quiet ring behind the body.
        return self::svg(210, 297, $out.self::circle(105, 168, 58, null, $c['soft'], 1.2).self::circle(105, 168, 53.5, null, $c['soft'], 0.4));
    }

    // Brochure / poster / cover art ------------------------------------------------------------------

    /**
     * Full-page art of a brochure or poster (portrait, 210 × 297).
     *
     * @param  array{primary: string, accent: string, dark: string, soft: string}  $c
     */
    public static function flyerBackground(string $style, array $c): string
    {
        if ($style === 'poster') {
            // Dark page, film-strip like blocks on both sides and a spotlight.
            $out = self::rect(0, 0, 210, 297, $c['dark']);
            $out .= self::polygon([[105, 0], [210, 0], [210, 150], [150, 190]], $c['primary'], 0.55);
            $out .= self::polygon([[105, 0], [0, 0], [0, 110], [70, 190]], $c['primary'], 0.3);
            $out .= self::circle(105, 118, 74, null, $c['accent'], 0.6).self::circle(105, 118, 81, null, $c['accent'], 0.25);

            foreach ([6, 195] as $x) {
                $out .= self::rect($x, 0, 9, 297, '#000000', null, 0, 0.55);

                for ($y = 6; $y < 297; $y += 14) {
                    $out .= self::rect($x + 2, $y, 5, 7, $c['soft']);
                }
            }

            return self::svg(210, 297, $out.self::rect(0, 232, 210, 65, '#000000', null, 0, 0.35));
        }

        // Brochure: light page, a slanted colour block on top, circles and a base band.
        $out = self::rect(0, 0, 210, 297, '#ffffff');
        $out .= self::polygon([[0, 0], [210, 0], [210, 96], [0, 128]], $c['primary']);
        $out .= self::polygon([[0, 128], [210, 96], [210, 101], [0, 133]], $c['accent']);
        $out .= self::circle(178, 30, 46, $c['dark'], null, 0, 0.35).self::circle(196, 74, 22, $c['accent'], null, 0, 0.8);
        $out .= self::circle(8, 122, 30, null, '#ffffff', 0.7, 0.5).self::circle(8, 122, 38, null, '#ffffff', 0.35, 0.5);

        return self::svg(210, 297, $out.self::rect(0, 271, 210, 26, $c['dark']).self::rect(0, 269, 210, 2, $c['accent']));
    }

    // Small pieces ---------------------------------------------------------------------------------------

    /**
     * A medal with two ribbons (60 × 84). $metal: emas | perak | perunggu; anything else gives a rosette in the
     * ribbon colours (finalists, participants). $mark: a digit drawn on the face, or null for a star.
     *
     * @param  array{0: string, 1: string}  $ribbons
     */
    public static function medal(string $metal, array $ribbons, ?string $mark = null): string
    {
        $out = self::polygon([[19, 40], [6, 82], [18, 73], [27, 83], [31, 44]], $ribbons[0])
            .self::polygon([[41, 40], [54, 82], [42, 73], [33, 83], [29, 44]], $ribbons[1]);

        if (! isset(self::METALS[$metal])) {
            for ($i = 0; $i < 16; $i++) {
                $angle = deg2rad($i * 22.5);
                $out .= self::circle(30 + 24.5 * cos($angle), 28 + 24.5 * sin($angle), 4.6, $ribbons[$i % 2]);
            }

            return self::svg(60, 84, $out.self::circle(30, 28, 22.5, '#ffffff').self::circle(30, 28, 20, $ribbons[0])
                .self::circle(30, 28, 16.5, null, '#ffffff', 0.7).self::polygon(self::star(30, 28, 10.5, 4.3), '#ffffff'));
        }

        [$rim, $face, $centre, $shine] = self::METALS[$metal];
        $out .= self::circle(30, 28, 26.5, $rim).self::circle(30, 28, 22.6, $face).self::circle(30, 28, 19.4, $centre)
            .self::circle(30, 28, 16.6, null, $shine, 0.6);

        if (isset(self::DIGITS[$mark])) {
            $out .= sprintf('<g transform="translate(30 28)">%s</g>', self::path(self::DIGITS[$mark], null, $shine, 2.7));
        } else {
            $out .= self::polygon(self::star(30, 28, 12.5, 5.1), $shine);
        }

        return self::svg(60, 84, $out);
    }

    /** Round committee stamp that reads CONTOH (60 × 60): never the stamp of a real body. */
    public static function seal(string $color): string
    {
        $out = self::circle(30, 30, 27.5, null, $color, 1.7, 0.8).self::circle(30, 30, 24.6, null, $color, 0.5, 0.8)
            .self::circle(30, 30, 15.5, null, $color, 0.8, 0.8);

        for ($i = 0; $i < 20; $i++) {
            $angle = deg2rad($i * 18);
            $out .= self::circle(30 + 20 * cos($angle), 30 + 20 * sin($angle), 0.75, $color, null, 0, 0.8);
        }

        // "CONTOH" in pen strokes, centred on the stamp: 6 letters of 3.2 × 5 with 1.1 between them.
        $x = 30 - (6 * 3.2 + 5 * 1.1) / 2;

        foreach (self::CONTOH as $letter) {
            $out .= sprintf('<g transform="translate(%s 27.5)">%s</g>', self::n($x), self::path($letter, null, $color, 0.85));
            $x += 4.3;
        }

        return self::svg(60, 60, $out.self::polygon(self::star(30, 20.5, 3, 1.2), $color, 0.8).self::polygon(self::star(30, 40.5, 3, 1.2), $color, 0.8));
    }

    /**
     * A pen stroke above a signer's name (90 × 30): loops made up from a hash of the seed, so two signers do
     * not share one. It is a doodle, not anyone's signature.
     */
    public static function penStroke(string $seed, string $color = '#1b2a6b'): string
    {
        $hash = hash('sha256', 'pen|'.$seed);
        $value = fn (int $i, float $min, float $max): float => $min + (hexdec(substr($hash, ($i * 2) % 60, 2)) / 255) * ($max - $min);

        $x = $value(0, 6, 12);
        $y = $value(1, 14, 20);
        $path = sprintf('M%s,%s', self::n($x), self::n($y));
        $loops = 4 + (int) round($value(2, 0, 2));

        for ($i = 0; $i < $loops; $i++) {
            $width = $value(3 + $i, 9, 15);
            $up = $value(9 + $i, 7, 14) * (1 - $i * 0.1);
            $down = $value(15 + $i, 3, 9);
            $path .= sprintf(
                ' C%s,%s %s,%s %s,%s',
                self::n($x + $width * 0.2), self::n($y - $up * 1.6),
                self::n($x + $width * 0.75), self::n($y + $down * 1.5),
                self::n($x + $width), self::n($y - $value(21 + $i, 0, 4)),
            );
            $x += $width;
            $y -= $value(21 + $i, 0, 1.2);
        }

        $path .= sprintf(' C%s,%s %s,%s %s,%s', self::n($x + 4), self::n($y - 9), self::n($x + 9), self::n($y + 3), self::n(min(86, $x + 14)), self::n($y - 5));
        $underline = sprintf('M%s,%s C%s,%s %s,%s %s,%s', self::n($value(27, 8, 16)), '25.5', '34', self::n($value(28, 22, 27)), '58', self::n($value(29, 25, 29)), self::n($value(30, 70, 84)), self::n($value(31, 21, 25)));

        return self::svg(90, 30, self::path($path, null, $color, 0.75).self::path($underline, null, $color, 0.55));
    }

    /**
     * A neutral emblem (40 × 40): atom, perisai, obor, bunga, lampu, bola-dunia, buku.
     *
     * @param  array{primary: string, accent: string, dark: string, soft: string}  $c
     */
    public static function emblem(string $name, array $c): string
    {
        $body = match ($name) {
            'atom' => self::ellipse(20, 20, 17.5, 6.6, null, $c['primary'], 1.1)
                .self::ellipse(20, 20, 17.5, 6.6, null, $c['primary'], 1.1, 60)
                .self::ellipse(20, 20, 17.5, 6.6, null, $c['primary'], 1.1, 120)
                .self::circle(20, 20, 3.4, $c['accent']),
            'perisai' => self::path('M20,2.5 L34.5,7.5 V20 C34.5,29.5 27.5,35.5 20,38.5 C12.5,35.5 5.5,29.5 5.5,20 V7.5 Z', $c['dark'])
                .self::path('M20,5.6 L31.7,9.7 V20 C31.7,27.6 26.2,32.7 20,35.3 C13.8,32.7 8.3,27.6 8.3,20 V9.7 Z', null, $c['accent'], 0.7)
                .self::polygon(self::star(20, 19.5, 8.2, 3.3), $c['accent']),
            'obor' => self::laurel($c['primary'])
                .self::path('M20,5 C25.5,10.5 24,14 22.3,16.6 C24.7,15.8 25.6,13.4 25.6,13.4 C27.6,19.6 24.2,23.4 20,23.4 C15.8,23.4 12.4,19.6 14.4,13.4 C15.2,15.9 16.8,16.9 17.9,16.2 C16,12.4 17.2,8.4 20,5 Z', $c['accent'])
                .self::rect(13.6, 24.2, 12.8, 2.5, $c['dark'])
                .self::polygon([[15.2, 26.7], [24.8, 26.7], [22, 37.5], [18, 37.5]], $c['dark']),
            'bunga' => implode('', array_map(
                fn (int $i): string => self::ellipse(20, 9.6, 3.5, 7.6, $i % 2 === 0 ? $c['primary'] : $c['accent'], null, 0, $i * 45, 20, 20),
                range(0, 7),
            )).self::circle(20, 20, 4.4, $c['dark']).self::circle(20, 20, 2, '#ffffff'),
            'lampu' => self::circle(20, 15.5, 10.5, $c['accent'])
                .self::path('M15.4,24.2 H24.6 V30.2 H15.4 Z', $c['dark'])
                .self::rect(16.6, 31.4, 6.8, 1.8, $c['dark'])
                .self::path('M16.6,17 L20,21.5 L23.4,17', null, '#ffffff', 1)
                .implode('', array_map(function (int $angle) use ($c): string {
                    $rad = deg2rad($angle);

                    return self::path(sprintf('M%s,%s L%s,%s', self::n(20 + 13.5 * cos($rad)), self::n(15.5 - 13.5 * sin($rad)), self::n(20 + 17.5 * cos($rad)), self::n(15.5 - 17.5 * sin($rad))), null, $c['primary'], 1.1);
                }, [0, 45, 90, 135, 180])),
            'bola-dunia' => self::circle(20, 20, 16.5, null, $c['primary'], 1.2)
                .self::ellipse(20, 20, 7.2, 16.5, null, $c['primary'], 0.8)
                .self::path('M3.5,20 H36.5 M6.2,11.5 H33.8 M6.2,28.5 H33.8 M20,3.5 V36.5', null, $c['primary'], 0.8)
                .self::circle(20, 20, 2.2, $c['accent']),
            default => self::polygon([[3.5, 11], [19, 14], [19, 34.5], [3.5, 31.5]], $c['primary'])
                .self::polygon([[36.5, 11], [21, 14], [21, 34.5], [36.5, 31.5]], $c['dark'])
                .self::path('M6.5,16.6 L16.4,18.5 M6.5,21.2 L16.4,23.1 M6.5,25.8 L16.4,27.7 M33.5,16.6 L23.6,18.5 M33.5,21.2 L23.6,23.1 M33.5,25.8 L23.6,27.7', null, '#ffffff', 0.7)
                .self::polygon(self::star(20, 6.6, 4.6, 1.9), $c['accent']),
        };

        return self::svg(40, 40, $body);
    }

    /** A banner with folded ends behind a result such as "JUARA I" (160 × 22). */
    public static function banner(string $color, string $fold): string
    {
        return self::svg(160, 22, self::polygon([[0, 4], [18, 4], [18, 22], [0, 22], [7, 13]], $fold)
            .self::polygon([[160, 4], [142, 4], [142, 22], [160, 22], [153, 13]], $fold)
            .self::polygon([[12, 18], [18, 18], [18, 22]], '#000000', 0.35)
            .self::polygon([[148, 18], [142, 18], [142, 22]], '#000000', 0.35)
            .self::rect(12, 0, 136, 18, $color));
    }

    // SVG helpers ----------------------------------------------------------------------------------------

    private static function svg(float $width, float $height, string $body): string
    {
        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%1$s" height="%2$s" viewBox="0 0 %1$s %2$s">%3$s</svg>',
            self::n($width),
            self::n($height),
            $body,
        );
    }

    /** @param list<array{0: float|int, 1: float|int}> $points */
    private static function polygon(array $points, ?string $fill, float $opacity = 1, ?string $stroke = null, float $strokeWidth = 0): string
    {
        $list = implode(' ', array_map(fn (array $p): string => self::n((float) $p[0]).','.self::n((float) $p[1]), $points));

        return sprintf('<polygon points="%s"%s/>', $list, self::paint($fill, $stroke, $strokeWidth, $opacity));
    }

    private static function rect(float $x, float $y, float $width, float $height, ?string $fill, ?string $stroke = null, float $strokeWidth = 0, float $opacity = 1): string
    {
        return sprintf('<rect x="%s" y="%s" width="%s" height="%s"%s/>', self::n($x), self::n($y), self::n($width), self::n($height), self::paint($fill, $stroke, $strokeWidth, $opacity));
    }

    private static function circle(float $cx, float $cy, float $radius, ?string $fill, ?string $stroke = null, float $strokeWidth = 0, float $opacity = 1): string
    {
        return sprintf('<circle cx="%s" cy="%s" r="%s"%s/>', self::n($cx), self::n($cy), self::n($radius), self::paint($fill, $stroke, $strokeWidth, $opacity));
    }

    private static function ellipse(float $cx, float $cy, float $rx, float $ry, ?string $fill, ?string $stroke = null, float $strokeWidth = 0, float $rotate = 0, ?float $pivotX = null, ?float $pivotY = null): string
    {
        $transform = $rotate === 0.0 ? '' : sprintf(' transform="rotate(%s %s %s)"', self::n($rotate), self::n($pivotX ?? $cx), self::n($pivotY ?? $cy));

        return sprintf('<ellipse cx="%s" cy="%s" rx="%s" ry="%s"%s%s/>', self::n($cx), self::n($cy), self::n($rx), self::n($ry), self::paint($fill, $stroke, $strokeWidth, 1), $transform);
    }

    private static function path(string $d, ?string $fill, ?string $stroke = null, float $strokeWidth = 0): string
    {
        return sprintf('<path d="%s"%s stroke-linecap="round" stroke-linejoin="round"/>', $d, self::paint($fill, $stroke, $strokeWidth, 1));
    }

    private static function paint(?string $fill, ?string $stroke, float $strokeWidth, float $opacity): string
    {
        $out = ' fill="'.($fill ?? 'none').'"';

        if ($stroke !== null) {
            $out .= sprintf(' stroke="%s" stroke-width="%s"', $stroke, self::n($strokeWidth));
        }

        return $opacity < 1 ? $out.sprintf(' opacity="%s"', self::n($opacity)) : $out;
    }

    /** @return list<array{0: float, 1: float}> */
    private static function star(float $cx, float $cy, float $outer, float $inner): array
    {
        $points = [];

        for ($i = 0; $i < 10; $i++) {
            $angle = deg2rad(-90 + $i * 36);
            $radius = $i % 2 === 0 ? $outer : $inner;
            $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
        }

        return $points;
    }

    /** @return list<array{0: float, 1: float}> */
    private static function hexagon(float $cx, float $cy, float $radius): array
    {
        $points = [];

        for ($i = 0; $i < 6; $i++) {
            $angle = deg2rad(-90 + $i * 60);
            $points[] = [$cx + $radius * cos($angle), $cy + $radius * sin($angle)];
        }

        return $points;
    }

    /** Two rows of leaves around the centre of a 40 × 40 emblem. */
    private static function laurel(string $color): string
    {
        $out = '';

        foreach ([1, -1] as $side) {
            for ($i = 0; $i < 6; $i++) {
                $angle = deg2rad(200 - $i * 24);
                $cx = 20 + $side * 16.5 * cos($angle) * -1;
                $cy = 21 - 16.5 * sin($angle) * -1;
                $out .= self::ellipse($cx, $cy, 3.4, 1.35, $color, null, 0, $side * (70 - $i * 24));
            }
        }

        return $out;
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
