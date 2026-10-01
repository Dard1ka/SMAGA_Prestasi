<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Actions\Users\AdminRecipients;
use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Admin\SignatureReplaced;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;

/**
 * "Hapus" in Surat & tanda tangan: detaches the active signature image (`signature_file_id` → null) so no
 * new letter can be generated until a new image is uploaded. The file itself stays as evidence. Audited;
 * Kepala Sekolah and Admins are notified.
 */
final class RemoveSignatureImage
{
    public function __construct(private readonly AdminRecipients $recipients) {}

    public function handle(User $actor): void
    {
        Gate::forUser($actor)->authorize('manageSignature', Setting::class);

        $removed = DB::transaction(function () use ($actor): bool {
            $setting = Setting::query()->whereKey(SettingKey::SignatureFileId->value)->lockForUpdate()->first();
            $previous = $setting?->value;

            if ($previous === null) {
                return false;
            }

            Setting::put(SettingKey::SignatureFileId, null);

            activity('setting')
                ->causedBy($actor)
                ->event('signature.removed')
                ->withProperties(['previous_file_id' => $previous])
                ->log('signature.removed');

            return true;
        });

        if ($removed) {
            Notification::send($this->recipients->principalsAndAdmins(), new SignatureReplaced((string) $actor->name, removed: true));
        }
    }
}
