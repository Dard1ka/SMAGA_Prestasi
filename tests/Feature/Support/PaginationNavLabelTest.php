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
 | SV1 second browser check (2026-10-30): the <nav> of every Livewire-paginated list kept the English aria-label
 | "Pagination Navigation", hard-coded in Livewire's own view. The app now publishes that view
 | (resources/views/vendor/livewire/{tailwind,simple-tailwind}.blade.php) and passes the label through __(), which
 | lang/id.json translates as "Navigasi halaman". Fictional data only.
 */

beforeEach(function (): void {
    A::seedRoles();
    Notification::fake();
    $this->withoutVite();
});

it('names the pagination nav in Bahasa Indonesia', function (): void {
    $admin = A::admin();
    Activity::query()->delete(); // test DB only

    // Log audit shows 25 rows a page: 30 rows give a second page, so the nav is drawn.
    foreach (range(1, 30) as $i) {
        activity('account')->causedBy($admin)->event('account.password_changed')->log('account.password_changed');
    }

    $html = Livewire::actingAs($admin)->test(AuditLog::class)->html();

    expect($html)->toContain('aria-label="Navigasi halaman"')
        ->not->toContain('Pagination Navigation');
});

it('publishes only the views the app uses, each with the translated label', function (): void {
    $views = collect(glob(resource_path('views/vendor/livewire/*.blade.php')) ?: [])->map(fn (string $path): string => basename($path))->sort()->values()->all();

    expect($views)->toBe(['simple-tailwind.blade.php', 'tailwind.blade.php']);

    foreach ($views as $view) {
        expect((string) file_get_contents(resource_path('views/vendor/livewire/'.$view)))
            ->toContain("aria-label=\"{{ __('Pagination Navigation') }}\"")
            ->not->toContain('aria-label="Pagination Navigation"');
    }
});
