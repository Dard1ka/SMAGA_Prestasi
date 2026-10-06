<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

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
#[Layout('layouts::app', ['subtitle' => 'Semua perubahan penting tercatat di sini. Tidak bisa diubah atau dihapus.'])]
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
        // M1: new activation order (email first, password change optional for students), yearly class import,
        // appreciation events, talent restore.
        'account.email_bound' => 'Email dihubungkan',
        'account.initial_password_changed' => 'Kata sandi awal diganti',
        'activation.password_kept' => 'Kata sandi dari sekolah tetap dipakai',
        'appreciation.event_deleted' => 'Acara apresiasi dihapus',
        'appreciation.released' => 'Prestasi dilepas dari acara',
        'student.class_changed' => 'Kelas siswa diperbarui',
        'student.phone_updated' => 'No HP siswa diperbarui',
        'talent.restored' => 'Pengajuan talenta dipulihkan',
        'talent.review_requested' => 'Siswa minta ditinjau ulang',
        'audit.exported' => 'Log audit di-download',
        'competition.created' => 'Lomba dibuat',
        'competition.submitted' => 'Lomba diusulkan',
        'competition.closed' => 'Lomba ditutup',
        'competition.weights_changed' => 'Bobot lomba diubah',
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
        'registration.rejected' => 'Pendaftaran ditolak',
        'registration.cancelled' => 'Pendaftaran dibatalkan',
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
        'settings.limits_updated' => 'Batas & arsip diubah',
        'settings.signer_updated' => 'Penandatangan diubah',
        'settings.letter_formats_updated' => 'Format nomor surat diubah',
        'settings.public_updated' => 'Halaman publik diubah',
        'signature.replaced' => 'Tanda tangan diganti',
        'signature.removed' => 'Tanda tangan dihapus',
        'student.archive_date_changed' => 'Tanggal arsip diubah',
        'student.consent_updated' => 'Persetujuan publikasi diubah',
        'student.import' => 'Impor siswa',
        'talent.exported' => 'Data talenta di-download',
        'two_factor.enabled' => '2FA mulai disiapkan',
        'two_factor.confirmed' => '2FA diaktifkan',
        'two_factor.disabled' => '2FA dimatikan',
        'two_factor.recovery_codes_regenerated' => 'Kode pemulihan 2FA dibuat ulang',
        'weight.published' => 'Bobot poin diubah',
        'weight.recomputed' => 'Poin dihitung ulang',
        'created' => 'Data dibuat',
        'updated' => 'Data diubah',
        'deleted' => 'Data dihapus',
    ];

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
            'reason' => is_scalar($properties['reason'] ?? null) ? (string) $properties['reason'] : null,
            'properties' => $properties !== [] ? $json($properties) : null,
            'changes' => $changes !== [] ? $json($changes) : null,
        ];
    }

    /** @return array<string, string> '' => all, 'sistem', then staff accounts that appear in the log */
    private function actorOptions(): array
    {
        $ids = Activity::query()->where('causer_type', (new User)->getMorphClass())->distinct()->pluck('causer_id');

        $staff = User::withTrashed()->staff()->whereKey($ids)->orderBy('name')->get(['id', 'name', 'username'])
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

    private function normalize(): void
    {
        foreach ($this->filters() as $key => $value) {
            $this->{$key} = $value;
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
