<?php

declare(strict_types=1);

use App\Enums\Bidang;
use App\Enums\FilePurpose;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Achievement;
use App\Models\File;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Publik\PublikFixtures;
use Tests\Feature\Siswa\Lomba\LombaFixtures as F;

/*
| QA9: `files.public` (FilePolicy::viewPublic, D-39) serves a verified certificate only while every member of the
| registration may appear by name on the public pages: publish consent AND an account that is not disabled and not
| deleted, the rule PublicAchievements uses for "named" (an archived alumnus with consent stays named). A team
| certificate prints every member's name, so one disabled or deleted teammate hides it. The answer is never indexed.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    F::roles();
    $this->nadia = F::student();
    $this->dimas = F::student('Dimas Arya Nugroho');
    Setting::put(SettingKey::PublicCertificates, true);
});

/** A verified report of a registration with the given members (first = ketua), with one certificate on the disk. */
function qa9PublicCertificate(Student ...$members): File
{
    $registration = Registration::factory()->create(['leader_student_id' => $members[0]->getKey()]);

    foreach (array_slice($members, 1) as $member) {
        RegistrationMember::factory()->confirmed()->create(['registration_id' => $registration->getKey(), 'student_id' => $member->getKey()]);
    }

    $report = Report::factory()->verified()->create(['registration_id' => $registration->getKey()]);
    $file = File::factory()->purpose(FilePurpose::ReportEvidence)->create(['owner_user_id' => $members[0]->user_id]);
    Storage::disk('local')->put($file->path, '%PDF-1.4');
    ReportFile::factory()->create(['report_id' => $report->getKey(), 'file_id' => $file->getKey()]);
    Student::query()->whereKey(array_map(fn (Student $s): string => $s->getKey(), $members))->update(['publish_consent' => true]);

    return $file;
}

function qa9MemberGoes(Student $student, string $how): void
{
    match ($how) {
        'disabled account' => User::query()->whereKey($student->user_id)->update(['status' => UserStatus::Disabled->value]),
        'deleted account' => User::query()->findOrFail($student->user_id)->delete(),
        'deleted student record' => Student::query()->findOrFail($student->getKey())->delete(),
    };
}

it('stops serving a team certificate once a teammate with consent is disabled or deleted (QA9)', function (string $how): void {
    $file = qa9PublicCertificate($this->nadia, $this->dimas);
    $this->get(route('files.public', $file))->assertOk();

    qa9MemberGoes($this->dimas, $how);

    $this->get(route('files.public', $file))->assertNotFound();
})->with(['disabled account', 'deleted account', 'deleted student record']);

it('stops serving the own certificate of a student who is disabled or deleted (QA9)', function (string $how): void {
    $file = qa9PublicCertificate($this->nadia);
    $this->get(route('files.public', $file))->assertOk();

    qa9MemberGoes($this->nadia, $how);

    $this->get(route('files.public', $file))->assertNotFound();
})->with(['disabled account', 'deleted account', 'deleted student record']);

it('keeps serving the certificate of an alumnus or a not yet activated teammate with consent (QA9)', function (UserStatus $status): void {
    $file = qa9PublicCertificate($this->nadia, $this->dimas);
    User::query()->whereKey($this->dimas->user_id)->update(['status' => $status->value]);

    $this->get(route('files.public', $file))->assertOk();
})->with([UserStatus::Archived, UserStatus::PendingActivation]);

it('tells search engines not to index a public certificate (QA9)', function (): void {
    $file = qa9PublicCertificate($this->nadia, $this->dimas);

    $this->get(route('files.public', $file))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    $this->call('HEAD', route('files.public', $file))
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('drops the certificate link from the public student page once a teammate is disabled (QA9)', function (): void {
    $this->withoutVite();
    $this->travelTo(now()->setDate(2026, 10, 1)->setTime(10, 0));
    $f = new PublikFixtures;
    $keisha = $f->student('Keisha Larasati', 'XI-6');
    $citra = $f->student('Citra Ayu Lestari', 'XI-2');
    $lkti = $f->lomba('LKTI Nasional 2026', Bidang::Riset, Level::Nasional, '2026-08-20');
    $f->prestasi($keisha, $lkti, ResultType::Juara1);
    $f->prestasi($citra, $lkti, ResultType::Juara1);

    $report = Report::query()->findOrFail(Achievement::query()->where('student_id', $keisha->id)->value('report_id'));
    RegistrationMember::query()->where('registration_id', $report->registration_id)->delete(); // factory leader
    foreach ([$keisha, $citra] as $member) {
        RegistrationMember::factory()->confirmed()->create(['registration_id' => $report->registration_id, 'student_id' => $member->id]);
    }
    $certificate = ReportFile::factory()->create(['report_id' => $report->id]);
    $slug = $f->slug($keisha);

    $this->get(route('public.prestasi.siswa', $slug))
        ->assertOk()
        ->assertSee(route('files.public', $certificate->file_id), false);

    User::query()->whereKey($citra->user_id)->update(['status' => UserStatus::Disabled->value]);
    PublicAchievements::flush();

    $this->get(route('public.prestasi.siswa', $slug))
        ->assertOk()
        ->assertSee('Terverifikasi')
        ->assertDontSee(route('files.public', $certificate->file_id), false)
        ->assertDontSee('Citra Ayu Lestari');
});
