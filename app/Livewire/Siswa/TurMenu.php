<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Models\User;
use App\Support\FirstName;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * First-run menu tour (UI-MAP #6/#7, boards Tur-Menu-Desktop + Tur-Menu-Mobile), embedded in
 * Siswa\Beranda. Shows while users.tour_seen_at is null, or once after /siswa/tur (e.g. from Bantuan), which
 * sets the session flash FORCE_KEY (before QA5 it was ?tur=1, which brought the tour back on every reload).
 * "Lewati" and "Mulai" store tour_seen_at. Desktop tips point at the sidebar (5 tips),
 * mobile tips at the tab bar (4 tips); both share one step counter: 0 welcome · 1–5 tips · 6 done.
 * Spotlights are replicas drawn above the scrim at the menu item's position (no page scripts).
 */
class TurMenu extends Component
{
    public const DONE = 6;

    public const DESKTOP_TIPS = 5;

    public const MOBILE_TIPS = 4;

    /** Session flash set by /siswa/tur: show the tour on the next page load even when it was seen. */
    public const FORCE_KEY = 'sth.tour_forced';

    #[Locked]
    public bool $show = false;

    public int $step = 0;

    public function mount(bool $paksa = false): void
    {
        $unseen = User::query()->whereKey(auth()->id())->whereNull('tour_seen_at')->exists();

        $this->show = $paksa || $unseen;
    }

    public function mulai(): void
    {
        $this->step = 1;
    }

    /** $tips = number of tips of the variant the user clicked in (desktop 5, mobile 4). */
    public function lanjut(int $tips = self::DESKTOP_TIPS): void
    {
        $tips = min(max($tips, 1), self::DESKTOP_TIPS);
        $this->step = $this->step >= $tips ? self::DONE : $this->step + 1;
    }

    public function kembali(int $tips = self::DESKTOP_TIPS): void
    {
        $tips = min(max($tips, 1), self::DESKTOP_TIPS);
        $this->step = $this->step >= self::DONE ? $tips : max(0, $this->step - 1);
    }

    /** "Lewati" and "Mulai": never show the tour automatically again. */
    public function selesai(): void
    {
        User::query()->whereKey(auth()->id())->update(['tour_seen_at' => now()]);

        $this->show = false;
        $this->step = 0;
        $this->dispatch('sth-modal-close', id: 'tur-menu');
    }

    public function render(): View
    {
        return view('livewire.siswa.tur-menu', [
            'firstName' => FirstName::of(auth()->user()?->name) ?? 'kamu',
            'desktop' => self::desktopTips(),
            'mobile' => self::mobileTips(),
        ]);
    }

    /**
     * Sidebar targets. `spot` = the item's top edge in the 264px sticky sidebar (px from the viewport top), `pop` =
     * spot − 20 so the arrow points at the item's middle. The numbers follow the sidebar's own metrics (brand 40px,
     * gap 18px, section label 28px, items 44px + 4px; components/sth/sidebar + .nav / .nav-sec in app.css): measure
     * again when one of those changes. Since the "Cari lomba" button above the menu is gone (one way to each place),
     * tip 1 points at the menu item. Classes are literal so Tailwind picks them up.
     *
     * @return array<int, array<string, string>>
     */
    public static function desktopTips(): array
    {
        return [
            1 => ['title' => 'Cari lomba', 'text' => 'Cari lomba dari sekolah dan daftar di sini.', 'icon' => 'search', 'label' => 'Cari lomba', 'spot' => 'top-[160px]', 'pop' => 'top-[140px]'],
            2 => ['title' => 'Lomba saya', 'text' => 'Pantau status, download surat tugas, dan laporkan hasil.', 'icon' => 'clipboard', 'label' => 'Lomba saya', 'spot' => 'top-[208px]', 'pop' => 'top-[188px]'],
            3 => ['title' => 'Portofolio', 'text' => 'Semua prestasi dan sertifikatmu.', 'icon' => 'award', 'label' => 'Portofolio', 'spot' => 'top-[256px]', 'pop' => 'top-[236px]'],
            4 => ['title' => 'Peringkat', 'text' => 'Lihat poin dan posisimu.', 'icon' => 'bars', 'label' => 'Peringkat', 'spot' => 'top-[304px]', 'pop' => 'top-[284px]'],
            // D-153: Profil saya (Data diri, Talenta saya, Keamanan akun) is the first item of "Akun", where Profil
            // talenta stood; Bantuan moved up one item (Akun = Profil saya, Notifikasi, Bantuan).
            5 => ['title' => 'Profil saya', 'text' => 'Data diri, talenta, dan keamanan akunmu.', 'icon' => 'user', 'label' => 'Profil saya', 'spot' => 'top-[394px]', 'pop' => 'top-[374px]'],
            self::DONE => ['title' => 'Siap dipakai!', 'text' => 'Tour ini bisa dibuka lagi di menu Bantuan.', 'icon' => 'help', 'label' => 'Bantuan', 'spot' => 'top-[490px]', 'pop' => 'top-[381px]'],
        ];
    }

    /**
     * Tab bar targets (5 equal columns, 6px side padding). `spot` places the replica over the tab,
     * `arrow` points the popover's arrow at its centre. Titles and labels are the tab bar's own names, which
     * are the menu names (Navigation::tabbar()).
     *
     * @return array<int, array<string, string>>
     */
    public static function mobileTips(): array
    {
        return [
            1 => ['title' => 'Lomba', 'text' => 'Cari lomba dari sekolah dan daftar di sini.', 'icon' => 'search', 'label' => 'Lomba', 'spot' => 'left-[calc(6px+(100vw-12px)/5)]', 'arrow' => 'left-[calc(6px+(100vw-12px)*3/10-24px)]'],
            2 => ['title' => 'Lomba saya', 'text' => 'Pantau status, download surat tugas, dan laporkan hasil.', 'icon' => 'clipboard', 'label' => 'Lomba saya', 'spot' => 'left-[calc(6px+(100vw-12px)*2/5)]', 'arrow' => 'left-[calc(6px+(100vw-12px)/2-24px)]'],
            3 => ['title' => 'Peringkat', 'text' => 'Lihat poin dan posisimu.', 'icon' => 'bars', 'label' => 'Peringkat', 'spot' => 'left-[calc(6px+(100vw-12px)*3/5)]', 'arrow' => 'left-[calc(6px+(100vw-12px)*7/10-24px)]'],
            4 => ['title' => 'Portofolio', 'text' => 'Semua prestasi dan sertifikatmu.', 'icon' => 'award', 'label' => 'Portofolio', 'spot' => 'left-[calc(6px+(100vw-12px)*4/5)]', 'arrow' => 'left-[calc(6px+(100vw-12px)*9/10-24px)]'],
        ];
    }
}
