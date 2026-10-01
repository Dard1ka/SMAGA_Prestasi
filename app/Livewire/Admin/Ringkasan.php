<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Accounts\VerifyOtpCode;
use App\Enums\RoleName;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Models\PrincipalReplacement;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * Admin "Status sistem" (route admin.ringkasan, W2-D addition; the Admin landing admin.beranda is the shared
 * Sekolah\Dashboard, UI-MAP #20): account counts, things that block the school's flow (no signature, no
 * Kepala Sekolah, staff without 2FA, OTP test mode), quick links and the latest Admin audit entries.
 * Aggregate queries only.
 */
#[Title('Status sistem')]
#[Layout('layouts::app', ['subtitle' => 'Ringkasan akun dan pengaturan sekolah.'])]
final class Ringkasan extends Component
{
    /** Audit events shown on the landing, with their Indonesian label. */
    public const EVENTS = [
        'account.staff_created' => 'Akun staf dibuat',
        'account.role_changed' => 'Peran diubah',
        // B5 (backlog #13): privileged role changes need a second Admin or the 24 h delay.
        'role.change_requested' => 'Perubahan peran diminta',
        'role.change_approved' => 'Perubahan peran disetujui',
        'role.change_cancelled' => 'Perubahan peran dibatalkan',
        'account.staff_reset' => 'Akun staf direset',
        'account.reset' => 'Akun siswa direset',
        'account.disabled' => 'Akun dinonaktifkan',
        'account.archived' => 'Akun diarsipkan',
        // B5 (backlog #8): Arsip otomatis + Log audit.
        'account.unarchived' => 'Akun dipulihkan dari arsip',
        'student.archive_date_changed' => 'Tanggal arsip diubah',
        'audit.exported' => 'Log audit diunduh',
        'account.admin_created' => 'Admin dibuat (CLI)',
        'principal.requested' => 'Ganti Kepala Sekolah diminta',
        'principal.cancelled' => 'Ganti Kepala Sekolah dibatalkan',
        'principal.applied' => 'Kepala Sekolah diganti',
        'weight.published' => 'Bobot poin diubah',
        'weight.recomputed' => 'Poin dihitung ulang',
        'settings.limits_updated' => 'Batas & arsip diubah',
        'settings.signer_updated' => 'Penandatangan diubah',
        'settings.letter_formats_updated' => 'Format nomor surat diubah',
        'settings.public_updated' => 'Halaman publik diubah',
        'signature.replaced' => 'Tanda tangan diganti',
        'signature.removed' => 'Tanda tangan dihapus',
    ];

    public function mount(): void
    {
        abort_unless(Gate::allows('viewAny', User::class) || Gate::allows('viewAny', Setting::class), 403);
    }

    public function render(): View
    {
        $today = CarbonImmutable::today();
        $staffWithout2fa = User::query()->staff()->canLogIn()->whereNull('two_factor_confirmed_at')->count();

        $attention = array_values(array_filter([
            Setting::get(SettingKey::SignatureFileId) === null
                ? ['tone' => 'warning', 'text' => 'Belum ada tanda tangan + stempel. TU belum bisa membuat surat tugas.', 'href' => route('admin.pengaturan', ['tab' => 'surat']), 'link' => 'Unggah tanda tangan']
                : null,
            User::query()->role(RoleName::KepalaSekolah->value)->canLogIn()->doesntExist()
                ? ['tone' => 'warning', 'text' => 'Belum ada akun Kepala Sekolah. Pendaftaran lomba tidak bisa disetujui.', 'href' => route('admin.pengaturan', ['tab' => 'pengguna']), 'link' => 'Tetapkan Kepala Sekolah']
                : null,
            ($pending = PrincipalReplacement::query()->pending()->with('toUser:id,name')->first()) !== null
                ? ['tone' => 'info', 'text' => 'Penggantian Kepala Sekolah ke '.$pending->toUser?->name.' berlaku '.$pending->effective_at->translatedFormat('j M Y H:i').'.', 'href' => route('admin.pengaturan', ['tab' => 'pengguna']), 'link' => 'Lihat']
                : null,
            $staffWithout2fa > 0
                ? ['tone' => 'info', 'text' => $staffWithout2fa.' akun staf belum memasang verifikasi dua langkah.', 'href' => route('admin.pengaturan', ['tab' => 'pengguna']), 'link' => 'Lihat staf']
                : null,
            VerifyOtpCode::testModeActive()
                ? ['tone' => 'warning', 'text' => 'Mode uji OTP menyala (hanya komputer pengembang).', 'href' => route('admin.pengaturan', ['tab' => 'email']), 'link' => 'Lihat']
                : null,
        ]));

        return view('livewire.admin.ringkasan', [
            'stats' => [
                'active' => Student::query()->whereHas('user', fn ($q) => $q->where('status', UserStatus::Active))->count(),
                'pending' => Student::query()->whereHas('user', fn ($q) => $q->where('status', UserStatus::PendingActivation))->count(),
                'staff' => User::query()->staff()->canLogIn()->count(),
                'dueSoon' => Student::query()
                    ->whereBetween('archive_at', [$today->toDateString(), $today->addDays(30)->toDateString()])
                    ->whereHas('user', fn ($q) => $q->where('status', '!=', UserStatus::Archived))
                    ->count(),
            ],
            'attention' => $attention,
            'links' => array_values(array_filter([
                Route::has('admin.import') ? ['icon' => 'upload', 'label' => 'Import siswa', 'href' => route('admin.import')] : null,
                ['icon' => 'users', 'label' => 'Pengguna & peran', 'href' => route('admin.pengguna.index')],
                ['icon' => 'sliders', 'label' => 'Pengaturan', 'href' => route('admin.pengaturan')],
                Route::has('kesiswaan.lomba.index') ? ['icon' => 'trophy', 'label' => 'Kelola lomba', 'href' => route('kesiswaan.lomba.index')] : null,
            ])),
            'audit' => Activity::query()
                ->whereIn('event', array_keys(self::EVENTS))
                ->with('causer')
                ->latest('id')
                ->limit(8)
                ->get(),
            'events' => self::EVENTS,
        ]);
    }
}
