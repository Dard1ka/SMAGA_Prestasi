<?php

declare(strict_types=1);

namespace App\Actions\Registrations;

use App\Enums\MemberRole;
use App\Enums\Permission;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\Student;
use App\Models\User;
use App\Notifications\Registrations\RegistrationAwaitingPrincipal;
use App\Notifications\Registrations\TeamInvitation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Siswa registers for a published, open lomba (UI-MAP #11, ARCHITECTURE §6).
 * - individu → `menunggu_kepsek` right away + Kepala Sekolah notified;
 * - tim → `menunggu_konfirmasi`, the actor is ketua (confirmed), each anggota is invited and must
 *   confirm personally (ConfirmMembership / DeclineMembership); all confirmed → `menunggu_kepsek`.
 * Checked inside the transaction (competition + students locked): registration window, participation
 * type, team size (2 … max_team_size), active students only, nobody already in a non-final registration
 * of the same lomba, mentor is an active guru.
 */
final class CreateRegistration
{
    /**
     * @param  list<string>  $memberStudentIds  invited anggota (ULIDs), without the leader
     *
     * @throws ValidationException
     */
    public function handle(
        User $actor,
        Competition $competition,
        RegistrationType $type,
        array $memberStudentIds,
        ?int $mentorId,
    ): Registration {
        Gate::forUser($actor)->authorize('create', [Registration::class, $competition]);

        /** @var Student $leader */
        $leader = $actor->loadMissing('student')->getRelation('student');
        $memberIds = array_values(array_unique(array_filter(
            array_map(fn (mixed $id): string => (string) $id, $memberStudentIds),
            fn (string $id): bool => $id !== '' && $id !== $leader->getKey(),
        )));

        $registration = DB::transaction(function () use ($actor, $competition, $type, $leader, $memberIds, $mentorId): Registration {
            /** @var Competition $locked */
            $locked = Competition::query()->whereKey($competition->getKey())->lockForUpdate()->firstOrFail();

            $this->assertCompetitionAccepts($locked, $type, count($memberIds));

            // Serialise concurrent registrations of the same students (consistent lock order).
            Student::query()->whereKey([$leader->getKey(), ...$memberIds])->orderBy('id')->lockForUpdate()->get();

            $this->assertMembersAvailable($locked, $leader, $memberIds);
            $mentor = $this->mentor($mentorId);
            $now = now();

            $registration = new Registration([
                'competition_id' => $locked->getKey(),
                'leader_student_id' => $leader->getKey(),
                'type' => $type,
                'mentor_id' => $mentor->getKey(),
            ]);
            $registration->forceFill([
                'status' => $type === RegistrationType::Individu
                    ? RegistrationStatus::MenungguKepsek
                    : RegistrationStatus::MenungguKonfirmasi,
            ])->save();

            $registration->members()->make(['student_id' => $leader->getKey(), 'invited_at' => $now])
                ->forceFill(['role' => MemberRole::Ketua, 'confirmed_at' => $now])
                ->save();

            foreach ($memberIds as $studentId) {
                $registration->members()->make(['student_id' => $studentId, 'invited_at' => $now])
                    ->forceFill(['role' => MemberRole::Anggota])
                    ->save();
            }

            activity('registration')
                ->performedOn($registration)
                ->causedBy($actor)
                ->event('registration.created')
                ->withProperties([
                    'competition_id' => $locked->getKey(),
                    'type' => $type->value,
                    'status' => $registration->status->value,
                    'invited' => count($memberIds),
                ])
                ->log('registration.created');

            return $registration;
        });

        if ($type === RegistrationType::Tim) {
            $invitees = User::query()->canLogIn()
                ->whereHas('student', fn ($q) => $q->whereKey($memberIds))
                ->get();
            Notification::send($invitees, new TeamInvitation($registration));
        } else {
            Notification::send(self::principals(), new RegistrationAwaitingPrincipal($registration));
        }

        return $registration;
    }

    /** Active approvers: Kepala Sekolah and Tata Usaha (registration.approve, D-150; Admin does not have it, D-01). */
    public static function principals(): Collection
    {
        return User::query()->canLogIn()->permission(Permission::RegistrationApprove->value)->get();
    }

    private function assertCompetitionAccepts(Competition $competition, RegistrationType $type, int $invited): void
    {
        if (! $competition->isOpenForRegistration()) {
            throw ValidationException::withMessages(['competition' => 'Pendaftaran lomba ini sudah ditutup.']);
        }

        if (! $competition->allowsRegistrationType($type)) {
            $label = $competition->participation?->label() ?? 'jenis lain';

            throw ValidationException::withMessages(['type' => "Lomba ini untuk peserta {$label}."]);
        }

        if ($type === RegistrationType::Individu && $invited > 0) {
            throw ValidationException::withMessages(['members' => 'Pendaftaran individu tidak memakai anggota.']);
        }

        if ($type === RegistrationType::Tim) {
            if ($invited < 1) {
                throw ValidationException::withMessages(['members' => 'Tambahkan minimal 1 anggota tim.']);
            }

            $max = $competition->max_team_size;

            if ($max !== null && $max > 0 && $invited + 1 > $max) {
                throw ValidationException::withMessages(['members' => "Tim maksimal {$max} orang, termasuk kamu."]);
            }
        }
    }

    /** @param list<string> $memberIds */
    private function assertMembersAvailable(Competition $competition, Student $leader, array $memberIds): void
    {
        if ($memberIds !== [] && Student::query()->active()->whereKey($memberIds)->count() !== count($memberIds)) {
            throw ValidationException::withMessages(['members' => 'Ada anggota yang tidak ditemukan atau tidak aktif.']);
        }

        $busy = Student::query()
            ->whereKey([$leader->getKey(), ...$memberIds])
            ->busyIn($competition)
            ->with('user:id,name')
            ->get();

        if ($busy->contains(fn (Student $s): bool => $s->getKey() === $leader->getKey())) {
            throw ValidationException::withMessages(['competition' => 'Kamu sudah terdaftar di lomba ini.']);
        }

        if ($busy->isNotEmpty()) {
            $names = $busy->map(fn (Student $s): string => (string) $s->user?->name)->implode(', ');

            throw ValidationException::withMessages(['members' => "{$names} sudah terdaftar di lomba ini."]);
        }
    }

    private function mentor(?int $mentorId): User
    {
        // One rule for who may be a guru pembimbing, shared with the Kepala Sekolah's "Ganti guru" (MentorChoice, D-132).
        $mentor = MentorChoice::find($mentorId);

        if ($mentor === null) {
            throw ValidationException::withMessages(['mentor' => 'Pilih guru pembimbing.']);
        }

        return $mentor;
    }
}
