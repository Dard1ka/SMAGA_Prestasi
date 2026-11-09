<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\MemberRole;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\RegistrationRevision;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * SV8, D-199 (the lead, 2026-11-09): "siswa boleh mengajukan revisi dalam kondisi apapun entah belum di acc tu atau
 * kesiswaan maupun sudah di acc. Kalau sudah di acc siswa tetap bisa revisi namun perlu persetujuan TU atau kesiswaan
 * dulu untuk acc revisi tersebut". The one place of the rules of a change of a registration by its ketua: add and
 * remove anggota, change the guru pembimbing.
 *
 * - BEFORE_APPROVAL: the change applies at once (ChangeRegistration);
 * - AFTER_APPROVAL: it is asked for as a revision (RequestRevision) and Tata Usaha or Kesiswaan decides it
 *   (ApproveRevision / RejectRevision). *Sementara*: not once the report was sent (laporan_dikirim, selesai), because the
 *   points are given per member; never for a final registration.
 *
 * check() runs inside the Action's transaction with the registration locked (and the students to add locked by the
 * caller, in id order like CreateRegistration). The member rules are those of CreateRegistration / InviteMembers: active
 * students only, never the ketua, nobody already in this team or who declined it, nobody in another running registration
 * of the lomba (or invited by another pending revision of it), 2 … Competition::teamLimit() people (D-36, D-196).
 */
final class RevisionRules
{
    /** The ketua changes the registration directly (no extra approval). */
    public const BEFORE_APPROVAL = [
        RegistrationStatus::MenungguLomba,
        RegistrationStatus::MenungguKonfirmasi,
        RegistrationStatus::MenungguKepsek,
    ];

    /** The ketua asks for a revision; Tata Usaha or Kesiswaan decides. */
    public const AFTER_APPROVAL = [
        RegistrationStatus::Disetujui,
        RegistrationStatus::SuratTerbit,
        RegistrationStatus::Berlangsung,
        RegistrationStatus::MenungguLaporan,
    ];

    public const MIN_REASON = 10;

    public const MAX_REASON = 500;

    /** The note of a rejection (the rule of RejectRegistration). */
    public const MIN_NOTE = 5;

    public const MAX_NOTE = 500;

    public const MSG_NOTHING = 'Belum ada yang diubah. Tambah atau keluarkan anggota, atau pilih guru pembimbing lain.';

    public const MSG_INDIVIDU = 'Pendaftaran individu tidak memakai anggota. Hanya guru pembimbing yang bisa diganti.';

    public const MSG_LEADER_ADD = 'Kamu sudah ketua tim ini.';

    public const MSG_LEADER_REMOVE = 'Ketua tim tidak bisa dikeluarkan.';

    public const MSG_BOTH = 'Siswa yang sama tidak bisa ditambah dan dikeluarkan sekaligus.';

    public const MSG_NOT_MEMBER = 'Siswa yang dipilih untuk dikeluarkan tidak ada di tim ini.';

    public const MSG_NOT_FOUND = 'Ada siswa yang tidak ditemukan atau tidak aktif.';

    public const MSG_TOO_FEW = 'Tim minimal 2 orang, termasuk ketua.';

    public const MSG_WINDOW = 'Batas pendaftaran sudah lewat, jadi anggota baru tidak bisa diundang lagi. Anggota masih bisa dikeluarkan dan guru pembimbing masih bisa diganti.';

    public const MSG_REASON_SHORT = 'Tulis alasan revisi minimal 10 karakter.';

    public const MSG_REASON_LONG = 'Alasan revisi maksimal 500 karakter.';

    public const MSG_PENDING = 'Masih ada revisi yang menunggu keputusan. Batalkan dulu revisi itu untuk mengajukan yang baru.';

    public const MSG_NOW_APPROVED = 'Pendaftaran ini baru saja disetujui. Perubahan sekarang perlu diajukan sebagai revisi.';

    public const MSG_NOT_APPROVED = 'Pendaftaran ini belum disetujui, jadi perubahannya langsung disimpan tanpa revisi.';

    public const MSG_REPORT_SENT = 'Laporan hasil sudah dikirim, jadi tim dan guru pembimbing tidak bisa diubah lagi.';

