<?php

declare(strict_types=1);

namespace Database\Seeders\Support\Demo;

use Database\Seeders\DemoSeeder;

/**
 * The people a sample document may name (LOCAL ONLY).
 *
 * - Recipients, authors, pembimbing and the school's signer: only the fictional people of the demo set
 *   (DemoSeeder::STUDENTS / STAFF = docs/design/DESIGN-SPEC-v2.md §5). A name that is not in the set is never
 *   printed: the caller falls back to FALLBACK_STUDENT or PRINCIPAL.
 * - Signers of an organizer's certificate: invented names with a committee role ("Ketua Panitia", "Ketua Dewan
 *   Juri"). Never an office of a real institution (no "Kepala Dinas", "Rektor", "Kepala Balai"), so no real
 *   office holder is implied. Any likeness to a real person is a coincidence.
 */
final class DemoPeople
{
    public const SCHOOL = 'SMA Negeri 3 Semarang';

    public const SCHOOL_CITY = 'Semarang';

    /** The fictional Kepala Sekolah of the demo set. */
    public const PRINCIPAL = ['name' => 'Drs. Bambang Hartono, M.Pd.', 'rank' => 'Pembina Tk. I, IV/b', 'nip' => '-'];

    /** Used when a file has no usable owner. */
    public const FALLBACK_STUDENT = ['name' => 'Nadia Putri Anindya', 'nis' => '225226201', 'class' => 'XI-4'];

    public const FALLBACK_MENTOR = 'Retno Wulandari, S.Pd.';

    private const SIGNERS = [
        'Dr. Satya Wirabuana, M.Pd.',
        'Ir. Danar Kusumajati, M.T.',
        'Dra. Laksmi Purbaningrum, M.Hum.',
        'Yudhistira Arkananta, S.Si., M.Sc.',
        'Dr. Ayunda Prameswari Hadi, M.Si.',
        'Bagaskara Mahendratama, S.Pd., M.Or.',
        'Kirana Dewanti Larasati, S.Sn., M.Sn.',
        'Dr. Raditya Wicaksana Putra, M.Eng.',
        'Nareswari Candrakirana, S.Pd., M.Pd.',
        'Drs. Gading Prawiratama, M.M.',
        'Dr. Anjani Widyaswara, M.Biotech.',
        'Pradipa Lokeswara, S.T., M.Kom.',
        'Sekar Arumdalu Wijayanti, S.Hum., M.A.',
        'Dr. Baskoro Adhiyaksa Nugraha, M.Sc.',
    ];

    private const SIGNERS_EN = [
        'Dr. Aiden Marlowe-Tan',
        'Prof. Selina Okafor-Lindqvist',
        'Dr. Haruto Vandersee',
        'Dr. Mirela Castellanos-Wu',
        'Prof. Idris Halvorsen-Rao',
        'Dr. Noor Anneliese Brandt',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_values(array_unique([
            ...array_column(DemoSeeder::STAFF, 0),
            ...array_column(DemoSeeder::STUDENTS, 0),
        ]));
    }

    public static function isFictional(?string $name): bool
    {
        return $name !== null && in_array(trim($name), self::names(), true);
    }

    /** The name when it belongs to the demo set, otherwise the fallback. */
    public static function nameOr(?string $name, string $fallback): string
    {
        return self::isFictional($name) ? trim((string) $name) : $fallback;
    }

    /**
     * Two different invented signers, always the same two for the same seed (e.g. the lomba name).
     *
     * @param  array{0: string, 1: string}  $roles
     * @return list<array{name: string, role: string}>
     */
    public static function signers(string $seed, array $roles, bool $english = false): array
    {
        $pool = $english ? self::SIGNERS_EN : self::SIGNERS;
        $first = self::pick($seed.'|1', count($pool));
        $second = self::pick($seed.'|2', count($pool) - 1);
        $second = $second >= $first ? $second + 1 : $second;

        return [
            ['name' => $pool[$first], 'role' => $roles[0]],
            ['name' => $pool[$second], 'role' => $roles[1]],
        ];
    }

    /** A stable number 0 … $count - 1 for a seed. */
    public static function pick(string $seed, int $count): int
    {
        return $count < 1 ? 0 : (int) (hexdec(substr(hash('sha256', $seed), 0, 8)) % $count);
    }
}
