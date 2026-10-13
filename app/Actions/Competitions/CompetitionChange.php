<?php

declare(strict_types=1);

namespace App\Actions\Competitions;

use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\User;
use App\Rules\NoWebAddress;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What changed on a published lomba that the people attached to it rely on, and who has to hear about it (lead,
 * 2026-10-10, D-126). One place for UpdateCompetition (audit row + notices) and the Kesiswaan drawer (its hint and
 * its toast).
 *
 * A FACT is something a registered student or the guru pembimbing plans with: batas daftar, tanggal, pelaksanaan,
 * tempat, peserta, the two links, juknis. Everything else (nama, deskripsi, penyelenggara and its jenis, bidang,
 * sampul, guru pembimbing default, "Daftar dibuka", and the weight fields, which keep their own audit event) is a
 * correction nobody is told about. Facts are compared on what a reader sees: dates by day (the form never edits
 * the time of day), the place without regard to case and spacing.
 *
 * Who relies on what:
 * - batas daftar, peserta: teams still collecting answers (`menunggu_konfirmasi`). Students only.
 * - everything else: registrations up to and including `berlangsung`, students and the guru pembimbing; a new
 *   final date also while the report is open (`menunggu_laporan`: the report deadline moves with it).
 * - never a registration that is over, rejected or cancelled, and never a student who is not registered.
 *
 * Students = the member rows nobody declined (ketua, confirmed and still-invited members) whose account can log
 * in. Guru = an active account that still holds the guru role (D-110 / QA10), one notice for all the guru's
 * registrations of this lomba. The account that made the change is never told. Above MAIL_LIMIT recipients the
 * notice goes to the bell only. No sentence prints a web address (SECURITY §3m-1): a link, and a place that holds
 * an address, is only said to have changed.
 */
final class CompetitionChange
{
    /** More recipients than this in one save: bell only, no mail (the provider's daily quota also carries the OTP mails). */
    public const MAIL_LIMIT = 50;

    /** @var list<string> the facts, in the order their sentences are listed */
    public const FACTS = ['deadline', 'dates', 'mode', 'location', 'participants', 'registration_url', 'website_url', 'juknis'];

    /** Snapshot key beside the facts: the final day alone, which decides whether a report deadline moved. */
    private const FINAL_DAY = 'final_on';

    /** A lomba that has not finished yet for the registration. */
    private const PRE_LOMBA = [
        RegistrationStatus::MenungguKonfirmasi,
        RegistrationStatus::MenungguKepsek,
        RegistrationStatus::Disetujui,
        RegistrationStatus::SuratTerbit,
        RegistrationStatus::Berlangsung,
    ];

    /** The team's own matter: the guru pembimbing is not told. */
    private const STUDENTS_ONLY = ['deadline', 'participants'];

    /**
     * The facts of a lomba as comparable values (fact => scalar), taken before and after a save.
     *
     * @return array<string, string|null>
     */
    public static function snapshot(Competition $competition): array
    {
        $participation = $competition->participation;

        return [
            'deadline' => $competition->registration_closes_at?->toDateString(),
            'dates' => ($competition->starts_on?->toDateString() ?? '').'|'.($competition->final_on?->toDateString() ?? ''),
            'mode' => $competition->mode?->value,
            'location' => self::comparable($competition->location),
            // "Maks anggota" means nothing on an individu lomba (a save empties it).
            'participants' => $participation === null || $participation === Participation::Individu
                ? $participation?->value
                : $participation->value.'|'.(string) $competition->max_team_size,
            'registration_url' => self::blankToNull($competition->registration_url),
            'website_url' => self::blankToNull($competition->website_url),
            'juknis' => self::blankToNull($competition->juknis_file_id),
            self::FINAL_DAY => $competition->final_on?->toDateString(),
        ];
    }

    /**
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     * @return list<string> the facts whose value differs, in FACTS order
     */
    public static function changed(array $before, array $after): array
    {
        return array_values(array_filter(
            self::FACTS,
            fn (string $fact): bool => ($before[$fact] ?? null) !== ($after[$fact] ?? null),
        ));
    }

    /**
     * Did the final date itself move (not only "Tanggal mulai")? Then registrations that wait for their report
     * rely on the change too.
     *
     * @param  array<string, string|null>  $before
     * @param  array<string, string|null>  $after
     */
    public static function finalChanged(array $before, array $after): bool
    {
        return ($before[self::FINAL_DAY] ?? null) !== ($after[self::FINAL_DAY] ?? null);
    }

    /**
     * The registration statuses that rely on a fact (empty for anything that is not a fact).
     *
     * @return list<RegistrationStatus>
     */
    public static function statusesFor(string $fact, bool $finalChanged = false): array
    {
        return match (true) {
            ! in_array($fact, self::FACTS, true) => [],
            in_array($fact, self::STUDENTS_ONLY, true) => [RegistrationStatus::MenungguKonfirmasi],
            $fact === 'dates' && $finalChanged => [...self::PRE_LOMBA, RegistrationStatus::MenungguLaporan],
            default => self::PRE_LOMBA,
        };
    }

