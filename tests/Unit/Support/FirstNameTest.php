<?php

declare(strict_types=1);

use App\Support\FirstName;

/*
| The lead's mail test of 2026-10-09: the first word of the name gave "Halo Drs.," for the Kepala Sekolah and
| "Halo, M." for a student called "M. Rizky …". FirstName::of() is the name a person is called by: the first word
| that is not an abbreviation (a title, a degree, an initial). Fictional names only.
*/

it('takes the first word that is not an abbreviation', function (?string $name, ?string $expected): void {
    expect(FirstName::of($name))->toBe($expected);
})->with([
    'plain name' => ['Nadia Putri Anindya', 'Nadia'],
    'one word' => ['Nadia', 'Nadia'],
    'title before, degree after' => ['Drs. Bambang Hartono, M.Pd.', 'Bambang'],
    'initial with a full stop' => ['M. Rizky Pratama', 'Rizky'],
    'initial without a full stop' => ['M Rizky Pratama', 'Rizky'],
    'short title' => ['Hj. Siti', 'Siti'],
    'abbreviated first name' => ['Muh. Fikri Ramadhan', 'Fikri'],
    'degree after a comma' => ['Bambang, S.Pd.', 'Bambang'],
    'two titles' => ['Dr. Ir. Retno Wulandari, M.T.', 'Retno'],
    'apostrophe' => ["Ma'ruf Hidayat", "Ma'ruf"],
    'hyphen' => ['Abdul-Aziz Rahman', 'Abdul-Aziz'],
    'double spaces are folded' => ['  Nadia   Putri  ', 'Nadia'],
    'line break and tab count as spaces' => ["Dra.\nSiti\tAminah", 'Siti'],
    'abbreviations only: the whole name' => ['M. A.', 'M. A.'],
    'one letter only: the whole name' => ['A', 'A'],
    'empty' => ['', null],
    'spaces only' => ["  \n ", null],
    'null' => [null, null],
]);

it('never returns a word with the comma that belongs to the degree', function (): void {
    expect(FirstName::of('Hendra, S.Pd., M.Si.'))->toBe('Hendra')
        ->and(FirstName::of('Hendra Kusuma, S.Pd., M.Si.'))->toBe('Hendra');
});

it('folds whitespace of any kind into single spaces', function (): void {
    expect(FirstName::fold("  Drs.  Bambang\r\n Hartono,\tM.Pd. "))->toBe('Drs. Bambang Hartono, M.Pd.')
        ->and(FirstName::fold(''))->toBe('')
        // Invalid UTF-8 must not empty the name (the /u pattern fails on it).
        ->and(FirstName::fold("Nadia\xC3  Putri"))->toBe("Nadia\xC3 Putri");
});
