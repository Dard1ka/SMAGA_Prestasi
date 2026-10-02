<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

/**
 * MailMessage whose greeting and lines are plain text. Laravel renders them as Markdown, and STH mails carry text
 * typed by users (lomba names, people's names, staff notes): a lomba named "[Masuk](https://…) ![x](https://…)"
 * would put a real link and a remote image into a mail sent by the school (SECURITY §3m-1).
 *
 * Every ASCII punctuation character is written as a numeric character reference. Markdown never reads those as
 * markup, the HTML part shows the text as typed and the text part decodes it again. Markdown::withSecuredEncoding()
 * (AppServiceProvider) stays on as a second layer; alone it is not enough: a backslash before "[" undoes it.
 *
 * Every notification builds its mail with this class (MailInjectionTest checks it). Intended markup: pass an
 * Illuminate\Support\HtmlString, never one built from user text.
 */
final class PlainMailMessage extends MailMessage
{
    /**
     * @param  Htmlable|string|null  $greeting
     * @return $this
     */
    public function greeting($greeting)
    {
        // Empty stays empty: the template then prints its default greeting.
        return parent::greeting(blank($greeting) ? $greeting : self::plain($greeting));
    }

    /**
     * @param  Htmlable|string|array<int, string>|null  $line
     */
    protected function formatLine($line)
    {
        return self::plain(parent::formatLine($line));
    }

    private static function plain(Htmlable|string|null $text): Htmlable
    {
        if ($text instanceof Htmlable) {
            return $text;
        }

        return new HtmlString((string) preg_replace_callback(
            '/[!-\/:-@\[-`{-~]/',
            static fn (array $match): string => '&#'.ord($match[0]).';',
            (string) $text,
        ));
    }
}
