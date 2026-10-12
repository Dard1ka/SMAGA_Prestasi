<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Livewire\Kesiswaan\Lomba\Index;
use App\Livewire\Kesiswaan\Talenta;
use App\Models\Competition;
use App\Notifications\Admin\LargeExportNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| Hardening of two Kesiswaan screens:
| - Kelola lomba › Antrean verifikasi: a submitter's website is linked only while it passes ExternalUrl, and its
|   host is shown in punycode (SECURITY §3m-1).
| - Talenta: the Excel export needs a password from the last 15 minutes, has a row cap, logs its filters and
|   alerts the Admins above 500 rows (SECURITY §3m-9); `$limit` of both screens is clamped.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();
    $this->withoutVite();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->admin = F::staff(RoleName::Admin, 'Operator STH');
});

/**
 * $count talent applications inserted in bulk: 100 branches, 100 applications per student (unique
 * student/type/branch), so the detail panel of the first student stays small.
 */
function kesiswaanHardeningApplications(int $count): void
{
    $typeId = F::talentType()->getKey();
    $now = now()->toDateTimeString();
    $branchIds = [];
    $branches = [];
    $applications = [];

    for ($b = 1; $b <= min(100, $count); $b++) {
        $branchIds[] = $id = Str::lower((string) Str::ulid());
        $branches[] = ['id' => $id, 'talent_type_id' => $typeId, 'name' => 'Cabang '.$b, 'active' => true, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now];
    }

    DB::table('talent_branches')->insert($branches);

    for ($s = 1; count($applications) < $count; $s++) {
        $studentId = F::student('Siswa Uji '.$s, 'X-8')->getKey();

        foreach (array_slice($branchIds, 0, $count - count($applications)) as $branchId) {
            $applications[] = ['id' => Str::lower((string) Str::ulid()), 'student_id' => $studentId, 'talent_type_id' => $typeId, 'talent_branch_id' => $branchId, 'status' => 'terdaftar', 'created_at' => $now, 'updated_at' => $now];
        }
    }

    foreach (array_chunk($applications, 100) as $chunk) {
        DB::table('talent_applications')->insert($chunk);
    }
}

// --- Antrean verifikasi: external links ------------------------------------------------------------------

it('links a valid website with its host in the verification queue', function (): void {
    Competition::factory()->create(['name' => 'Lomba Link Baik', 'website_url' => 'https://example.org/nsif']);
    Competition::factory()->create(['name' => 'Lomba Tanpa Link', 'website_url' => null]);

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('href="https://example.org/nsif"', false)
        ->assertSee('example.org')
        ->assertSee('Tanpa link')
        ->assertDontSee('Link tidak valid');
});

it('shows a look-alike IDN host as punycode in the verification queue', function (): void {
    $host = 'smagа-lomba.example'; // the second "а" is Cyrillic U+0430
    $ascii = (string) idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
    $url = 'https://'.$host.'/info';

    Competition::factory()->create(['name' => 'Lomba IDN', 'website_url' => $url]);

    expect($ascii)->toStartWith('xn--');

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('Lomba IDN')
        ->assertSee($ascii)
        ->assertViewHas('queue', fn (Collection $queue): bool => $queue->first()['website'] === $url && $queue->first()['websiteHost'] === $ascii);
})->skip(fn (): bool => ! function_exists('idn_to_ascii'), 'ext-intl is not installed');

it('does not link a queued website that fails the URL rule', function (string $url): void {
    Competition::factory()->create(['name' => 'Lomba Link Buruk', 'website_url' => $url]);

    Livewire::withQueryParams(['tab' => 'verifikasi'])->actingAs($this->kesiswaan)->test(Index::class)
        ->assertSee('Lomba Link Buruk')
        ->assertSee('Link tidak valid')
        ->assertDontSee('href="'.e($url).'"', false)
        ->assertViewHas('queue', fn (Collection $queue): bool => $queue->first()['website'] === null && $queue->first()['websiteHost'] === null);
})->with([
    'http' => ['http://tidak-aman.test/lomba'],
    'credentials' => ['https://panitia:rahasia@example.org/lomba'],
    'ip literal' => ['https://127.0.0.1/lomba'],
    'javascript scheme' => ['javascript:alert(1)'],
]);

