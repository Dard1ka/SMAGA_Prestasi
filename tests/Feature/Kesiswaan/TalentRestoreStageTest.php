<?php

declare(strict_types=1);

use App\Actions\Talent\MoveTalentStage;
use App\Actions\Talent\RestoreTalentApplication;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\UserStatus;
use App\Models\TalentApplication;
use App\Notifications\Talent\TalentApplicationRestored;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Kesiswaan\KesiswaanFixtures as F;

/*
| M3-A (lead, 2026-10-06): "Pulihkan" puts a tidak-lolos pilihan talenta back into the stage Kesiswaan chooses
| (Terdaftar, Pemetaan, Seleksi or Peserta pembinaan), no longer only into the stage it was rejected from.
| The Action side: RestoreTalentApplication::handle(…, ?TalentStatus $to). The screen: TalentaM3Test.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();

    $this->kesiswaan = F::staff(RoleName::Kesiswaan);
    $this->guru = F::staff(RoleName::Guru, 'Pak Hendra Kusuma');
});

/** A choice rejected from $from, with a pembina, an assessment date and both notes on it. */
function m3aRejected(TalentStatus $from = TalentStatus::Seleksi, array $attributes = []): TalentApplication
{
    return TalentApplication::factory()->rejected($from, 'Nilai asesmen kurang.')->create(array_merge([
        'student_id' => F::student('Citra Ayu Lestari', 'XI-2')->getKey(),
        'talent_type_id' => F::talentType()->getKey(),
    ], $attributes))->fresh();
}

it('restores a rejected choice into the stage Kesiswaan chooses', function (TalentStatus $to): void {
    $application = m3aRejected(TalentStatus::Seleksi);
    $application->forceFill(['mentor_id' => $this->guru->getKey(), 'assessment_on' => '2026-10-09', 'note' => 'Catatan internal.'])->save();

    $restored = app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh(), 'Kami buka lagi untukmu.', $to);

    $fresh = $application->fresh();
    expect($restored->status)->toBe($to)
        ->and($fresh->status)->toBe($to)
        ->and($fresh->rejected_from)->toBeNull()
        ->and($fresh->student_note)->toBe('Kami buka lagi untukmu.')
        // Nothing a stage holds is lost or asked for again: pembina, date and the internal note stay.
        ->and($fresh->mentor_id)->toBe($this->guru->getKey())
        ->and($fresh->assessment_on->format('Y-m-d'))->toBe('2026-10-09')
        ->and($fresh->note)->toBe('Catatan internal.');

    $log = Activity::query()->where('event', 'talent.restored')->sole();
    expect($log->properties['from'])->toBe('tidak_lolos')
        ->and($log->properties['to'])->toBe($to->value)
        ->and($log->properties['rejected_from'])->toBe('seleksi')
        ->and((int) $log->causer_id)->toBe((int) $this->kesiswaan->getKey())
        ->and($log->subject_id)->toBe($application->getKey());

    Notification::assertSentTo($application->student->user, TalentApplicationRestored::class, fn (TalentApplicationRestored $n): bool => $n->toArray($application->student->user)['to'] === $to->value
        && str_contains($n->body, 'Catatan dari Kesiswaan: Kami buka lagi untukmu.'));
})->with([
    'terdaftar' => [TalentStatus::Terdaftar],
    'pemetaan' => [TalentStatus::Pemetaan],
    'seleksi (the earlier stage)' => [TalentStatus::Seleksi],
    'peserta pembinaan' => [TalentStatus::Pembinaan],
]);

