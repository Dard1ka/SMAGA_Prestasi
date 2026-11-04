<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Accounts\AccountControls;
use App\Actions\Registrations\CancelApprovedRegistration;
use App\Actions\Reports\ExportAuditLog;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin · Log audit (UI-MAP §5 `admin.audit.index`, backlog #8, owner B5; SECURITY §3m-9 "tidak ada menu
 * hapus/edit log"). Read-only list of activity_log with filters (pelaku, peristiwa, subjek, rentang tanggal,
 * nama pelaku) from ExportAuditLog::query(), 25 per page, newest first, details (properties, changes, hash)
 * in a native <details>. "Download CSV" exports the filtered rows formula-safe, needs a password confirmation
 * from the last 15 minutes and is itself audited. `audit.view` only (Admin).
 */
#[Title('Log audit')]
// One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Semua perubahan penting tercatat di sini. Tidak bisa diubah atau dihapus.', 'search' => false])]
final class AuditLog extends Component
{
    use RequiresRecentPassword;
    use WithPagination;

    /** Indonesian labels of the known events; anything else shows its code. */
    public const EVENT_LABELS = [
        'account.activated' => 'Akun diaktifkan',
        'account.activation_reopened' => 'Aktivasi dibuka lagi',
        'account.admin_created' => 'Admin dibuat (CLI)',
        'account.archived' => 'Akun diarsipkan',
        'account.disabled' => 'Akun dinonaktifkan',
        // SV5: EnableAccount, the undo of "Nonaktif" (Admin › Kelas, Status aktivasi).
        'account.enabled' => 'Akun diaktifkan lagi',
        'account.email_changed' => 'Email diganti',
        'account.frozen' => 'Akun dibekukan',
        'account.unfrozen' => 'Akun dibuka',
        'account.reset' => 'Akun siswa direset',
        'account.role_changed' => 'Peran diubah',
        'account.sessions_revoked' => 'Sesi diakhiri',
        'account.session_revoked' => 'Sesi diakhiri',
        'account.staff_created' => 'Akun staf dibuat',
        'account.staff_reset' => 'Akun staf direset',
        'account.unarchived' => 'Akun dipulihkan dari arsip',
        // Admin "Ubah status" of a student (the lead, 2026-11-05): ActivateStudentAccount, ExpireActivationWindow,
        // FreezeAccount. Properties: reason (typed), from (K9 state), the old / new window end.
        'account.activated_by_admin' => 'Diaktifkan Admin',
        'account.activation_expired_by_admin' => 'Masa aktivasi diakhiri Admin',
        'account.frozen_by_admin' => 'Dibekukan Admin',
        // M1: new activation order (email first, password change optional for students), yearly class import,
        // appreciation events, talent restore.
        'account.email_bound' => 'Email dihubungkan',
        'account.initial_password_changed' => 'Kata sandi awal diganti',
        // D-173 (smaga_server): `sth:siswa:kata-sandi-awal` moved pending students to the birth-date password (counts only).
        'student.initial_password_rule_applied' => 'Kata sandi awal siswa diganti ke pola tanggal lahir',
        'student.all_deleted' => 'Semua data siswa dihapus',
        'activation.password_kept' => 'Kata sandi dari sekolah tetap dipakai',
        'activation.profile_confirmed' => 'Data diri diisi saat aktivasi',
        'appreciation.event_deleted' => 'Acara apresiasi dihapus',
        'appreciation.list_printed' => 'Daftar apresiasi dicetak',
        'appreciation.released' => 'Prestasi dilepas dari acara',
        'student.class_changed' => 'Kelas siswa diperbarui',
        // Admin › Kelas (App\Actions\Classrooms).
        'classroom.created' => 'Kelas ditambahkan',
        'classroom.renamed' => 'Nama kelas diganti',
        'classroom.deleted' => 'Kelas dihapus',
        'classroom.numbered' => 'Kelas dijadikan X-1…XII-12',
        // Sampah (Admin › Kelas, the lead's decision of 5 Nov 2026, smaga_server only): App\Actions\Trash and
        // `sth:sampah:hapus-permanen`.
        'student.trashed' => 'Siswa dipindahkan ke Sampah',
        'classroom.trashed' => 'Kelas dipindahkan ke Sampah',
        'student.restored' => 'Siswa dipulihkan dari Sampah',
        'classroom.restored' => 'Kelas dipulihkan dari Sampah',
        'trash.purged' => 'Sampah dihapus permanen',
        'student.phone_updated' => 'No HP siswa diperbarui',
        'student.profile_updated' => 'Data diri siswa diubah',
        'talent.restored' => 'Pengajuan talenta dipulihkan',
        'talent.review_requested' => 'Siswa minta ditinjau ulang',
        'audit.exported' => 'Log audit di-download',
        'competition.created' => 'Lomba dibuat',
        'competition.submitted' => 'Lomba diusulkan',
        'competition.closed' => 'Lomba ditutup',
        'competition.weights_changed' => 'Bobot lomba diubah',
        'competition.facts_changed' => 'Info lomba diubah',
        'letter.downloaded' => 'Surat di-download',
        'letter.revoked' => 'Surat dicabut',
        'letters.issued' => 'Surat diterbitkan',
        'letters.sent' => 'Surat dikirim',
        'letter_template.updated' => 'Template surat diubah',
        'principal.requested' => 'Ganti Kepala Sekolah diminta',
        'principal.cancelled' => 'Ganti Kepala Sekolah dibatalkan',
        'principal.applied' => 'Kepala Sekolah diganti',
        'publication.published' => 'Prestasi dipublikasikan',
        'publication.flyer_generated' => 'E-flyer dibuat',
        'registration.created' => 'Pendaftaran dibuat',
        'registration.approved' => 'Pendaftaran disetujui',
        'registration.mentor_changed' => 'Guru pembimbing diganti saat persetujuan',
        'registration.rejected' => 'Pendaftaran ditolak',
        'registration.cancelled' => 'Pendaftaran dibatalkan',
        // D-148: a registration that waited for its lomba goes on once the lomba is published.
        'registration.resumed' => 'Pendaftaran dilanjutkan setelah lomba dipublikasikan',
        'report.submitted' => 'Laporan hasil dikirim',
        'report.verified' => 'Laporan hasil diverifikasi',
        'report.revision_requested' => 'Laporan perlu revisi',
        'report.rejected' => 'Laporan hasil ditolak',
        'school_report.exported' => 'Laporan prestasi di-download',
        'achievement.exported' => 'Database prestasi di-download',
        'account.activation_report_exported' => 'Laporan aktivasi di-download',
        'role.change_requested' => 'Perubahan peran diminta',
        'role.change_approved' => 'Perubahan peran disetujui',
        'role.change_cancelled' => 'Perubahan peran dibatalkan',
        'settings.limits_updated' => 'Arsip akun diubah',
        'settings.signer_updated' => 'Penandatangan diubah',
        'settings.letter_formats_updated' => 'Format nomor surat diubah',
        'settings.public_updated' => 'Halaman publik diubah',
        'signature.replaced' => 'Tanda tangan diganti',
        'signature.removed' => 'Tanda tangan dihapus',
        'student.archive_date_changed' => 'Tanggal arsip diubah',
        // Old rows only: nothing writes this event since D-162 (20 Okt 2026, consent removed).
        'student.consent_updated' => 'Persetujuan publikasi diubah',
        'student.import' => 'Impor siswa',
        'talent.exported' => 'Data talenta di-download',
        'two_factor.enabled' => '2FA mulai disiapkan',
        'two_factor.confirmed' => '2FA diaktifkan',
        'two_factor.disabled' => '2FA dimatikan',
        'two_factor.recovery_codes_regenerated' => 'Kode pemulihan 2FA dibuat ulang',
        'two_factor.challenge_failed' => 'Kode 2FA salah saat masuk',
        'weight.published' => 'Bobot poin diubah',
        'weight.recomputed' => 'Poin dihitung ulang',
        // QA5 retest (backlog #64): the events that still showed their code.
        'appreciation.event_created' => 'Acara apresiasi dibuat',
        'appreciation.event_completed' => 'Acara apresiasi selesai',
        'appreciation.recorded' => 'Prestasi dijadwalkan untuk apresiasi',
        'competition.published' => 'Lomba dipublikasikan',
        'competition.rejected' => 'Lomba ditolak',
        'file.downloaded' => 'Berkas di-download',
        'file.public_downloaded' => 'Berkas publik di-download',
        'password.changed' => 'Kata sandi diganti',
        'account.password_reset' => 'Kata sandi direset lewat kode email',
        'publication.scheduled' => 'Publikasi dijadwalkan',
        'publication.rescheduled' => 'Jadwal publikasi diubah',
        'publication.reminded' => 'Pengingat publikasi dikirim ke Humas (otomatis)',
        'registration.members_invited' => 'Anggota tim diundang',
        'registration.member_confirmed' => 'Anggota tim menerima undangan',
        'registration.member_declined' => 'Anggota tim menolak undangan',
        'registration.invitation_withdrawn' => 'Undangan tim dibatalkan ketua',
        'registration.invitations_expired' => 'Undangan tim kedaluwarsa',
        'registration.laporan_dikirim' => 'Laporan hasil dikirim siswa',
        'registration.berlangsung' => 'Lomba dimulai (otomatis)',
        'registration.menunggu_laporan' => 'Menunggu laporan hasil (otomatis)',
        'security.initial_password_on_active' => 'Kata sandi dari sekolah dicoba pada akun aktif',
        'security.reset_volume' => 'Banyak reset akun dalam satu jam',
        'setting.activation_window' => 'Masa aktivasi diubah',
        'talent.applied' => 'Siswa mendaftar talenta',
        'talent.cancelled' => 'Pilihan talenta dihapus siswa',
        'talent.stage_changed' => 'Tahap talenta diubah',
        'talent.rejected' => 'Pilihan talenta tidak lolos',
        // Rows before FS1 may hold an assessment date, rows since D-142 the guru pembina or the note: words true for both.
        'talent.assessment_updated' => 'Data talenta diubah',
        'talent_profile.saved' => 'Profil talenta disimpan',
        'weight.recompute_skipped' => 'Hitung ulang poin dilewati',
        'created' => 'Data dibuat',
        'updated' => 'Data diubah',
        'deleted' => 'Data dihapus',
        'restored' => 'Data dipulihkan',
    ];

