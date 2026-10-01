<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Enums\RoleName;
use App\Models\PrincipalReplacement;
use App\Models\User;
use App\Notifications\Admin\PrincipalReplacementNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * "Ganti Kepala Sekolah" (D-01, SECURITY §3m-9): Admin moves the `kepala_sekolah` role to another existing
 * staff account. With a current Kepala Sekolah the change waits DELAY_HOURS (24 h) and can be cancelled by
 * that Kepala Sekolah or another Admin (CancelPrincipalReplacement); ApplyPrincipalReplacement runs it from the
 * scheduler. Without a current Kepala Sekolah (fresh install) it applies at once. One pending request at a time.
 * The target must be an active staff account that is not an Admin (D-01: separate accounts) and not a student.
 * Audited; old + new Kepala Sekolah and every Admin are notified.
 */
final class RequestPrincipalReplacement
{
    public const DELAY_HOURS = 24;

    public function __construct(
        private readonly AdminRecipients $recipients,
        private readonly ApplyPrincipalReplacement $apply,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, User $newPrincipal, string $reason): PrincipalReplacement
    {
        Gate::forUser($actor)->authorize('create', PrincipalReplacement::class);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Isi alasan (maks. 500 karakter).']);
        }

        self::assertEligible($newPrincipal);

        $replacement = DB::transaction(function () use ($actor, $newPrincipal, $reason): PrincipalReplacement {
            if (PrincipalReplacement::query()->pending()->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['user' => 'Masih ada penggantian Kepala Sekolah yang menunggu. Batalkan dulu bila perlu.']);
            }

            $current = User::query()->role(RoleName::KepalaSekolah->value)->canLogIn()->lockForUpdate()->first();

            if ($current !== null && $current->is($newPrincipal)) {
                throw ValidationException::withMessages(['user' => 'Akun ini sudah Kepala Sekolah.']);
            }

            $replacement = new PrincipalReplacement;
            $replacement->forceFill([
                'from_user_id' => $current?->getKey(),
                'to_user_id' => $newPrincipal->getKey(),
                'requested_by' => $actor->getKey(),
                'reason' => $reason,
                'effective_at' => $current === null ? now() : now()->addHours(self::DELAY_HOURS),
            ])->save();

            activity('account')
                ->performedOn($replacement)
                ->causedBy($actor)
                ->event('principal.requested')
                ->withProperties([
                    'from_user_id' => $current?->getKey(),
                    'to_user_id' => $newPrincipal->getKey(),
                    'effective_at' => $replacement->effective_at->toIso8601String(),
                    'reason' => $reason,
                ])
                ->log('principal.requested');

            return $replacement;
        });

        if ($replacement->from_user_id === null) {
            $this->apply->handle($replacement, $actor);

            return $replacement->refresh();
        }

        $from = User::query()->find($replacement->from_user_id);

        Notification::send(
            $this->recipients->admins()->push($newPrincipal)->when($from !== null, fn ($c) => $c->push($from))->unique(fn (User $u) => $u->getKey()),
            new PrincipalReplacementNotice(
                PrincipalReplacementNotice::REQUESTED,
                (string) $from?->name,
                (string) $newPrincipal->name,
                $replacement->effective_at->translatedFormat('j F Y H:i'),
            ),
        );

        return $replacement;
    }

    /**
     * @throws ValidationException
     */
    public static function assertEligible(User $user): void
    {
        if (! $user->canLogIn() || $user->trashed()) {
            throw ValidationException::withMessages(['user' => 'Akun tujuan tidak aktif.']);
        }

        if ($user->hasRole(RoleName::Siswa->value)) {
            throw ValidationException::withMessages(['user' => 'Akun siswa tidak bisa menjadi Kepala Sekolah.']);
        }

        if ($user->hasRole(RoleName::Admin->value)) {
            throw ValidationException::withMessages(['user' => 'Akun Admin harus terpisah dari akun Kepala Sekolah.']);
        }
    }
}
