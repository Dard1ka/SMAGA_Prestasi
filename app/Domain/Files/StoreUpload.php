<?php

declare(strict_types=1);

namespace App\Domain\Files;

use App\Enums\FilePurpose;
use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * The only way user uploads reach storage (CLAUDE.md §3.4, SECURITY §3d):
 * 1. UploadRules: extension + sniffed-MIME allowlist, size limit, `%PDF-` magic;
 * 2. PDFs: heuristic scan for active content (/JavaScript, /JS, /OpenAction, /Launch, /EmbeddedFile, /AA) → refused;
 * 3. images: dimensions checked before decoding (≤ 8000 px), re-encoded with GD (EXIF/GPS dropped),
 *    EXIF orientation applied, scaled down to ≤ 2048 px;
 * 4. stored on the private `local` disk under a random path ("lampiran/Y/m/<ulid>.<ext>", extension from the
 *    sniffed MIME) and recorded as an App\Models\File (sha256 + size of the stored bytes).
 * 5. UploadLimits (B2): 30 stored uploads per user per hour, 200 MB stored per student; refused as InvalidUpload.
 * Files are served only by App\Http\Controllers\FileController after App\Policies\FilePolicy.
 *
 * Usage: $file = app(StoreUpload::class)->store($upload, FilePurpose::Juknis, $actor);
 *        catch InvalidUpload → $e->toValidationException('brosur').
 */
final class StoreUpload
{
    public function __construct(private readonly UploadLimits $limits = new UploadLimits) {}

    public const DISK = 'local';

    public const MAX_DECODE_SIDE = 8000;

    public const MAX_IMAGE_SIDE = 2048;

    /** PDF names that trigger scripts, auto actions, launches or embedded files. */
    public const PDF_ACTIVE_NAMES = ['javascript', 'js', 'openaction', 'launch', 'embeddedfile', 'embeddedfiles', 'aa'];

    public const MSG_PDF_ACTIVE = 'PDF berisi skrip atau lampiran tersembunyi. Simpan ulang sebagai PDF biasa.';

    public const MSG_IMAGE_TOO_LARGE = 'Ukuran gambar terlalu besar (maks. 8000 piksel).';

    public const MSG_IMAGE_BROKEN = 'Gambar tidak bisa dibaca.';

    /** Limits per purpose (SECURITY §3d). Callers may pass their own UploadRules instead. */
    public static function rulesFor(FilePurpose $purpose): UploadRules
    {
        $mb = 1024 * 1024;

        return match ($purpose) {
            FilePurpose::Juknis, FilePurpose::ReportEvidence, FilePurpose::TalentEvidence => new UploadRules(5 * $mb),
            FilePurpose::Cover => new UploadRules(3 * $mb, ['jpg', 'jpeg', 'png', 'webp']),
            FilePurpose::Signature => new UploadRules($mb, ['png']),
            default => new UploadRules,
        };
    }

    /**
     * @throws InvalidUpload
     */
    public function store(
        UploadedFile $upload,
        FilePurpose $purpose,
        ?User $owner,
        ?UploadRules $rules = null,
        string $directory = 'lampiran',
    ): File {
        $this->limits->ensureCanUpload($owner);

        $rules ??= self::rulesFor($purpose);
        $path = (string) $upload->getRealPath();
        $originalName = $upload->getClientOriginalName();

        $errors = $rules->validate($path, $originalName);

        if ($errors !== []) {
            throw new InvalidUpload($errors);
        }

        $mime = (string) $rules->sniffMime($path);

        $bytes = $mime === 'application/pdf'
            ? $this->checkedPdf($path)
            : $this->reencodedImage($path, $mime);

        $this->limits->ensureFitsQuota($owner, strlen($bytes));

        $storagePath = $rules->storagePath($mime, now(), $directory);
        Storage::disk(self::DISK)->put($storagePath, $bytes);

        try {
            $file = File::query()->create([
                'disk' => self::DISK,
                'path' => $storagePath,
                'original_name' => $rules->sanitizeOriginalName($originalName),
                'mime' => $mime,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'purpose' => $purpose,
                'owner_user_id' => $owner?->getKey(),
            ]);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($storagePath);

            throw $e;
        }

        $this->limits->recordUpload($owner);

        return $file;
    }

    /** Sniffed MIME types accepted for import spreadsheets, per extension (storeSpreadsheet()). */
    public const SPREADSHEET_MIMES = [
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'csv' => ['text/plain', 'text/csv', 'application/csv', 'text/x-csv'],
    ];

    public const MSG_SPREADSHEET_TYPE = 'Pilih file .xlsx atau .csv.';

