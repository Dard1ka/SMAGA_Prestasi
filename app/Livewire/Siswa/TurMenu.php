<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * First-run menu tour (UI-MAP #6/#7, boards Tur-Menu-Desktop + Tur-Menu-Mobile), embedded in
 * Siswa\Beranda. Shows while users.tour_seen_at is null, or when forced (/siswa/tur → ?tur=1, e.g. from
 * Bantuan). "Lewati" and "Mulai" store tour_seen_at. Desktop tips point at the sidebar (5 tips),
 * mobile tips at the tab bar (4 tips); both share one step counter: 0 welcome · 1–5 tips · 6 done.
 * Spotlights are replicas drawn above the scrim at the menu item's position (no page scripts).
 */
class TurMenu extends Component
{
    public const DONE = 6;

    public const DESKTOP_TIPS = 5;

    public const MOBILE_TIPS = 4;

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
            'firstName' => strtok(trim((string) auth()->user()?->name), ' ') ?: 'kamu',
            'desktop' => self::desktopTips(),
            'mobile' => self::mobileTips(),
        ]);
    }

    /**
     * Sidebar targets. `top` = the item's top edge in the 264px sticky sidebar (px from the viewport top);
     * classes are literal so Tailwind picks them up.
     *
     * @return array<int, array<string, string>>
     */
    public static function desktopTips(): array
    {
        return [
            1 => ['title' => 'Cari lomba', 'text' => 'Cari lomba dari sekolah dan daftar di sini.', 'icon' => 'search', 'label' => 'Cari lomba', 'cta' => '1', 'spot' => 'top-[80px]', 'pop' => 'top-[60px]'],
            2 => ['title' => 'Lomba saya', 'text' => 'Pantau status, download surat tugas, dan laporkan hasil.', 'icon' => 'clipboard', 'label' => 'Lomba saya', 'cta' => '', 'spot' => 'top-[269px]', 'pop' => 'top-[249px]'],
            3 => ['title' => 'Portofolio', 'text' => 'Semua prestasi dan sertifikatmu.', 'icon' => 'award', 'label' => 'Portofolio', 'cta' => '', 'spot' => 'top-[317px]', 'pop' => 'top-[297px]'],
            4 => ['title' => 'Peringkat', 'text' => 'Lihat poin dan posisimu.', 'icon' => 'bars', 'label' => 'Peringkat', 'cta' => '', 'spot' => 'top-[365px]', 'pop' => 'top-[345px]'],
            5 => ['title' => 'Profil talenta', 'text' => 'Atur minat dan bakatmu.', 'icon' => 'user', 'label' => 'Profil talenta', 'cta' => '', 'spot' => 'top-[455px]', 'pop' => 'top-[435px]'],
            self::DONE => ['title' => 'Siap dipakai!', 'text' => 'Tour ini bisa dibuka lagi di menu Bantuan.', 'icon' => 'help', 'label' => 'Bantuan', 'cta' => '', 'spot' => 'top-[503px]', 'pop' => 'top-[394px]'],
        ];
    }

    /**
     * Tab bar targets (5 equal columns, 6px side padding). `spot` places the replica over the tab,
     * `arrow` points the popover's arrow at its centre.
     *
     * @return array<int, array<string, string>>
     */
    public static function mobileTips(): array
    {
        return [
            1 => ['title' => 'Lomba', 'text' => 'Cari lomba dari sekolah dan daftar di sini.', 'icon' => 'search', 'label' => 'Lomba', 'spot' => 'left-[calc(6px+(100vw-12px)/5)]', 'arrow' => 'left-[calc(6px+(100vw-12px)*3/10-24px)]'],
            2 => ['title' => 'Lombaku', 'text' => 'Pantau status, download surat tugas, dan laporkan hasil.', 'icon' => 'clipboard', 'label' => 'Lombaku', 'spot' => 'left-[calc(6px+(100vw-12px)*2/5)]', 'arrow' => 'left-[calc(6px+(100vw-12px)/2-24px)]'],
            3 => ['title' => 'Peringkat', 'text' => 'Lihat poin dan posisimu.', 'icon' => 'bars', 'label' => 'Peringkat', 'spot' => 'left-[calc(6px+(100vw-12px)*3/5)]', 'arrow' => 'left-[calc(6px+(100vw-12px)*7/10-24px)]'],
            4 => ['title' => 'Profil', 'text' => 'Portofolio prestasi dan data talentamu.', 'icon' => 'user', 'label' => 'Profil', 'spot' => 'left-[calc(6px+(100vw-12px)*4/5)]', 'arrow' => 'left-[calc(6px+(100vw-12px)*9/10-24px)]'],
        ];
    }
}
