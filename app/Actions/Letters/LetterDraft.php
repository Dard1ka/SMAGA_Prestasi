<?php

declare(strict_types=1);

namespace App\Actions\Letters;

use App\Enums\EventMode;
use App\Models\Registration;
use Carbon\CarbonImmutable;

/**
 * What Tata Usaha types for one registration's letters (mockup TU-Surat-Tugas, step 1). The competition
 * data (name, organizer, members, mentor) always comes from the database, never from this draft.
 */
final readonly class LetterDraft
{
    public const DEFAULT_TIME = '07.00 WIB s.d. selesai';

    public function __construct(
        public ?int $siswaSequence,
        public ?int $guruSequence,
        public CarbonImmutable $issuedOn,
        public ?string $basisNumber,
        public ?CarbonImmutable $basisDate,
        public string $scheduleDay,
        public string $scheduleTime,
        public string $place,
    ) {}

    /**
     * Prefill from the competition: letter date today, schedule from starts_on/final_on, time
     * "07.00 WIB s.d. selesai", place from the location (or "Daring").
     *
     * @return array{tanggalSurat: string, dasarNomor: string, dasarTanggal: string, hariTanggal: string, pukul: string, tempat: string}
     */
    public static function defaults(Registration $registration, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $competition = $registration->competition;
        $day = '';

        if ($competition?->starts_on !== null) {
            $day = $competition->starts_on->locale('id')->translatedFormat('l, j F Y');

            if ($competition->final_on !== null && ! $competition->final_on->isSameDay($competition->starts_on)) {
                $day .= ' s.d. '.$competition->final_on->locale('id')->translatedFormat('l, j F Y');
            }
        }

        $place = $competition?->mode === EventMode::Daring ? 'Daring' : (string) ($competition?->location ?? '');

        return [
            'tanggalSurat' => $today->format('Y-m-d'),
            'dasarNomor' => '',
            'dasarTanggal' => '',
            'hariTanggal' => $day,
            'pukul' => self::DEFAULT_TIME,
            'tempat' => $place,
        ];
    }
}
