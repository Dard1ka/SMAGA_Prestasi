<?php

declare(strict_types=1);

use App\Domain\Competitions\OrganizerWeight;
use App\Enums\EventStatus;
use App\Enums\OrganizerType;
use App\Enums\WeightCategory;

/*
| D-146: the kategori bobot and the status ajang of a lomba follow from the jenis penyelenggara and the tick "Ajang
| Puspresnas". A student's lomba always takes this map; Kesiswaan may override it (audited at the decision).
*/

it('maps every jenis penyelenggara without Puspresnas (D-146)', function (OrganizerType $type, WeightCategory $cat, EventStatus $status): void {
    $r = OrganizerWeight::for($type, false);
    expect($r->category)->toBe($cat)->and($r->eventStatus)->toBe($status);
})->with([
    [OrganizerType::PemerintahPusat, WeightCategory::B, EventStatus::PemerintahNonPuspresnas],
    [OrganizerType::PemerintahDaerah, WeightCategory::B, EventStatus::PemerintahNonPuspresnas],
    [OrganizerType::BumnBumd, WeightCategory::B, EventStatus::PemerintahNonPuspresnas],
    [OrganizerType::Ptn, WeightCategory::C, EventStatus::NonPemerintah],
    [OrganizerType::Pts, WeightCategory::C, EventStatus::NonPemerintah],
    [OrganizerType::OrganisasiProfesi, WeightCategory::C, EventStatus::NonPemerintah],
    [OrganizerType::FederasiOlahraga, WeightCategory::C, EventStatus::NonPemerintah],
    [OrganizerType::YayasanLembaga, WeightCategory::D, EventStatus::NonPemerintah],
    [OrganizerType::PerusahaanSwasta, WeightCategory::D, EventStatus::NonPemerintah],
    [OrganizerType::Lainnya, WeightCategory::E, EventStatus::NonPemerintah],
]);

it('gives A · Puspresnas for every type once Puspresnas is ticked', function (OrganizerType $type): void {
    $r = OrganizerWeight::for($type, true);
    expect($r->category)->toBe(WeightCategory::A)->and($r->eventStatus)->toBe(EventStatus::Puspresnas);
})->with(OrganizerType::cases());

it('calls a weight that differs from the map an override', function (): void {
    expect(OrganizerWeight::isOverride(OrganizerType::Ptn, WeightCategory::C, EventStatus::NonPemerintah))->toBeFalse()
        ->and(OrganizerWeight::isOverride(OrganizerType::Ptn, WeightCategory::B, EventStatus::NonPemerintah))->toBeTrue()
        ->and(OrganizerWeight::isOverride(OrganizerType::Ptn, WeightCategory::A, EventStatus::Puspresnas))->toBeFalse()
        ->and(OrganizerWeight::isOverride(OrganizerType::Ptn, WeightCategory::C, EventStatus::Terkurasi))->toBeTrue()
        ->and(OrganizerWeight::isOverride(null, WeightCategory::C, null))->toBeFalse();
});

it('calls a missing weight next to a known type an override of the map', function (): void {
    // Kesiswaan cleared the category: the lomba no longer carries what the map gives.
    expect(OrganizerWeight::isOverride(OrganizerType::Ptn, null, EventStatus::NonPemerintah))->toBeTrue()
        ->and(OrganizerWeight::isOverride(OrganizerType::Ptn, WeightCategory::C, null))->toBeTrue();
});
