<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Lomba;

use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\RegistrationMember;
use App\Support\Sampul;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Display data for lomba cards/detail (Beranda, Katalog, Detail): registration status chip, peserta,
 * penyelenggara, date, place, and "N siswa SMAGA mendaftar". Formatting only; the rules
 * (open window, participation) come from the Competition model.
 */
final class LombaPresenter
{
    /** Status of the registration window: [label, tone, key] with key dibuka|segera|ditutup. */
    public static function window(Competition $competition, ?DateTimeInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $opens = $competition->registration_opens_at;
        $closes = $competition->registration_closes_at;

        if ($opens !== null && $opens->greaterThan($now)) {
            return ['label' => 'Segera dibuka', 'tone' => 'gray', 'key' => 'segera', 'note' => 'Daftar dibuka '.self::date($opens)];
        }

        if ($closes === null) {
            return ['label' => 'Dibuka', 'tone' => 'green', 'key' => 'dibuka', 'note' => null];
        }

        if ($closes->lessThan($now)) {
            return ['label' => 'Ditutup', 'tone' => 'gray', 'key' => 'ditutup', 'note' => 'Pendaftaran ditutup '.self::date($closes)];
        }

        $days = self::daysUntil($closes, $now);

        if ($days <= 3) {
            return [
                'label' => $days === 0 ? 'Ditutup hari ini' : "Ditutup {$days} hari lagi",
                'tone' => 'orange',
                'key' => 'dibuka',
                'note' => null,
            ];
        }

        return ['label' => "Dibuka · {$days} hari lagi", 'tone' => 'green', 'key' => 'dibuka', 'note' => null];
    }

    /** Whole calendar days from today to the date (0 = today). */
    public static function daysUntil(DateTimeInterface $date, ?DateTimeInterface $now = null): int
    {
        $today = CarbonImmutable::instance($now ?? now())->startOfDay();

        return (int) $today->diffInDays(CarbonImmutable::instance($date)->startOfDay(), false);
    }

    /** "Individu", "Tim maks 3", "Individu/Tim". */
    public static function peserta(Competition $competition): string
    {
        return match ($competition->participation) {
            Participation::Tim => $competition->max_team_size ? 'Tim maks '.$competition->max_team_size : 'Tim',
            Participation::Keduanya => 'Individu/Tim',
            Participation::Individu => 'Individu',
            default => 'Peserta belum diatur',
        };
    }

    /** "Puspresnas · Kategori A". */
    public static function penyelenggara(Competition $competition): string
    {
        $parts = array_filter([
            $competition->organizer_name,
            $competition->weight_category !== null ? 'Kategori '.$competition->weight_category->value : null,
        ]);

        return $parts === [] ? 'Penyelenggara belum diisi' : implode(' · ', $parts);
    }

    public static function tanggal(Competition $competition): string
    {
        $start = $competition->starts_on;
        $final = $competition->final_on;

        if ($start === null && $final === null) {
            return 'Tanggal menyusul';
        }

        if ($start !== null && $final !== null && ! $start->isSameDay($final)) {
            return $start->isSameMonth($final)
                ? $start->translatedFormat('j').'–'.self::date($final)
                : self::date($start).' – '.self::date($final);
        }

        return self::date($start ?? $final);
    }

    public static function tempat(Competition $competition): string
    {
        return $competition->location ?: ($competition->mode?->label() ?? 'Tempat menyusul');
    }

    public static function tingkat(Competition $competition): string
    {
        return $competition->level?->shortLabel() ?? 'Tingkat menyusul';
    }

    /** "1,2 MB" / "350 KB" (decimal comma, CLAUDE.md §3.9). */
    public static function fileSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.').' MB';
        }

        return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.').' KB';
    }

    public static function date(?DateTimeInterface $date): string
    {
        return $date === null ? '–' : CarbonImmutable::instance($date)->locale('id')->translatedFormat('j M Y');
    }

    /**
     * Names of SMAGA students in active (not cancelled/rejected) registrations, per competition id.
     * Only names reach the view; cards show initials avatars + a count.
     *
     * @param  Collection<int, Competition>|list<string>  $competitions
     * @return array<string, list<string>>
     */
    public static function registrants(Collection|array $competitions): array
    {
        $ids = $competitions instanceof Collection ? $competitions->modelKeys() : $competitions;

        if ($ids === []) {
            return [];
        }

        $rows = RegistrationMember::query()
            ->whereNull('declined_at')
            ->join('registrations', 'registrations.id', '=', 'registration_members.registration_id')
            ->join('students', 'students.id', '=', 'registration_members.student_id')
            ->join('users', 'users.id', '=', 'students.user_id')
            ->whereIn('registrations.competition_id', $ids)
            ->whereNull('registrations.deleted_at')
            ->whereNotIn('registrations.status', [RegistrationStatus::Dibatalkan->value, RegistrationStatus::Ditolak->value])
            ->orderBy('registration_members.created_at')
            ->get(['registrations.competition_id as competition_id', 'users.name as name', 'students.id as sid']);

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->competition_id][(string) $row->sid] = (string) $row->name;
        }

        return array_map(fn (array $names): array => array_values($names), $result);
    }

    /**
     * Props for <x-sth.lomba-card>.
     *
     * @param  array<string, list<string>>  $registrants
     * @return array<string, mixed>
     */
    public static function card(Competition $competition, array $registrants, string $href): array
    {
        $window = self::window($competition);
        $names = $registrants[$competition->getKey()] ?? [];

        return [
            'id' => $competition->getKey(),
            'title' => $competition->name,
            'href' => $href,
            'bidang' => $competition->bidang?->value ?? 'sains',
            'tingkat' => self::tingkat($competition),
            'status' => $window['label'],
            'statusTone' => $window['tone'],
            'peserta' => self::peserta($competition),
            'penyelenggara' => self::penyelenggara($competition),
            'date' => self::tanggal($competition),
            'place' => self::tempat($competition),
            'names' => $names,
            'count' => count($names),
            'note' => $window['key'] === 'segera' ? $window['note'] : null,
            'window' => $window['key'],
            // Cover: the uploaded poster, else a template drawing picked from the id (App\Support\Sampul).
            'poster' => Sampul::poster($competition),
        ];
    }
}
