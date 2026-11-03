<?php

declare(strict_types=1);

namespace App\Domain\Files;

/**
 * Heuristic scan of an uploaded PDF for active content (SECURITY §3d). Not a PDF parser: wherever it cannot be
 * sure what a reader would see, the answer is "active" and the upload is refused.
 *
 * 1. The unmistakable names (/JavaScript, /OpenAction, /Launch, /EmbeddedFile) are searched in every byte of the
 *    file, so no string, comment or stream body can hide them.
 * 2. The object syntax between the streams is searched for every active name, hex-escaped spellings included.
 * 3. A `stream` keyword must directly follow its dictionary, read the way a reader reads it (strings, hex strings
 *    and comments skipped). A stream readers can take objects from (/Type /ObjStm, or only /First or /N) is
 *    inflated and searched too; if it cannot be read (another filter, a predictor, damaged or oversized data) the
 *    file is refused.
 * 4. Other stream bodies (images, fonts, page content) are skipped: random bytes would give false alarms on the
 *    short names /JS and /AA. Such a body may not contain an object header ("12 0 obj") of its own.
 */
final class PdfScanner
{
    /** PDF names that trigger scripts, auto actions, launches or embedded files. */
    public const ACTIVE_NAMES = ['javascript', 'js', 'openaction', 'launch', 'embeddedfile', 'embeddedfiles', 'aa'];

    /** Total size all object streams of one file may inflate to (decompression bombs). */
    public const MAX_INFLATED_BYTES = 16 * 1024 * 1024;

    /** Active names long enough to never appear in compressed or image data by chance. */
    private const UNMISTAKABLE_NAMES = ['javascript', 'openaction', 'launch', 'embeddedfile', 'embeddedfiles'];

    /** Dictionary keys that let a reader use a stream as an object stream, with or without /Type. */
    private const OBJECT_STREAM_NAMES = ['objstm', 'first', 'n'];

    /** Encodings (and their short forms) the scanner cannot undo. */
    private const UNREADABLE_NAMES = [
        'asciihexdecode', 'ahx', 'ascii85decode', 'a85', 'lzwdecode', 'lzw', 'runlengthdecode', 'rl', 'crypt',
        'dctdecode', 'dct', 'jpxdecode', 'ccittfaxdecode', 'ccf', 'jbig2decode', 'decodeparms', 'dp',
    ];

    /** PDF white space: for regex character classes, and as bytes. */
    private const SPACE = '\x00\t\n\x0c\r ';

    private const SPACE_BYTES = "\x00\t\n\x0c\r ";

    /** The keyword right after a dictionary, or as a word of its own at the end of a line. */
    private const STREAM_KEYWORD = '~(?:>>['.self::SPACE.']*+\Kstream(?![^'.self::SPACE.'()<>\[\]{}/%])'
        .'|(?<![^'.self::SPACE.'()<>\[\]{}%])stream(?=[\x00\t\x0c ]*+[\r\n]))~';

    /** The keyword as the token at this very position. */
    private const KEYWORD_HERE = '~\Gstream(?![^'.self::SPACE.'()<>\[\]{}/%])~';

    private const OBJECT_HEADER = '~(?<![0-9])[0-9]++['.self::SPACE.']++[0-9]++['.self::SPACE.']++obj(?![A-Za-z0-9])~';

    /** An object stream starts with pairs of numbers (object number, offset). */
    private const OBJECT_STREAM_START = '~^(?:['.self::SPACE.']++|%[^\r\n]*+)*+[+-]?[0-9.]++(?:['.self::SPACE.']++|%[^\r\n]*+)++[+-]?[0-9.]~';

    public static function hasActiveContent(string $bytes): bool
    {
        if (self::names($bytes, self::UNMISTAKABLE_NAMES) !== 0) {
            return true;
        }

        $length = strlen($bytes);
        $budget = self::MAX_INFLATED_BYTES;
        $offset = 0;

        while ($offset < $length) {
            // The next stream, found twice: as the keyword in the raw bytes and as a reader reaches it. They must agree,
            // else the keyword sits in a string or comment, or in a form only lenient readers take as a stream.
            $found = preg_match(self::STREAM_KEYWORD, $bytes, $match, PREG_OFFSET_CAPTURE, $offset);
            $dictionary = self::streamDictionary($bytes, $offset, $keywordAt);

            if ($found === false || $keywordAt !== ($match[0][1] ?? null)) {
                return true;
            }

            if ($keywordAt === null) {
                return self::names(substr($bytes, $offset), self::ACTIVE_NAMES) !== 0;
            }

            $lineEnd = $keywordAt + strcspn($bytes, "\r\n", $keywordAt);

            if ($lineEnd >= $length || self::names(substr($bytes, $offset, $keywordAt - $offset), self::ACTIVE_NAMES) !== 0) {
                return true;
            }

            $dataStart = $lineEnd + (substr($bytes, $lineEnd, 2) === "\r\n" ? 2 : 1);
            $dataEnd = strpos($bytes, 'endstream', $dataStart);
            $data = substr($bytes, $dataStart, ($dataEnd === false ? $length : $dataEnd) - $dataStart);

            if (self::holdsObjects($data) || self::objectStreamIsActive($dictionary, $data, $budget)) {
                return true;
            }

            $offset = $dataEnd === false ? $length : $dataEnd + strlen('endstream');
        }

        return false;
    }