    /**
     * B2 (backlog #19): keep an import spreadsheet (.xlsx/.csv) privately until the queued import has read it.
     * Extension AND sniffed MIME must match, size ≤ $maxBytes, same hourly upload limit as every upload. The bytes
     * are stored unchanged (data for the importer, never displayed or served inline) under "impor/Y/m/<ulid>.<ext>",
     * recorded as a File with purpose `import` (FilePolicy: student.import). Delete it with discard() when done.
     *
     * @throws InvalidUpload
     */
    public function storeSpreadsheet(UploadedFile $upload, User $owner, int $maxBytes = 2 * 1024 * 1024): File
    {
        $this->limits->ensureCanUpload($owner);

        $path = (string) $upload->getRealPath();
        $extension = strtolower((string) pathinfo($upload->getClientOriginalName(), PATHINFO_EXTENSION));
        $size = $path !== '' && is_file($path) ? (int) filesize($path) : 0;
        $mime = $size > 0 ? (new UploadRules)->sniffMime($path) : null;

        if (! isset(self::SPREADSHEET_MIMES[$extension]) || $mime === null || ! in_array($mime, self::SPREADSHEET_MIMES[$extension], true)) {
            throw new InvalidUpload([self::MSG_SPREADSHEET_TYPE]);
        }

        if ($size > $maxBytes) {
            throw new InvalidUpload(['Ukuran file maksimal '.intdiv($maxBytes, 1024 * 1024).' MB.']);
        }

        $bytes = (string) file_get_contents($path);
        $storagePath = sprintf('impor/%s/%s.%s', now()->format('Y/m'), strtolower((string) Str::ulid()), $extension);
        Storage::disk(self::DISK)->put($storagePath, $bytes);

        try {
            $file = File::query()->create([
                'disk' => self::DISK,
                'path' => $storagePath,
                'original_name' => (new UploadRules)->sanitizeOriginalName($upload->getClientOriginalName()),
                'mime' => $mime,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'purpose' => FilePurpose::Import,
                'owner_user_id' => $owner->getKey(),
            ]);
        } catch (Throwable $e) {
            Storage::disk(self::DISK)->delete($storagePath);

            throw $e;
        }

        $this->limits->recordUpload($owner);

        return $file;
    }

    /** Remove a stored file (e.g. when the surrounding transaction failed). */
    public function discard(File $file): void
    {
        $file->storage()->delete($file->path);
        $file->delete();
    }

    /**
     * True when the PDF's object syntax contains an active-content name (hex-escaped names like
     * /J#61vaScript included). Binary stream bodies are skipped (random bytes would give false alarms);
     * compressed object streams (/ObjStm, where dictionaries can hide) are inflated and scanned too.
     */
    public static function pdfHasActiveContent(string $bytes): bool
    {
        $syntax = self::pdfObjectSyntax($bytes);

        if (preg_match_all('~/([A-Za-z0-9#]{2,40})~', $syntax, $matches) === false) {
            return true;
        }

        foreach (array_unique($matches[1]) as $name) {
            $decoded = preg_replace_callback('~#([0-9A-Fa-f]{2})~', fn (array $m): string => chr((int) hexdec($m[1])), $name);

            if (in_array(strtolower((string) $decoded), self::PDF_ACTIVE_NAMES, true)) {
                return true;
            }
        }

        return false;
    }

    /** Everything outside stream bodies, plus the (inflated) bodies of object streams. */
    private static function pdfObjectSyntax(string $bytes): string
    {
        $syntax = '';
        $offset = 0;

        while (preg_match('~(?<!end)stream\r?\n~', $bytes, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $keywordAt = $match[0][1];
            $before = substr($bytes, $offset, $keywordAt - $offset);
            $syntax .= $before;

            $dataStart = $keywordAt + strlen($match[0][0]);
            $dataEnd = strpos($bytes, 'endstream', $dataStart);

            if ($dataEnd === false) {
                // Unterminated stream: scan the rest as syntax (errs on the safe side).
                $syntax .= substr($bytes, $dataStart);
                $offset = strlen($bytes);
                break;
            }

            $dictionaryAt = strrpos($before, '<<');
            $dictionary = $dictionaryAt === false ? '' : substr($before, $dictionaryAt);

            if (str_contains($dictionary, '/ObjStm')) {
                $data = rtrim(substr($bytes, $dataStart, $dataEnd - $dataStart), "\r\n");
                $inflated = str_contains($dictionary, '/FlateDecode') ? @gzuncompress($data, 20 * 1024 * 1024) : $data;
                // An object stream we cannot read might hide anything: keep a marker that fails the scan.
                $syntax .= "\n".($inflated === false ? '/JavaScript' : $inflated)."\n";
            }

            $offset = $dataEnd + strlen('endstream');
        }

        return $syntax.substr($bytes, $offset);
    }

    private function checkedPdf(string $path): string
    {
        $bytes = (string) file_get_contents($path);

        if (self::pdfHasActiveContent($bytes)) {
            throw new InvalidUpload([self::MSG_PDF_ACTIVE]);
        }

        return $bytes;
    }

    private function reencodedImage(string $path, string $mime): string
    {
        $size = @getimagesize($path);

        if ($size === false) {
            throw new InvalidUpload([self::MSG_IMAGE_BROKEN]);
        }

        if ($size[0] > self::MAX_DECODE_SIDE || $size[1] > self::MAX_DECODE_SIDE) {
            throw new InvalidUpload([self::MSG_IMAGE_TOO_LARGE]);
        }

        try {
            $image = ImageManager::usingDriver(GdDriver::class)->decodePath($path);
            $image->orient();
            $image->scaleDown(self::MAX_IMAGE_SIDE, self::MAX_IMAGE_SIDE);

            $encoded = match ($mime) {
                'image/png' => $image->encodeUsingFormat(Format::PNG),
                'image/webp' => $image->encodeUsingFormat(Format::WEBP, quality: 85),
                default => $image->encodeUsingFormat(Format::JPEG, quality: 85),
            };

            return $encoded->toString();
        } catch (InvalidUpload $e) {
            throw $e;
        } catch (Throwable) {
            throw new InvalidUpload([self::MSG_IMAGE_BROKEN]);
        }
    }
}
