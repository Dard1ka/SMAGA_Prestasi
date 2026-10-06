<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Enums\EventMode;
use App\Enums\LetterType;
use App\Models\Letter;
use App\Models\Registration;
use Carbon\CarbonImmutable;

/**
 * What Tata Usaha types for ONE letter (M1-A, lead 2026-10-04: every letter has its own form and its own
 * data, the two letters of a registration may differ): the complete number, the letter date, the "Dasar"
 * (surat dari / nomor / tanggal, plus "hal" on the guru letter) and the "Pelaksanaan" (hari, tanggal, pukul,
 * tempat). Who is in the letter (members, guru pembimbing, lomba name, signer) always comes from the
 * database, never from this draft (SECURITY §3g).
 */
final readonly class LetterDraft
{
    public const DEFAULT_TIME = '07.00 WIB s.d Selesai';

    public const DEFAULT_SUBJECT = 'Undangan';

    /** "sampai dengan" exactly as the school writes it between two dates or times. */
    public const RANGE = 's.d';

    public function __construct(
        public string $number,
        public CarbonImmutable $issuedOn,
        public string $basisFrom,
        public ?string $basisNumber,
        public ?CarbonImmutable $basisDate,
        public string $scheduleDay,
        public string $scheduleDate,
        public string $scheduleTime,
        public string $place,
        public string $basisSubject = self::DEFAULT_SUBJECT,
    ) {}

    /**
     * Form values for a new letter, prefilled from the lomba: letter date today, "Surat dari" = penyelenggara,
     * hari/tanggal from starts_on (… s.d … final_on), time "07.00 WIB s.d Selesai", place = location or "Daring".
     * The number stays empty: Tata Usaha types it.
     *
     * @return array{nomor: string, tanggalSurat: string, dasarDari: string, dasarNomor: string, dasarTanggal: string, dasarHal: string, hari: string, tanggal: string, pukul: string, tempat: string}
     */
    public static function defaults(Registration $registration, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $competition = $registration->competition;
        $day = '';
        $date = '';

        if ($competition?->starts_on !== null) {
            $start = $competition->starts_on->locale('id');
            $day = $start->translatedFormat('l');
            $date = $start->translatedFormat('j F Y');

            if ($competition->final_on !== null && ! $competition->final_on->isSameDay($competition->starts_on)) {
                $end = $competition->final_on->locale('id');
                $day .= ' '.self::RANGE.' '.$end->translatedFormat('l');
                $date .= ' '.self::RANGE.' '.$end->translatedFormat('j F Y');
            }
        }

        return [
            'nomor' => '',
            'tanggalSurat' => $today->format('Y-m-d'),
            'dasarDari' => trim((string) $competition?->organizer_name),
            'dasarNomor' => '',
            'dasarTanggal' => '',
            'dasarHal' => self::DEFAULT_SUBJECT,
            'hari' => $day,
            'tanggal' => $date,
            'pukul' => self::DEFAULT_TIME,
            'tempat' => $competition?->mode === EventMode::Daring ? 'Daring' : trim((string) $competition?->location),
        ];
    }

    /**
     * Form values of an issued letter (what it printed). Letters issued before M1-A have "Hari, tanggal" in
     * one column and no "Surat dari": those are split / taken from the lomba.
     *
     * @return array{nomor: string, tanggalSurat: string, dasarDari: string, dasarNomor: string, dasarTanggal: string, dasarHal: string, hari: string, tanggal: string, pukul: string, tempat: string}
     */
    public static function fromLetter(Letter $letter, Registration $registration): array
    {
        $defaults = self::defaults($registration);
        $day = trim((string) $letter->schedule_day);
        $date = trim((string) $letter->schedule_date);

        if ($date === '' && str_contains($day, ',')) {
            [$day, $date] = array_map(trim(...), explode(',', $day, 2));
        }

        return [
            'nomor' => (string) $letter->full_number,
            'tanggalSurat' => $letter->issued_on->format('Y-m-d'),
            'dasarDari' => trim((string) $letter->basis_from) !== '' ? (string) $letter->basis_from : $defaults['dasarDari'],
            'dasarNomor' => (string) $letter->basis_number,
            'dasarTanggal' => $letter->basis_date?->format('Y-m-d') ?? '',
            'dasarHal' => trim((string) $letter->basis_subject) !== '' ? (string) $letter->basis_subject : self::DEFAULT_SUBJECT,
            'hari' => $day !== '' ? $day : $defaults['hari'],
            'tanggal' => $date !== '' ? $date : $defaults['tanggal'],
            'pukul' => trim((string) $letter->schedule_time) !== '' ? (string) $letter->schedule_time : $defaults['pukul'],
            'tempat' => trim((string) $letter->place) !== '' ? (string) $letter->place : $defaults['tempat'],
        ];
    }

    /** Does this letter type print the "Hal" of the invitation? Only the Surat Perintah Tugas does. */
    public static function usesSubject(LetterType $type): bool
    {
        return $type === LetterType::Guru;
    }
}
