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
 * student or teacher data. Only sent letters count as valid; a sent letter that TU revoked shows
 * "Dicabut" (date + replacement number, B3). Throttled 30/min/IP, never indexed.
 */
final class LetterVerificationController extends Controller
{
    public function __invoke(string $code): Response
    {
        $letter = preg_match('/^[a-f0-9]{32}$/', $code) === 1
            ? Letter::query()->where('verification_code', $code)->whereNotNull('sent_at')->first()
            : null;

        $competitionName = null;
        $replacementNumber = null;

        if ($letter !== null) {
            $competitionId = Registration::withTrashed()->whereKey($letter->registration_id)->value('competition_id');
            $competitionName = $competitionId !== null ? Competition::withTrashed()->whereKey($competitionId)->value('name') : null;

            // B3 (#11): a revoked letter shows "Dicabut" and, once sent, the number of its replacement.
            if ($letter->revoked_at !== null) {
                $replacementNumber = Letter::query()->where('replaces_letter_id', $letter->getKey())->whereNotNull('sent_at')->value('full_number');
            }
        }

        return response()
            ->view('letters.verifikasi', [
                'letter' => $letter,
                'competitionName' => $competitionName,
                'revoked' => $letter?->revoked_at !== null,
                'replacementNumber' => $replacementNumber,
            ], $letter !== null ? 200 : 404)
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }
}
