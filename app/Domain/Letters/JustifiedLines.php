<?php

declare(strict_types=1);

namespace App\Domain\Letters;

use Closure;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Illuminate\Support\Facades\File;

/**
 * SV8, D-194 (lead, 2026-11-09: the guru letter "identik 100%" with the school's PDF): the lines of a justified paragraph
 * the way the school's Word file sets them, measured on that PDF (16 justified lines). Every line but the last ends at
 * the same place, one space before the column edge (548.46 pt for a column to 551.8 pt: the trailing space counts), and
 * Word may also NARROW the spaces to get there: a word still goes on the line while the spaces of that line stay at
 * least MIN_SPACE of a normal space (the example takes "… 2026, Pukul 15.30" at 95 % and "… Kec. Laweyan," at 85 %, and
 * leaves "2025" for the next line rather than 72 %). Each space is then a whole number of twips (GRID, rounded down),
 * so a line ends up to a few tenths of a point short of the edge, as on the paper. dompdf only widens spaces and
 * breaks before that, so the guru letter asks this class for its lines and prints each one as it is, the difference
 * spread over its spaces as `word-spacing`. The last line keeps plain spaces (left aligned) unless it is too wide.
 *
 * Text is plain: runs of words separated by single spaces (LetterContent / LetterTemplate collapse whitespace), a run
 * regular or bold. Widths come from the same font metrics dompdf draws with (Helvetica, the Arial metrics), so the
 * lines never wrap again in the PDF.
 */
final class JustifiedLines
{
    /** The narrowest space Word allows on a justified line, as a share of a normal space (measured: 0.72–0.85). */
    public const MIN_SPACE = 0.8;

    /** Word sets every space on whole twips (1/20 pt). */
    public const GRID = 0.05;

    private static ?FontMetrics $metrics = null;

    /** @param  Closure(string, bool): float  $width  width in pt of a text, regular (false) or bold (true) */
    public function __construct(private readonly Closure $width) {}

    /** Measures with dompdf's Helvetica / Helvetica-Bold at $size pt (the guru letter's 12 pt). */
    public static function helvetica(float $size): self
    {
        $metrics = self::metrics();
        $regular = $metrics->getFont('Helvetica', 'normal');
        $bold = $metrics->getFont('Helvetica', 'bold');

        return new self(fn (string $text, bool $isBold): float => $metrics->getTextWidth($text, $isBold ? $bold : $regular, $size));
    }

    /**
     * @param  list<array{text: string, bold: bool}>  $runs  the paragraph
     * @param  float  $width  where every line but the last ends (the column edge less one space)
     * @return list<array{runs: list<array{text: string, bold: bool}>, spacing: float}> spacing = pt added to (or, when
     *                                                                                  negative, taken from) each space
     */
    public function split(array $runs, float $width): array
    {
        $words = $this->words($runs);

        if ($words === []) {
            return [['runs' => [], 'spacing' => 0.0]];
        }

        $space = ($this->width)(' ', false);
        $lines = [];
        $line = [];
        $letters = 0.0; // the width of the words of $line, without spaces

        foreach ($words as $word) {
            $wordWidth = $this->wordWidth($word);
            $gaps = count($line);

            // The word stays on this line while every space can still be at least MIN_SPACE of a normal one.
            if ($line !== [] && ($width - $letters - $wordWidth) / $gaps < self::MIN_SPACE * $space - 1e-9) {
                $lines[] = [$line, $letters];
                $line = [];
                $letters = 0.0;
            }

            $line[] = $word;
            $letters += $wordWidth;
        }

        $lines[] = [$line, $letters];
        $last = count($lines) - 1;
        $result = [];

        foreach ($lines as $index => [$lineWords, $lineLetters]) {
            $gaps = count($lineWords) - 1;
            $gap = $space;

            // Justified lines fill the width; the last line keeps normal spaces unless it is wider than the column.
            if ($gaps > 0 && ($index < $last || $lineLetters + $gaps * $space > $width + 1e-6)) {
                $gap = floor(($width - $lineLetters) / $gaps / self::GRID + 1e-6) * self::GRID;
            }

            $result[] = ['runs' => $this->runs($lineWords), 'spacing' => round($gap - $space, 4)];
        }

        return $result;
    }

    /**
     * The words of the runs, each a list of pieces (a word may change from regular to bold inside); `space` = the style
     * of the space in front of it (null for the first word).
     *
     * @param  list<array{text: string, bold: bool}>  $runs
     * @return list<array{pieces: list<array{text: string, bold: bool}>, space: bool|null}>
     */
    private function words(array $runs): array
    {
        $words = [];
        $current = ['pieces' => [], 'space' => null];

        foreach ($runs as $run) {
            foreach (explode(' ', $run['text']) as $index => $part) {
                if ($index > 0) {
                    // A space: the word before it is complete, the next one starts after a space of this run's style.
                    if ($current['pieces'] !== []) {
                        $words[] = $current;
                    }

                    $current = ['pieces' => [], 'space' => $words === [] ? null : $run['bold']];
                }

                if ($part !== '') {
                    $current['pieces'][] = ['text' => $part, 'bold' => $run['bold']];
                }
            }
        }

        if ($current['pieces'] !== []) {
            $words[] = $current;
        }

        return $words;
    }

    /** @param  array{pieces: list<array{text: string, bold: bool}>, space: bool|null}  $word */
    private function wordWidth(array $word): float
    {
        $width = 0.0;

        foreach ($word['pieces'] as $piece) {
            $width += ($this->width)($piece['text'], $piece['bold']);
        }

        return $width;
    }

    /**
     * The words of one line as runs: the pieces joined by single spaces, neighbours of one style merged.
     *
     * @param  list<array{pieces: list<array{text: string, bold: bool}>, space: bool|null}>  $words
     * @return list<array{text: string, bold: bool}>
     */
    private function runs(array $words): array
    {
        $runs = [];
        $append = static function (string $text, bool $bold) use (&$runs): void {
            $lastIndex = array_key_last($runs);

            if ($lastIndex !== null && $runs[$lastIndex]['bold'] === $bold) {
                $runs[$lastIndex]['text'] .= $text;
            } else {
                $runs[] = ['text' => $text, 'bold' => $bold];
            }
        };

        foreach ($words as $index => $word) {
            if ($index > 0) {
                $append(' ', $word['space'] ?? false);
            }

            foreach ($word['pieces'] as $piece) {
                $append($piece['text'], $piece['bold']);
            }
        }

        return $runs;
    }

    private static function metrics(): FontMetrics
    {
        if (self::$metrics === null) {
            // The renderer's font folders (PdfRenderer::options()): the core fonts of dompdf, the cache under storage.
            $cache = storage_path('app/pdf-fonts');
            File::ensureDirectoryExists($cache, 0700);

            $options = new Options;
            $options->setIsRemoteEnabled(false);
            $options->setIsJavascriptEnabled(false);
            $options->setIsPhpEnabled(false);
            $options->setFontDir(base_path('vendor/dompdf/dompdf/lib/fonts'));
            $options->setFontCache($cache);

            self::$metrics = (new Dompdf($options))->getFontMetrics();
        }

        return self::$metrics;
    }
}
