<?php

declare(strict_types=1);

use App\Domain\Registrations\InvalidTransition;
use App\Domain\Registrations\RegistrationStateMachine as SM;

it('allows exactly the transitions of ARCHITECTURE §6', function () {
    expect((new SM)->transitions())->toBe([
        'menunggu_konfirmasi' => ['menunggu_kepsek', 'dibatalkan'],
        'menunggu_kepsek' => ['disetujui', 'ditolak', 'dibatalkan'],
        'disetujui' => ['surat_terbit'],
        'ditolak' => [],
        'surat_terbit' => ['berlangsung'],
        'berlangsung' => ['menunggu_laporan'],
        'menunggu_laporan' => ['laporan_dikirim'],
        'laporan_dikirim' => ['selesai', 'menunggu_laporan'],
        'selesai' => [],
        'dibatalkan' => [],
    ]);
});

it('answers canTransition for the whole status matrix', function () {
    $sm = new SM;
    $allowed = $sm->transitions();

    foreach ($sm->statuses() as $from) {
        foreach ($sm->statuses() as $to) {
            expect($sm->canTransition($from, $to))->toBe(in_array($to, $allowed[$from], true), "{$from} → {$to}");
        }
    }
});

it('follows the happy path of a team registration', function () {
    $sm = new SM;
    $path = [SM::MENUNGGU_KONFIRMASI, SM::MENUNGGU_KEPSEK, SM::DISETUJUI, SM::SURAT_TERBIT,
        SM::BERLANGSUNG, SM::MENUNGGU_LAPORAN, SM::LAPORAN_DIKIRIM, SM::MENUNGGU_LAPORAN,
        SM::LAPORAN_DIKIRIM, SM::SELESAI];

    for ($i = 1; $i < count($path); $i++) {
        $sm->assertTransition($path[$i - 1], $path[$i]);
    }

    expect($sm->isFinal(SM::SELESAI))->toBeTrue();
});

it('throws a DomainException with an Indonesian message', function () {
    try {
        (new SM)->assertTransition(SM::DISETUJUI, SM::DIBATALKAN);
        $this->fail('No exception thrown');
    } catch (InvalidTransition $e) {
        expect($e)->toBeInstanceOf(DomainException::class)
            ->and($e->getMessage())->toBe('Aksi ini tidak bisa dilakukan pada status pendaftaran sekarang.')
            ->and($e->from)->toBe('disetujui')
            ->and($e->to)->toBe('dibatalkan');
    }
});

it('never allows leaving a final status or skipping the principal', function () {
    $sm = new SM;

    expect($sm->canTransition(SM::DITOLAK, SM::DISETUJUI))->toBeFalse()
        ->and($sm->canTransition(SM::DIBATALKAN, SM::MENUNGGU_KEPSEK))->toBeFalse()
        ->and($sm->canTransition(SM::MENUNGGU_KONFIRMASI, SM::DISETUJUI))->toBeFalse()
        ->and($sm->canTransition(SM::DISETUJUI, SM::DISETUJUI))->toBeFalse()
        ->and($sm->canTransition('unknown', SM::DISETUJUI))->toBeFalse();
});

it('maps every status to a tracker phase', function (string $status, int $phase) {
    expect((new SM)->phaseFor($status))->toBe($phase);
})->with([
    ['menunggu_konfirmasi', 1],
    ['menunggu_kepsek', 2],
    ['ditolak', 2],
    ['disetujui', 3],
    ['surat_terbit', 3],
    ['berlangsung', 4],
    ['menunggu_laporan', 5],
    ['laporan_dikirim', 5],
    ['selesai', 6],
    ['dibatalkan', 1],
]);

it('lists next statuses and final statuses', function () {
    $sm = new SM;

    expect($sm->allowedNext(SM::LAPORAN_DIKIRIM))->toBe(['selesai', 'menunggu_laporan'])
        ->and(array_values(array_filter($sm->statuses(), $sm->isFinal(...))))->toBe(['ditolak', 'selesai', 'dibatalkan']);
});

it('rejects unknown statuses where a phase is required', function () {
    (new SM)->phaseFor('hilang');
})->throws(InvalidArgumentException::class);

it('accepts backed enum cases', function () {
    $sm = new SM;

    expect($sm->canTransition(TestRegistrationStatus::MenungguKepsek, TestRegistrationStatus::Disetujui))->toBeTrue()
        ->and($sm->phaseFor(TestRegistrationStatus::Disetujui))->toBe(3);
});

enum TestRegistrationStatus: string
{
    case MenungguKepsek = 'menunggu_kepsek';
    case Disetujui = 'disetujui';
}
