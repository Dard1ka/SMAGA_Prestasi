<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Actions\Users\AdminRecipients;
use App\Domain\Files\InvalidUpload;
use App\Domain\Files\StoreUpload;
use App\Domain\Files\UploadRules;
use App\Enums\FilePurpose;
use App\Enums\SettingKey;
use App\Models\File;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Admin\SignatureReplaced;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The single signature + stamp image printed on every surat tugas (SECURITY §3m-5, UI-MAP #24):
 * Admin only (`signature.manage`), PNG ≤ 1 MB and ≤ 3000 × 3000 px. Stored through App\Domain\Files\StoreUpload
 * like every user upload (CLAUDE.md §3.4; W4): extension + sniffed MIME, re-encoded with GD (metadata dropped,
 * scaled to ≤ 2048 px), private `local` disk under `ttd/Y/m/<ulid>.png`, and B2's hourly upload limit.
 * Versioned: old files stay (evidence), `signature_file_id` points to the new one. Audited with both ids +
 * SHA-256; Kepala Sekolah and Admins are notified. The file is never web-served directly; TU only uses it
 * through letter generation.
 */
final class ReplaceSignatureImage
{
    public const MAX_BYTES = 1024 * 1024;

    public const MAX_SIDE = 3000;

    public const DIRECTORY = 'ttd';

    public function __construct(
        private readonly AdminRecipients $recipients,
        private readonly StoreUpload $store,
    ) {}

    /**
     * @throws ValidationException
     */
    public function handle(User $actor, UploadedFile $upload): File
    {
        Gate::forUser($actor)->authorize('manageSignature', Setting::class);

        $rules = new UploadRules(self::MAX_BYTES, ['png']);
        $path = (string) $upload->getRealPath();
        $errors = $path !== '' ? $rules->validate($path, (string) $upload->getClientOriginalName()) : [UploadRules::MSG_UNREADABLE];

        if ($errors !== []) {
            throw ValidationException::withMessages(['signature' => $errors[0]]);
        }

        // Stricter than StoreUpload's 8000 px decode limit: refuse before anything is decoded or stored.
        $size = @getimagesize($path);

        if ($size === false) {
            throw ValidationException::withMessages(['signature' => UploadRules::MSG_CONTENT]);
        }

        if ($size[0] > self::MAX_SIDE || $size[1] > self::MAX_SIDE) {
            throw ValidationException::withMessages(['signature' => 'Ukuran gambar maksimal 3000 × 3000 piksel.']);
        }

        try {
            $file = $this->store->store($upload, FilePurpose::Signature, $actor, $rules, self::DIRECTORY);
        } catch (InvalidUpload $e) {
            throw $e->toValidationException('signature');
        }

        try {
            DB::transaction(function () use ($actor, $file): void {
                $setting = Setting::query()->whereKey(SettingKey::SignatureFileId->value)->lockForUpdate()->first();
                $previous = $setting?->value;

                Setting::put(SettingKey::SignatureFileId, $file->getKey());

                activity('setting')
                    ->performedOn($file)
                    ->causedBy($actor)
                    ->event('signature.replaced')
                    ->withProperties(['previous_file_id' => $previous, 'file_id' => $file->getKey(), 'sha256' => $file->sha256])
                    ->log('signature.replaced');
            });
        } catch (Throwable $e) {
            $this->store->discard($file);

            throw $e;
        }

        Notification::send($this->recipients->principalsAndAdmins(), new SignatureReplaced((string) $actor->name));

        return $file;
    }
}
