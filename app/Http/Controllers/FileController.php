<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Files\CoverThumbnail;
use App\Enums\FilePurpose;
use App\Livewire\Publik\Data\PublicSettings;
use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
 *             (verified certificates of named students while Admin enables public certificates), never
 *             indexed (`X-Robots-Tag: noindex, nofollow`, QA9).
 * - cover():  GET /berkas/sampul/{file} (`files.cover`), logged in + activated (+ staff 2FA), only a File with
 *             purpose `cover` and FilePolicy::view (the lomba it belongs to: published, or an usulan for its
 *             submitter and the verifiers). Sends the small JPEG of the cover (CoverThumbnail, at most 640 px),
 *             never the stored original.
 *
 * Cover pictures (`files.cover`) are for <img> on lomba cards, a dozen per page, so that route differs from the
 * two above in three ways:
 * - NOT audited. A poster shown in a list is not a download: one row per card and page view would bury the real
 *   downloads in the audit log. The original of a cover is still only served by show(), audited as ever.
 * - The browser may keep the picture: `Cache-Control: private, max-age=86400` (its own cache only, never a
 *   proxy) with an `ETag`, and `If-None-Match` is answered with 304 before anything is read or made. A cover
 *   File is never rewritten (a new cover is a new File, so a new address), which is what makes this safe.
 * - Its own throttle counter (240 per minute per user), so a page of cards never uses up the download limit.
 * Everything else is as above: inline with the download headers (`sandbox`, `nosniff`, never framed), and 404
 * for a file that is not a cover, not yours, gone from the disk or not an image a picture can be made of (the
 * card then shows its template cover). HEAD: the same answer without a body.
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

    /** Seconds a browser may show a cover picture (`files.cover`) from its own cache without asking again. */
    public const COVER_MAX_AGE = 86400;

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

    /**
     * The address of a lomba's cover picture for an <img> (`files.cover`), from `competitions.cover_file_id`;
     * null when the lomba has no cover. Whether the viewer may see it is decided by the route, not here.
     */
    public static function coverUrl(?string $fileId): ?string
    {
        if ($fileId === null || $fileId === '' || ! Route::has('files.cover')) {
            return null;
        }

        return route('files.cover', ['file' => $fileId]);
    }

    public function show(Request $request, File $file): StreamedResponse
    {
        abort_unless(Gate::inspect('view', $file)->allowed(), 404);

        return $this->stream($request, $file, 'file.downloaded', framablePdf: true);
    }

    public function publik(Request $request, File $file): StreamedResponse
    {
        abort_unless(Gate::inspect('viewPublic', $file)->allowed(), 404);

        $response = $this->stream($request, $file, 'file.public_downloaded', framablePdf: false);

        // A certificate carries a student's name: never indexed, like every per-student public answer (D-38, QA9).
        $response->headers->set('X-Robots-Tag', PublicSettings::robots(studentPage: true));

        return $response;
    }

    public function cover(Request $request, File $file): Response
    {
        abort_unless($file->purpose === FilePurpose::Cover && Gate::inspect('view', $file)->allowed(), 404);
        abort_unless($file->storage()->exists($file->path), 404);

        $response = new Response('', 200, [
            'Content-Type' => CoverThumbnail::MIME,
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age='.self::COVER_MAX_AGE,
        ]);
        $response->setEtag(CoverThumbnail::etag($file));

        // The browser still holds this picture: 304, and nothing is read from the disk or made.
        if ($response->isNotModified($request)) {
            return $response;
        }

        $bytes = CoverThumbnail::bytes($file);
        abort_if($bytes === null, 404);

        // HEAD: the framework drops the body and keeps the length.
        return $response->setContent($bytes)->header('Content-Length', (string) strlen($bytes));
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
