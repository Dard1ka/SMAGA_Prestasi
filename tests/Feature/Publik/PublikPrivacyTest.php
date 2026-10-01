<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Achievement;
use App\Models\Competition;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Publik\PublikFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));

    $this->f = new PublikFixtures;
    $this->nadia = $this->f->student('Nadia Putri Anindya', 'XI-4', attributes: ['nis' => '225226201', 'nisn' => '0098765432']);
    User::query()->whereKey($this->nadia->user_id)->update(['email' => 'nadia.anindya@sth.test']);

    // Fictional student WITHOUT publish consent.
    $this->bima = $this->f->student('Bima Satria Wicaksono', 'XI-5', consent: false, attributes: ['nis' => '225226777', 'nisn' => '0091234567']);
    User::query()->whereKey($this->bima->user_id)->update(['email' => 'bima.wicaksono@sth.test']);

    $this->opsi = $this->f->lomba('OPSI 2026', Bidang::Riset, Level::Nasional, '2026-09-20');
    $this->robotik = $this->f->lomba('Kompetisi Robotik Nasional 2025', Bidang::Riset, Level::Nasional, '2025-11-02');

    $this->f->prestasi($this->nadia, $this->opsi, ResultType::Juara2);
    $this->f->prestasi($this->bima, $this->opsi, ResultType::Juara2);   // same team, full points each (D-08)
    $this->f->prestasi($this->bima, $this->robotik, ResultType::Juara1);

    $this->slug = $this->f->slug($this->nadia);
});

/** Every public URL of the data set. @return list<string> */
function publikUrls(object $test): array
{
    return [
        route('public.home'),
        route('public.prestasi'),
        route('public.prestasi', ['bidang' => 'riset', 'siswa' => $test->slug]),
        route('public.prestasi.siswa', $test->slug),
        route('public.lomba.pemenang', $test->opsi->id),
        route('public.lomba.pemenang', $test->robotik->id),
        route('public.peringkat'),
        route('public.peringkat', ['alumni' => 1]),
        route('public.peringkat', ['mode' => 'kelas']),
    ];
}

it('never shows the name of a student without publish consent, but counts them', function (): void {
    foreach (publikUrls($this) as $url) {
        $this->get($url)->assertOk()->assertDontSee('Bima Satria Wicaksono')->assertDontSee('Bima Satria');
    }

    $this->get(route('public.prestasi', ['bidang' => 'riset']))->assertSee('Siswa kelas XI');
    $this->get(route('public.peringkat'))->assertSee('Siswa kelas XI');
    $this->get(route('public.lomba.pemenang', $this->robotik->id))->assertSee('Siswa kelas XI');

    $overview = app(PublicAchievements::class)->overview();
    expect($overview['total'])->toBe(3)
        ->and($overview['students'])->toBe(2)
        ->and($overview['nasional'])->toBe(3);

    // Kelas mode aggregates everybody of the class, named or not.
    $kelas = collect(app(PublicAchievements::class)->ranking('kelas'))->keyBy('kelas');
    expect($kelas['XI-5']['points'])->toBe('148.00')
        ->and($kelas['XI-5']['students'])->toBe(1);
});

it('never shows NIS, NISN, email or internal ids in public HTML', function (): void {
    foreach (publikUrls($this) as $url) {
        $html = $this->get($url)->assertOk()->getContent();

        foreach (['225226201', '0098765432', 'nadia.anindya@sth.test', '225226777', '0091234567', 'bima.wicaksono@sth.test', $this->nadia->id, $this->bima->id] as $secret) {
            expect(str_contains($html, (string) $secret))->toBeFalse("{$secret} leaked on {$url}");
        }
    }
});

it('keeps the Livewire snapshot free of data (filters only)', function (): void {
    $html = $this->get(route('public.prestasi', ['bidang' => 'riset', 'siswa' => $this->slug]))->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = json_decode(html_entity_decode($match[1] ?? ''), true);

    expect(array_keys($snapshot['data'] ?? []))->toEqualCanonicalizing(['bidang', 'tahun', 'tingkat', 'siswa']);
});

it('returns 404 for an unknown slug and for a student without consent', function (): void {
    $this->get('/prestasi/siswa/'.str_repeat('a', 20))->assertNotFound();
    $this->get('/prestasi/siswa/NOT-A-SLUG')->assertNotFound();

    $this->bima->forceFill(['public_slug' => str_repeat('b', 20)])->saveQuietly();
    PublicAchievements::flush();

    $this->get('/prestasi/siswa/'.str_repeat('b', 20))->assertNotFound();
    $this->get(route('public.prestasi', ['siswa' => str_repeat('b', 20)]))->assertOk()->assertDontSee('Bima Satria Wicaksono');
});

