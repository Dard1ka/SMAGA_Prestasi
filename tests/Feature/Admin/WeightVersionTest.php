<?php

declare(strict_types=1);

use App\Actions\Settings\PublishWeightVersion;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\RoleName;
use App\Enums\WeightCategory;
use App\Jobs\RecomputePoints;
use App\Livewire\Admin\Pengaturan\Bobot;
use App\Models\Achievement;
use App\Models\WeightValue;
use App\Models\WeightVersion;
use App\Notifications\Admin\WeightVersionPublished;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    $this->admin = A::admin();
    $this->version = WeightVersion::factory()->withDefaultValues()->create(['effective_at' => now()->subYear()]);
});

/** Default weights as form input (decimal comma, like the screen). */
function sthWeightsInput(array $override = []): array
{
    $table = WeightTable::defaults();
    $input = [
        'level' => array_map(fn (string $v) => str_replace('.', ',', $v), $table->levels()),
        'result' => array_map(fn (string $v) => str_replace('.', ',', $v), $table->results()),
        'organizer' => array_map(fn (string $v) => str_replace('.', ',', $v), $table->organizers()),
    ];

    return array_replace_recursive($input, $override);
}

it('publishes a new weight version, audits the diff and queues the recalculation', function (): void {
    Queue::fake();
    Notification::fake();
    $kepsek = A::staff(RoleName::KepalaSekolah);

    $version = app(PublishWeightVersion::class)->handle($this->admin, sthWeightsInput(['result' => ['juara_2' => '0,80']]), 'Rapat kurikulum');

    expect(WeightVersion::active()->is($version))->toBeTrue()
        ->and($version->toWeightTable()->result(ResultType::Juara2))->toBe('0.80')
        ->and(WeightValue::query()->where('weight_version_id', $version->id)->count())->toBe(20)
        ->and($this->version->fresh()->values()->count())->toBe(20); // the old version is kept

    $log = Activity::query()->where('event', 'weight.published')->sole();
    expect($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['changes'])->toBe(['result.juara_2' => ['from' => '0.85', 'to' => '0.80']]);

    Queue::assertPushed(RecomputePoints::class, fn (RecomputePoints $job) => $job->weightVersionId === $version->id);
    Notification::assertSentTo([$kepsek, $this->admin], WeightVersionPublished::class);
});

it('rejects invalid, out-of-range and unchanged weights', function (array $override, string $field): void {
    Queue::fake();

    expect(fn () => app(PublishWeightVersion::class)->handle($this->admin, sthWeightsInput($override)))
        ->toThrow(ValidationException::class);

    try {
        app(PublishWeightVersion::class)->handle($this->admin, sthWeightsInput($override));
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(WeightVersion::query()->count())->toBe(1);
    Queue::assertNothingPushed();
})->with([
    'not a number' => [['level' => ['nasional' => 'delapan']], 'weights.level.nasional'],
    'three decimals' => [['result' => ['finalis' => '0,555']], 'weights.result.finalis'],
    'factor above 1' => [['organizer' => ['A' => '1,5']], 'weights.organizer.A'],
    'level zero' => [['level' => ['sekolah' => '0']], 'weights.level.sekolah'],
    'missing key' => [['result' => ['peserta' => '']], 'weights.result.peserta'],
    'nothing changed' => [[], 'weights'],
]);

it('lets only weight.manage publish weights', function (RoleName $role): void {
    expect(fn () => app(PublishWeightVersion::class)->handle(A::staff($role), sthWeightsInput(['level' => ['kota' => '45']])))
        ->toThrow(AuthorizationException::class);
})->with([RoleName::Kesiswaan, RoleName::KepalaSekolah, RoleName::TataUsaha]);

it('recalculates every achievement with the new version and logs one summary', function (): void {
    $a = Achievement::factory()->scored(Level::Nasional, ResultType::Juara2, WeightCategory::A)->create(['weight_version_id' => $this->version->id]);
    $b = Achievement::factory()->scored(Level::Kota, ResultType::Juara1, WeightCategory::B)->create(['weight_version_id' => $this->version->id]);
    Queue::fake();

    $new = app(PublishWeightVersion::class)->handle($this->admin, sthWeightsInput(['result' => ['juara_2' => '0,80'], 'level' => ['kota' => '50']]));
    (new RecomputePoints($new->id, $this->admin->id))->handle(app(PointCalculator::class));

    expect($a->fresh()->points)->toBe('64.00')              // 80 × 0,80 × 1,00
        ->and($a->fresh()->w_result)->toBe('0.80')
        ->and($a->fresh()->weight_version_id)->toBe($new->id)
        ->and($a->fresh()->result)->toBe(ResultType::Juara2)   // snapshot of the capaian stays
        ->and($b->fresh()->points)->toBe('40.00');            // 50 × 1,00 × 0,80

    $log = Activity::query()->where('event', 'weight.recomputed')->sole();
    expect($log->properties['changed'])->toBe(2)
        ->and($log->properties['before'])->toBe('100.00')
        ->and($log->properties['after'])->toBe('104.00')
        ->and($log->causer_id)->toBe($this->admin->id);

    // Idempotent: a second run changes nothing.
    (new RecomputePoints($new->id))->handle(app(PointCalculator::class));
    expect(Activity::query()->where('event', 'weight.recomputed')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'weight.recomputed')->latest('id')->first()->properties['checked'])->toBe(0);
});

it('skips the recalculation of a version that is no longer active', function (): void {
    $achievement = Achievement::factory()->create(['weight_version_id' => $this->version->id]);
    Queue::fake();
    $older = app(PublishWeightVersion::class)->handle($this->admin, sthWeightsInput(['level' => ['nasional' => '90']]));
    $this->travel(1)->seconds();
    app(PublishWeightVersion::class)->handle($this->admin, sthWeightsInput(['level' => ['nasional' => '95']]));

    (new RecomputePoints($older->id))->handle(app(PointCalculator::class));

    expect($achievement->fresh()->weight_version_id)->toBe($this->version->id)
        ->and(Activity::query()->where('event', 'weight.recompute_skipped')->count())->toBe(1);
});

it('saves weights from the Bobot tab only after a recent password confirmation', function (): void {
    Queue::fake();
    Notification::fake();
    $this->actingAs($this->admin);

    Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->call('save')
        ->assertRedirect(route('password.confirm'));
    expect(WeightVersion::query()->count())->toBe(1);

    session(A::passwordConfirmed());
    Livewire::test(Bobot::class)
        ->assertSet('weights.result.juara_2', '0,85')
        ->set('weights.level.nasional', '90')
        ->set('note', 'Uji')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast');

    expect(WeightVersion::active()->toWeightTable()->level(Level::Nasional))->toBe('90.00');
    Queue::assertPushed(RecomputePoints::class);
});

it('shows field errors from the Action on the Bobot tab', function (): void {
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Bobot::class)
        ->set('weights.organizer.B', '2')
        ->call('save')
        ->assertHasErrors(['weights.organizer.B']);
});

it('forbids the Bobot tab to Kesiswaan', function (): void {
    $this->actingAs(A::staff(RoleName::Kesiswaan));

    Livewire::test(Bobot::class)->assertForbidden();
});
