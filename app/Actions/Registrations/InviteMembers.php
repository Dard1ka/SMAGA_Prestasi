<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\TeamInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The ketua invites more anggota — typically a replacement for someone who declined (D-36, UI-MAP #11,
 * B3 / backlog #9). Only while the registration is `menunggu_konfirmasi` and the lomba still accepts
 * registrations (RegistrationPolicy::invite). Same member rules as CreateRegistration, re-checked inside
 * the transaction with the registration, the competition and the students locked:
 * - active students only, never the ketua, never someone already in this team;
 * - someone who declined this team cannot be invited again (no invitation loops);
 * - nobody who is already in another non-final registration of the same lomba;
 * - the team (members who did not decline + new ones) stays within max_team_size.
 * New rows: role anggota, invited_at = now; invitees get TeamInvitation after commit. Audited
 * `registration.members_invited`. Errors use the key `members`.
 */
final class InviteMembers
{
    /**
     * @param  list<string>  $studentIds  student ULIDs to invite
     *
     * @throws ValidationException
     */
    public function handle(User $actor, Registration $registration, array $studentIds): Registration
    {
        Gate::forUser($actor)->authorize('invite', $registration);

        $ids = array_values(array_unique(array_filter(
            array_map(fn (mixed $id): string => trim((string) $id), $studentIds),
            fn (string $id): bool => $id !== '',
        )));

        if ($ids === []) {
            throw ValidationException::withMessages(['members' => 'Pilih siswa yang ingin diundang.']);
        }

        $locked = DB::transaction(function () use ($actor, $registration, $ids): Registration {
            /** @var Registration $locked */
            $locked = Registration::query()->whereKey($registration->getKey())->lockForUpdate()->firstOrFail();
            /** @var Competition $competition */
            $competition = Competition::withTrashed()->whereKey($locked->competition_id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RegistrationStatus::MenungguKonfirmasi || ! $locked->isTeam()) {
                throw ValidationException::withMessages(['members' => 'Anggota hanya bisa diundang selama tim menunggu konfirmasi.']);
            }

            if (! $competition->isOpenForRegistration()) {
                throw ValidationException::withMessages(['members' => 'Batas pendaftaran sudah lewat.']);
            }

            if (in_array((string) $locked->leader_student_id, $ids, true)) {
                throw ValidationException::withMessages(['members' => 'Kamu sudah ketua tim ini.']);
            }

            // Same lock order as CreateRegistration (students by id) to serialise concurrent invites.
            Student::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();

            $this->assertInvitable($locked, $competition, $ids);

            $now = now();

            foreach ($ids as $studentId) {
                $locked->members()->make(['student_id' => $studentId, 'invited_at' => $now])
                    ->forceFill(['role' => MemberRole::Anggota])
                    ->save();
            }

            activity('registration')
                ->performedOn($locked)
                ->causedBy($actor)
                ->event('registration.members_invited')
                ->withProperties(['student_ids' => $ids, 'invited' => count($ids), 'status' => $locked->status->value])
                ->log('registration.members_invited');

            return $locked->setRelation('competition', $competition);
        });

        $invitees = User::query()->canLogIn()->whereHas('student', fn ($q) => $q->whereKey($ids))->get();

        if ($invitees->isNotEmpty()) {
            Notification::send($invitees, new TeamInvitation($locked));
        }

        $registration->setRawAttributes($locked->getAttributes(), true);

        return $registration;
    }

    /**
     * @param  list<string>  $ids
     *
     * @throws ValidationException
     */
    private function assertInvitable(Registration $registration, Competition $competition, array $ids): void
    {
        $students = Student::query()->whereKey($ids)->active()->with('user:id,name')->get()->keyBy('id');

        if ($students->count() !== count($ids)) {
            throw ValidationException::withMessages(['members' => 'Ada siswa yang tidak ditemukan atau tidak aktif.']);
        }

        $name = fn (string $id): string => (string) $students->get($id)?->user?->name;

        $rows = $registration->members()->lockForUpdate()->get()->keyBy('student_id');

        foreach ($ids as $id) {
            $row = $rows->get($id);

            if ($row !== null && $row->declined_at !== null) {
                throw ValidationException::withMessages(['members' => $name($id).' sudah menolak undangan tim ini.']);
            }

            if ($row !== null) {
                throw ValidationException::withMessages(['members' => $name($id).' sudah ada di tim ini.']);
            }
        }

        $busy = Student::query()->whereKey($ids)->busyIn($competition)->with('user:id,name')->get();

        if ($busy->isNotEmpty()) {
            $names = $busy->map(fn (Student $s): string => (string) $s->user?->name)->implode(', ');

            throw ValidationException::withMessages(['members' => "{$names} sudah terdaftar di lomba ini."]);
        }

        $max = $competition->max_team_size;
        $team = $rows->filter(fn (RegistrationMember $m): bool => $m->declined_at === null)->count();

        if ($max !== null && $max > 0 && $team + count($ids) > $max) {
            throw ValidationException::withMessages(['members' => "Tim maksimal {$max} orang, termasuk kamu."]);
        }
    }
}
