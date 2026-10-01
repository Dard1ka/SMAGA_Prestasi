<?php

declare(strict_types=1);

namespace App\Actions\Publications;

use Intervention\Image\Alignment;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\Geometry\Factories\CircleFactory;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\Font;
use RuntimeException;

/**
 * Draws the Humas e-flyer (1080×1350 PNG, Instagram 4:5) with Intervention Image 4 (GD + FreeType,
 * DejaVu fonts from resources/fonts). Content = school, "SELAMAT & SUKSES", initials (there is no student
 * photo in STH), name, class, capaian, lomba, tingkat, pembimbing — nothing else (SECURITY §3m-7).
 * Pure rendering: no storage, no database.
 */
final class FlyerRenderer
{
    public const WIDTH = 1080;

    public const HEIGHT = 1350;

    public const DEFAULT_TEMPLATE = 'biru';

    /** template key => UI label */
    public const TEMPLATES = [
        'biru' => 'Biru lavender',
        'emas' => 'Emas prestasi',
        'putih' => 'Putih minimal',
    ];

    private const CENTER_X = 540;

    private const TEXT_WIDTH = 920;

    /** Spec colours (DESIGN-SPEC-v2): primary #3148D6, gold/medal only for the capaian. */
    private const PALETTES = [
        'biru' => [
            'top' => '#DDE7FF', 'mid' => '#E9E2FF', 'bottom' => '#F6EDFF',
            'blob1' => '#7B5CE029', 'blob2' => '#3D7BE024', 'dot' => '#F2B63A', 'band' => null,
            'accent' => '#2A3CB8', 'ink' => '#151A33', 'ink2' => '#4A5068',
            'ring' => '#FFFFFF', 'avatar' => '#D2DBFF', 'avatarInk' => '#5534B8',
            'pill' => '#F2B63A', 'pillInk' => '#151A33',
        ],
        'emas' => [
            'top' => '#FFF3D1', 'mid' => '#FFF8E4', 'bottom' => '#FFFDF6',
            'blob1' => '#F2B63A47', 'blob2' => '#F3DF9E80', 'dot' => '#3148D6', 'band' => null,
            'accent' => '#6B4E00', 'ink' => '#151A33', 'ink2' => '#4A5068',
            'ring' => '#FFFFFF', 'avatar' => '#FFE6A3', 'avatarInk' => '#6B4E00',
            'pill' => '#3148D6', 'pillInk' => '#FFFFFF',
        ],
        'putih' => [
            'top' => '#FFFFFF', 'mid' => '#FFFFFF', 'bottom' => '#F4F5FB',
            'blob1' => '#ECEFFF', 'blob2' => '#F1F2FA', 'dot' => '#F2B63A', 'band' => '#3148D6',
            'accent' => '#3148D6', 'ink' => '#151A33', 'ink2' => '#4A5068',
            'ring' => '#3148D6', 'avatar' => '#ECEFFF', 'avatarInk' => '#2A3CB8',
            'pill' => '#F2B63A', 'pillInk' => '#151A33',
        ],
    ];

    private readonly ImageManager $manager;

    public function __construct()
    {
        $this->manager = ImageManager::usingDriver(GdDriver::class);
    }

    public static function isTemplate(string $template): bool
    {
        return array_key_exists($template, self::TEMPLATES);
    }

    /** PNG bytes. */
    public function render(AchievementFacts $facts, string $template = self::DEFAULT_TEMPLATE): string
    {
        $palette = self::PALETTES[$template] ?? self::PALETTES[self::DEFAULT_TEMPLATE];
        $image = $this->manager->createImage(self::WIDTH, self::HEIGHT);

        $this->background($image, $palette);

        $this->line($image, 'SMA Negeri 3 Semarang', 92, $this->font(true, 38, $palette['accent']));
        $this->rect($image, self::CENTER_X - 60, 160, 120, 6, $palette['accent']);
        $this->line($image, 'SELAMAT & SUKSES', 196, $this->font(true, 82, $palette['accent']));

        $this->avatar($image, $facts->initials(), $palette);
        $this->content($image, $facts, $palette);

        $mentor = $facts->mentorLine();
        $footer = $this->font(false, 30, $palette['ink2']);

        if ($mentor !== null) {
            $this->line($image, $this->fit($mentor, $footer, self::TEXT_WIDTH), 1232, $footer);
        }

        $this->line($image, '#SMAGASpectacular', 1284, $this->font(true, 28, $palette['accent']));

        return $image->encodeUsingFormat(Format::PNG)->toString();
    }

