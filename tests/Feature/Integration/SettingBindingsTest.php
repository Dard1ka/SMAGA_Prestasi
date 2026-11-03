<?php

declare(strict_types=1);

use App\Domain\Archive\GraduationCalculator;
use App\Domain\Deadlines\ReportDeadline;
use App\Enums\SettingKey;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestDisks;

/*
| W3-A: ReportDeadline / GraduationCalculator come from the Admin settings (AppServiceProvider), the public
| switches are SettingKey cases, and every Feature test runs on its own storage disks (Tests\Support\TestDisks).
*/

uses(RefreshDatabase::class);

it('builds ReportDeadline and GraduationCalculator from the settings, with the defaults as fallback', function (): void {
    expect(app(ReportDeadline::class)->days())->toBe(45)
        ->and(app(GraduationCalculator::class)->archiveMonths())->toBe(18);

    Setting::put(SettingKey::ReportDeadlineDays, 60);
    Setting::put(SettingKey::ArchiveMonthsAfterGrade12, 24);

    expect(app(ReportDeadline::class)->days())->toBe(60)
        ->and(app(ReportDeadline::class)->dueDate('2026-09-24')->toDateString())->toBe('2026-11-23')
        ->and(app(GraduationCalculator::class)->archiveMonths())->toBe(24);

    // Out-of-range values never crash a screen or the scheduler: back to the defaults (D-25).
    Setting::put(SettingKey::ReportDeadlineDays, 0);
    Setting::put(SettingKey::ArchiveMonthsAfterGrade12, 999);

    expect(app(ReportDeadline::class)->days())->toBe(45)
        ->and(app(GraduationCalculator::class)->archiveMonths())->toBe(18);
});

it('has the public switches as setting keys, both off by default', function (): void {
    expect(SettingKey::PublicIndexing->value)->toBe('public_indexing')
        ->and(SettingKey::PublicCertificates->value)->toBe('public_certificates')
        ->and(SettingKey::PublicIndexing->defaultValue())->toBeFalse()
        ->and(SettingKey::PublicCertificates->defaultValue())->toBeFalse()
        ->and(Setting::get(SettingKey::PublicCertificates))->toBeFalse();
});

it('gives every test its own empty local and public disk', function (): void {
    foreach (['local', 'public'] as $disk) {
        expect(TestDisks::root($disk))->toContain('framework/testing/disks/iso-'.$disk.'-')
            ->and(Storage::disk($disk)->allFiles())->toBe([]);
    }

    expect($this->pdfSandboxRoot)->toContain('iso-pdf-tmp-')->and(is_dir($this->pdfSandboxRoot))->toBeTrue();
});
