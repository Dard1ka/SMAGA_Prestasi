<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Bidang;
use App\Http\Controllers\FileController;
use App\Models\Competition;

/**
 * Cover of a lomba (`<x-sth.sampul>`, DESIGN-SPEC-v3 §5): its own poster when Kesiswaan uploaded one
 * (`competitions.cover_file_id`, served by `files.cover`), else one of three template drawings of its
 * bidang (`.sampul-<bidang>-<1..3>` in resources/css/app.css).
 *
 * The variant comes from the lomba id, so one lomba always shows the same drawing on every screen and
 * lomba of one bidang do not all look alike. View helper only: no query, no authorization here (the
 * route checks FilePolicy for each image).
 */
final class Sampul
{
    public const VARIANTS = 3;

    /** 1…VARIANTS, stable for a seed (a lomba id; null or '' → 1). */
    public static function variant(string|int|null $seed): int
    {
        if ($seed === null || $seed === '') {
            return 1;
        }

        return (crc32((string) $seed) % self::VARIANTS) + 1;
    }

    /** Design-system key of a bidang (sains | riset | seni | olahraga); unknown or missing → sains. */
    public static function key(Bidang|string|null $bidang): string
    {
        if (is_string($bidang)) {
            $bidang = Bidang::tryFrom($bidang === 'seni' ? Bidang::SeniBudaya->value : $bidang);
        }

        return $bidang?->cssKey() ?? Bidang::Sains->cssKey();
    }

    /** CSS class of the template drawing, e.g. "sampul-riset-2". */
    public static function templateClass(Bidang|string|null $bidang, string|int|null $seed): string
    {
        return 'sampul-'.self::key($bidang).'-'.self::variant($seed);
    }

    /**
     * Address of the lomba's own poster for an <img>, or null (→ template drawing) when it has none or the
     * signed-in user would not get it. `files.cover` decides by FilePolicy, which for a cover is
     * CompetitionPolicy::view (published, or the submitter / a verifier). Asking the same question here saves
     * a request that would answer 404 on every page view, e.g. for a closed lomba in a student's history.
     * The route still checks every request itself.
     */
    public static function poster(?Competition $competition): ?string
    {
        $fileId = $competition?->cover_file_id;

        if ($fileId === null || auth()->user()?->can('view', $competition) !== true) {
            return null;
        }

        return FileController::coverUrl((string) $fileId);
    }
}
