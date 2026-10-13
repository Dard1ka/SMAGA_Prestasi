<?php

declare(strict_types=1);

use App\Console\Commands\Registrations\SendReportReminders;
use App\Enums\RegistrationStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Models\Report;
use App\Notifications\Reports\ReportReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Siswa\Hasil\HasilFixtures as F;

/*
| D-151 (FS1 Paket B): no report deadline, so `sth:reports:remind` reminds the ketua 3 and 14 days after the final
| (ReportFollowUp::REMINDER_DAYS), once each (registration_reminders kinds report_after_3 / report_after_14), never
| once a report exists, and not for a registration whose day-14 reminder lies more than CATCH_UP_DAYS back (no burst
| of reminders for old rows on the first run after the deploy).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
    F::boot();

    $this->nadia = F::student();
    $this->member = F::student('Dimas Arya Nugroho');
    $this->competition = F::competition(); // final 24 Sep 2026
    $this->registration = F::registration($this->nadia, RegistrationStatus::MenungguLaporan, $this->competition, [$this->member]);
});

afterEach(fn () => F::resetToday());

it('reminds the ketua on H+3 and H+14 after the final, once each', function (): void {
    $run = fn (string $d) => $this->artisan('sth:reports:remind', ['--date' => $d])->assertSuccessful();

    $run('2026-09-26'); // H+2
    Notification::assertNothingSent();

    $run('2026-09-27'); // H+3
    $run('2026-09-27');
    Notification::assertSentToTimes($this->nadia->user, ReportReminder::class, 1);
    Notification::assertSentTo($this->nadia->user, ReportReminder::class, fn (ReportReminder $n): bool => $n->daysAfterFinal === 3);

    $run('2026-10-07'); // H+13: nothing new
    Notification::assertSentToTimes($this->nadia->user, ReportReminder::class, 1);

    $run('2026-10-08'); // H+14
    Notification::assertSentToTimes($this->nadia->user, ReportReminder::class, 2);
    Notification::assertSentTo($this->nadia->user, ReportReminder::class, fn (ReportReminder $n): bool => $n->daysAfterFinal === 14);
    Notification::assertNotSentTo($this->member->user, ReportReminder::class);

    expect(DB::table('registration_reminders')->where('registration_id', $this->registration->getKey())->orderBy('kind')->pluck('kind')->all())
        ->toBe(['report_after_14', 'report_after_3']);
});

it('sends each reminder once, never after a report and never far behind', function (): void {
    // Missed both days: only the latest one that is still within the catch-up window goes out.
    $this->artisan('sth:reports:remind', ['--date' => '2026-10-12'])->assertSuccessful(); // H+18
    Notification::assertSentToTimes($this->nadia->user, ReportReminder::class, 1);
    Notification::assertSentTo($this->nadia->user, ReportReminder::class, fn (ReportReminder $n): bool => $n->daysAfterFinal === 14);
    expect(DB::table('registration_reminders')->where('registration_id', $this->registration->getKey())->pluck('kind')->all())->toBe(['report_after_14']);

    $this->artisan('sth:reports:remind', ['--date' => '2026-10-12'])->assertSuccessful();
    Notification::assertSentToTimes($this->nadia->user, ReportReminder::class, 1);

    // A report waits for its revision (back in menunggu_laporan): it was sent, so no reminder.
    $revising = F::registration(F::student('Keisha Larasati', 'XI-6'), RegistrationStatus::MenungguLaporan, $this->competition);
    $report = Report::factory()->create(['registration_id' => $revising->getKey(), 'claimed_result' => ResultType::Finalis]);
    $report->forceFill(['status' => ReportStatus::PerluRevisi])->save();

    // A lomba whose final lies far back (H+72 > 14 + CATCH_UP_DAYS): nothing on the first run after the deploy.
    $old = F::registration(F::student('Raka Pradipta', 'XII-2'), RegistrationStatus::MenungguLaporan, F::competition([
        'name' => 'OPSI 2026', 'starts_on' => '2026-07-30', 'final_on' => '2026-08-01',
    ]));

    $this->artisan('sth:reports:remind', ['--date' => '2026-10-12'])->assertSuccessful();

    Notification::assertNotSentTo($revising->leader->user, ReportReminder::class);
    Notification::assertNotSentTo($old->leader->user, ReportReminder::class);
    expect(SendReportReminders::CATCH_UP_DAYS)->toBe(7)
        ->and(DB::table('registration_reminders')->whereIn('registration_id', [$revising->getKey(), $old->getKey()])->count())->toBe(0);
});

it('stops at the edge of the catch-up window', function (): void {
    $this->artisan('sth:reports:remind', ['--date' => '2026-10-15'])->assertSuccessful(); // H+21 = 14 + 7
    Notification::assertSentToTimes($this->nadia->user, ReportReminder::class, 1);

    $other = F::registration(F::student('Hana Safira', 'X-8'), RegistrationStatus::MenungguLaporan, $this->competition);
    $this->artisan('sth:reports:remind', ['--date' => '2026-10-16'])->assertSuccessful(); // H+22
    Notification::assertNotSentTo($other->leader->user, ReportReminder::class);
});

it('words the reminder without any deadline', function (): void {
    $mail = (new ReportReminder('01jreg', 'OPSI 2026', 3))->toMail($this->nadia->user);

    // PlainMailMessage keeps lines as escaped text (D-69): compare what a reader sees.
    $lines = array_map(fn (mixed $line): string => html_entity_decode((string) $line, ENT_QUOTES | ENT_HTML5), $mail->introLines);

    expect($lines)->toBe(['Lomba OPSI 2026 sudah selesai. Segera laporkan hasil lombamu: upload sertifikat dan foto dokumentasi.'])
        ->and($mail->subject)->toBe('Laporan OPSI 2026 ditunggu')
        ->and((new ReportReminder('01jreg', 'OPSI 2026', 14))->toArray($this->nadia->user))->toMatchArray([
            'days_after_final' => 14,
            'registration_id' => '01jreg',
        ]);
});