    /** @param array<string, string|null> $palette */
    private function background(ImageInterface $image, array $palette): void
    {
        $step = 10;

        for ($y = 0; $y < self::HEIGHT; $y += $step) {
            $t = $y / self::HEIGHT;
            $color = $t < 0.5
                ? self::mix((string) $palette['top'], (string) $palette['mid'], $t * 2)
                : self::mix((string) $palette['mid'], (string) $palette['bottom'], ($t - 0.5) * 2);
            $this->rect($image, 0, $y, self::WIDTH, $step, $color);
        }

        $this->circle($image, 1010, 40, 380, (string) $palette['blob1']);
        $this->circle($image, 40, 1340, 330, (string) $palette['blob2']);
        $this->circle($image, 968, 452, 22, (string) $palette['dot']);
        $this->circle($image, 1010, 412, 10, (string) $palette['dot']);

        if ($palette['band'] !== null) {
            $this->rect($image, 0, 0, self::WIDTH, 24, $palette['band']);
            $this->rect($image, 0, self::HEIGHT - 24, self::WIDTH, 24, $palette['band']);
        }
    }

    /** @param array<string, string|null> $palette */
    private function avatar(ImageInterface $image, string $initials, array $palette): void
    {
        $cy = 452;
        $this->circle($image, self::CENTER_X, $cy, 142, (string) $palette['ring']);
        $this->circle($image, self::CENTER_X, $cy, 130, (string) $palette['avatar']);

        $font = $this->font(true, 112, (string) $palette['avatarInk']);
        $box = $this->box($initials, $font);
        $this->line($image, $initials, $cy - intdiv($box['height'], 2), $font);
    }

    /**
     * Name, class, capaian pill, lomba (≤ 2 lines), tingkat — vertically centred between the avatar
     * and the footer.
     *
     * @param  array<string, string|null>  $palette
     */
    private function content(ImageInterface $image, AchievementFacts $facts, array $palette): void
    {
        [$nameFont, $nameLines, $nameStep] = $this->wrapFitting($facts->studentName, [64, 56, 48], (string) $palette['ink'], 2);
        [$competitionFont, $competitionLines, $competitionStep] = $this->wrapFitting($facts->competitionName, [52, 46, 40], (string) $palette['ink'], 2);
        $classFont = $this->font(false, 38, (string) $palette['ink2']);
        $pillFont = $this->font(true, 48, (string) $palette['pillInk']);
        $levelFont = $this->font(true, 40, (string) $palette['accent']);
        $classLine = $facts->classLine();
        $levelLine = $facts->levelLine();

        $pillHeight = 88;
        $height = count($nameLines) * $nameStep
            + ($classLine !== null ? 12 + 46 : 0)
            + 30 + $pillHeight
            + 30 + count($competitionLines) * $competitionStep
            + ($levelLine !== null ? 8 + 48 : 0);

        $top = 616;
        $bottom = 1200;
        $y = $top + max(0, intdiv($bottom - $top - $height, 2));

        foreach ($nameLines as $line) {
            $this->line($image, $line, $y, $nameFont);
            $y += $nameStep;
        }

        if ($classLine !== null) {
            $y += 12;
            $this->line($image, $classLine, $y, $classFont);
            $y += 46;
        }

        $y += 30;
        $label = $this->fit($facts->resultLabel, $pillFont, 760);
        $box = $this->box($label, $pillFont);
        $pillWidth = min(860, $box['width'] + 104);
        $radius = intdiv($pillHeight, 2);
        $left = self::CENTER_X - intdiv($pillWidth, 2);
        $this->rect($image, $left + $radius, $y, $pillWidth - 2 * $radius, $pillHeight, (string) $palette['pill']);
        $this->circle($image, $left + $radius, $y + $radius, $radius, (string) $palette['pill']);
        $this->circle($image, $left + $pillWidth - $radius, $y + $radius, $radius, (string) $palette['pill']);
        $this->line($image, $label, $y + $radius - intdiv($box['height'], 2), $pillFont);
        $y += $pillHeight;

        $y += 30;
        foreach ($competitionLines as $line) {
            $this->line($image, $line, $y, $competitionFont);
            $y += $competitionStep;
        }

        if ($levelLine !== null) {
            $y += 8;
            $this->line($image, $levelLine, $y, $levelFont);
        }
    }

