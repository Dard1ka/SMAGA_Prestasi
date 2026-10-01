<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Actions\Templates\LetterTemplate;
use App\Domain\Letters\PdfDocument;
use App\Domain\Letters\PdfQrCode;
use App\Domain\Letters\PdfRenderer;
use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\SettingKey;
use App\Models\File;
use App\Models\Letter;
use App\Models\Registration;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Shared PDF steps of IssueLetters and RevokeLetter (SECURITY §3g/§3m-5/§3m-6): the one signature +
 * stamp image from settings, the dompdf render with the verification QR, and the private-disk `files`
 * row (purpose letter, SHA-256). Callers collect written paths and call cleanup() when their
 * transaction fails, so no orphan PDF stays on disk.
 */
final class LetterPdf
{
    public const TEMPLATES = [
        'siswa' => 'surat-tugas-siswa',
        'guru' => 'surat-perintah-tugas-guru',
    ];

    public function __construct(
        private readonly PdfRenderer $renderer,
        private readonly PdfQrCode $qr,
    ) {}

    /**
     * The signature + stamp image (settings.signature_file_id, private disk). TU never downloads it; it
     * only travels into the render sandbox.
     *
     * @return array{bytes: string, file_id: string, sha256: string}
     *
     * @throws ValidationException key `signature`
     */
    public function signature(): array
    {
        $fileId = Setting::get(SettingKey::SignatureFileId);
        $file = is_string($fileId) && $fileId !== '' ? File::query()->find($fileId) : null;
        $bytes = $file !== null && $file->purpose === FilePurpose::Signature ? $file->storage()->get($file->path) : null;

        if (! is_string($bytes) || $bytes === '') {
            throw ValidationException::withMessages(['signature' => 'Tanda tangan dan stempel belum diunggah Admin.']);
        }

        return ['bytes' => $bytes, 'file_id' => (string) $file->getKey(), 'sha256' => hash('sha256', $bytes)];
    }

    /**
     * @param  string|null  $replaces  full number of the revoked letter this one replaces (printed in the footer)
     *
     * @throws ValidationException key `signature` when the image cannot be used
     */
    public function render(LetterType $type, LetterContent $content, Letter $letter, string $signature, ?string $replaces = null): PdfDocument
    {
        $url = route('public.surat.verifikasi', ['code' => $letter->verification_code]);

        try {
            return $this->renderer->render(self::TEMPLATES[$type->value], [
                'content' => $content,
                'number' => $letter->full_number,
                'verification' => ['code' => $letter->verification_code, 'url' => $url],
                'replaces' => $replaces,
                'letterTemplate' => LetterTemplate::current(), // B5: TU letter template (plain text, read once per render)
            ], [
                'ttd.png' => $signature,
                'qr.png' => $this->qr->png($url, 6),
            ]);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['signature' => 'Gambar tanda tangan tidak bisa dipakai. Minta Admin mengunggah ulang.']);
        }
    }

    /** @param list<array{disk: string, path: string}> $written */
    public function store(PdfDocument $document, LetterType $type, Registration $registration, User $actor, array &$written, int $version = 1): File
    {
        $path = sprintf('surat/%s/%s.pdf', now()->format('Y'), Str::lower((string) Str::ulid()));
        Storage::disk('local')->put($path, $document->bytes);
        $written[] = ['disk' => 'local', 'path' => $path];

        $registration->loadMissing('competition');
        $slug = Str::limit(Str::slug((string) $registration->competition?->name), 60, '');
        $prefix = $type === LetterType::Siswa ? 'surat-tugas-siswa-' : 'surat-perintah-tugas-guru-';

        $file = new File;
        $file->forceFill([
            'disk' => 'local',
            'path' => $path,
            'original_name' => $prefix.($slug !== '' ? $slug : 'lomba').($version > 1 ? '-versi-'.$version : '').'.pdf',
            'mime' => 'application/pdf',
            'size' => $document->size(),
            'sha256' => $document->sha256(),
            'purpose' => FilePurpose::Letter,
            'owner_user_id' => $actor->getKey(),
        ])->save();

        return $file;
    }

    /** @param list<array{disk: string, path: string}> $written */
    public function cleanup(array $written): void
    {
        foreach ($written as $item) {
            rescue(fn () => Storage::disk($item['disk'])->delete($item['path']), null, false);
        }
    }
}