    public const MSG_CANCELLED = 'Pendaftaran ini sudah dibatalkan.';

    public const MSG_REJECTED = 'Pendaftaran ini tidak disetujui.';

    public const MSG_NOT_LEADER = 'Hanya ketua tim yang bisa mengubah pendaftaran ini.';

    /** Why this registration cannot be changed at all (null = before or after the approval, it can). */
    public static function closedReason(Registration $registration): ?string
    {
        return match ($registration->status) {
            RegistrationStatus::LaporanDikirim, RegistrationStatus::Selesai => self::MSG_REPORT_SENT,
            RegistrationStatus::Dibatalkan => self::MSG_CANCELLED,
            RegistrationStatus::Ditolak => self::MSG_REJECTED,
            default => null,
        };
    }

    /**
     * The actor is the signed-in ketua of the registration, a student who may register (`registration.create`), whatever
     * its status (the Actions check the status under their lock and answer it with a sentence).
     */
    public static function leads(User $actor, Registration $registration): bool
    {
        if (! $actor->canLogIn() || ! $actor->can(Permission::RegistrationCreate->value)) {
            return false;
        }

        $student = $actor->loadMissing('student')->getRelation('student');

        return $student !== null && (string) $registration->leader_student_id === (string) $student->getKey();
    }

    public static function isBeforeApproval(Registration $registration): bool
    {
        return in_array($registration->status, self::BEFORE_APPROVAL, true);
    }

    public static function isAfterApproval(Registration $registration): bool
    {
        return in_array($registration->status, self::AFTER_APPROVAL, true);
    }

    /**
     * Validate a change of the locked registration and return it normalised: the students to add and to remove
     * (ULIDs), and the new guru (null = the guru stays; the guru it already has counts as no change).
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @param  string|null  $exceptRevision  the revision being approved (its own invitations are not "another team")
     * @return array{add: list<string>, remove: list<string>, mentor: ?User}
     *
     * @throws ValidationException keys `changes`, `members`, `mentor`
     */
    public static function check(
        Registration $locked,
        Competition $competition,
        array $add,
        array $remove,
        int|string|null $mentorId,
        bool $afterApproval,
        ?string $exceptRevision = null,
    ): array {
        $add = self::ids($add);
        $remove = self::ids($remove);
        $mentor = self::mentor($locked, $mentorId);

        if ($add === [] && $remove === [] && $mentor === null) {
            throw ValidationException::withMessages(['changes' => self::MSG_NOTHING]);
        }

        if (! $locked->isTeam() && ($add !== [] || $remove !== [])) {
            throw ValidationException::withMessages(['members' => self::MSG_INDIVIDU]);
        }

        $leader = (string) $locked->leader_student_id;

        if (in_array($leader, $add, true)) {
            throw ValidationException::withMessages(['members' => self::MSG_LEADER_ADD]);
        }

        if (in_array($leader, $remove, true)) {
            throw ValidationException::withMessages(['members' => self::MSG_LEADER_REMOVE]);
        }

        if (array_intersect($add, $remove) !== []) {
            throw ValidationException::withMessages(['members' => self::MSG_BOTH]);
        }

        $rows = $locked->members()->lockForUpdate()->get()->keyBy(fn (RegistrationMember $m): string => (string) $m->student_id);

        foreach ($remove as $id) {
            $row = $rows->get($id);

            if ($row === null || $row->role !== MemberRole::Anggota || $row->declined_at !== null
                || ($afterApproval && $row->confirmed_at === null)) {
                throw ValidationException::withMessages(['members' => self::MSG_NOT_MEMBER]);
            }
        }

        if ($add !== []) {
            self::assertInvitable($locked, $competition, $rows, $add, $exceptRevision);
        }

        if ($locked->isTeam()) {
            // The people of the team (D-36: not declined, not in the trash) after the change.
            $people = ConfirmMembership::peopleAmong($rows->values())
                ->map(fn (RegistrationMember $m): string => (string) $m->student_id)
                ->all();
            $after = count(array_diff($people, $remove)) + count($add);

            if ($remove !== [] && $after < ConfirmMembership::MIN_TEAM_SIZE) {
                throw ValidationException::withMessages(['members' => self::MSG_TOO_FEW]);
            }

            // SV8, D-196: the lomba's own "Maks anggota", never more than Competition::MAX_TEAM_SIZE.
            $max = $competition->teamLimit();

            if ($add !== [] && $after > $max) {
                throw ValidationException::withMessages(['members' => "Tim maksimal {$max} orang, termasuk ketua."]);
            }
        }

        return ['add' => $add, 'remove' => $remove, 'mentor' => $mentor];
    }