    /**
     * Words for the reason codes the Actions write into `properties.reason` (r3, 2026-10-31: the screen printed
     * "Alasan: letter_queue"). Every other reason is text a person typed and is printed as stored; the CSV and the
     * details keep the stored value. A new code gets a line here (AuditLogTest scans the app for them).
     */
    public const REASON_LABELS = [
        AccountControls::FROZEN_NOT_ME => 'Laporan "Bukan saya" lewat email',
        AccountControls::FROZEN_FAILED_ATTEMPTS => 'Gagal masuk '.AccountControls::MAX_FAILED_ATTEMPTS.' kali',
        // Followed by the reason Tata Usaha typed (the row's `note`), see reasonText().
        CancelApprovedRegistration::REASON => 'Dihapus dari antrean surat',
        // ResumeWaitingRegistrations writes it as a literal (Registration::cancelledWithLomba() reads the same literal).
        'competition_rejected' => 'Usulan lomba ditolak',
    ];

    /** The search reads at most this many characters (the box keeps what was typed, ExportAuditLog::normalize() trims). */
    public const SEARCH_MAX = 100;

    #[Url(except: '')]
    public string $pelaku = '';

    #[Url(except: '')]
    public string $peristiwa = '';

    #[Url(except: '')]
    public string $subjek = '';