it('names the stage the choice is back in, in the notification for the student', function (TalentStatus $to, string $sentence): void {
    $application = m3aRejected(TalentStatus::Pemetaan);

    // D-109: Peserta pembinaan needs a guru pembina.
    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application, null, $to, $to === TalentStatus::Pembinaan ? $this->guru->getKey() : null);

    Notification::assertSentTo($application->student->user, TalentApplicationRestored::class, fn (TalentApplicationRestored $n): bool => str_contains($n->body, $sentence)
        && ! str_contains($n->body, 'Permintaan tinjau ulangmu')
        && $n->title === 'Pengajuan talenta dibuka kembali: '.MoveTalentStage::talentLabel($application));
})->with([
    'terdaftar' => [TalentStatus::Terdaftar, 'dibuka kembali, sekarang di tahap Terdaftar.'],
    'pemetaan' => [TalentStatus::Pemetaan, 'dibuka kembali, sekarang di tahap Pemetaan.'],
    'seleksi' => [TalentStatus::Seleksi, 'dibuka kembali, sekarang di tahap Seleksi/asesmen.'],
    'peserta pembinaan' => [TalentStatus::Pembinaan, 'dibuka kembali. Kamu sekarang peserta pembinaan.'],
]);

it('goes back to the earlier stage when no stage is passed, and to Terdaftar when that stage is unknown', function (): void {
    $known = m3aRejected(TalentStatus::Pemetaan);
    $unknown = TalentApplication::factory()->status(TalentStatus::TidakLolos)->create([
        'student_id' => F::student('Fajar Ramadhan', 'XII-1')->getKey(),
        'talent_type_id' => F::talentType()->getKey(),
    ]);

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $known);
    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $unknown);

    expect($known->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($unknown->fresh()->status)->toBe(TalentStatus::Terdaftar)
        ->and(Activity::query()->where('event', 'talent.restored')->where('subject_id', $unknown->getKey())->sole()->properties['rejected_from'])->toBeNull();
});

it('closes the open review request whatever stage is chosen, and says so to the student', function (): void {
    $application = TalentApplication::factory()->rejected(TalentStatus::Seleksi)->reviewRequested('Saya sakit saat asesmen.')->create([
        'student_id' => F::student('Bima Satria Wicaksono', 'XII-7')->getKey(),
        'talent_type_id' => F::talentType()->getKey(),
    ]);

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application, null, TalentStatus::Terdaftar);

    $fresh = $application->fresh();
    expect($fresh->status)->toBe(TalentStatus::Terdaftar)
        ->and($fresh->review_requested_at)->toBeNull()
        ->and($fresh->review_message)->toBeNull()
        ->and($fresh->hasOpenReviewRequest())->toBeFalse()
        ->and(Activity::query()->where('event', 'talent.restored')->sole()->properties['review_requested'])->toBeTrue();

    Notification::assertSentTo($application->student->user, TalentApplicationRestored::class, fn (TalentApplicationRestored $n): bool => str_contains($n->body, 'Permintaan tinjau ulangmu diterima.')
        && str_contains($n->body, 'sekarang di tahap Terdaftar.'));
});

it('sends the saved draft with a restore into a chosen stage when no note is passed', function (): void {
    $application = m3aRejected(TalentStatus::Seleksi);
    $application->forceFill(['student_note_draft' => 'Asesmen susulan Jumat ini.'])->save();

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh(), null, TalentStatus::Pemetaan);

    expect($application->fresh()->student_note)->toBe('Asesmen susulan Jumat ini.')
        ->and($application->fresh()->student_note_draft)->toBeNull();
});

it('puts the choice back into the pipeline at the chosen stage: the next move starts from there', function (): void {
    $early = m3aRejected(TalentStatus::Seleksi);
    $late = m3aRejected(TalentStatus::Terdaftar, ['student_id' => F::student('Hana Safira', 'X-8')->getKey()]);

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $early, null, TalentStatus::Terdaftar);
    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $late, null, TalentStatus::Pembinaan, $this->guru->getKey());

    // Back at the start: one step forward is Pemetaan, never a jump to where it was rejected.
    expect(fn () => app(MoveTalentStage::class)->handle($this->kesiswaan, $early->fresh(), TalentStatus::Pembinaan))->toThrow(ValidationException::class);
    app(MoveTalentStage::class)->handle($this->kesiswaan, $early->fresh(), TalentStatus::Pemetaan);

    // Peserta pembinaan is the end of the pipeline, as after a normal move.
    expect($early->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($late->fresh()->status)->toBe(TalentStatus::Pembinaan)
        ->and(fn () => app(MoveTalentStage::class)->handle($this->kesiswaan, $late->fresh(), TalentStatus::Pembinaan))->toThrow(ValidationException::class, 'Tahap ini sudah final.');
});

