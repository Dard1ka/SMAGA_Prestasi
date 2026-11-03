<?php

declare(strict_types=1);

use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Support\FileSizeLabel;

/*
| r3 (2026-10-31): one file-size rule for every screen, with the decimal comma (CLAUDE.md §3.9). Humas › Arsip foto
| printed a 1,6 MB photo as "2 MB" (Number::fileSize with no decimal).
*/

it('prints megabytes with one decimal and a decimal comma, smaller files in whole kilobytes', function (int $bytes, string $label): void {
    expect(FileSizeLabel::format($bytes))->toBe($label);
})->with([
    'empty file' => [0, '1 KB'],
    'a few bytes' => [70, '1 KB'],
    '350 KB' => [358_400, '350 KB'],
    'just under 1 MB, rounds to 1.023 KB' => [1_047_552, '1.023 KB'],
    'rounds to 1.024 KB' => [1_048_100, '1,0 MB'],
    'exactly 1 MB' => [1_048_576, '1,0 MB'],
    '1,6 MB' => [1_677_722, '1,6 MB'],
    '4,96 MB rounds up' => [5_200_937, '5,0 MB'],
    '12,5 MB' => [13_107_200, '12,5 MB'],
    'negative is read as nothing' => [-5, '1 KB'],
]);

it('is the rule behind the presenter the student, guru and Kesiswaan screens use', function (): void {
    foreach ([0, 358_400, 1_048_100, 1_677_722] as $bytes) {
        expect(LombaPresenter::fileSize($bytes))->toBe(FileSizeLabel::format($bytes));
    }
});
