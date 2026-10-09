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
 * Refused: a scheme (`https://…`), `www.…`, a bare host with a common top-level domain (`situs.com`,
 * `sekolah.sch.id`, `bit.ly/x`), a host with a country domain often used for short links or spam (`rb.gy`, `is.gd`,
 * `youtu.be`, `lnkd.in`, `situs.ru`; QA10) and any lower-case `host.tld/path` (`shorturl.at/abc`, QA10).
 * Not refused: abbreviations and numbers with dots ("Tk. Prov.", "Vol.2", "S.T.E.M", "Lomba 1.0"), academic degrees
 * ("S.Pd.", "M.Si.", "M.Sn.", "S.Th.", "Dr.Ir."), school levels ("Tk.SMA/SMK", "Tk.SD/MI", "Tk.MA").
 *
 *   'name' => ['required', 'string', 'max:200', new NoWebAddress]
 */
final class NoWebAddress implements ValidationRule
{
    public const MESSAGE = 'Nama lomba tidak boleh berisi alamat web. Tulis alamatnya di kolom situs web.';

    /** Top-level domains people actually type (generic, Indonesian, link shorteners): refused after any host. */
    private const TLDS = 'com|net|org|id|info|biz|xyz|site|online|app|dev|io|me|co|link|ly|gl|gle|ee|to|my|web|edu|gov|sch|ac'
        .'|go|or|tv|cc|top|club|shop|store|blog|page|live|space|tech|cloud|example'
        // QA10: generic domains common in spam and short links that are no Indonesian or English word.
        .'|mobi|icu|cyou|sbs|cfd|buzz|click|vip|email';

    /**
     * Country domains used for short links and spam (QA10). Two letters can also end an abbreviation ("M.Si.",
     * "S.Th."), so these count only after a host of at least two characters.
     */
    private const COUNTRY_TLDS = 'at|be|gd|gy|in|ru|cn|uk|us|eu|jp|kr|sg|au|ph|vn|hk|tw|nl|ca|de|fr|es|br|mx|nz|ch'
        .'|pl|cz|ro|ua|su|ws|pw|ml|ga|cf|gq|la|nu|fm|ai|cx|ms|sh|li|lt|lv|kz|by|uz|pk|bd|lk|np';

    /**
     * Country domains that also end common Indonesian abbreviations ("Tk.TK", "Tk.MA", "Tk.SD", "Dr.Ir.", "S.Ag."):
     * refused only when written in lower case, the way an address is typed (QA10).
     */
    private const LOWER_CASE_TLDS = 'tk|ma|sd|pt|ir|it|se|si|sk|sn|th|ag';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::found($value)) {
            $fail(self::MESSAGE);
        }
    }

    public static function found(string $text): bool
    {
        $host = '(?<![a-z0-9.\-])[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9\-]+)*';

        return preg_match(
            '~[a-z][a-z0-9+.\-]*://'                                                  // scheme
            .'|(?<![a-z0-9])www\.[a-z0-9\-]'                                           // www.
            .'|'.$host.'\.(?:'.self::TLDS.')(?![a-z0-9\-])'                            // host.com
            .'|(?<![a-z0-9.\-])(?:[a-z0-9\-]+\.)*[a-z0-9][a-z0-9\-]+\.(?:'.self::COUNTRY_TLDS.')(?![a-z0-9\-])~iu', // rb.gy
            $text,
        ) === 1
            // Lower case only from here (QA10), so "Tk.SMA/SMK", "Tk.MA" or "Vol.II/2026" stay ordinary names.
            || preg_match('~(?<![A-Za-z0-9.\-])(?:[a-z0-9\-]+\.)*[a-z0-9][a-z0-9\-]+\.(?:'.self::LOWER_CASE_TLDS.')(?![A-Za-z0-9\-])~u', $text) === 1
            // host.tld/path, whatever the domain: "shorturl.at/abc", "panitia.zz/daftar".
            || preg_match('~(?<![A-Za-z0-9.\-])[a-z0-9][a-z0-9\-]*(?:\.[a-z0-9\-]+)*\.[a-z]{2,24}/[A-Za-z0-9]~u', $text) === 1;
    }
}