    /** Lock the students to add, in id order (the lock order of CreateRegistration and InviteMembers). @param list<string> $ids */
    public static function lockStudents(array $ids): void
    {
        if ($ids !== []) {
            Student::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * The reason of a revision, trimmed, or the refusal (key `reason`).
     *
     * @throws ValidationException
     */
    public static function reason(?string $reason): string
    {
        $reason = trim((string) $reason);

        if (mb_strlen($reason) < self::MIN_REASON) {
            throw ValidationException::withMessages(['reason' => self::MSG_REASON_SHORT]);
        }

        if (mb_strlen($reason) > self::MAX_REASON) {
            throw ValidationException::withMessages(['reason' => self::MSG_REASON_LONG]);
        }

        return $reason;
    }

    /** @return list<string> unique, trimmed, non-empty ids */
    public static function ids(array $ids): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (mixed $id): string => trim((string) $id), $ids),
            fn (string $id): bool => $id !== '',
        )));
    }

    /** The new guru, or null when none is asked for or it is the guru the registration has. @throws ValidationException */
    private static function mentor(Registration $locked, int|string|null $mentorId): ?User
    {
        if ($mentorId === null || trim((string) $mentorId) === '') {
            return null;
        }

        // One rule for who may be a guru pembimbing (D-132): an account with the guru role that can log in.
        $mentor = MentorChoice::find(trim((string) $mentorId));

        if ($mentor === null) {
            throw ValidationException::withMessages(['mentor' => ApproveRegistration::MSG_MENTOR]);
        }

        return $locked->mentor_id !== null && (int) $locked->mentor_id === (int) $mentor->getKey() ? null : $mentor;
    }

    /**
     * @param  Collection<string, RegistrationMember>  $rows
     * @param  list<string>  $add
     *
     * @throws ValidationException
     */
    private static function assertInvitable(Registration $locked, Competition $competition, $rows, array $add, ?string $exceptRevision): void
    {
        $students = Student::query()->whereKey($add)->active()->with('user:id,name')->get()
            ->keyBy(fn (Student $s): string => (string) $s->getKey());

        if ($students->count() !== count($add)) {
            throw ValidationException::withMessages(['members' => self::MSG_NOT_FOUND]);
        }

        $name = fn (string $id): string => (string) $students->get($id)?->user?->name;

        foreach ($add as $id) {
            $row = $rows->get($id);

            if ($row !== null && $row->declined_at !== null) {
                throw ValidationException::withMessages(['members' => $name($id).' sudah menolak undangan tim ini.']);
            }

            if ($row !== null) {
                throw ValidationException::withMessages(['members' => $name($id).' sudah ada di tim ini.']);
            }
        }

        $busy = Student::query()->whereKey($add)->busyIn($competition)->with('user:id,name')->get();

        if ($busy->isNotEmpty()) {
            $names = $busy->map(fn (Student $s): string => (string) $s->user?->name)->implode(', ');

            throw ValidationException::withMessages(['members' => "{$names} sudah terdaftar di lomba ini."]);
        }

        // Invited by another pending revision of the same lomba: two teams would wait for the same student.
        $invited = RegistrationRevision::query()->pending()
            ->when($exceptRevision !== null, fn (Builder $q) => $q->whereKeyNot($exceptRevision))
            ->whereHas('registration', fn (Builder $r) => $r->where('competition_id', $locked->competition_id))
            ->get(['id', 'payload'])
            ->flatMap(fn (RegistrationRevision $r): array => $r->additions())
            ->intersect($add)
            ->unique()
            ->values();

        if ($invited->isNotEmpty()) {
            $names = $invited->map(fn (string $id): string => $name($id))->implode(', ');

            throw ValidationException::withMessages(['members' => "{$names} sedang diundang ke tim lain untuk lomba ini."]);
        }
    }
}
