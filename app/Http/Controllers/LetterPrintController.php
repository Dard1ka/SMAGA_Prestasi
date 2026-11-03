<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Letters\StreamLetter;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tata Usaha · "Cetak" (M1-A): opens a letter PDF inline in a new browser tab so it can be printed from the
 * browser's PDF viewer (`tu.surat.cetak`). Same check and audit as a download (StreamLetter: LetterPolicy::view,
 * `letter.downloaded`), `Content-Disposition: inline`, never cached.
 *
 * logo(): the two institution emblems of the letterhead (resources/pdf/assets) for the HTML preview on the TU
 * screens (`tu.surat.kop`); the PDF itself reads them from disk. Only the two known names are served.
 */
final class LetterPrintController extends Controller
{
    /** logo key => [file in resources/pdf/assets, content type] */
    public const LOGOS = [
        'pemprov' => ['logo-pemprov.png', 'image/png'],
        'sekolah' => ['logo-sekolah.jpg', 'image/jpeg'],
    ];

    public function show(Request $request, Letter $letter, StreamLetter $stream): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $stream->handle($user, $letter, inline: true);
    }

    public function logo(string $logo): BinaryFileResponse
    {
        Gate::authorize('viewQueue', Letter::class);
        abort_unless(isset(self::LOGOS[$logo]), 404);

        [$file, $type] = self::LOGOS[$logo];
        $path = resource_path('pdf/assets/'.$file);
        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
