<?php

declare(strict_types=1);

namespace App\Domain\Registrations;

use App\Domain\Support\Key;
use BackedEnum;
use InvalidArgumentException;

/**
 * Pure transition map for RegistrationStatus (ARCHITECTURE §6) and its 6-phase tracker mapping.
 * Accepts App\Enums\RegistrationStatus cases or their string values; returns string values.
 * Eloquent-backed Actions (lockForUpdate, audit, notifications) wrap this in wave 2.
 */
final class RegistrationStateMachine
{
    public const MENUNGGU_KONFIRMASI = 'menunggu_konfirmasi';

    public const MENUNGGU_KEPSEK = 'menunggu_kepsek';

    public const DISETUJUI = 'disetujui';

    public const DITOLAK = 'ditolak';

    public const SURAT_TERBIT = 'surat_terbit';

    public const BERLANGSUNG = 'berlangsung';

    public const MENUNGGU_LAPORAN = 'menunggu_laporan';

    public const LAPORAN_DIKIRIM = 'laporan_dikirim';

    public const SELESAI = 'selesai';

    public const DIBATALKAN = 'dibatalkan';

    /** menunggu_kepsek → dibatalkan: "leader cancels before the principal decides" (§6, mockup Lomba-Saya). */
    private const TRANSITIONS = [
        self::MENUNGGU_KONFIRMASI => [self::MENUNGGU_KEPSEK, self::DIBATALKAN],
        self::MENUNGGU_KEPSEK => [self::DISETUJUI, self::DITOLAK, self::DIBATALKAN],
        self::DISETUJUI => [self::SURAT_TERBIT],
        self::DITOLAK => [],
        self::SURAT_TERBIT => [self::BERLANGSUNG],
        self::BERLANGSUNG => [self::MENUNGGU_LAPORAN],
        self::MENUNGGU_LAPORAN => [self::LAPORAN_DIKIRIM],
        self::LAPORAN_DIKIRIM => [self::SELESAI, self::MENUNGGU_LAPORAN],
        self::SELESAI => [],
        self::DIBATALKAN => [],
    ];

    /** Tracker phases: 1 Daftar · 2 Persetujuan · 3 Surat tugas · 4 Lomba · 5 Laporan hasil · 6 Poin & apresiasi. */
    private const PHASES = [
        self::MENUNGGU_KONFIRMASI => 1,
        self::MENUNGGU_KEPSEK => 2,
        self::DITOLAK => 2,
        self::DISETUJUI => 3,
        self::SURAT_TERBIT => 3,
        self::BERLANGSUNG => 4,
        self::MENUNGGU_LAPORAN => 5,
        self::LAPORAN_DIKIRIM => 5,
        self::SELESAI => 6,
        self::DIBATALKAN => 1,
    ];

    /** @return list<string> */
    public function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    /** @return array<string, list<string>> */
    public function transitions(): array
    {
        return self::TRANSITIONS;
    }

    /** @return list<string> */
    public function allowedNext(string|BackedEnum $from): array
    {
        return self::TRANSITIONS[$this->known($from)];
    }

    public function canTransition(string|BackedEnum $from, string|BackedEnum $to): bool
    {
        return in_array(Key::of($to), self::TRANSITIONS[Key::of($from)] ?? [], true);
    }

    /** @throws InvalidTransition */
    public function assertTransition(string|BackedEnum $from, string|BackedEnum $to): void
    {
        if (! $this->canTransition($from, $to)) {
            throw new InvalidTransition(Key::of($from), Key::of($to));
        }
    }

    /** Tracker phase 1–6. dibatalkan shows as phase 1 (ended), ditolak as phase 2 (ended). */
    public function phaseFor(string|BackedEnum $status): int
    {
        return self::PHASES[$this->known($status)];
    }

    /** ditolak, selesai, dibatalkan: no further transitions. */
    public function isFinal(string|BackedEnum $status): bool
    {
        return self::TRANSITIONS[$this->known($status)] === [];
    }

    private function known(string|BackedEnum $status): string
    {
        $key = Key::of($status);

        if (! array_key_exists($key, self::TRANSITIONS)) {
            throw new InvalidArgumentException("Status pendaftaran tidak dikenal: {$key}.");
        }

        return $key;
    }
}
