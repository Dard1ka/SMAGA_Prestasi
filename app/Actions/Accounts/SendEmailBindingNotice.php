<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Models\User;
use App\Notifications\Accounts\EmailBindingNotice;
use App\Notifications\Support\MailGreeting;
use App\Support\FirstName;
use App\Support\PublicAddress;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * K8 (docs/SECURITY.md §3a): records an `email_notices` row and mails the notice with a signed, single-use
 * "Bukan saya?" link (route `akun.bukan-saya`, valid 7 days). Call after the email change has committed.
 * Kinds: bound (activation, to the new address), changed_new / changed_old (Akun › Keamanan, to both addresses).
 * The link is built and signed for the app's public address (APP_URL), not for the host of the request (SV1).
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

        $url = self::publicUrls()->temporarySignedRoute('akun.bukan-saya', $expires, ['notice' => $id]);

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

    /**
     * URLs on the app's public address (config app.url) for a link that is opened from a mailbox (SV1). The signature
     * covers scheme, host and path, so a link signed for the host this request came in on (a LAN address of the
     * school's server) would be refused at the public address. A copy of the URL generator: nothing else of this
     * request changes, and there is nothing to restore. Without a public APP_URL (empty, or a loopback address on a
     * laptop copy: PublicAddress) the request's host is the only one.
     */
    private static function publicUrls(): UrlGenerator
    {
        /** @var UrlGenerator $urls */
        $urls = clone URL::getFacadeRoot();
        $root = PublicAddress::root();

        if ($root === null) {
            return $urls;
        }

        // setRequest() also drops the copied route URL generator, which still points at the original.
        $urls->setRequest(Request::create($root.'/'));
        $urls->useOrigin($root);
        $urls->forceScheme(parse_url($root, PHP_URL_SCHEME) ?: null);

        return $urls;
    }
}