    public static function tellsMentor(string $fact): bool
    {
        return in_array($fact, self::FACTS, true) && ! in_array($fact, self::STUDENTS_ONLY, true);
    }

    /**
     * The sentence of one changed fact, with the value as it is now. `$status` = the registration of the reader:
     * one that waits for its report hears the new end date (no report deadline since D-151).
     */
    public static function line(string $fact, Competition $competition, ?RegistrationStatus $status = null): string
    {
        return match ($fact) {
            'deadline' => 'Batas daftar sekarang '.self::day($competition->registration_closes_at)
                .'. Undangan tim yang belum dijawab tidak berlaku lagi setelah tanggal itu.',
            'dates' => $status === RegistrationStatus::MenungguLaporan && $competition->final_on !== null
                ? 'Tanggal selesai sekarang '.self::day($competition->final_on).'.'
                : 'Tanggal lomba sekarang '.self::dayRange($competition->starts_on, $competition->final_on).'.',
            'mode' => 'Pelaksanaan sekarang '.($competition->mode?->label() ?? 'belum diisi').'.',
            'location' => self::place($competition->location),
            'participants' => 'Peserta sekarang '.self::participants($competition).'.',
            'registration_url' => self::blankToNull($competition->registration_url) === null ? 'Link registrasi dihapus.' : 'Link registrasi diganti.',
            'website_url' => self::blankToNull($competition->website_url) === null ? 'Website resmi dihapus.' : 'Website resmi diganti.',
            'juknis' => 'Juknis/brosur diganti.',
            default => '',
        };
    }

    /**
     * Who is told about these changed facts, read from the registrations of the lomba as they are now.
     *
     * @param  list<string>  $facts  result of changed()
     * @return array{
     *     groups: list<array{registration: string, users: Collection<int, User>, lines: list<string>}>,
     *     mentors: list<array{user: User, lines: list<string>, registration: ?string}>,
     *     students: int,
     *     mentorCount: int,
     *     mail: bool,
     * } groups = the students of one registration with their sentences; a mentor's `registration` is the one binaan
     *   page to open, null when the guru has several registrations on this lomba; mail = false above MAIL_LIMIT
     *   recipients (and when nobody is told)
     */
    public static function audience(Competition $competition, array $facts, User $actor, bool $finalChanged = false): array
    {
        $statuses = [];

        foreach ($facts as $fact) {
            foreach (self::statusesFor($fact, $finalChanged) as $status) {
                $statuses[$status->value] = $status;
            }
        }

        $registrations = $statuses === [] ? collect() : $competition->registrations()
            ->whereIn('status', array_keys($statuses))
            ->with(['members' => fn ($members) => $members->whereNull('declined_at')->orderBy('id'), 'members.student.user'])
            ->orderBy('id')
            ->get();

        $groups = [];
        $perMentor = [];
        // One notice per account and save; the actor is never told.
        $seen = [(int) $actor->getKey() => true];

        /** @var Registration $registration */
        foreach ($registrations as $registration) {
            $own = array_values(array_filter(
                $facts,
                fn (string $fact): bool => in_array($registration->status, self::statusesFor($fact, $finalChanged), true),
            ));

            if ($own === []) {
                continue;
            }

            $users = $registration->members
                ->map(fn (RegistrationMember $member): ?User => $member->student?->user)
                ->filter(function (?User $user) use (&$seen): bool {
                    if ($user === null || ! $user->canLogIn() || isset($seen[(int) $user->getKey()])) {
                        return false;
                    }

                    $seen[(int) $user->getKey()] = true;

                    return true;
                })
                ->values();

            if ($users->isNotEmpty()) {
                $groups[] = [
                    'registration' => (string) $registration->getKey(),
                    'users' => $users,
                    'lines' => array_map(fn (string $fact): string => self::line($fact, $competition, $registration->status), $own),
                ];
            }

            if ($registration->mentor_id === null) {
                continue;
            }

            foreach (array_filter($own, self::tellsMentor(...)) as $fact) {
                $perMentor[(int) $registration->mentor_id]['registrations'][(string) $registration->getKey()] = true;
                $perMentor[(int) $registration->mentor_id]['lines'][$fact][self::line($fact, $competition, $registration->status)] = true;
            }
        }

        $mentors = [];

        if ($perMentor !== []) {
            $accounts = User::query()->gurus()->canLogIn()
                ->whereKey(array_keys($perMentor))
                ->whereKeyNot($actor->getKey())
                ->orderBy('id')
                ->get();

            foreach ($accounts as $mentor) {
                $own = $perMentor[(int) $mentor->getKey()];
                $lines = [];

                // FACTS order; two registrations in different stages may give two sentences for the dates.
                foreach ($facts as $fact) {
                    array_push($lines, ...array_map(strval(...), array_keys($own['lines'][$fact] ?? [])));
                }

                $mentors[] = [
                    'user' => $mentor,
                    'lines' => $lines,
                    'registration' => count($own['registrations']) === 1 ? (string) array_key_first($own['registrations']) : null,
                ];
            }
        }

        $students = array_sum(array_map(fn (array $group): int => $group['users']->count(), $groups));
        $total = $students + count($mentors);

        return [
            'groups' => $groups,
            'mentors' => $mentors,
            'students' => $students,
            'mentorCount' => count($mentors),
            'mail' => $total > 0 && $total <= self::MAIL_LIMIT,
        ];
    }

