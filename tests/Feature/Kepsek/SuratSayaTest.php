<?php

declare(strict_types=1);

use App\Enums\FilePurpose;
use App\Enums\LetterType;
use App\Enums\RoleName;
use App\Livewire\Kepsek\SuratSaya;
use App\Models\File;
use App\Models\Letter;
use App\Models\PrincipalReplacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Kepsek\KepsekFixtures as F;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::seed();
    $this->withoutVite();
    $this->travelTo('2026-10-01 10:00:00');
    $this->kepsek = F::kepsek();
});

function b5Letter(array $attributes = []): Letter
{
    $letter = Letter::factory()->create(array_diff_key($attributes, array_flip(['generated_at', 'revoked_at', 'sent_at', 'file_id'])));
    $letter->forceFill(array_intersect_key($attributes, array_flip(['generated_at', 'revoked_at', 'sent_at', 'file_id'])))->save();

    return $letter->fresh();
}

it('lists the letters issued since this account became Kepala Sekolah, with revoked ones marked', function (): void {
    $old = b5Letter(['generated_at' => '2026-08-01 09:00:00', 'issued_on' => '2026-08-01']);
    $mine = b5Letter(['generated_at' => '2026-09-20 09:00:00', 'issued_on' => '2026-09-20', 'sent_at' => '2026-09-20 10:00:00']);
    $revoked = b5Letter(['generated_at' => '2026-09-25 09:00:00', 'issued_on' => '2026-09-25', 'revoked_at' => '2026-09-26 09:00:00']);

    $replacement = new PrincipalReplacement;
    $replacement->forceFill([
        'to_user_id' => $this->kepsek->id,
        'requested_by' => A::admin()->id,
        'reason' => 'SK Kepala Sekolah baru.',
        'effective_at' => '2026-09-01 08:00:00',
        'applied_at' => '2026-09-01 08:00:00',
    ])->save();

    $this->actingAs($this->kepsek);

    Livewire::test(SuratSaya::class)
        ->assertSee($mine->full_number)
        ->assertSee($revoked->full_number)
        ->assertSee('Dicabut')
        ->assertSee('Terkirim')
        ->assertDontSee($old->full_number)
        ->set('status', 'dicabut')
        ->assertSee($revoked->full_number)
        ->assertDontSee($mine->full_number);
});

it('lists every letter when there is no replacement record, and warns above 20 letters a day', function (): void {
    foreach (range(1, 21) as $i) {
        b5Letter(['generated_at' => '2026-10-01 0'.($i % 9).':00:00', 'type' => LetterType::Siswa]);
    }

    $this->actingAs($this->kepsek);

    Livewire::test(SuratSaya::class)
        ->assertSee('Jumlah surat tidak biasa')
        ->assertSee('21');
});

it('downloads a letter through the letter policy and audits the download', function (): void {
    Storage::disk('local')->put('surat/2026/contoh.pdf', '%PDF-1.4 contoh');
    $file = new File;
    $file->forceFill([
        'disk' => 'local',
        'path' => 'surat/2026/contoh.pdf',
        'original_name' => 'surat-tugas.pdf',
        'mime' => 'application/pdf',
        'size' => 15,
        'sha256' => hash('sha256', '%PDF-1.4 contoh'),
        'purpose' => FilePurpose::Letter,
    ])->save();
    $letter = b5Letter(['file_id' => $file->id]);

    $this->actingAs($this->kepsek);

    Livewire::test(SuratSaya::class)->call('download', $letter->id)->assertFileDownloaded('surat-tugas.pdf');
    expect(Activity::query()->where('event', 'letter.downloaded')->sole()->causer_id)->toBe($this->kepsek->id);
});

it('forbids the list to roles without the route', function (): void {
    $this->actingAs(A::staff(RoleName::Humas));

    Livewire::test(SuratSaya::class)->assertForbidden();
});
