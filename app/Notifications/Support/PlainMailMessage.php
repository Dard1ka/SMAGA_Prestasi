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
 * (AppServiceProvider) stays on as a second layer; alone it is not enough: a backslash before "[" undoes it, and it
 * only acts on a view compiled while a mail is rendered. Production compiles every view beforehand (view:cache) with
 * the ordinary Blade echo, where it does nothing. So the notification view prints the greeting and every line
 * through plain() once more: text stays text there too, for a mail that was not built with this class as well.
 *
 * Every notification builds its mail with this class (MailInjectionTest checks it). Intended markup: pass an
 * Illuminate\Support\HtmlString, never one built from user text.
 *
 * The template (lead, 2026-10-10, D-129): resources/views/vendor/notifications/email.blade.php with the components
 * in resources/views/vendor/mail/{html,text} and the theme html/themes/sth.css. A mail is: the greeting
 * (MailGreeting, one rule for every mail), the lines, at most one link (the action button; the address under it is
 * text) and the footer sentence below. No salutation, no image; the text part carries no Markdown.
 */
final class PlainMailMessage extends MailMessage
{
    /** The one sentence under every mail STH sends: notification mails, the OTP mail and `sth:mail-test`. */
    public const FOOTER = 'Email otomatis dari SMAGA Talent Hub, SMA Negeri 3 Semarang. Jangan balas email ini.';

    /**
     * @param  Htmlable|string|null  $greeting
     * @return $this
     */
    public function greeting($greeting)
    {
        if ($greeting instanceof Htmlable) {
            return parent::greeting($greeting);
        }

        // One line: a name with a line break must not end the heading and start a paragraph of its own.
        $text = (string) $greeting;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? (string) preg_replace('/\s+/', ' ', $text));

        // Empty stays empty: the template then prints the neutral greeting (MailGreeting::NEUTRAL).
        return parent::greeting($text === '' ? null : self::plain($text));
    }

    /**
     * @param  Htmlable|string|array<int, string>|null  $line
     */
    protected function formatLine($line)
    {
        return self::plain(parent::formatLine($line));
    }

    /**
     * Text as it is, for Markdown: punctuation as character references. An Htmlable is trusted and returned as given.
     * The notification view prints the greeting and every line through this once more, so a mail that was not built
     * with this class (a stock MailMessage) is inert as well, whichever way the view was compiled.
     */
    public static function plain(Htmlable|string|null $text): Htmlable
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
