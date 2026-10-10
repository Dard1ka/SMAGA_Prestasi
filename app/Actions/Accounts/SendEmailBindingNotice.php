<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use App\Notifications\Accounts\EmailBindingNotice;
use App\Notifications\Support\MailGreeting;
use App\Support\FirstName;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * K8 (docs/SECURITY.md §3a): records an `email_notices` row and mails the notice with a signed, single-use
 * "Bukan saya?" link (route `akun.bukan-saya`, valid 7 days). Call after the email change has committed.
 * Kinds: bound (activation, to the new address), changed_new / changed_old (Akun › Keamanan, to both addresses).
 */
final class SendEmailBindingNotice
{
    public const VALID_DAYS = 7;

    public function handle(User $user, string $kind, string $toEmail, string $newEmail): void
    {
        if (! Route::has('akun.bukan-saya') || trim($toEmail) === '') {
            return;
        }

        $id = (string) Str::ulid();
        $now = now();
        $expires = $now->copy()->addDays(self::VALID_DAYS);

        DB::table('email_notices')->insert([
            'id' => $id,
            'user_id' => $user->getKey(),
            'kind' => $kind,
            'sent_to_masked' => (string) AccountControls::maskEmail($toEmail),
            'expires_at' => $expires,
            'used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $url = URL::temporarySignedRoute('akun.bukan-saya', $expires, ['notice' => $id]);

        Notification::route('mail', $toEmail)->notify(new EmailBindingNotice(
            $kind,
            (string) FirstName::of($user->name),
            (string) AccountControls::maskEmail($newEmail),
            $url,
            self::VALID_DAYS,
            // The mail goes to a bare address, so the notice cannot ask the account itself.
            MailGreeting::for($user),
        ));
    }
}
