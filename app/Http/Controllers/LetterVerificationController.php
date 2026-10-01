<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Competition;
use App\Models\Letter;
use App\Models\Registration;
use Illuminate\Http\Response;

/**
 * Public check behind the QR code of every surat tugas (`public.surat.verifikasi`, SECURITY §3g, U-05).
 * No login. Shows only: valid or not found, letter type, number, issue date and the lomba name — no
 * student or teacher data. Only sent letters count as valid. Throttled 30/min/IP, never indexed.
 */
final class LetterVerificationController extends Controller
{
    public function __invoke(string $code): Response
    {
        $letter = preg_match('/^[a-f0-9]{32}$/', $code) === 1
            ? Letter::query()->where('verification_code', $code)->whereNotNull('sent_at')->first()
            : null;

        $competitionName = null;

        if ($letter !== null) {
            $competitionId = Registration::withTrashed()->whereKey($letter->registration_id)->value('competition_id');
            $competitionName = $competitionId !== null ? Competition::withTrashed()->whereKey($competitionId)->value('name') : null;
        }

        return response()
            ->view('letters.verifikasi', [
                'letter' => $letter,
                'competitionName' => $competitionName,
            ], $letter !== null ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }
}
