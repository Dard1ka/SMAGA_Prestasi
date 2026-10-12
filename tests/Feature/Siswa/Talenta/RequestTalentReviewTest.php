<?php

declare(strict_types=1);

use App\Actions\Talent\RequestTalentReview;
use App\Actions\Talent\RestoreTalentApplication;
use App\Enums\RoleName;
use App\Enums\TalentStatus;
use App\Enums\UserStatus;
use App\Models\TalentApplication;
use App\Notifications\Talent\TalentReviewRequested;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| "Minta ditinjau ulang" (lead, 2026-10-04): a student asks Kesiswaan to look at a rejected pilihan talenta
| again. The request never changes the status; one open request per rejection; every Kesiswaan is notified.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::boot();
    Notification::fake();

    $this->nadia = F::student();
    $this->kesiswaan = F::staff(RoleName::Kesiswaan, 'Ibu Dwi Rahayu');
    $this->rejected = TalentApplication::factory()->rejected(TalentStatus::Seleksi, 'Nilai asesmen kurang.')->create(['student_id' => $this->nadia->getKey()]);
    $this->request = app(RequestTalentReview::class);
});

it('stores the request without touching the status and notifies every Kesiswaan, nobody else', function (): void {
    $second = F::staff(RoleName::Kesiswaan, 'Pak Bayu Kesiswaan');
    $disabled = F::staff(RoleName::Kesiswaan, 'Kesiswaan Nonaktif');
    $disabled->forceFill(['status' => UserStatus::Disabled])->save();
    $admin = F::staff(RoleName::Admin, 'Operator STH');
    $guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->rejected->forceFill(['mentor_id' => $guru->getKey()])->save();

    $this->request->handle($this->nadia->user, $this->rejected, "  Saya sakit saat asesmen.\nMohon dijadwalkan ulang.  ");

    $fresh = $this->rejected->fresh();
    expect($fresh->status)->toBe(TalentStatus::TidakLolos)
        ->and($fresh->rejected_from)->toBe(TalentStatus::Seleksi)
        ->and($fresh->student_note)->toBe('Nilai asesmen kurang.')
        ->and($fresh->review_requested_at)->not->toBeNull()
        ->and($fresh->review_message)->toBe("Saya sakit saat asesmen.\nMohon dijadwalkan ulang.")
        ->and($fresh->hasOpenReviewRequest())->toBeTrue()
        ->and(TalentApplication::query()->reviewRequested()->count())->toBe(1);

    foreach ([$this->kesiswaan, $second] as $recipient) {
        Notification::assertSentTo($recipient, TalentReviewRequested::class, fn (TalentReviewRequested $n): bool => str_contains($n->body, 'Nadia Putri Anindya (XI-4) minta pilihan talenta')
            && str_contains($n->body, 'Pesan siswa: Saya sakit saat asesmen. Mohon dijadwalkan ulang.')
            && ! str_contains($n->body, (string) $this->nadia->nis)
            && $n->actionLabel === 'Buka Database talenta siswa');
    }

    Notification::assertNotSentTo($disabled, TalentReviewRequested::class);
    Notification::assertNotSentTo($admin, TalentReviewRequested::class);
    Notification::assertNotSentTo($guru, TalentReviewRequested::class);
    Notification::assertNotSentTo($this->nadia->user, TalentReviewRequested::class);

    $log = Activity::query()->where('event', 'talent.review_requested')->sole();
    expect((int) $log->causer_id)->toBe((int) $this->nadia->user->getKey());
});

it('accepts a request without a message and refuses one longer than 500 characters', function (): void {
    expect(fn () => $this->request->handle($this->nadia->user, $this->rejected, str_repeat('a', 501)))
        ->toThrow(ValidationException::class, 'Pesan maksimal 500 karakter.');
    expect($this->rejected->fresh()->review_requested_at)->toBeNull();

    $this->request->handle($this->nadia->user, $this->rejected, '   ');

    expect($this->rejected->fresh()->review_requested_at)->not->toBeNull()
        ->and($this->rejected->fresh()->review_message)->toBeNull();
    Notification::assertSentTo($this->kesiswaan, TalentReviewRequested::class, fn (TalentReviewRequested $n): bool => ! str_contains($n->body, 'Pesan siswa'));
});