// Forbidden cases --------------------------------------------------------------------------------

it('offers exactly the four pipeline stages as a target', function (): void {
    expect(RestoreTalentApplication::TARGETS)->toBe([TalentStatus::Terdaftar, TalentStatus::Pemetaan, TalentStatus::Seleksi, TalentStatus::Pembinaan])
        ->and(RestoreTalentApplication::TARGETS)->not->toContain(TalentStatus::TidakLolos);
});

it('refuses "tidak lolos" as a target and changes nothing', function (): void {
    $application = TalentApplication::factory()->rejected(TalentStatus::Seleksi, 'Nilai asesmen kurang.')->reviewRequested('Mohon ditinjau.')->create([
        'student_id' => F::student('Citra Ayu Lestari', 'XI-2')->getKey(),
        'talent_type_id' => F::talentType()->getKey(),
    ]);

    try {
        app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application, 'Catatan.', TalentStatus::TidakLolos);
        $this->fail('A restore into "tidak lolos" must be refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toBe(['to' => [RestoreTalentApplication::TARGET_MESSAGE]]);
    }

    $fresh = $application->fresh();
    expect($fresh->status)->toBe(TalentStatus::TidakLolos)
        ->and($fresh->rejected_from)->toBe(TalentStatus::Seleksi)
        ->and($fresh->student_note)->toBe('Nilai asesmen kurang.')
        ->and($fresh->review_requested_at)->not->toBeNull()
        ->and(Activity::query()->where('event', 'talent.restored')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

it('restores only from tidak lolos, also when a stage is passed', function (TalentStatus $status): void {
    $application = F::application(status: $status);

    expect(fn () => app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application, null, TalentStatus::Pemetaan))
        ->toThrow(ValidationException::class, 'Hanya pilihan yang tidak lolos yang bisa dipulihkan.');

    expect($application->fresh()->status)->toBe($status)
        ->and(Activity::query()->where('event', 'talent.restored')->exists())->toBeFalse();
    Notification::assertNothingSent();
})->with([TalentStatus::Terdaftar, TalentStatus::Pemetaan, TalentStatus::Seleksi, TalentStatus::Pembinaan]);

it('lets only Kesiswaan and Admin choose a stage', function (): void {
    $application = m3aRejected(TalentStatus::Seleksi, ['mentor_id' => $this->guru->getKey()]);

    foreach ([$this->guru, F::staff(RoleName::Humas, 'Mas Galih'), F::staff(RoleName::KepalaSekolah, 'Kepala'), F::staff(RoleName::TataUsaha, 'Staf TU'), $application->student->user] as $user) {
        expect(fn () => app(RestoreTalentApplication::class)->handle($user, $application, null, TalentStatus::Pembinaan))->toThrow(AuthorizationException::class);
    }

    expect($application->fresh()->status)->toBe(TalentStatus::TidakLolos);
    Notification::assertNothingSent();

    app(RestoreTalentApplication::class)->handle(F::staff(RoleName::Admin, 'Operator STH'), $application, null, TalentStatus::Pembinaan, $this->guru->getKey());

    expect($application->fresh()->status)->toBe(TalentStatus::Pembinaan);
});

it('refuses a chosen stage on a choice the student withdrew, and tells no archived student', function (): void {
    $withdrawn = m3aRejected(TalentStatus::Seleksi);
    $withdrawn->delete();

    expect(fn () => app(RestoreTalentApplication::class)->handle($this->kesiswaan, $withdrawn, null, TalentStatus::Pemetaan))->toThrow(AuthorizationException::class);

    $archived = m3aRejected(TalentStatus::Pemetaan, ['student_id' => F::student('Galang Putra Mahendra', 'X-TAHFIDZ')->getKey()]);
    $archived->student->user->forceFill(['status' => 'archived'])->save();

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $archived, null, TalentStatus::Seleksi);

    expect($archived->fresh()->status)->toBe(TalentStatus::Seleksi);
    Notification::assertNothingSent();
});

// QA5 (lead, 2026-10-07): a peserta pembinaan always has a guru pembina ---------------------------------------

it('needs a guru pembina before a choice is restored into Peserta pembinaan', function (): void {
    $application = m3aRejected(TalentStatus::Seleksi);

    expect(fn () => app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application, null, TalentStatus::Pembinaan))
        ->toThrow(ValidationException::class, 'Peserta pembinaan perlu guru pembina. Pilih guru pembinanya.');

    expect($application->fresh()->status)->toBe(TalentStatus::TidakLolos)
        ->and(Activity::query()->where('event', 'talent.restored')->exists())->toBeFalse();
    Notification::assertNothingSent();

    // The guru chosen in the dialog becomes the pembina in the same step.
    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh(), null, TalentStatus::Pembinaan, $this->guru->getKey());

    $fresh = $application->fresh();
    expect($fresh->status)->toBe(TalentStatus::Pembinaan)
        ->and($fresh->mentor_id)->toBe($this->guru->getKey())
        ->and(Activity::query()->where('event', 'talent.restored')->sole()->properties['mentor_id'])->toBe($this->guru->getKey());
});

