<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Actions\Users\AdminRecipients;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * The single signature + stamp image printed on every surat tugas (SECURITY §3m-5, UI-MAP #24):
 * Admin only (`signature.manage`), PNG ≤ 1 MB, checked with App\Domain\Files\UploadRules (extension +
 * sniffed MIME), re-encoded with Intervention Image (metadata dropped), stored privately under
 * `ttd/<ulid>.png` on the `local` disk. Versioned: old files stay (evidence), `signature_file_id` points to
 * the new one. Audited with both ids + SHA-256; Kepala Sekolah and Admins are notified.
 * The file is never web-served directly; TU only uses it through letter generation.
 */
final class ReplaceSignatureImage
{
    public const MAX_BYTES = 1024 * 1024;

    public const MAX_SIDE = 3000;

    public function __construct(private readonly AdminRecipients $recipients) {}

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

        try {
            $image = ImageManager::usingDriver(Driver::class)->decodePath($path);

            if ($image->width() > self::MAX_SIDE || $image->height() > self::MAX_SIDE) {
                throw ValidationException::withMessages(['signature' => 'Ukuran gambar maksimal 3000 × 3000 piksel.']);
            }

            $bytes = $image->encode(new PngEncoder)->toString();
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw ValidationException::withMessages(['signature' => UploadRules::MSG_CONTENT]);
        }

        $storagePath = 'ttd/'.Str::lower((string) Str::ulid()).'.png';
        Storage::disk('local')->put($storagePath, $bytes);

        try {
            $file = DB::transaction(function () use ($actor, $upload, $rules, $storagePath, $bytes): File {
                $setting = Setting::query()->whereKey(SettingKey::SignatureFileId->value)->lockForUpdate()->first();
                $previous = $setting?->value;

                $file = new File;
                $file->forceFill([
                    'disk' => 'local',
                    'path' => $storagePath,
                    'original_name' => $rules->sanitizeOriginalName((string) $upload->getClientOriginalName()),
                    'mime' => 'image/png',
                    'size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'purpose' => FilePurpose::Signature,
                    'owner_user_id' => $actor->getKey(),
                ])->save();

                Setting::put(SettingKey::SignatureFileId, $file->getKey());

                activity('setting')
                    ->performedOn($file)
                    ->causedBy($actor)
                    ->event('signature.replaced')
                    ->withProperties(['previous_file_id' => $previous, 'file_id' => $file->getKey(), 'sha256' => $file->sha256])
                    ->log('signature.replaced');

                return $file;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($storagePath);

            throw $e;
        }

        Notification::send($this->recipients->principalsAndAdmins(), new SignatureReplaced((string) $actor->name));

        return $file;
    }
}