// --- $limit ----------------------------------------------------------------------------------------------

it('clamps a client-set limit on Kelola lomba', function (): void {
    F::competition();

    Livewire::actingAs($this->kesiswaan)->test(Index::class)
        ->set('limit', 1000000)
        ->assertSet('limit', 250)
        ->call('showMore')
        ->assertSet('limit', 250)
        ->set('limit', -10)
        ->assertSet('limit', 25)
        ->call('showMore')
        ->assertSet('limit', 50);
});

it('clamps a client-set limit on Talenta', function (): void {
    F::application();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('limit', 1000000)
        ->assertSet('limit', 300)
        ->call('showMore')
        ->assertSet('limit', 300)
        ->set('limit', -10)
        ->assertSet('limit', 30)
        ->call('showMore')
        ->assertSet('limit', 60);
});

it('never renders more Talenta rows than the cap and says how to see the rest', function (): void {
    kesiswaanHardeningApplications(301);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->set('limit', 1000000)
        ->assertViewHas('rows', fn (Collection $rows): bool => $rows->count() === 300)
        ->assertViewHas('hasMore', false)
        ->assertSee('Persempit filter untuk melihat lainnya.');
});

// --- Talenta export --------------------------------------------------------------------------------------

it('asks for the password again before the talent export', function (): void {
    F::application();

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export')
        ->assertRedirect(route('password.confirm'))
        ->assertNoFileDownloaded();

    expect(Activity::query()->where('event', 'talent.exported')->exists())->toBeFalse();
});

it('downloads the talent export after a recent password confirmation and logs every filter', function (): void {
    session()->put('auth.password_confirmed_at', time());
    $hana = F::student('Hana Safira', 'X-8');
    F::application($hana);
    F::application(F::student('Aurelia Maharani', 'X-3'), branch: 'Kimia');

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('setTahap', 'terdaftar')
        ->call('setJenis', 'osn')
        ->set('kelas', $hana->classroom_id)
        ->set('q', 'hana')
        ->call('export')
        ->assertFileDownloaded();

    $log = Activity::query()->where('event', 'talent.exported')->sole();

    expect((int) $log->causer_id)->toBe((int) $this->kesiswaan->getKey())
        ->and($log->properties['filters'])->toBe(['tahap' => 'terdaftar', 'jenis' => 'osn', 'kelas' => $hana->classroom_id, 'q' => 'hana'])
        ->and($log->properties['rows'])->toBe(1)
        ->and($log->properties['large'])->toBeFalse();

    Notification::assertNotSentTo($this->admin, LargeExportNotice::class);
});

it('alerts every Admin when a talent export has more than 500 rows', function (): void {
    session()->put('auth.password_confirmed_at', time());
    kesiswaanHardeningApplications(501);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export')
        ->assertFileDownloaded();

    $log = Activity::query()->where('event', 'talent.exported')->sole();

    expect($log->properties['rows'])->toBe(501)
        ->and($log->properties['large'])->toBeTrue()
        ->and($log->properties['filters'])->toBe([]);

    Notification::assertSentTo($this->admin, LargeExportNotice::class, fn (LargeExportNotice $n): bool => str_contains(implode(' ', $n->lines()), 'men-download Database talenta siswa berisi 501 baris'));
    Notification::assertNotSentTo($this->kesiswaan, LargeExportNotice::class);
});

it('refuses a talent export above the row cap', function (): void {
    session()->put('auth.password_confirmed_at', time());
    kesiswaanHardeningApplications(10001);

    Livewire::actingAs($this->kesiswaan)->test(Talenta::class)
        ->call('export')
        ->assertNoFileDownloaded()
        ->assertDispatched('sth-toast', message: 'Terlalu banyak baris (10.001). Persempit filter, maks. 10.000 baris.', tone: 'error');

    expect(Activity::query()->where('event', 'talent.exported')->exists())->toBeFalse();
    Notification::assertNothingSent();
});