    /**
     * For the screen's toast after a save: how many people the change was told to, or null when nobody was (no
     * fact changed, the lomba is not published, nobody attached). `$before` = snapshot() taken before the save,
     * `$competition` = the saved lomba. The audit row `competition.facts_changed` holds the counts of the send itself.
     *
     * @param  array<string, string|null>  $before
     * @return array{students: int, mentors: int, mail: bool}|null
     */
    public static function summary(array $before, Competition $competition, User $actor): ?array
    {
        if ($competition->status !== CompetitionStatus::Dipublikasikan) {
            return null;
        }

        $after = self::snapshot($competition);
        $facts = self::changed($before, $after);

        if ($facts === []) {
            return null;
        }

        $audience = self::audience($competition, $facts, $actor, self::finalChanged($before, $after));

        return $audience['students'] + $audience['mentorCount'] === 0
            ? null
            : ['students' => $audience['students'], 'mentors' => $audience['mentorCount'], 'mail' => $audience['mail']];
    }

    /**
     * Students registered for this lomba today: the members nobody declined of its registrations up to and including
     * `berlangsung` (the number in the hint at the top of "Ubah lomba"; which of them a changed fact is told to is
     * statusesFor()). 0 for a lomba that is not published.
     */
    public static function attachedStudents(Competition $competition): int
    {
        if ($competition->status !== CompetitionStatus::Dipublikasikan) {
            return 0;
        }

        return RegistrationMember::query()
            ->whereNull('declined_at')
            ->whereHas('registration', fn (Builder $registration) => $registration
                ->where('competition_id', $competition->getKey())
                ->whereIn('status', self::PRE_LOMBA))
            ->distinct()
            ->count('student_id');
    }

    /**
     * "Tempat sekarang Kota Semarang." The place is typed by people and its field has no address rule: with a web
     * address in it the sentence only says that it changed, like the two link fields (mail clients link such text
     * by themselves, D-76). A false match of the rule only shortens the sentence; it never blocks a save.
     */
    private static function place(?string $location): string
    {
        $place = rtrim(self::oneLine($location), '.');

        return match (true) {
            $place === '' => 'Tempat sekarang belum diisi.',
            NoWebAddress::found($place) => 'Tempat diganti.',
            default => 'Tempat sekarang '.$place.'.',
        };
    }

    /** "Individu", "Tim, maks 3", "Individu atau tim (maks 3)". */
    private static function participants(Competition $competition): string
    {
        $max = $competition->max_team_size;

        return match ($competition->participation) {
            Participation::Individu => 'Individu',
            Participation::Tim => $max !== null ? 'Tim, maks '.$max : 'Tim',
            Participation::Keduanya => $max !== null ? 'Individu atau tim (maks '.$max.')' : 'Individu atau tim',
            default => 'belum diisi',
        };
    }

    /** "9 Okt 2026". */
    private static function day(?DateTimeInterface $date): string
    {
        return $date === null ? 'belum diisi' : CarbonImmutable::instance($date)->locale('id')->translatedFormat('j M Y');
    }

    /** "8 Nov 2026", "8–10 Nov 2026", "30 Okt – 8 Nov 2026", "7 Nov 2026 – 2 Jan 2027". */
    private static function dayRange(?DateTimeInterface $start, ?DateTimeInterface $final): string
    {
        $start ??= $final;
        $final ??= $start;

        if ($start === null || $final === null) {
            return 'belum diisi';
        }

        $from = CarbonImmutable::instance($start)->locale('id');
        $to = CarbonImmutable::instance($final)->locale('id');

        return match (true) {
            $from->isSameDay($to) => self::day($to),
            $from->isSameMonth($to) => $from->day.'–'.self::day($to),
            $from->isSameYear($to) => $from->translatedFormat('j M').' – '.self::day($to),
            default => self::day($from).' – '.self::day($to),
        };
    }

    /** Text typed by people as one line: trimmed, any run of whitespace is one space. */
    private static function oneLine(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    private static function comparable(?string $value): ?string
    {
        $value = mb_strtolower(self::oneLine($value));

        return $value === '' ? null : $value;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
