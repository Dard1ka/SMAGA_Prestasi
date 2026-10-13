<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Publications\PrintAppreciationList;
use App\Models\AppreciationEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * "Cetak daftar" of an appreciation event (D-141, `apresiasi.cetak`): the list of students who may be named as an
 * inline PDF in a new tab. Policy-checked and audited by PrintAppreciationList; the answer is never cached or framed
 * (same headers as StreamLetter).
 */
final class AppreciationListPrintController extends Controller
{
    public function show(Request $request, AppreciationEvent $event, PrintAppreciationList $print): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $document = $print->handle($user, $event);

        return response($document->bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', PrintAppreciationList::fileName($event)),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "frame-ancestors 'none'",
        ]);
    }
}
