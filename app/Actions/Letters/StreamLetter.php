<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Enums\FilePurpose;
use App\Models\File;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands one letter PDF to a user: LetterPolicy::view, audit `letter.downloaded`, stream from the private disk.
 * Two ways (M1-A): "Download" = attachment; "Cetak" = inline, so the browser opens the PDF in its own viewer
 * and Tata Usaha prints from there (`inline: true` in the audit properties). Never cached.
 *
 * The inline response carries its own short CSP (`frame-ancestors 'none'`): a PDF runs no page scripts, and
 * the stricter policies (the app's `object-src 'none'`, the file controller's `sandbox`) keep some browsers
 * from showing a PDF at all. The file is our own dompdf output (JavaScript off), never an upload.
 */
final class StreamLetter
{
    /**
     * @throws AuthorizationException
     */
    public function handle(User $user, Letter $letter, bool $inline = false): StreamedResponse
    {
        Gate::forUser($user)->authorize('view', $letter);

        $file = $letter->file_id !== null ? File::query()->find($letter->file_id) : null;
        abort_if($file === null || $file->purpose !== FilePurpose::Letter || ! $file->storage()->exists($file->path), 404);

        activity('letter')
            ->performedOn($letter)
            ->causedBy($user)
            ->event('letter.downloaded')
            ->withProperties(['number' => $letter->full_number] + ($inline ? ['inline' => true] : []))
            ->log('letter.downloaded');

        $headers = [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($inline) {
            return $file->storage()->response($file->path, $file->original_name, $headers + [
                'Content-Security-Policy' => "frame-ancestors 'none'",
            ], 'inline');
        }

        return $file->storage()->download($file->path, $file->original_name, $headers);
    }
}
