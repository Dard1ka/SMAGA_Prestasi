<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Lomba;

use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Support\Sampul;
use App\Support\StaffRoleLabel;
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
    /** "Laporan hasil" on the read screens: there is no report deadline (D-151), only this request. */
    public const REPORT_LINE = 'Segera laporkan setelah lomba selesai';

    /** D-148: a registration for the own lomba that Kesiswaan has not verified yet goes nowhere before that. */
    public const WAITING_FOR_LOMBA = 'Pendaftaran diteruskan setelah Kesiswaan memverifikasi lomba ini.';

    /**
     * The sentence under a `menunggu_lomba` registration (Pendaftaran lomba panel, Lomba saya); a team also hears when its
     * invitations go out.
     */
    public static function waitingForLomba(Registration $registration): string
    {
        return self::WAITING_FOR_LOMBA.($registration->isTeam() ? ' Undangan tim dikirim saat itu.' : '');
    }

    /**
     * Status of the registration window: [label, tone, key] with key dibuka|segera|ditutup|ditolak|menunggu.
     * QA10: the lomba's status comes first. Only a published lomba has a window from its dates; the proposer of a lomba
     * Kesiswaan closed, rejected or has not checked yet saw a green "Dibuka · N hari lagi" beside "Pendaftaran ditutup".
     */
    public static function window(Competition $competition, ?DateTimeInterface $now = null): array
    {
        $byStatus = match ($competition->status) {
            CompetitionStatus::Ditutup => ['label' => 'Ditutup', 'tone' => 'gray', 'key' => 'ditutup', 'note' => 'Pendaftaran ditutup Kesiswaan'],
            CompetitionStatus::Ditolak => ['label' => 'Ditolak', 'tone' => 'red', 'key' => 'ditolak', 'note' => null],
            CompetitionStatus::MenungguVerifikasi => ['label' => 'Menunggu verifikasi', 'tone' => 'gray', 'key' => 'menunggu', 'note' => null],
            default => null,
        };

        if ($byStatus !== null) {
            return $byStatus;
        }

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

    /**
     * The notice on Detail lomba for a lomba that is not in the catalog (only its proposer sees it there), one text per
     * status (QA10: "Lomba ini masih ditutup" / "masih ditolak" read wrong). Null for a published lomba. A rejection
     * names the role that decided by the one lomba rule (StaffRoleLabel::forLomba()).
     *
     * @return array{title: string, text: string}|null
     */
    public static function unpublishedNotice(Competition $competition): ?array
    {
        return match ($competition->status) {
            CompetitionStatus::Ditutup => [
                'title' => 'Ditutup Kesiswaan',
                'text' => 'Kesiswaan sudah menutup lomba ini. Lomba ini tidak tampil di katalog lagi dan tidak menerima pendaftaran baru.',
            ],
            CompetitionStatus::Ditolak => [
                'title' => 'Usulan ditolak',
                'text' => StaffRoleLabel::forLomba($competition->verifier).' menolak usulan lomba ini, jadi lomba ini tidak tampil di katalog dan tidak menerima pendaftaran.',
            ],
            CompetitionStatus::MenungguVerifikasi => [
                'title' => 'Belum tampil di katalog',
                'text' => 'Lomba ini masih menunggu verifikasi Kesiswaan. Siswa lain belum bisa melihatnya.',
            ],
            default => null,
        };
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

    /** "Pelaksanaan" (start – end of the lomba), "Tanggal menyusul" when no date is set (D-147). */
    public static function tanggal(Competition $competition): string
    {
        return self::range($competition->starts_on, $competition->final_on) ?? 'Tanggal menyusul';
    }

    /**
     * "Pendaftaran" (registration opens – closes, D-147); null when neither date is set. With one date only it says which
     * end that is ("Sampai 25 Okt 2026", "Mulai 12 Okt 2026"): a bare date read as a registration of that one day (FS1 QA).
     */
    public static function pendaftaran(Competition $competition): ?string
    {
        $opens = $competition->registration_opens_at;
        $closes = $competition->registration_closes_at;

        return match (true) {
            $opens === null && $closes !== null => 'Sampai '.self::date($closes),
            $opens !== null && $closes === null => 'Mulai '.self::date($opens),
            default => self::range($opens, $closes),
        };
    }

    /**
     * A period the short way (D-147): "12–30 Okt 2026", "28 Sep – 5 Okt 2026", "28 Des 2026 – 5 Jan 2027"; one date
     * when both are the same day or one is missing; null when both are missing. Compared by calendar day.
     */
    public static function range(?DateTimeInterface $from, ?DateTimeInterface $to): ?string
    {
        if ($from === null && $to === null) {
            return null;
        }

        if ($from === null || $to === null) {
            return self::date($from ?? $to);
        }

        $start = CarbonImmutable::instance($from)->locale('id');
        $end = CarbonImmutable::instance($to)->locale('id');

        if ($start->isSameDay($end)) {
            return self::date($end);
        }

        if ($start->isSameMonth($end)) {
            return $start->translatedFormat('j').'–'.self::date($end);
        }

        return $start->isSameYear($end)
            ? $start->translatedFormat('j M').' – '.self::date($end)
            : self::date($start).' – '.self::date($end);
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
     * Names of SMAGA students in active (not cancelled/rejected) registrations, per competition id: members who
     * confirmed (the ketua always has); QA10: an invitation nobody answered yet is not a registrant.
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
            ->whereNotNull('registration_members.confirmed_at')
            ->whereNull('registration_members.declined_at')
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
