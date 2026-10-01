<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private file streaming (SECURITY §3d). Not allowed (or missing on disk) → 404, never 403, so file ids
 * cannot be probed. Always `attachment`; `?tampil=1` shows re-encoded images inline (the only safe
 * inline type). Every download is audited (actor or guest, file, purpose; no names, no contents).
 *
 * - show():   GET /berkas/{file} (`files.show`), logged in + activated (+ staff 2FA), FilePolicy::view.
 * - publik(): GET /berkas/publik/{file} (`files.public`), guests too, FilePolicy::viewPublic only
 *             (verified certificates with publish consent while Admin enables public certificates).
 */
final class FileController extends Controller
{
    public function show(Request $request, File $file): StreamedResponse
    {
        abort_unless(Gate::inspect('view', $file)->allowed(), 404);

        return $this->stream($request, $file, 'file.downloaded');
    }

    public function publik(Request $request, File $file): StreamedResponse
    {
        abort_unless(Gate::inspect('viewPublic', $file)->allowed(), 404);

        return $this->stream($request, $file, 'file.public_downloaded');
    }

    private function stream(Request $request, File $file, string $event): StreamedResponse
    {
        $storage = $file->storage();
        abort_unless($storage->exists($file->path), 404);

        $inline = $request->boolean('tampil') && $file->isImage();
        $user = $request->user();

        activity('file')
            ->performedOn($file)
            ->causedBy($user)
            ->event($event)
            ->withProperties([
                'purpose' => $file->purpose->value,
                'own' => $user !== null && (int) $file->owner_user_id === (int) $user->getKey(),
            ])
            ->log($event);

        $headers = [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ];

        return $inline
            ? $storage->response($file->path, $file->original_name, $headers, 'inline')
            : $storage->download($file->path, $file->original_name, $headers);
    }
}
