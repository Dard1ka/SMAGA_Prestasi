<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The database channel of STH (the bell, Akun › Notifikasi): a row keeps the PATH of its target, not the absolute
 * address.
 *
 * Every notification builds one address with route() and uses it for the mail button and for the bell row. A mail
 * needs the absolute address. A bell row is opened inside the app, and Notifikasi::internalTarget() only offers
 * "Buka" for an address of the host the page runs on: a row that stored "https://old-host/siswa/lomba-saya/…" lost its
 * button as soon as the app answered under another host (a demo behind a tunnel whose address changes at every
 * start, a copy of the database on a second machine, a site that moves to a new domain). So the address is cut down
 * to its path here, in the one place every bell row passes, and only when it starts with this application's own root
 * at that moment; an address of any other site is stored as it came and stays refused by the screen (RV1).
 *
 * A trashed notifiable (a student in Admin › Kelas › Sampah, smaga_server) gets no bell row: a queued notification
 * restores its notifiable without the SoftDeletes scope, so a notice queued before the trash would still land there.
 * "Pulihkan" brings the account back without the notices of its days in the trash.
 */
final class BellChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return Model|null the stored row, null when the notifiable is in the trash
     */
    public function send($notifiable, Notification $notification)
    {
        if (self::trashed($notifiable)) {
            return null;
        }

        return parent::send($notifiable, $notification);
    }

    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function getData($notifiable, Notification $notification)
    {
        $data = parent::getData($notifiable, $notification);

        if (is_array($data) && is_string($data['url'] ?? null)) {
            $data['url'] = self::relative($data['url']);
        }

        return $data;
    }

    private static function trashed(mixed $notifiable): bool
    {
        return $notifiable instanceof Model
            && in_array(SoftDeletes::class, class_uses_recursive($notifiable), true)
            && $notifiable->trashed();
    }

    /**
     * "https://<this app>/siswa/lomba-saya?tab=1" → "/siswa/lomba-saya?tab=1"; anything else unchanged.
     */
    public static function relative(string $url): string
    {
        $root = rtrim(url('/'), '/');

        if ($root === '' || ! str_starts_with($url, $root)) {
            return $url;
        }

        $rest = substr($url, strlen($root));

        return match (true) {
            $rest === '' => '/',
            $rest[0] === '/' => $rest,
            $rest[0] === '?' || $rest[0] === '#' => '/'.$rest,
            // "https://app.example.evil/…" only shares its first letters with the root: another site.
            default => $url,
        };
    }
}
