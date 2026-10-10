<?php

declare(strict_types=1);

use App\Rules\ExternalUrl;
use App\Rules\NoWebAddress;

/*
| The lead's mail test of 2026-10-09: a link with one wrong character was refused four times with one sentence that
| named only "https" and "alamat IP", so the refusals looked random. ExternalUrl now says what is wrong
| (problem()), and the forms clean a pasted link at both ends first (normalize()).
|
| The set of ACCEPTED addresses must not move: stored links are checked again on every display (Detail lomba,
| Guru › Lomba, Kelola lomba) and on every save of a lomba. externalUrlLegacyPasses() below is the rule as it was
| before this change, kept here as the yardstick.
*/

/** ExternalUrl::passes() as it was on 2026-10-09, copied verbatim (only the two private helpers are inlined). */
function externalUrlLegacyPasses(string $url): bool
{
    $asciiHost = function (string $host): string {
        if ($host === '' || preg_match('/^[\x20-\x7E]+$/', $host) === 1 || ! function_exists('idn_to_ascii')) {
            return $host;
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return $ascii === false ? $host : $ascii;
    };

    if ($url === '' || strlen($url) > 500 || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
        return false;
    }

    $parts = parse_url($url);

    if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || ! isset($parts['host'])) {
        return false;
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    if (isset($parts['port']) && (int) $parts['port'] !== 443) {
        return false;
    }

    $host = strtolower(trim($parts['host'], '[]'));

    if (filter_var($host, FILTER_VALIDATE_IP) !== false || ! str_contains($host, '.') || str_ends_with($host, '.')) {
        return false;
    }

    if (preg_match('/^[0-9.]+$/', $host) === 1 || str_ends_with($host, '.localhost') || $host === 'localhost') {
        return false;
    }

    return filter_var(str_ireplace($host, $asciiHost($host), $url), FILTER_VALIDATE_URL) !== false;
}

/** @return list<string> addresses the rule accepted before the change */
function externalUrlAccepted(): array
{
    return [
        'https://contoh.test/lomba-uji',
        'https://contoh.test/lomba-siswa',
        'https://contoh.test/lomba-guru',
        'https://open.spotify.com',
        'https://open.spotify.com/',
        'https://example.com/a',
        'https://sub.domain.sch.id/path?x=1',
        'HTTPS://CONTOH.TEST/Lomba-Uji',
        'https://contoh.test:443/lomba',
        'https://lomba.go.id:443/a',
        'https://ispo.example.id/daftar?x=1',
        'https://bit.ly/DaftarLomba2026',
        'https://docs.google.com/forms/d/e/1FAIpQLSexample/viewform?usp=sf_link',
        'https://www.instagram.com/p/Cabc123/?igsh=MTIz',
        'https://id.wikipedia.org/wiki/Olimpiade_Sains_Nasional_(Indonesia)',
        'https://contoh.test/a?b[]=1&c={x}|y',
        'https://lomba.sekolah.contoh/',
        'https://xn--bcher-kva.example/',
        'https://bücher.example/',
        'https://example.org/nsif#jadwal',
        'https://example.org/a%20b?q=lomba%20sains',
        'https://a-b.example.co.id/x_y/z.pdf',
        'https://contoh.example/'.str_repeat('a', 477),
    ];
}

/** @return list<string> addresses the rule refused before the change */
function externalUrlRefused(): array
{
    return [
        '',
        'http://x.id',
        'http://contoh.test/lomba-siswa',
        'http://ispo.example.id',
        'contoh.test/lomba-uji',
        'www.contoh.test/lomba-uji',
        'https://contoh.test/lomba-uji ',
        ' https://contoh.test/lomba-uji',
        'https://contoh.test/lomba uji',
        'https:// contoh.test/lomba-uji',
        'https:/contoh.test/lomba-uji',
        'https//contoh.test/lomba-uji',
        'htpps://contoh.test/lomba-uji',
        'https://contoh,test/lomba-siswa',
        'https://contoh.test:8443/lomba',
        'https://ispo.example.id:8081/',
        'https://contoh.test:99999/lomba',
        'https://203.0.113.7/lomba',
        'https://127.0.0.1/admin',
        'https://127.1/',
        'https://[::1]/',
        'https://2130706433/',
        'https://localhost/lomba',
        'https://a.localhost/lomba',
        'https://intranet/lomba',
        'https://contoh.test./lomba',
        'https://user:rahasia@contoh.test/',
        'https://user@contoh.test/',
        'https://contoh.test/lomba–2026',
        'https://contoh.test/olimpiade/Juknis Lomba 2026.pdf',
        'https://contoh.test/cari?q=lomba sains',
        'https://contoh.test/cari?q=süd',
        'https://lomba_sains.contoh.test/',
        "https://ispo.example.id/\nx",
        "https://ispo.example.id/\tx",
        "https://ispo.example.id/\x00x",
        "https://ispo.example.id/\x7Fx",
        "https://contoh.test/lomba\u{00A0}uji",
        "https://contoh.test/lomba\u{200B}uji",
        'https://',
        'https:///lomba',
        'javascript:alert(1)',
        'data:text/html,<b>x</b>',
        'ftp://contoh.test/lomba',
        'mailto:panitia@contoh.test',
        '//contoh.test/lomba',
        'lomba sains',
        "https://contoh.test/\xC3\x28",
        'https://contoh.example/'.str_repeat('a', 478),
    ];
}

// The accepted set stays where it was ----------------------------------------------------------------------------

/** A failing address as printable text (some hold control characters or broken UTF-8). */
function externalUrlPrintable(string $url): string
{
    return (string) json_encode($url, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

it('still accepts every address it accepted before', function (): void {
    $wrong = [];

    foreach (externalUrlAccepted() as $url) {
        if (! externalUrlLegacyPasses($url) || ! ExternalUrl::passes($url) || ExternalUrl::problem($url) !== null) {
            $wrong[] = externalUrlPrintable($url);
        }
    }

    expect($wrong)->toBe([]);
});

it('still refuses every address it refused before, now with a sentence', function (): void {
    $wrong = [];

    foreach (externalUrlRefused() as $url) {
        if (externalUrlLegacyPasses($url) || ExternalUrl::passes($url) || ! is_string(ExternalUrl::problem($url)) || ExternalUrl::problem($url) === '') {
            $wrong[] = externalUrlPrintable($url);
        }
    }

    expect($wrong)->toBe([]);
});

it('gives the same answer as the rule before the change for every variation of the known addresses', function (): void {
    $seeds = [...externalUrlAccepted(), ...externalUrlRefused()];
    $different = [];

    foreach ($seeds as $seed) {
        $variants = [
            $seed, strtoupper($seed), $seed.'/', $seed.'?a=1', $seed.'#x', ' '.$seed, $seed.' ', $seed."\n", "\u{FEFF}".$seed,
            str_replace('https://', 'http://', $seed), str_replace('https://', '', $seed), str_replace('https://', 'https://www.', $seed),
            str_replace('.', ',', $seed), str_replace('-', ' ', $seed), str_replace('-', '_', $seed), str_replace('/', '\\', $seed),
            str_replace('://', '://user:pass@', $seed), preg_replace('~^(https://[^/?#]+)~i', '$1:8080', $seed) ?? $seed,
            preg_replace('~^(https://[^/?#]+)~i', '$1:443', $seed) ?? $seed, preg_replace('~^(https://[^/?#]+)~i', '$1.', $seed) ?? $seed,
        ];

        foreach ($variants as $url) {
            if (ExternalUrl::passes($url) !== externalUrlLegacyPasses($url)) {
                $different[] = externalUrlPrintable($url);
            }
        }
    }

    expect($different)->toBe([]);
});

it('does not judge the top-level domain', function (string $url): void {
    expect(ExternalUrl::problem($url))->toBeNull();
})->with([
    'https://contoh.test/lomba',
    'https://contoh.example/lomba',
    'https://contoh.invalid/lomba',
    'https://contoh.id/lomba',
    'https://sman-contoh.sch.id/lomba',
    'https://kampus-contoh.ac.id/lomba',
    'https://panitia.zz/daftar',
]);

// One sentence per reason ----------------------------------------------------------------------------------------

it('names what is wrong with a refused link', function (string $url, string $sentence): void {
    expect(ExternalUrl::problem($url))->toBe($sentence);
})->with([
    'empty' => ['', ExternalUrl::MESSAGE],
    'http' => ['http://x.id', ExternalUrl::HTTP],
    'http, upper case' => ['HTTP://CONTOH.TEST/lomba', ExternalUrl::HTTP],
    'no scheme' => ['contoh.test/lomba-uji', ExternalUrl::NO_HTTPS],
    'www without scheme' => ['www.contoh.test/lomba-uji', ExternalUrl::NO_HTTPS],
    'colon missing' => ['https//contoh.test/lomba-uji', ExternalUrl::NO_HTTPS],
    'one slash' => ['https:/contoh.test/lomba-uji', ExternalUrl::NO_HTTPS],
    'typo in https' => ['htpps://contoh.test/lomba-uji', ExternalUrl::NO_HTTPS],
    'javascript' => ['javascript:alert(1)', ExternalUrl::NO_HTTPS],
    'data' => ['data:text/html,<b>x</b>', ExternalUrl::NO_HTTPS],
    'ftp' => ['ftp://contoh.test/lomba', ExternalUrl::NO_HTTPS],
    'protocol-relative' => ['//contoh.test/lomba', ExternalUrl::NO_HTTPS],
    'space in the path' => ['https://contoh.test/lomba uji', ExternalUrl::SPACE],
    'space after the scheme' => ['https:// contoh.test/lomba-uji', ExternalUrl::SPACE],
    'space in the query' => ['https://contoh.test/cari?q=lomba sains', ExternalUrl::SPACE],
    'line break inside' => ["https://ispo.example.id/\nx", ExternalUrl::SPACE],
    'tab inside' => ["https://ispo.example.id/\tx", ExternalUrl::SPACE],
    'no-break space inside' => ["https://contoh.test/lomba\u{00A0}uji", ExternalUrl::SPACE],
    'comma for the dot' => ['https://contoh,test/lomba-siswa', ExternalUrl::HOST],
    'host without a dot' => ['https://intranet/lomba', ExternalUrl::HOST],
    'underscore in the host' => ['https://lomba_sains.contoh.test/', ExternalUrl::HOST],
    'host ending in a dot' => ['https://contoh.test./lomba', ExternalUrl::HOST],
    'no host' => ['https://', ExternalUrl::HOST],
    'ipv4' => ['https://127.0.0.1/admin', ExternalUrl::IP],
    'ipv6' => ['https://[::1]/', ExternalUrl::IP],
    'decimal ip' => ['https://2130706433/', ExternalUrl::IP],
    'short ip' => ['https://127.1/', ExternalUrl::IP],
    'localhost' => ['https://localhost/lomba', ExternalUrl::IP],
    'name under localhost' => ['https://a.localhost/lomba', ExternalUrl::IP],
    'odd port' => ['https://contoh.test:8443/lomba', ExternalUrl::PORT],
    'port out of range' => ['https://contoh.test:99999/lomba', ExternalUrl::PORT],
    'user and password' => ['https://user:rahasia@contoh.test/', ExternalUrl::CREDENTIALS],
    'user only' => ['https://user@contoh.test/', ExternalUrl::CREDENTIALS],
    'en dash in the path' => ['https://contoh.test/lomba–2026', ExternalUrl::CHARACTERS],
    'umlaut in the query' => ['https://contoh.test/cari?q=süd', ExternalUrl::CHARACTERS],
    'control character' => ["https://ispo.example.id/\x00x", ExternalUrl::CHARACTERS],
    'delete character' => ["https://ispo.example.id/\x7Fx", ExternalUrl::CHARACTERS],
    'invalid utf-8' => ["https://contoh.test/\xC3\x28", ExternalUrl::CHARACTERS],
    '501 characters' => ['https://contoh.example/'.str_repeat('a', 478), ExternalUrl::TOO_LONG],
]);

it('accepts exactly 500 characters and refuses 501', function (): void {
    $base = 'https://contoh.example/';

    expect(ExternalUrl::problem($base.str_repeat('a', ExternalUrl::MAX_LENGTH - strlen($base))))->toBeNull()
        ->and(ExternalUrl::problem($base.str_repeat('a', ExternalUrl::MAX_LENGTH - strlen($base) + 1)))->toBe(ExternalUrl::TOO_LONG);
});

it('fails as a validation rule with the sentence of problem()', function (mixed $value, ?string $sentence): void {
    $messages = [];

    (new ExternalUrl)->validate('website_url', $value, function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    expect($messages)->toBe($sentence === null ? [] : [$sentence]);
})->with([
    'accepted' => ['https://contoh.test/lomba-uji', null],
    'http' => ['http://contoh.test/lomba-uji', ExternalUrl::HTTP],
    'odd port' => ['https://contoh.test:8080/', ExternalUrl::PORT],
    'array' => [['https://contoh.test/'], ExternalUrl::MESSAGE],
    'number' => [12345, ExternalUrl::MESSAGE],
    'null' => [null, ExternalUrl::MESSAGE],
]);

// Cleaning a pasted link -----------------------------------------------------------------------------------------

it('strips what a paste carries along at both ends of a link', function (string $typed): void {
    expect(ExternalUrl::normalize($typed))->toBe('https://contoh.test/lomba-siswa')
        ->and(ExternalUrl::problem(ExternalUrl::normalize($typed)))->toBeNull();
})->with([
    'nothing to strip' => ['https://contoh.test/lomba-siswa'],
    'space in front' => [' https://contoh.test/lomba-siswa'],
    'space behind' => ['https://contoh.test/lomba-siswa '],
    'tabs and spaces' => ["\t https://contoh.test/lomba-siswa \t"],
    'line break behind' => ["https://contoh.test/lomba-siswa\n"],
    'windows line break' => ["https://contoh.test/lomba-siswa\r\n"],
    'no-break space' => ["\u{00A0}https://contoh.test/lomba-siswa\u{00A0}"],
    'narrow no-break space' => ["https://contoh.test/lomba-siswa\u{202F}"],
    'ideographic space' => ["\u{3000}https://contoh.test/lomba-siswa"],
    'zero-width space behind' => ["https://contoh.test/lomba-siswa\u{200B}"],
    'zero-width space inside' => ["https://contoh.test/lomba-\u{200B}siswa"],
    'soft hyphen inside' => ["https://contoh.test/lomba-\u{00AD}siswa"],
    'word joiner' => ["\u{2060}https://contoh.test/lomba-siswa"],
    'left-to-right mark' => ["\u{200E}https://contoh.test/lomba-siswa\u{200E}"],
    'direction embedding around it' => ["\u{202A}https://contoh.test/lomba-siswa\u{202C}"],
    'direction isolate around it' => ["\u{2066}https://contoh.test/lomba-siswa\u{2069}"],
    'byte order mark' => ["\u{FEFF}https://contoh.test/lomba-siswa"],
    'mixed' => ["\u{FEFF} \u{00A0}https://contoh.test/lomba-siswa\u{200B}\r\n "],
]);

it('returns an empty text for anything that is not text', function (mixed $value): void {
    expect(ExternalUrl::normalize($value))->toBe('');
})->with([
    'null' => [null],
    'array' => [['https://contoh.test/']],
    'number' => [443],
    'true' => [true],
    'only spaces' => ["  \t\u{00A0}\n"],
    'empty' => [''],
]);

it('keeps a space inside the link, which is then refused as a space', function (): void {
    $clean = ExternalUrl::normalize('  https://contoh.test/lomba uji  ');

    expect($clean)->toBe('https://contoh.test/lomba uji')
        ->and(ExternalUrl::problem($clean))->toBe(ExternalUrl::SPACE);
});

it('never adds or repairs the scheme', function (string $typed, string $sentence): void {
    expect(ExternalUrl::normalize($typed))->toBe(trim($typed))
        ->and(ExternalUrl::problem(ExternalUrl::normalize($typed)))->toBe($sentence);
})->with([
    [' contoh.test/lomba ', ExternalUrl::NO_HTTPS],
    [' www.contoh.test ', ExternalUrl::NO_HTTPS],
    [' http://contoh.test/lomba ', ExternalUrl::HTTP],
]);

it('does not throw on invalid UTF-8 and still trims it', function (): void {
    $clean = ExternalUrl::normalize("  https://contoh.test/\xC3\x28  ");

    expect($clean)->toBe("https://contoh.test/\xC3\x28")
        ->and(ExternalUrl::problem($clean))->toBe(ExternalUrl::CHARACTERS);
});

// Copy -----------------------------------------------------------------------------------------------------------

it('words every sentence for students and staff alike, in everyday words', function (string $sentence): void {
    // The sentences show on the student sheet and on the Kesiswaan drawer: no pronoun, no formal words.
    expect($sentence)->not->toMatch('/\b(tautan|unggah|unduh|pratinjau|peramban|kamu|anda)\b/i')
        ->and($sentence)->not->toMatch('/\(D-\d+\)/')
        ->and($sentence)->toEndWith(str_contains($sentence, 'Contoh: ') ? '/lomba' : '.');
})->with([
    ExternalUrl::MESSAGE, ExternalUrl::NO_HTTPS, ExternalUrl::HTTP, ExternalUrl::SPACE, ExternalUrl::HOST, ExternalUrl::IP,
    ExternalUrl::PORT, ExternalUrl::CREDENTIALS, ExternalUrl::CHARACTERS, ExternalUrl::TOO_LONG, NoWebAddress::MESSAGE,
]);

it('uses a documentation domain in its examples and points a name with an address to the link field', function (): void {
    expect(ExternalUrl::NO_HTTPS)->toContain('https://www.example.com/lomba')
        ->and(ExternalUrl::HOST)->toContain('https://www.example.com/lomba')
        ->and(ExternalUrl::problem('https://www.example.com/lomba'))->toBeNull()
        ->and(NoWebAddress::MESSAGE)->toContain('kolom link')
        ->and(NoWebAddress::MESSAGE)->not->toContain('situs web');
});