it('returns 404 for a lomba without verified achievements', function (): void {
    $empty = $this->f->lomba('LDBI Kota Semarang 2026', Bidang::SeniBudaya, Level::Kota, '2026-08-01');

    $this->get(route('public.lomba.pemenang', $empty->id))->assertNotFound();
    $this->get('/prestasi/lomba/'.str_repeat('Z', 26))->assertNotFound();
    $this->get('/prestasi/lomba/bukan-ulid')->assertNotFound();
});

it('hides a student once consent is withdrawn and the cache is flushed', function (): void {
    $this->get(route('public.peringkat'))->assertSee('Nadia Putri Anindya');

    $this->nadia->forceFill(['publish_consent' => false])->saveQuietly();
    PublicAchievements::flush();

    $this->get(route('public.peringkat'))->assertDontSee('Nadia Putri Anindya')->assertSee('Siswa kelas XI');
    $this->get(route('public.prestasi.siswa', $this->slug))->assertNotFound();
});

it('shows alumni with the Alumni label and their rank including alumni', function (): void {
    $ardi = $this->f->student('Ardiansyah Nur Hakim', 'XII-3', archived: true, attributes: ['grade12_start_date' => '2024-07-01']);
    $this->f->prestasi($ardi, $this->f->lomba('OSN Nasional 2024 Kimia', Bidang::Sains, Level::Nasional, '2024-10-05'), ResultType::Juara1);
    $slug = $this->f->slug($ardi);

    $this->get(route('public.prestasi.siswa', $slug))
        ->assertOk()
        ->assertSee('Alumni · lulus 2025')
        ->assertSee('termasuk alumni');
});

it('keeps disabled accounts out of names and rankings but inside the totals', function (): void {
    $gone = $this->f->student('Laras Wening Pangestu', 'XI-7');
    $this->f->prestasi($gone, $this->robotik, ResultType::Finalis);
    User::query()->whereKey($gone->user_id)->update(['status' => UserStatus::Disabled->value]);
    PublicAchievements::flush();

    $this->get(route('public.peringkat'))->assertOk()->assertDontSee('Laras Wening Pangestu');
    $this->get(route('public.lomba.pemenang', $this->robotik->id))->assertOk()->assertDontSee('Laras Wening Pangestu');
    expect(app(PublicAchievements::class)->overview()['total'])->toBe(4);
});

/** Certificate of $student's achievement in $lomba, with registration memberships for $members. */
function publikCertificate(Student $student, Competition $lomba, array $members): ReportFile
{
    $report = Report::query()->findOrFail(Achievement::query()->where('student_id', $student->id)->where('competition_id', $lomba->id)->value('report_id'));
    RegistrationMember::query()->where('registration_id', $report->registration_id)->delete(); // factory leader

    foreach ($members as $member) {
        RegistrationMember::factory()->confirmed()->create(['registration_id' => $report->registration_id, 'student_id' => $member->id]);
    }

    return ReportFile::factory()->create(['report_id' => $report->id]);
}

it('links a certificate through files.public only when Admin allowed it and shows the verified badge otherwise', function (): void {
    $lkti = $this->f->lomba('LKTI Nasional 2026', Bidang::Riset, Level::Nasional, '2026-08-20');
    $this->f->prestasi($this->nadia, $lkti, ResultType::Juara1);
    $file = publikCertificate($this->nadia, $lkti, [$this->nadia]);
    PublicAchievements::flush();

    $this->get(route('public.prestasi.siswa', $this->slug))
        ->assertOk()
        ->assertSee('Terverifikasi')
        ->assertDontSee('Lihat sertifikat');

    Setting::put(SettingKey::PublicCertificates, true);
    PublicAchievements::flush();

    $this->get(route('public.prestasi.siswa', $this->slug))
        ->assertOk()
        ->assertSee('Lihat sertifikat')
        ->assertSee(route('files.public', $file->file_id), false)
        ->assertDontSee(route('files.show', $file->file_id), false);
});

it('never links a team certificate when one member has no publish consent', function (): void {
    // OPSI 2026: Nadia (consent) + Bima (no consent) share one report, so the certificate shows both names.
    $file = publikCertificate($this->nadia, $this->opsi, [$this->nadia, $this->bima]);
    Setting::put(SettingKey::PublicCertificates, true);
    PublicAchievements::flush();

    $this->get(route('public.prestasi.siswa', $this->slug))
        ->assertOk()
        ->assertSee('Terverifikasi')
        ->assertDontSee('Lihat sertifikat')
        ->assertDontSee(route('files.public', $file->file_id), false);
});

it('assigns a random 20-hex slug only to consenting students', function (): void {
    expect($this->slug)->toMatch('/^[a-f0-9]{20}$/')
        ->and(Student::query()->whereKey($this->bima->id)->value('public_slug'))->toBeNull();
});
