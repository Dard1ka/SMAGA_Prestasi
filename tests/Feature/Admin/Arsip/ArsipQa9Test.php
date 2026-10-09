<?php

declare(strict_types=1);

use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Archive\UnarchiveStudent;
use App\Actions\Users\ArchiveDueStudentAccounts;
use App\Enums\Bidang;
use App\Enums\Level;
use App\Enums\ResultType;
use App\Enums\UserStatus;
use App\Livewire\Admin\ArsipOtomatis;
use App\Livewire\Admin\Pengaturan\BatasArsip;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\Classroom;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Admin\AdminTestHelpers as A;
use Tests\Feature\Publik\PublikFixtures;

uses(RefreshDatabase::class);

/*
 | QA9 (2026-10-09): the nightly archive and Admin › Arsip otomatis. Fictional data only.
 */

beforeEach(function (): void {
    A::seedRoles();
    config(['session.driver' => 'database']);
    Notification::fake();
    $this->withoutVite();
    $this->travelTo('2029-12-01 09:00:00');
    $this->admin = A::admin();
    Classroom::factory()->level(12)->create(['name' => 'XII-2']);
});

function qa9ArsipStudent(string $archiveAt, UserStatus $status = UserStatus::Active, string $name = 'Raka Aditya Pratama'): Student
{
    $student = Student::factory()->activated()->create([
        'classroom_id' => Classroom::query()->where('name', 'XII-2')->value('id'),
        'grade12_start_date' => '2028-07-01',
        'archive_at' => $archiveAt,
    ]);
    $student->user->forceFill(['name' => $name, 'status' => $status])->save();

    return $student->refresh();
}

// Item 1: the nightly archive never turns a disabled student into a named alumnus ------------------------------

it('leaves a disabled student disabled and hidden when the archive date passes (QA9)', function (): void {
    $f = new PublikFixtures;
    $laras = $f->student('Laras Wening Pangestu', 'XII-5', attributes: ['archive_at' => '2029-11-30', 'grade12_start_date' => '2028-07-01']);
    $f->prestasi($laras, $f->lomba('OSN Nasional 2029 Kimia', Bidang::Sains, Level::Nasional, '2029-10-05'), ResultType::Juara1);
    $laras->forceFill(['public_slug' => str_repeat('c', 20)])->saveQuietly();
    User::query()->whereKey($laras->user_id)->update(['status' => UserStatus::Disabled->value]);
    // The achievement factory adds team students of its own: keep them out of this run.
    Student::query()->whereKeyNot($laras->getKey())->update(['archive_at' => '2099-01-01']);
    PublicAchievements::flush();

    expect(app(ArchiveDueStudentAccounts::class)->handle())->toBe(0)
        ->and($laras->user->fresh()->status)->toBe(UserStatus::Disabled)
        ->and(Activity::query()->where('event', 'account.archived')->exists())->toBeFalse();

    foreach (['alumni', 'semua'] as $group) {
        $this->get(route('public.peringkat', ['status' => $group]))->assertOk()->assertDontSee('Laras Wening Pangestu');
    }

    $this->get(route('public.prestasi.siswa', str_repeat('c', 20)))->assertNotFound();
});

it('archives only active and pending students whose date has come (QA9)', function (): void {
    $active = qa9ArsipStudent('2029-11-30', name: 'Bagas Wicaksono');
    $pending = qa9ArsipStudent('2029-11-30', UserStatus::PendingActivation, 'Citra Kirana');
    $disabled = qa9ArsipStudent('2029-11-30', UserStatus::Disabled, 'Dimas Prakoso');
    $today = now()->startOfDay();

    expect(Student::query()->dueForArchive($today)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$active->id, $pending->id])->sort()->values()->all());

    app(ArchiveDueStudentAccounts::class)->handle();

    expect($active->user->fresh()->status)->toBe(UserStatus::Archived)
        ->and($pending->user->fresh()->status)->toBe(UserStatus::Archived)
        ->and($disabled->user->fresh()->status)->toBe(UserStatus::Disabled);
});