    #[Url(except: '')]
    public string $dari = '';

    #[Url(except: '')]
    public string $sampai = '';

    #[Url(except: '')]
    public string $q = '';

    public function mount(): void
    {
        ExportAuditLog::authorize($this->actor());
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('pelaku', 'peristiwa', 'subjek', 'dari', 'sampai', 'q');
        $this->resetPage();
    }

    public function export(ExportAuditLog $export): ?BinaryFileResponse
    {
        ExportAuditLog::authorize($this->actor());

        if (! $this->passwordRecentlyConfirmed('export')) {
            return null;
        }

        $result = $export->handle($this->actor(), $this->filters());

        return response()->download($result['path'], $result['filename'], ['Content-Type' => 'text/csv; charset=UTF-8'])->deleteFileAfterSend();
    }

    public function render(): View
    {
        ExportAuditLog::authorize($this->actor());

        $page = ExportAuditLog::query($this->filters())->with('causer')->orderByDesc('id')->paginate(25);
        $types = ExportAuditLog::subjectTypes();
        // B2's hash chain (side table audit_seals, sealed after commit): show the seal of each row when present.
        $seals = Schema::hasTable('audit_seals')
            ? DB::table('audit_seals')->whereIn('activity_id', collect($page->items())->pluck('id'))->pluck('hash', 'activity_id')
            : collect();

        return view('livewire.admin.audit-log', [
            'entries' => $page,
            'rows' => collect($page->items())->map(fn (Activity $a): array => $this->row($a) + ['hash' => $seals[$a->id] ?? null]),
            'actorOptions' => $this->actorOptions(),
            'eventOptions' => $this->eventOptions(),
            'subjectOptions' => collect($types)->mapWithKeys(fn (array $t, string $key) => [$key => $t['label']])->sort()->all(),
            'filtered' => array_filter($this->filters(), fn (string $v): bool => $v !== '') !== [],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Activity $a): array
    {
        $properties = $a->properties?->toArray() ?? [];
        $changes = $a->attribute_changes?->toArray() ?? [];
        $json = fn (array $data): string => (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'id' => $a->id,
            'at' => $a->created_at?->translatedFormat('j M Y H:i:s'),
            'actor' => $a->causer_id === null ? 'Sistem' : (string) ($a->causer?->name ?? 'Akun #'.$a->causer_id),
            'system' => $a->causer_id === null,
            'event' => (string) ($a->event ?? $a->description),
            'eventLabel' => self::EVENT_LABELS[(string) $a->event] ?? (string) ($a->event ?? $a->description),
            'log' => (string) $a->log_name,
            'subject' => ExportAuditLog::subjectLabel($a->subject_type),
            'subjectId' => (string) $a->subject_id,
            'reason' => self::reasonText($properties),
            'properties' => $properties !== [] ? $json($properties) : null,
            'changes' => $changes !== [] ? $json($changes) : null,
        ];
    }

    /**
     * The "Alasan:" line of a row: a known code in words (REASON_LABELS), "Dihapus dari antrean surat" with the note
     * Tata Usaha typed, the stored count of failed logins, else the stored text as typed; null without a reason.
     *
     * @param  array<string, mixed>  $properties
     */
    public static function reasonText(array $properties): ?string
    {
        $reason = $properties['reason'] ?? null;

        if (! is_scalar($reason) || trim((string) $reason) === '') {
            return null;
        }

        $reason = (string) $reason;
        $label = self::REASON_LABELS[$reason] ?? null;

        if ($label === null) {
            return $reason;
        }

        if ($reason === CancelApprovedRegistration::REASON) {
            $note = is_scalar($properties['note'] ?? null) ? trim((string) $properties['note']) : '';

            return $note !== '' ? $label.' · '.$note : $label;
        }

        $attempts = $properties['attempts'] ?? null;

        if ($reason === AccountControls::FROZEN_FAILED_ATTEMPTS && is_int($attempts) && $attempts > 0) {
            return 'Gagal masuk '.$attempts.' kali';
        }

        return $label;
    }

    /**
     * '' => all, 'sistem', then every non-student account that appears in the log as a causer. Not User::staff(): it
     * needs a role, and a replaced Kepala Sekolah or a new account still waiting for its role has none (QA9).
     *
     * @return array<string, string>
     */
    private function actorOptions(): array
    {
        $ids = Activity::query()->where('causer_type', (new User)->getMorphClass())->distinct()->pluck('causer_id');

        // withTrashed: a student whose student record was deleted is still not staff (QA9 review).
        $staff = User::withTrashed()->whereDoesntHave('student', fn ($q) => $q->withTrashed())->whereKey($ids)->orderBy('name')->get(['id', 'name', 'username'])
            ->mapWithKeys(fn (User $u) => [(string) $u->getKey() => $u->name.($u->username ? ' ('.$u->username.')' : '')])
            ->all();

        return ['' => 'Semua pelaku', 'sistem' => 'Sistem (otomatis)'] + $staff;
    }

    /** @return array<string, string> */
    private function eventOptions(): array
    {
        $events = Activity::query()->whereNotNull('event')->distinct()->pluck('event')
            ->mapWithKeys(fn (string $e) => [$e => self::EVENT_LABELS[$e] ?? $e])
            ->sort()
            ->all();

        return ['' => 'Semua peristiwa'] + $events;
    }

    /** @return array{pelaku: string, peristiwa: string, subjek: string, dari: string, sampai: string, q: string} */
    private function filters(): array
    {
        return ExportAuditLog::normalize([
            'pelaku' => $this->pelaku, 'peristiwa' => $this->peristiwa, 'subjek' => $this->subjek,
            'dari' => $this->dari, 'sampai' => $this->sampai, 'q' => $this->q,
        ]);
    }

    /**
     * Writes the cleaned filters back, so the selects, the date fields and the address show what the list reads. The
     * search box is left as typed (SV1, backlog #81): its trimmed value written back here would land in the box while
     * the Admin is still typing and eat the space before the next word ("Siti " + pause + "Rahmawati" became
     * "SitiRahmawati"). filters() trims it for the list, the export and its audit row; here it is only cut to what the
     * search reads.
     */
    private function normalize(): void
    {
        foreach ($this->filters() as $key => $value) {
            if ($key !== 'q') {
                $this->{$key} = $value;
            }
        }

        if (mb_strlen($this->q) > self::SEARCH_MAX) {
            $this->q = mb_substr($this->q, 0, self::SEARCH_MAX);
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function returnUrl(): string
    {
        return route('admin.audit.index', array_filter($this->filters(), fn (string $v): bool => $v !== ''));
    }
}
