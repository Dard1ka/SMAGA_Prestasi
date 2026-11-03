<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The address people outside the app reach it by (SV1), for a link that leaves the app for good: the QR code and the
 * check address printed on a surat tugas (LetterPdf::verificationUrl()) and the signed "Bukan saya?" link of an
 * email-binding notice (SendEmailBindingNotice). That is config('app.url') (APP_URL): on the school's server
 * http://<its public IP>:8149, later the https domain, whichever address TU or the student happened to open the app by.
 *
 * root() is null when APP_URL is empty or names this machine itself (localhost, *.localhost, 127.0.0.0/8, ::1): a laptop
 * copy keeps such an APP_URL while testers reach it through the LAN or a tunnel, and there the address the browser used
 * is the only one that works. The caller then builds the link on the request host, as before.
 */
final class PublicAddress
{
    /** APP_URL without a trailing slash, or null when it is empty or a loopback address (see the class comment). */
    public static function root(): ?string
    {
        $root = rtrim((string) config('app.url'), '/');

        if ($root === '') {
            return null;
        }

        $host = strtolower(trim((string) parse_url($root, PHP_URL_HOST), '[]'));

        return $host === '' || self::isLoopback($host) ? null : $root;
    }

    public static function isLoopback(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost') || $host === '::1') {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.');
    }
}
