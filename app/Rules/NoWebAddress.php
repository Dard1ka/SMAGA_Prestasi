<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A free-text name (lomba) must not carry a web address (D-76, SECURITY §3m-1). The name is printed in mails sent
 * from the school's address; STH never turns it into a link, but mail clients auto-link anything that looks like
 * one ("daftar di www.situs.example"), which makes a proposal usable for phishing. Addresses belong in the
 * `website_url` / `registration_url` fields (App\Rules\ExternalUrl).
 *
 * Refused: a scheme (`https://…`), `www.…`, and a bare host with a common top-level domain (`situs.com`,
 * `sekolah.sch.id`, `bit.ly/x`). Not refused: abbreviations and numbers with dots ("Tk. Prov.", "Vol.2", "S.T.E.M").
 *
 *   'name' => ['required', 'string', 'max:200', new NoWebAddress]
 */
final class NoWebAddress implements ValidationRule
{
    public const MESSAGE = 'Nama lomba tidak boleh berisi alamat web. Tulis alamatnya di kolom situs web.';

    /** Top-level domains people actually type (generic, Indonesian, link shorteners). */
    private const TLDS = 'com|net|org|id|info|biz|xyz|site|online|app|dev|io|me|co|link|ly|gl|gle|ee|to|my|web|edu|gov|sch|ac'
        .'|go|or|tv|cc|top|club|shop|store|blog|page|live|space|tech|cloud|example';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::found($value)) {
            $fail(self::MESSAGE);
        }
    }

    public static function found(string $text): bool
    {
        return preg_match(
            '~[a-z][a-z0-9+.\-]*://'                                                  // scheme
            .'|(?<![a-z0-9])www\.[a-z0-9\-]'                                           // www.
            .'|(?<![a-z0-9.\-])[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9\-]+)*\.(?:'.self::TLDS.')(?![a-z0-9\-])~iu',
            $text,
        ) === 1;
    }
}
