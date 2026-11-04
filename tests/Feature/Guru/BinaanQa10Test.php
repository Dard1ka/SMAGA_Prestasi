<?php

declare(strict_types=1);

use App\Actions\Registrations\ExpirePendingInvitations;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Livewire\Guru\Binaan;
use App\Livewire\Guru\BinaanDetail;
use App\Livewire\Guru\Lomba;
use App\Models\Competition;
use App\Models\RegistrationMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Guru\GuruFixtures;

/*
| QA10 (2026-10-09), Guru: a student invited to a binaan's team who has not answered yet is not in the team. The
| student screens count only confirmed members since QA10; the guru's "Siswa binaan" count and the binaan list of a
| lomba counted the open invitation too. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    GuruFixtures::boot();
    Notification::fake();

    $this->retno = GuruFixtures::guru('Retno Wulandari, S.Pd.');
    $this->nadia = GuruFixtures::student('Nadia Putri Anindya', 'XI-4');
    $this->raka = GuruFixtures::student('Raka Pradipta', 'XI-4');
    $this->registration = GuruFixtures::registration($this->retno, $this->nadia, RegistrationStatus::MenungguKonfirmasi, [$this->raka]);
    // Raka has not answered the invitation yet.
    RegistrationMember::query()->where('registration_id', $this->registration->getKey())->where('student_id', $this->raka->getKey())
        ->firstOrFail()->forceFill(['confirmed_at' => null])->save();
});

it('counts only the students in the team as binaan, not an open invitation (QA10)', function (): void {
    Livewire::actingAs($this->retno)->test(Binaan::class)
        ->assertViewHas('counts', fn (array $counts): bool => $counts['siswa'] === 1);
});

it('lists only the students in the team as binaan of a lomba (QA10)', function (): void {
    Livewire::actingAs($this->retno)->test(Lomba::class, ['lihat' => $this->registration->competition_id])
        ->assertViewHas('detail', fn (?array $detail): bool => $detail !== null && count($detail['binaan']) === 1)
        ->assertSee('Nadia Putri Anindya')
        ->assertDontSee('Raka Pradipta');
});

it('says "Tidak menjawab" for an invitation that ran out at the deadline, "Menolak" only for a real answer (lead, 2026-10-10)', function (): void {
    // The scheduler ends an unanswered invitation at the registration deadline with `declined_at` (D-50). The two student
    // screens tell it from a real decline since QA10; the guru's detail still said "Menolak" for a student who never answered.
    $dimas = GuruFixtures::student('Dimas Arya Nugroho', 'XI-4');
    $declined = new RegistrationMember(['registration_id' => $this->registration->getKey(), 'student_id' => $dimas->getKey(), 'invited_at' => now()]);
    $declined->forceFill(['role' => MemberRole::Anggota, 'declined_at' => now()])->save();

    Livewire::actingAs($this->retno)->test(BinaanDetail::class, ['registration' => $this->registration])
        ->assertViewHas('members', fn (array $members): bool => collect($members)->pluck('state', 'name')->all() == [
            'Nadia Putri Anindya' => ['Terkonfirmasi', 'green'],
            'Raka Pradipta' => ['Menunggu konfirmasi', 'gray'],
            'Dimas Arya Nugroho' => ['Menolak', 'red'],
        ]);

    Competition::query()->whereKey($this->registration->competition_id)->firstOrFail()
        ->forceFill(['registration_closes_at' => now()->subHour()])->save();

    expect(app(ExpirePendingInvitations::class)->handle($this->registration->fresh()))->toBe(['expired' => 1, 'ready' => false, 'team' => 1]);

    Livewire::actingAs($this->retno)->test(BinaanDetail::class, ['registration' => $this->registration->fresh()])
        ->assertViewHas('members', fn (array $members): bool => collect($members)->pluck('state', 'name')->all() == [
            'Nadia Putri Anindya' => ['Terkonfirmasi', 'green'],
            'Raka Pradipta' => ['Tidak menjawab', 'gray'],
            'Dimas Arya Nugroho' => ['Menolak', 'red'],
        ])
        ->assertSee('Tidak menjawab')
        ->assertSee('Menolak');
});