    /**
     * Bold text wrapped to ≤ $maxLines at the largest size that fits; at the smallest size the last line
     * is shortened with an ellipsis.
     *
     * @param  list<int>  $sizes  largest first
     * @return array{0: Font, 1: list<string>, 2: int} font, lines, line step in px
     */
    private function wrapFitting(string $text, array $sizes, string $color, int $maxLines): array
    {
        foreach ($sizes as $index => $size) {
            $font = $this->font(true, $size, $color);
            $lines = $this->wrap($text, $font, self::TEXT_WIDTH);
            $last = $index === count($sizes) - 1;

            if (count($lines) <= $maxLines || $last) {
                if (count($lines) > $maxLines) {
                    $lines = [...array_slice($lines, 0, $maxLines - 1), implode(' ', array_slice($lines, $maxLines - 1))];
                }

                $lines = array_map(fn (string $line): string => $this->fit($line, $font, self::TEXT_WIDTH), $lines);

                return [$font, $lines, (int) round($size * 1.24)];
            }
        }

        throw new RuntimeException('No font size given.');
    }

    /**
     * Greedy word wrap (a single over-long word stays on its own line).
     *
     * @return list<string>
     */
    private function wrap(string $text, Font $font, int $width): array
    {
        $lines = [];
        $current = '';

        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current === '' || $this->box($candidate, $font)['width'] <= $width) {
                $current = $candidate;

                continue;
            }

            $lines[] = $current;
            $current = $word;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /** Shortens one line with "…" until it fits. */
    private function fit(string $text, Font $font, int $width): string
    {
        if ($this->box($text, $font)['width'] <= $width) {
            return $text;
        }

        $chars = mb_strlen($text);

        while ($chars > 1 && $this->box(mb_substr($text, 0, $chars).'…', $font)['width'] > $width) {
            $chars--;
        }

        return rtrim(mb_substr($text, 0, $chars)).'…';
    }

    /** @return array{width: int, height: int} */
    private function box(string $text, Font $font): array
    {
        $size = $this->manager->driver->fontProcessor()->boxSize($text, $font);

        return ['width' => $size->width(), 'height' => $size->height()];
    }

    /** Centred line whose top edge is at $y. */
    private function line(ImageInterface $image, string $text, int $y, Font $font): void
    {
        if ($text === '') {
            return;
        }

        $image->text($text, self::CENTER_X, $y, $font);
    }

    private function font(bool $bold, float $size, string $color): Font
    {
        $path = resource_path('fonts/'.($bold ? 'DejaVuSans-Bold.ttf' : 'DejaVuSans.ttf'));

        if (! is_file($path)) {
            throw new RuntimeException('Font e-flyer tidak ditemukan: '.basename($path));
        }

        return new Font(
            filepath: $path,
            size: $size,
            color: $color,
            alignmentHorizontal: Alignment::CENTER,
            alignmentVertical: Alignment::TOP,
        );
    }

    private function rect(ImageInterface $image, int $x, int $y, int $width, int $height, string $color): void
    {
        $image->drawRectangle(function (RectangleFactory $rectangle) use ($x, $y, $width, $height, $color): void {
            $rectangle->at($x, $y);
            $rectangle->size($width, $height);
            $rectangle->background($color);
        });
    }

    private function circle(ImageInterface $image, int $x, int $y, int $radius, string $color): void
    {
        $image->drawCircle(function (CircleFactory $circle) use ($x, $y, $radius, $color): void {
            $circle->at($x, $y);
            $circle->radius($radius);
            $circle->background($color);
        });
    }

    /** Linear mix of two #RRGGBB colours. */
    private static function mix(string $from, string $to, float $t): string
    {
        $t = max(0.0, min(1.0, $t));
        $a = sscanf(ltrim($from, '#'), '%02x%02x%02x');
        $b = sscanf(ltrim($to, '#'), '%02x%02x%02x');

        return sprintf(
            '#%02X%02X%02X',
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        );
    }
}
