<?php

declare(strict_types=1);

use App\Actions\Accounts\CompleteActivation;
use App\Actions\Accounts\IssueOtpCode;
use App\Actions\Accounts\ResetStudentAccount;
use App\Enums\RoleName;
use App\Livewire\Auth\Activation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers as H;

/*
| QA10 item 2, on the activation screen: SECURITY §3b allows an account 10 codes a day (IssueOtpCode). When that limit
| holds, the screen says how long to wait in minutes, not "Tunggu 0 detik" or tens of thousands of seconds.
| Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    H::seedRoles();
    Mail::fake();
    Notification::fake();
    config(['fortify.sth.otp_test_mode' => false]);
});

it('tells the student in minutes when the codes of the day are used up (QA10)', function (): void {
    $user = H::pendingStudent()->user;
    $issue = app(IssueOtpCode::class);

    // Ten codes today, in two hours (five an hour is the other limit).
    foreach (range(1, 10) as $i) {
        expect($issue->handle($user, CompleteActivation::PURPOSE, 'nadia.anindya@example.test'))->toBeTrue();
        $this->travel($i === 5 ? 3600 : 61)->seconds();
    }

    $this->travel(1)->hours();
    $this->actingAs($user);

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_EMAIL)
        ->set('email', 'nadia.anindya@example.test')
        ->call('sendCode')
        ->assertHasErrors('email')
        ->assertSet('step', Activation::STEP_EMAIL)
        ->assertSee('Terlalu banyak percobaan. Coba lagi dalam')
        ->assertDontSee('detik sebelum kirim ulang');

    Mail::assertQueuedCount(10);
});

// Review of QA10: an Admin reset (K10) only marked the day's OTP rows as used, so a hijacker who used up the email
// codes of the day still kept the owner out of the new activation. LoginLockout::clear(), which every Admin reset,
// unfreeze and "Buka ulang" calls, now also starts the OTP limits of the account afresh.
it('releases the code limits of the day after an Admin reset (QA10)', function (): void {
    $user = H::activeStudent()->user;
    $issue = app(IssueOtpCode::class);

    foreach (range(1, 10) as $i) {
        expect($issue->handle($user, CompleteActivation::PURPOSE, 'pembajak@example.test'))->toBeTrue();
        $this->travel($i === 5 ? 3600 : 61)->seconds();
    }

    app(ResetStudentAccount::class)->handle(H::staff(RoleName::Admin), $user, 'Pemilik datang dengan kartu pelajar.');
    $this->travel(61)->seconds();

    $this->actingAs(User::query()->findOrFail($user->getKey()));

    Livewire::test(Activation::class)
        ->assertSet('step', Activation::STEP_EMAIL)
        ->set('email', 'nadia.anindya@example.test')
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', Activation::STEP_CODE);
});
