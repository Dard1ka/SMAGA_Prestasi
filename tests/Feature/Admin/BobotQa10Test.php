<?php

declare(strict_types=1);

use App\Actions\Settings\PublishWeightVersion;
use App\Domain\Points\WeightTable;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Livewire\Admin\Pengaturan\Bobot;
use App\Models\WeightVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * QA10: Admin › Pengaturan › Bobot poin could not be saved again once a tingkat weight was 1000. The field showed
 * "1.000" (thousands dot) and PublishWeightVersion read that as 1 with three decimals ("Isi angka, maks. 2 desimal.").
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Queue::fake();
    Notification::fake();
    $this->admin = A::admin();
    WeightVersion::factory()->withDefaultValues()->create(['effective_at' => now()->subYear()]);
});

/** Default weights as the screen sends them (decimal comma), with overrides. @return array<string, array<string, string>> */
function qa10LombaWeights(array $override = []): array
{
    $table = WeightTable::defaults();
    $comma = fn (array $values): array => array_map(fn (string $v): string => str_replace('.', ',', $v), $values);

    return array_replace_recursive([
        'level' => $comma($table->levels()),
        'result' => $comma($table->results()),
        'organizer' => $comma($table->organizers()),
    ], $override);
}

it('shows a tingkat weight of 1000 without a thousands dot and saves the tab again (QA10)', function (): void {
    app(PublishWeightVersion::class)->handle($this->admin, qa10LombaWeights(['level' => ['internasional' => '1000']]));
    $this->travel(1)->seconds();

    $this->actingAs($this->admin);
    session(A::passwordConfirmed());

    Livewire::test(Bobot::class)
        ->assertSet('weights.level.internasional', '1000')
        ->assertSet('weights.result.juara_2', '0,85')
        ->set('weights.level.nasional', '90')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-toast', message: 'Bobot tersimpan. Poin sedang dihitung ulang.', tone: 'success');

    $table = WeightVersion::active()->toWeightTable();
    expect($table->level(Level::Internasional))->toBe('1000.00')
        ->and($table->level(Level::Nasional))->toBe('90.00');
});

it('reads a tingkat weight typed with a thousands dot as the whole number (QA10)', function (): void {
    $version = app(PublishWeightVersion::class)->handle($this->admin, qa10LombaWeights(['level' => ['internasional' => '1.000']]));

    expect($version->toWeightTable()->level(Level::Internasional))->toBe('1000.00');
});

it('still refuses three decimals for a capaian or penyelenggara factor (QA10)', function (string $group, string $key): void {
    try {
        app(PublishWeightVersion::class)->handle($this->admin, qa10LombaWeights([$group => [$key => '1.000']]));
        $this->fail('Three decimals were accepted.');
    } catch (ValidationException $e) {
        expect($e->errors()["weights.{$group}.{$key}"][0])->toBe('Isi angka, maks. 2 desimal.');
    }

    expect(WeightVersion::active()->toWeightTable()->result(ResultType::Juara1))->toBe('1.00');
})->with([['result', 'juara_1'], ['organizer', 'A']]);
