<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Actions\Fortify\LoginIdentifier;
use App\Models\User;
use App\Support\FirstName;

/**
 * The first line of every mail STH sends, one rule (lead, 2026-10-10, D-129):
 * - a student: "Halo Nadia," (the name the student is called by, App\Support\FirstName);
 * - staff, and an account that has no role yet (a new Admin / Kesiswaan / TU account waits for its role):
 *   "Yth. Drs. Bambang Hartono, M.Pd.," (the full name as stored, never a pronoun);
 * - nobody known (a mail to a bare address, an empty name): "Halo,".
 * Before, each notification built its own: "Halo!" where none was set, "Halo Drs.," for the Kepala Sekolah,
 * "Halo Kamu," and "Halo Siswa," as fallbacks.
 *
 * A notification that knows its audience calls student() or staff(); one that reaches both calls for(). The result
 * is plain text: PlainMailMessage::greeting() encodes it, the OTP mail escapes it.
 */
final class MailGreeting
{
    public const NEUTRAL = 'Halo,';

    /** The greeting for whoever receives the mail: decided by the account, not by the notification. */
    public static function for(object $notifiable): string
    {
        if (! $notifiable instanceof User) {
            return self::NEUTRAL;
        }

        // loadMissing first: a user that came in a collection must not lazy load its roles (strict models).
        return LoginIdentifier::isStudentAccount($notifiable->loadMissing('roles'))
            ? self::student($notifiable)
            : self::staff($notifiable);
    }

    /** @param object|string|null $who the notifiable (its name is used) or the name itself */
    public static function student(object|string|null $who): string
    {
        $firstName = FirstName::of(self::name($who));

        return $firstName === null ? self::NEUTRAL : 'Halo '.$firstName.',';
    }

    /** @param object|string|null $who the notifiable (its name is used) or the name itself */
    public static function staff(object|string|null $who): string
    {
        $name = FirstName::fold((string) self::name($who));

        return $name === '' ? self::NEUTRAL : 'Yth. '.$name.',';
    }

    private static function name(object|string|null $who): ?string
    {
        if (is_object($who)) {
            $who = $who->name ?? null;
        }

        return is_string($who) ? $who : null;
    }
}
