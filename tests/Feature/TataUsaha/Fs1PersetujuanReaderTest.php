<?php

declare(strict_types=1);

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Livewire\Kepsek\Persetujuan;
use App\Models\Registration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Feature\Auth\AuthTestHelpers;
use Tests\Feature\KepalaSekolah\W2cFixtures as F;

/*
| FS1 B2: Persetujuan lomba speaks of the queue an approved row goes to in the reader's words (D-119, the rule of
| Persetujuan::SUBTITLE / SUBTITLE_TU): the Kepala Sekolah reads "antrean TU", Tata Usaha (who approves too, D-150)
| reads its own queue, "antrean surat tugas", never "TU" in the third person. Fictional data only.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    AuthTestHelpers::seedRoles();
    Notification::fake();
    $this->withoutVite();
    $this->kepsek = F::staff(RoleName::KepalaSekolah, ['name' => 'Drs. Bambang Hartono, M.Pd.']);
    $this->tu = F::staff(RoleName::TataUsaha, ['name' => 'Sri Handayani, S.E.']);
    $this->retno = fs1prMentor('Retno Wulandari, S.Pd.', 'Bu');
    $this->hendra = fs1prMentor('Hendra Kusuma, S.Pd., M.Si.', 'Pak');
});

function fs1prMentor(string $name, string $honorific): User
{
    $guru = F::mentor($name);
    $guru->staffProfile()->update(['honorific' => $honorific]);

    return $guru->fresh();
}

/** A team of two active students waiting for the approval, guided by Bu Retno. */
function fs1prRegistration(User $mentor): Registration
{
    $registration = F::registration(names: ['Nadia Putri Anindya', 'Dimas Arya Nugroho'], mentor: $mentor);

    foreach ($registration->members()->with('student.user')->get() as $member) {
        $member->student->user->forceFill([
            'email' => 'siswa.'.uniqid().'@example.test',
            'email_verified_at' => now(),
            'status' => UserStatus::Active,
            'must_change_password' => false,
        ])->save();
    }

    return $registration->fresh();
}

/** The visible text of the component, tags and Livewire markers removed. */
function fs1prText(string $html): string
{
    return (string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
}

it('keeps "antrean TU" for the Kepala Sekolah: dialog line, decided chip and both toasts', function (): void {
    $first = fs1prRegistration($this->retno);
    $second = fs1prRegistration($this->retno);

    $this->actingAs($this->kepsek)->get(route('kepsek.persetujuan.index'))->assertOk()
        ->assertSee('Pendaftaran langsung disetujui dan masuk antrean TU. Persetujuan tidak bisa dibatalkan dari layar ini.');

    Livewire::actingAs($this->kepsek)->test(Persetujuan::class)
        ->call('approve', $first->getKey())
        ->assertDispatched('sth-toast', message: 'Pendaftaran disetujui. Masuk antrean TU.', tone: 'success')
        ->assertSee('Disetujui · masuk antrean TU')
        ->call('askMentor', $second->getKey())
        ->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('approveWithMentor')
        ->assertDispatched('sth-toast', message: 'Pendaftaran disetujui dengan guru pembimbing Pak Hendra Kusuma, S.Pd., M.Si. Masuk antrean TU.', tone: 'success');
});

it('words the queue for Tata Usaha as its own: antrean surat tugas, never TU in the third person', function (): void {
    $first = fs1prRegistration($this->retno);
    $second = fs1prRegistration($this->retno);

    $this->actingAs($this->tu)->get(route('tu.persetujuan.index'))->assertOk()
        ->assertSee('Pendaftaran langsung disetujui dan masuk antrean surat tugas. Persetujuan tidak bisa dibatalkan dari layar ini.')
        ->assertDontSee('antrean TU');

    $screen = Livewire::actingAs($this->tu)->test(Persetujuan::class)
        ->call('approve', $first->getKey())
        ->assertDispatched('sth-toast', message: 'Pendaftaran disetujui. Masuk antrean surat tugas.', tone: 'success')
        ->assertSee('Disetujui · masuk antrean surat')
        ->assertDontSee('antrean TU');

    // Every line of the component a Tata Usaha reader sees after an approval: no "TU", no "Tata Usaha", no pronoun.
    expect(fs1prText($screen->html()))->not->toMatch('/\bTU\b/')
        ->not->toContain('Tata Usaha')
        ->not->toMatch('/\b(?:kamu|Anda)\b/i');

    $screen->call('askMentor', $second->getKey())
        ->set('mentorChoice', (string) $this->hendra->getKey())
        ->call('approveWithMentor')
        ->assertDispatched('sth-toast', message: 'Pendaftaran disetujui dengan guru pembimbing Pak Hendra Kusuma, S.Pd., M.Si. Masuk antrean surat tugas.', tone: 'success')
        ->assertNotDispatched('sth-toast', message: 'Pendaftaran disetujui dengan guru pembimbing Pak Hendra Kusuma, S.Pd., M.Si. Masuk antrean TU.');
});

it('reads an account that is Kepala Sekolah and Tata Usaha at once as the Kepala Sekolah, like the subtitle', function (): void {
    $both = F::staff(RoleName::KepalaSekolah, ['name' => 'Dra. Wening Pratiwi, M.Pd.']);
    $both->assignRole(RoleName::TataUsaha->value);
    $registration = fs1prRegistration($this->retno);

    $this->actingAs($both)->get(route('kepsek.persetujuan.index'))->assertOk()
        ->assertSee(Persetujuan::SUBTITLE)
        ->assertSee('Pendaftaran langsung disetujui dan masuk antrean TU.');

    Livewire::actingAs($both)->test(Persetujuan::class)
        ->call('approve', $registration->getKey())
        ->assertDispatched('sth-toast', message: 'Pendaftaran disetujui. Masuk antrean TU.', tone: 'success')
        ->assertSee('Disetujui · masuk antrean TU');
});