it('allows one open request per rejection and a new one after the next rejection', function (): void {
    $this->request->handle($this->nadia->user, $this->rejected, 'Pertama.');

    // The policy sees the open request; a stale model that passed it is stopped under the lock.
    expect(fn () => $this->request->handle($this->nadia->user, $this->rejected->fresh(), 'Kedua.'))->toThrow(AuthorizationException::class);

    $stale = $this->rejected->fresh();
    $stale->review_requested_at = null;
    expect(fn () => $this->request->handle($this->nadia->user, $stale, 'Kedua.'))
        ->toThrow(ValidationException::class, 'Permintaanmu sudah terkirim. Tunggu kabar dari Kesiswaan.');

    expect($this->rejected->fresh()->review_message)->toBe('Pertama.');
    Notification::assertSentToTimes($this->kesiswaan, TalentReviewRequested::class, 1);

    // Kesiswaan restores (request closed) and later rejects again: the student may ask once more.
    app(RestoreTalentApplication::class)->handle($this->kesiswaan, $this->rejected->fresh());
    expect($this->rejected->fresh()->review_requested_at)->toBeNull()
        ->and($this->nadia->user->can('requestReview', $this->rejected->fresh()))->toBeFalse();

    $this->rejected->fresh()->forceFill(['status' => TalentStatus::TidakLolos, 'rejected_from' => TalentStatus::Seleksi])->save();
    $this->request->handle($this->nadia->user, $this->rejected->fresh(), 'Ketiga.');

    expect($this->rejected->fresh()->review_message)->toBe('Ketiga.');
    Notification::assertSentToTimes($this->kesiswaan, TalentReviewRequested::class, 2);
});

it('refuses other students, staff, frozen owners and choices that are not rejected', function (): void {
    $dimas = F::student('Dimas Arya Nugroho');
    $guru = F::staff(RoleName::Guru, 'Retno Wulandari, S.Pd.');
    $this->rejected->forceFill(['mentor_id' => $guru->getKey()])->save();

    foreach ([$dimas->user, $guru, $this->kesiswaan, F::staff(RoleName::Admin, 'Operator STH'), F::staff(RoleName::Humas, 'Mas Galih')] as $user) {
        expect(fn () => $this->request->handle($user, $this->rejected, 'x'))->toThrow(AuthorizationException::class);
    }

    foreach ([TalentStatus::Terdaftar, TalentStatus::Pemetaan, TalentStatus::Seleksi, TalentStatus::Pembinaan] as $status) {
        $open = TalentApplication::factory()->status($status)->create(['student_id' => $this->nadia->getKey(), 'talent_type_id' => $this->rejected->talent_type_id]);
        expect(fn () => $this->request->handle($this->nadia->user, $open, 'x'))->toThrow(AuthorizationException::class);
    }

    // Restored between the page load and the click: the stale model passes the policy, the lock does not.
    $stale = $this->rejected->fresh();
    $this->rejected->fresh()->forceFill(['status' => TalentStatus::Seleksi, 'rejected_from' => null])->save();
    expect(fn () => $this->request->handle($this->nadia->user, $stale, 'x'))
        ->toThrow(ValidationException::class, 'Pilihan ini sudah dibuka kembali.');

    $this->nadia->user->forceFill(['status' => UserStatus::Archived])->save();
    $this->rejected->fresh()->forceFill(['status' => TalentStatus::TidakLolos])->save();
    expect(fn () => $this->request->handle($this->nadia->user->fresh(), $this->rejected->fresh(), 'x'))->toThrow(AuthorizationException::class);

    expect($this->rejected->fresh()->review_requested_at)->toBeNull();
    Notification::assertNothingSent();
});
