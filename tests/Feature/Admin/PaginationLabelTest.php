<?php

declare(strict_types=1);

use App\Livewire\Admin\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

/*
 | SV1 browser check (2026-10-30): the arrows of a paginated list had the aria-label "Berikutnya &raquo;", read aloud
 | letter by letter. lang/id/pagination.php held HTML entities, which Livewire's pagination view prints unescaped as
 | the button text but escapes in aria-label. The lines now hold the characters themselves. Fictional data only.
 */

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
});

it('holds the arrow characters themselves, not HTML entities', function (): void {
    expect(__('pagination.previous'))->toBe('« Sebelumnya')
        ->and(__('pagination.next'))->toBe('Berikutnya »');
});

it('gives the arrows of a paginated list a clean aria-label and keeps their visible text', function (): void {
    $admin = A::admin();
    Activity::query()->delete(); // test DB only: drop the model events of the fixture above

    // Log audit shows 25 rows a page: 30 rows give a second page, so both arrows are drawn.
    foreach (range(1, 30) as $i) {
        activity('account')->causedBy($admin)->event('account.password_changed')->log('account.password_changed');
    }

    $html = Livewire::actingAs($admin)->test(AuditLog::class)->html();

    expect($html)->toContain('aria-label="« Sebelumnya"')
        ->toContain('aria-label="Berikutnya »"')
        ->toMatch('/>\s*Berikutnya »\s*</')
        ->not->toContain('&amp;raquo;')
        ->not->toContain('&amp;laquo;')
        ->not->toContain('&raquo;');
});
