<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private file streaming (SECURITY §3d). Not allowed (or missing on disk) → 404, never 403, so file ids
 * cannot be probed. `attachment` by default. Every download is audited (actor or guest, file, purpose; no
 * names, no contents).
 *
 * - show():   GET /berkas/{file} (`files.show`), logged in + activated (+ staff 2FA), FilePolicy::view.
 * - publik(): GET /berkas/publik/{file} (`files.public`), guests too, FilePolicy::viewPublic only
 *             (verified certificates with publish consent while Admin enables public certificates).
 *
 * Inline (`?tampil=1`, M3-C) is for the preview popup (`data-sth-preview`, resources/js/app.js) and for image
 * thumbnails. Only the types in INLINE_TYPES are ever inline; any other file stays an attachment whatever the
 * query says.
 * - Re-encoded images: shown in an <img>, so the answer keeps the download headers (`sandbox`, never framed).
 * - PDF, `files.show` only: the popup shows it in an <iframe> of the same origin, with the browser's own PDF
 *   viewer. That answer, and only that one, may be framed by this origin (`X-Frame-Options: SAMEORIGIN`,
 *   `frame-ancestors 'self'`) and carries no `sandbox`, because Chrome refuses to start its PDF viewer in a
 *   sandboxed document. What keeps the file from running anything in the app's origin: the stored MIME comes
 *   from the server's own sniffing at upload, the answer says `application/pdf` with `nosniff` (never HTML),
 *   `default-src 'none'` stays, and PdfScanner refused PDFs with scripts or actions at upload (D-70).
 *   The public route never sends a PDF inline: nothing there needs a frame.
 * - HEAD: the popup asks first whether the file can be shown (status, type, disposition), so it never starts a
 *   download by itself. Same policy check and headers, no bytes read, nothing audited.
 */
final class FileController extends Controller
{
    /** Query flag that asks for the file inline. */
    public const INLINE_PARAMETER = 'tampil';

    public const PREVIEW_PDF = 'pdf';

    public const PREVIEW_IMAGE = 'image';

    /**
     * The only MIME types that are ever sent inline → the viewer the popup uses (`data-sth-preview-type`).
     * Exactly what StoreUpload keeps: scanned PDFs and re-encoded images (never SVG).
     *
     * @var array<string, string>
     */
    private const INLINE_TYPES = [
        'application/pdf' => self::PREVIEW_PDF,
        'image/jpeg' => self::PREVIEW_IMAGE,
        'image/png' => self::PREVIEW_IMAGE,
        'image/webp' => self::PREVIEW_IMAGE,
    ];

    /** `pdf` or `image` when the preview popup can show this file, null when it can only be downloaded. */
    public static function previewType(File $file): ?string
    {
        return self::INLINE_TYPES[strtolower((string) $file->mime)] ?? null;
    }

    /** The inline address for `data-sth-preview` (`files.show` + `?tampil=1`); null when there is no preview. */
    public static function previewUrl(File $file): ?string
    {
        if (self::previewType($file) === null || ! Route::has('files.show')) {
            return null;
        }

        return route('files.show', ['file' => $file, self::INLINE_PARAMETER => 1]);
    }

    public function show(Request $request, File $file): StreamedResponse
    {
        abort_unless(Gate::inspect('view', $file)->allowed(), 404);

        return $this->stream($request, $file, 'file.downloaded', framablePdf: true);
    }

    public function publik(Request $request, File $file): StreamedResponse
    {
        abort_unless(Gate::inspect('viewPublic', $file)->allowed(), 404);

        return $this->stream($request, $file, 'file.public_downloaded', framablePdf: false);
    }

    private function stream(Request $request, File $file, string $event, bool $framablePdf): StreamedResponse
    {
        $storage = $file->storage();
        abort_unless($storage->exists($file->path), 404);

        $type = $request->boolean(self::INLINE_PARAMETER) ? self::previewType($file) : null;

        if ($type === self::PREVIEW_PDF && ! $framablePdf) {
            $type = null;
        }

        $inline = $type !== null;
        $probe = $request->isMethod('HEAD');
        $user = $request->user();

        if (! $probe) {
            activity('file')
                ->performedOn($file)
                ->causedBy($user)
                ->event($event)
                ->withProperties([
                    'purpose' => $file->purpose->value,
                    'own' => $user !== null && (int) $file->owner_user_id === (int) $user->getKey(),
                ] + ($inline ? ['inline' => true] : []))
                ->log($event);
        }

        $headers = [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ];

        if ($type === self::PREVIEW_PDF) {
            $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'self'";
            $headers['X-Frame-Options'] = 'SAMEORIGIN';
        }

        $response = $inline
            ? $storage->response($file->path, $file->original_name, $headers, 'inline')
            : $storage->download($file->path, $file->original_name, $headers);

        if ($probe) {
            // Headers only: do not read the file for a body that is thrown away.
            $response->setCallback(static function (): void {});
        }

        return $response;
    }
}
