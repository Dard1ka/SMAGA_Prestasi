<?php

declare(strict_types=1);

use App\Domain\Letters\JustifiedLines;

/*
 * SV8, D-194: the Word way of justifying the guru letter (measured on the school's PDF). A fake font here: every
 * character and the space are 1 pt wide, so the numbers can be followed by hand.
 */

function sv8jLines(string|array $runs, float $width): array
{
    $runs = is_string($runs) ? [['text' => $runs, 'bold' => false]] : $runs;

    return (new JustifiedLines(fn (string $text, bool $bold): float => (float) mb_strlen($text)))->split($runs, $width);
}

/** @return list<string> */
function sv8jTexts(array $lines): array
{
    return array_map(fn (array $line): string => implode('', array_column($line['runs'], 'text')), $lines);
}

it('keeps a paragraph that fits on one line with plain spaces', function (): void {
    $lines = sv8jLines('aaaa bbbb', 20);

    expect(sv8jTexts($lines))->toBe(['aaaa bbbb'])
        ->and($lines[0]['spacing'])->toBe(0.0);
});

it('spreads every line but the last over the width, the spaces on whole twips', function (): void {
    // 12 letters and 2 spaces on a 15 pt line: each space 1.5 pt (0.5 more); "dddd" no longer fits.
    $lines = sv8jLines('aaaa bbbb cccc dddd', 15);

    expect(sv8jTexts($lines))->toBe(['aaaa bbbb cccc', 'dddd'])
        ->and($lines[0]['spacing'])->toBe(0.5)
        ->and($lines[1]['spacing'])->toBe(0.0);

    // 15.13 pt: a space would be 1.565 pt, Word sets 1.55 (whole twips, rounded down), so the line ends 0.03 pt short.
    expect(sv8jLines('aaaa bbbb cccc dddd', 15.13)[0]['spacing'])->toBe(0.55);
});

it('narrows the spaces to keep a word on the line, down to MIN_SPACE of a normal space', function (): void {
    // 13 letters and 3 spaces need 16 pt. At 15.5 pt each space can still be 0.83 pt (>= 0.8): "d" stays, and being
    // the last line, too wide for the column, its spaces are narrowed (0.8 pt on whole twips).
    $kept = sv8jLines('aaaa bbbb cccc d', 15.5);
    expect(sv8jTexts($kept))->toBe(['aaaa bbbb cccc d'])
        ->and($kept[0]['spacing'])->toBe(-0.2);

    // At 15.2 pt the spaces would be 0.73 pt: "d" goes to the next line.
    $moved = sv8jLines('aaaa bbbb cccc d', 15.2);
    expect(sv8jTexts($moved))->toBe(['aaaa bbbb cccc', 'd'])
        ->and($moved[0]['spacing'])->toBe(0.6);

    expect(JustifiedLines::MIN_SPACE)->toBe(0.8)
        ->and(JustifiedLines::GRID)->toBe(0.05);
});

it('keeps bold words bold across the lines and the spaces in the style of their run', function (): void {
    $lines = sv8jLines([
        ['text' => 'aa bb ', 'bold' => false],
        ['text' => 'cc dd', 'bold' => true],
        ['text' => ' ee', 'bold' => false],
    ], 8);

    expect($lines[0]['runs'])->toBe([['text' => 'aa bb ', 'bold' => false], ['text' => 'cc', 'bold' => true]])
        ->and($lines[1]['runs'])->toBe([['text' => 'dd', 'bold' => true], ['text' => ' ee', 'bold' => false]]);
});

it('collapses runs of spaces and copes with an empty text', function (): void {
    expect(sv8jTexts(sv8jLines('  aaaa   bbbb  ', 20)))->toBe(['aaaa bbbb'])
        ->and(sv8jLines('', 20))->toBe([['runs' => [], 'spacing' => 0.0]])
        // a single word wider than the column stays alone on its line
        ->and(sv8jTexts(sv8jLines('aaaaaaaaaa bb', 5)))->toBe(['aaaaaaaaaa', 'bb']);
});
