<?php

declare(strict_types=1);

use App\Actions\Settings\PublishWeightVersion;
use App\Domain\Points\WeightTable;
use App\Enums\Level;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Admin\Pengaturan\Bobot;
use App\Models\WeightVersion;
use App\Notifications\Admin\WeightVersionPublished;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\Admin\AdminTestHelpers as A;

/*
 * SV1 (browser check of 2026-10-30): Enter in any field of Admin › Pengaturan › Bobot poin saved the weights at once,
 * without the dialog "Simpan bobot baru?". The form's only submit button was the one inside the dialog
 * (form="bobot-form"), and a browser's implicit submission (Enter in a text field) clicks the form's first submit
 * button. Now the form's submit only asks (askSave(), D-128): "Simpan bobot" under the form is the form's own submit
 * button, and the dialog's button is no submit button of the form, so only that button saves.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    A::seedRoles();
    Queue::fake();
    Notification::fake();
    $this->admin = A::admin();
    WeightVersion::factory()->withDefaultValues()->create(['effective_at' => now()->subYear()]);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
});

it('sends the form and Enter in a field to the question, never to the save (SV1)', function (): void {
    $html = Livewire::test(Bobot::class)->html();

    $start = strpos($html, '<form id="bobot-form"');
    expect($start)->not->toBeFalse();
    $end = strpos($html, '</form>', (int) $start);
    $form = substr($html, (int) $start, (int) $end - (int) $start);
    $outside = substr($html, 0, (int) $start).substr($html, (int) $end);

    // Enter in a field clicks the form's default button: its first submit button in tree order, inside the form or
    // tied to it with form="…". That button is "Simpan bobot" under the form now, and the submit only asks.
    expect($form)->toStartWith('<form id="bobot-form" wire:submit="askSave"')
        ->and(substr_count($form, 'type="submit"'))->toBe(1)
        ->and($form)->toContain('aria-haspopup="dialog"')
        // Nothing outside the form submits it, the dialog's button least of all: it saves by its own click.
        ->and($outside)->not->toContain('type="submit"')
        ->and($html)->not->toContain('form="bobot-form"')
        ->and($outside)->toContain('wire:click="save"')
        ->and($html)->not->toContain('wire:submit="save"');
});

it('opens the dialog on the submit and saves nothing yet (D-128)', function (): void {
    Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->call('askSave')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-open', id: Bobot::DIALOG)
        ->assertNotDispatched('sth-toast');

    expect(WeightVersion::query()->count())->toBe(1);
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

it('saves what the dialog asked about from the dialog button, and a second click changes nothing', function (): void {
    $component = Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->set('note', 'Rapat kurikulum 30 Okt 2026')
        ->call('askSave')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-close', id: Bobot::DIALOG)
        ->assertDispatched('sth-toast', message: 'Bobot tersimpan. Poin sedang dihitung ulang.', tone: 'success')
        ->assertSet('asked', null)
        ->assertSet('note', '')
        ->assertSet('weights.level.nasional', '90');

    $active = WeightVersion::active();
    expect($active->toWeightTable()->level(Level::Nasional))->toBe('90.00')
        ->and($active->note)->toBe('Rapat kurikulum 30 Okt 2026')
        ->and(WeightVersion::query()->count())->toBe(2);

    // A double click on the dialog's button finds no question any more: no second version, no error page.
    $component->call('save')->assertHasNoErrors();
    expect(WeightVersion::query()->count())->toBe(2);
});

it('does nothing on the dialog button without a question', function (): void {
    Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->call('save')
        ->assertHasNoErrors()
        ->assertNotDispatched('sth-toast');

    expect(WeightVersion::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('saves the weights the dialog asked about, not a value changed behind it', function (): void {
    // The page is inert while the dialog is open; only a forged request could change a field in between.
    Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->call('askSave')
        ->set('weights.level.nasional', '5')
        ->call('save')
        ->assertHasNoErrors();

    expect(WeightVersion::active()->toWeightTable()->level(Level::Nasional))->toBe('90.00');
});

it('shows a wrong weight under its field instead of asking', function (): void {
    Livewire::test(Bobot::class)
        ->set('weights.organizer.B', '2')
        ->call('askSave')
        ->assertHasErrors(['weights.organizer.B'])
        ->assertNotDispatched('sth-modal-open')
        // The button sits under a long form: app.js brings the first marked field into view.
        ->assertDispatched('sth-form-invalid', form: Bobot::FORM)
        ->assertSet('asked', null);

    expect(WeightVersion::query()->count())->toBe(1);
});

it('refuses an unchanged tab on the question, without opening the dialog (D-128)', function (): void {
    $component = Livewire::test(Bobot::class)
        // Typed another way, the same weight at two decimals: still nothing changed, as PublishWeightVersion compares.
        ->set('weights.level.nasional', '80,0')
        ->call('askSave')
        ->assertHasErrors(['weights' => [Bobot::UNCHANGED]])
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-form-invalid', form: Bobot::FORM)
        ->assertSet('asked', null)
        ->assertNotDispatched('sth-toast');

    // The refusal stands on the tab: at the top of the form, as the summary app.js scrolls to (sth-form-invalid).
    $html = $component->html();
    $start = (int) strpos($html, '<form id="'.Bobot::FORM.'"');
    $form = substr($html, $start, (int) strpos($html, '</form>', $start) - $start);
    expect($form)->toContain(Bobot::UNCHANGED)
        ->toMatch('~<div role="alert"[^>]*\bdata-sth-error-summary\b[^>]*>~')
        ->and(strpos($form, 'data-sth-error-summary'))->toBeLessThan(strpos($form, '<table'));

    // The same rule and sentence as the Action, which checks it again under its lock.
    expect(fn () => app(PublishWeightVersion::class)->handle($this->admin, $component->get('weights')))
        ->toThrow(ValidationException::class, Bobot::UNCHANGED);

    expect(WeightVersion::query()->count())->toBe(1);
    Queue::assertNothingPushed();
    Notification::assertNothingSent();
});

it('refuses a note over 255 characters on the question, counted as the Action counts it', function (): void {
    Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->set('note', str_repeat('a', Bobot::NOTE_MAX + 1))
        ->call('askSave')
        ->assertHasErrors(['note' => [Bobot::NOTE_TOO_LONG]])
        ->assertSee(Bobot::NOTE_TOO_LONG)
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-form-invalid', form: Bobot::FORM)
        ->assertSet('asked', null);

    // A wrong weight and a long note stand together, not in two rounds.
    Livewire::test(Bobot::class)
        ->set('weights.organizer.B', '2')
        ->set('note', str_repeat('a', Bobot::NOTE_MAX + 1))
        ->call('askSave')
        ->assertHasErrors(['weights.organizer.B', 'note'])
        ->assertNotDispatched('sth-modal-open');

    // The Action trims first: 255 characters (not bytes) between spaces fit.
    Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->set('note', '  '.str_repeat('é', Bobot::NOTE_MAX).'  ')
        ->call('askSave')
        ->assertHasNoErrors()
        ->assertDispatched('sth-modal-open', id: Bobot::DIALOG);

    $weights = Livewire::test(Bobot::class)->set('weights.level.nasional', '90')->get('weights');
    expect(fn () => app(PublishWeightVersion::class)->handle($this->admin, $weights, str_repeat('a', Bobot::NOTE_MAX + 1)))
        ->toThrow(ValidationException::class, Bobot::NOTE_TOO_LONG);

    expect(WeightVersion::query()->count())->toBe(1);
});

it('closes the dialog and shows the refusal on the tab when another Admin saved the same weights meanwhile', function (): void {
    $component = Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->call('askSave')
        ->assertDispatched('sth-modal-open', id: Bobot::DIALOG);

    // Another tab saves the same weights first; PublishWeightVersion finds nothing left to change under its lock.
    app(PublishWeightVersion::class)->handle(A::admin(['name' => 'Operator Kedua', 'username' => 'operator2']), $component->get('weights'));
    $this->travel(1)->seconds();

    $component->call('save')
        ->assertHasErrors(['weights' => [Bobot::UNCHANGED]])
        ->assertDispatched('sth-modal-close', id: Bobot::DIALOG)
        ->assertDispatched('sth-form-invalid', form: Bobot::FORM)
        ->assertSee(Bobot::UNCHANGED)
        ->assertNotDispatched('sth-toast');

    expect(WeightVersion::query()->count())->toBe(2);
});

it('says in the dialog who else is told, only who really gets the notice (D-128, D-109)', function (bool $principalActive, bool $colleagueActive, string $told): void {
    $principal = A::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $colleague = A::admin(['name' => 'Operator Kedua', 'username' => 'operator2']);

    foreach ([[$principal, $principalActive], [$colleague, $colleagueActive]] as [$user, $active]) {
        if (! $active) {
            $user->forceFill(['status' => UserStatus::Disabled])->save();
        }
    }

    $component = Livewire::test(Bobot::class)
        ->assertDontSee($told)
        ->set('weights.level.nasional', '90')
        ->call('askSave')
        ->assertDispatched('sth-modal-open', id: Bobot::DIALOG)
        ->assertSee(Bobot::DIALOG_TEXT.' '.$told);

    $component->call('save')->assertHasNoErrors();

    // The sentence follows the notices that went out (the acting Admin is told as well, without a sentence for it).
    Notification::assertSentTo($this->admin, WeightVersionPublished::class);
    $principalActive
        ? Notification::assertSentTo($principal, WeightVersionPublished::class)
        : Notification::assertNotSentTo($principal, WeightVersionPublished::class);
    $colleagueActive
        ? Notification::assertSentTo($colleague, WeightVersionPublished::class)
        : Notification::assertNotSentTo($colleague, WeightVersionPublished::class);
})->with([
    'Kepala Sekolah and another Admin' => [true, true, 'Kepala Sekolah dan Admin lain diberi tahu.'],
    'Kepala Sekolah only' => [true, false, 'Kepala Sekolah diberi tahu.'],
    'another Admin only' => [false, true, 'Admin lain diberi tahu.'],
    'nobody but the acting Admin' => [false, false, Bobot::TOLD_NOBODY],
]);

it('asks for the password before the question, not after the answer', function (): void {
    session()->forget('auth.password_confirmed_at');

    Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->call('askSave')
        ->assertRedirect(route('password.confirm'))
        ->assertNotDispatched('sth-modal-open')
        ->assertSet('asked', null);

    expect(WeightVersion::query()->count())->toBe(1);
});

it('keeps the question out of the browser\'s reach', function (): void {
    $table = WeightTable::defaults();

    expect(fn () => Livewire::test(Bobot::class)->set('asked', [
        'weights' => ['level' => $table->levels(), 'result' => $table->results(), 'organizer' => $table->organizers()],
        'note' => '',
    ]))->toThrow(CannotUpdateLockedPropertyException::class);

    expect(WeightVersion::query()->count())->toBe(1);
});

it('forgets the question with "Batalkan perubahan"', function (): void {
    Livewire::test(Bobot::class)
        ->set('weights.level.nasional', '90')
        ->call('askSave')
        ->call('resetForm')
        ->assertSet('asked', null)
        ->assertSet('weights.level.nasional', '80')
        ->call('save')
        ->assertNotDispatched('sth-toast');

    expect(WeightVersion::query()->count())->toBe(1);
});