    /**
     * True when readers may take objects from this stream and those objects are active or cannot be read.
     */
    private static function objectStreamIsActive(string $dictionary, string $data, int &$budget): bool
    {
        if (self::names($dictionary, self::OBJECT_STREAM_NAMES) === 0) {
            return false;
        }

        if (self::names($dictionary, self::UNREADABLE_NAMES) !== 0) {
            return true;
        }

        if (self::names($dictionary, ['filter']) !== 0) {
            // Readable only with exactly one FlateDecode.
            $data = self::names($dictionary, ['flatedecode', 'fl']) === 1 ? self::inflate($data, $budget) : null;

            if ($data === null) {
                return true;
            }
        }

        $isObjectStream = self::names($dictionary, ['objstm']) !== 0 || preg_match(self::OBJECT_STREAM_START, $data) !== 0;

        return $isObjectStream && self::names($data, self::ACTIVE_NAMES) !== 0;
    }

    /**
     * zlib data inflated within what is left of the size budget, or null (damaged, not zlib, too large). The line
     * break before "endstream" may still be attached: gzuncompress() ignores bytes after the end of the data.
     */
    private static function inflate(string $data, int &$budget): ?string
    {
        if ($budget < 1) {
            return null;
        }

        // A failed inflate is an answer here, not a warning to report.
        set_error_handler(static fn (): bool => true);

        try {
            $inflated = gzuncompress($data, $budget);
        } finally {
            restore_error_handler();
        }

        // gzuncompress() may return somewhat more than the limit asked for.
        if ($inflated === false || strlen($inflated) > $budget) {
            return null;
        }

        $budget -= strlen($inflated);

        return $inflated;
    }

    /**
     * Reads the syntax from $offset the way a reader does (strings, hex strings and comments skipped) up to the
     * first `stream` keyword that directly follows a dictionary. Sets $keywordAt to its position and returns the
     * keys and direct values of that dictionary (strings, comments and nested dictionaries left out); null on both
     * when there is no such keyword. Text inside a string or comment cannot pose as the dictionary or the keyword.
     */
    private static function streamDictionary(string $bytes, int $offset, ?int &$keywordAt): ?string
    {
        $keywordAt = null;
        $length = strlen($bytes);
        $depth = 0;
        $entries = '';
        $closed = false; // a top-level dictionary just ended and only white space or comments followed

        for ($i = $offset; $i < $length;) {
            $plain = strcspn($bytes, '%(<>', $i);
            $two = substr($bytes, $i + $plain, 2);

            if ($depth === 1) {
                $entries .= substr($bytes, $i, $plain).' ';
            } elseif ($depth === 0 && ($space = strspn($bytes, self::SPACE_BYTES, $i, $plain)) !== $plain) {
                if ($closed && preg_match(self::KEYWORD_HERE, $bytes, offset: $i + $space) === 1) {
                    $keywordAt = $i + $space;

                    return $entries;
                }

                $closed = false;
            }

            $i += $plain;

            if ($two === '') {
                break;
            }

            if ($two[0] === '%') {
                // Comment, up to the end of the line.
                $i += strcspn($bytes, "\r\n", $i);

                continue;
            }

            $closed = false;

            if ($two[0] === '(') {
                // Literal string: balanced parentheses, a backslash escapes the next character.
                for ($open = 1, $i++; $open > 0; $i++) {
                    if ($i >= $length || ($i += strcspn($bytes, '()\\', $i)) >= $length) {
                        return null;
                    }

                    match ($bytes[$i]) {
                        '(' => $open++,
                        ')' => $open--,
                        default => $i++,
                    };
                }
            } elseif ($two === '<<') {
                $entries = $depth++ === 0 ? '' : $entries;
                $i += 2;
            } elseif ($two[0] === '<') {
                // Hex string.
                if (($close = strpos($bytes, '>', $i)) === false) {
                    return null;
                }

                $i = $close + 1;
            } elseif ($two === '>>' && $depth > 0) {
                $closed = --$depth === 0;
                $i += 2;
            } else {
                return null;
            }
        }

        return null;
    }

    /**
     * True when a stream body contains an object header ("12 0 obj", comments between the tokens allowed):
     * a cross-reference table could point readers at an object hidden there.
     */
    private static function holdsObjects(string $data): bool
    {
        if (! str_contains($data, 'obj')) {
            return false;
        }

        $withoutComments = preg_replace('~([0-9])(?:['.self::SPACE.']*+%[^\r\n]*+)++~', '$1 ', $data);

        return $withoutComments === null
            || preg_match(self::OBJECT_HEADER, $data) !== 0
            || preg_match(self::OBJECT_HEADER, $withoutComments) !== 0;
    }

    /**
     * How often one of the (lowercase) names occurs in $text as a PDF name, in any case and with any character
     * written as a #xx escape (/J#61vaScript). A PCRE failure counts as found.
     *
     * @param  list<string>  $names
     */
    private static function names(string $text, array $names): int
    {
        static $patterns = [];

        $pattern = $patterns[implode('|', $names)] ??= '~/(?:'.implode('|', array_map(
            fn (string $name): string => implode('', array_map(
                fn (string $char): string => sprintf('(?:%s|#%02x|#%02x)', $char, ord($char), ord(strtoupper($char))),
                str_split($name),
            )),
            $names,
        )).')(?![A-Za-z0-9#])~i';

        $count = preg_match_all($pattern, $text);

        return $count === false ? 1 : $count;
    }
}