it('keeps the pembina a choice already has when it goes back into Peserta pembinaan', function (): void {
    $application = m3aRejected(TalentStatus::Seleksi, ['mentor_id' => $this->guru->getKey()]);

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application, null, TalentStatus::Pembinaan);

    expect($application->fresh()->status)->toBe(TalentStatus::Pembinaan)
        ->and($application->fresh()->mentor_id)->toBe($this->guru->getKey());
});

it('asks no pembina for the earlier stages, and only accepts an active guru as pembina', function (): void {
    $application = m3aRejected(TalentStatus::Seleksi);

    expect(fn () => app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application, null, TalentStatus::Pembinaan, F::staff(RoleName::Humas, 'Mas Galih')->getKey()))
        ->toThrow(ValidationException::class, 'Pilih guru pembina dari daftar.');

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh(), null, TalentStatus::Seleksi);

    expect($application->fresh()->status)->toBe(TalentStatus::Seleksi)
        ->and($application->fresh()->mentor_id)->toBeNull();
});

it('restores past a pembina who can no longer log in, and asks for another guru for Peserta pembinaan', function (): void {
    $application = m3aRejected(TalentStatus::Seleksi, ['mentor_id' => $this->guru->getKey()]);
    $this->guru->forceFill(['status' => UserStatus::Disabled])->save();

    // Peserta pembinaan with that pembina: refused, whether the dialog sends the old id or nothing.
    foreach ([null, $this->guru->getKey()] as $sent) {
        expect(fn () => app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh(), null, TalentStatus::Pembinaan, $sent))
            ->toThrow(ValidationException::class, 'Guru pembina yang tercatat tidak aktif lagi. Pilih guru pembina lain.');
    }

    expect($application->fresh()->status)->toBe(TalentStatus::TidakLolos);

    // An earlier stage asks no pembina, so the old one is no reason to refuse (the row keeps it, as it would anyway).
    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $application->fresh(), null, TalentStatus::Pemetaan, $this->guru->getKey());

    expect($application->fresh()->status)->toBe(TalentStatus::Pemetaan)
        ->and($application->fresh()->mentor_id)->toBe($this->guru->getKey());

    // A new guru chosen for Peserta pembinaan replaces the inactive one.
    $other = m3aRejected(TalentStatus::Seleksi, ['mentor_id' => $this->guru->getKey(), 'student_id' => F::student('Hana Safira', 'XI-3')->getKey()]);
    $active = F::staff(RoleName::Guru, 'Bu Retno Wulandari');

    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $other, null, TalentStatus::Pembinaan, $active->getKey());

    expect($other->fresh()->status)->toBe(TalentStatus::Pembinaan)
        ->and($other->fresh()->mentor_id)->toBe($active->getKey());
});
