<?php

declare(strict_types=1);

namespace App\Domain\Competitions;

use App\Enums\EventStatus;
use App\Enums\OrganizerType;
use App\Enums\WeightCategory;

/**
 * The kategori bobot and the status ajang a lomba gets from its jenis penyelenggara and the tick "Ajang Puspresnas"
 * (D-146). Pure: no database, no settings; the weight of each category still comes from the active WeightVersion.
 *
 * | Masukan                                                         | Kategori | Status ajang                |
 * |-----------------------------------------------------------------|----------|-----------------------------|
 * | Puspresnas ticked (any type)                                    | A        | Puspresnas                  |
 * | Pemerintah pusat, Pemerintah daerah, BUMN/BUMD                  | B        | Pemerintah (non-Puspresnas) |
 * | PTN, PTS, Organisasi profesi, Federasi olahraga                 | C        | Non-pemerintah              |
 * | Yayasan/lembaga, Perusahaan swasta                              | D        | Non-pemerintah              |
 * | Lainnya                                                         | E        | Non-pemerintah              |
 *
 * A student's lomba always takes this map (SubmitCompetition, never the student's input). Kesiswaan may choose another
 * weight in the drawer; isOverride() tells VerifyCompetition to audit that choice. Lomba stored before D-146 are not
 * recomputed.
 */
final class OrganizerWeight
{
    public static function for(OrganizerType $type, bool $puspresnas): OrganizerWeightResult
    {
        if ($puspresnas) {
            return new OrganizerWeightResult(WeightCategory::A, EventStatus::Puspresnas);
        }

        return match ($type) {
            OrganizerType::PemerintahPusat,
            OrganizerType::PemerintahDaerah,
            OrganizerType::BumnBumd => new OrganizerWeightResult(WeightCategory::B, EventStatus::PemerintahNonPuspresnas),
            OrganizerType::Ptn,
            OrganizerType::Pts,
            OrganizerType::OrganisasiProfesi,
            OrganizerType::FederasiOlahraga => new OrganizerWeightResult(WeightCategory::C, EventStatus::NonPemerintah),
            OrganizerType::YayasanLembaga,
            OrganizerType::PerusahaanSwasta => new OrganizerWeightResult(WeightCategory::D, EventStatus::NonPemerintah),
            OrganizerType::Lainnya => new OrganizerWeightResult(WeightCategory::E, EventStatus::NonPemerintah),
        };
    }

    /**
     * Does the chosen weight differ from what the map gives this type? The tick is read from the status ajang
     * (Puspresnas = ticked). False without a type: there is nothing to compare with.
     */
    public static function isOverride(?OrganizerType $type, ?WeightCategory $category, ?EventStatus $status): bool
    {
        if ($type === null) {
            return false;
        }

        $mapped = self::for($type, $status === EventStatus::Puspresnas);

        return $mapped->category !== $category || $mapped->eventStatus !== $status;
    }
}