it('does not list a disabled student among the accounts archived tonight (QA9)', function (): void {
    qa9ArsipStudent('2029-11-30', UserStatus::Disabled, 'Dimas Prakoso');
    qa9ArsipStudent('2029-12-10', name: 'Bagas Wicaksono');
    $this->actingAs($this->admin);

    Livewire::test(ArsipOtomatis::class)
        ->assertSee('Bagas Wicaksono')
        ->assertDontSee('Dimas Prakoso')
        ->assertViewHas('stats', fn (array $stats): bool => $stats['tonight'] === 0 && $stats['soon'] === 1);
});

// Item 5: a stale Arsip otomatis screen gets a toast, not a 403 -------------------------------------------------

it('says so when the student was archived before the date dialog opens (QA9)', function (): void {
    $student = qa9ArsipStudent('2029-12-20');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $screen = Livewire::test(ArsipOtomatis::class);

    app(ArchiveAccount::class)->handle($this->admin, $student->user, 'Pindah sekolah.');

    $screen->call('openDate', $student->id)
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', tone: 'warning');
});

it('says so when the student was archived while the date dialog was open (QA9)', function (): void {
    $student = qa9ArsipStudent('2029-12-20');
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $screen = Livewire::test(ArsipOtomatis::class)
        ->call('openDate', $student->id)
        ->set('archiveDate', '2030-12-20')
        ->set('archiveReason', 'Tinggal kelas.');

    app(ArchiveAccount::class)->handle($this->admin, $student->user, 'Pindah sekolah.');

    $screen->call('saveDate')
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'arsip-tanggal')
        ->assertSet('studentId', null);

    expect($student->fresh()->archive_at->toDateString())->toBe('2029-12-20');
});

it('says so when another Admin restored the account before or during the restore dialog (QA9)', function (): void {
    $alumni = qa9ArsipStudent('2029-11-01', UserStatus::Archived, 'Putri Ayu Lestari');
    $second = A::admin(['name' => 'Ibu Ratna Sari', 'username' => 'operator2']);
    $this->actingAs($this->admin);
    session(A::passwordConfirmed());
    $screen = Livewire::withQueryParams(['tab' => 'arsip'])->test(ArsipOtomatis::class)
        ->call('openRestore', $alumni->id)
        ->set('archiveDate', '2030-07-01')
        ->set('archiveReason', 'Masih aktif.');

    app(UnarchiveStudent::class)->handle($second, $alumni->user, '2030-08-01', 'Tanggal impor salah.');

    $screen->call('restore')
        ->assertDispatched('sth-toast', tone: 'warning')
        ->assertDispatched('sth-modal-close', id: 'arsip-pulihkan');

    $screen->call('openRestore', $alumni->id)
        ->assertNotDispatched('sth-modal-open')
        ->assertDispatched('sth-toast', tone: 'warning');

    expect($alumni->fresh()->archive_at->toDateString())->toBe('2030-08-01')
        ->and(Activity::query()->where('event', 'account.unarchived')->count())->toBe(1);
});

// Item 8: "Diarsipkan" shows the day the account was archived, not a future archive date ------------------------

it('shows the day of a manual archive, not the future archive date (QA9)', function (): void {
    $student = qa9ArsipStudent('2031-07-01', name: 'Hana Safira');
    app(ArchiveAccount::class)->handle($this->admin, $student->user, 'Pindah sekolah.');
    $this->actingAs($this->admin);

    Livewire::withQueryParams(['tab' => 'arsip'])->test(ArsipOtomatis::class)
        ->assertSee('Hana Safira')
        ->assertSee('1 Des 2029')
        ->assertDontSee('1 Jul 2031');

    Livewire::test(BatasArsip::class)
        ->assertSee('Hana Safira')
        ->assertSee('1 Des 2029')
        ->assertDontSee('1 Jul 2031');

    // The archive date itself is unchanged: UnarchiveStudent and the nightly job still read it.
    expect($student->fresh()->archive_at->toDateString())->toBe('2031-07-01');
});
