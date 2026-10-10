<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Models\User;
use App\Support\Navigation;
use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Notifikasi (`notifikasi.index`, every role, docs/UI-MAP.md §2, B1): the signed-in user's own database
 * notifications (bell in the top bar), newest first, filter Semua / Belum dibaca, mark one or all as read,
 * open the target. Another user's notification id → 404. Targets are followed only when they point at this
 * application (path + query of the stored URL; anything else is ignored), so no open redirect.
 *
 * D-127: every render tells the bell of the top bar the unread count (browser event `sth-badges`, key
 * Navigation::BELL), so neither "Tandai dibaca" nor "Tandai semua dibaca" needs a page reload. The view's root
 * carries `data-sth-refresh-on`, so app.js refreshes this list when a background check sees the count change.
 */
#[Title('Notifikasi')]
#[Layout('layouts::app', ['subtitle' => 'Kabar terbaru untuk akun ini.'])]
final class Notifikasi extends Component
{
    use WithPagination;

    public const PER_PAGE = 15;

    #[Url(as: 'filter', except: 'semua')]
    public string $filter = 'semua';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter === 'belum' ? 'belum' : 'semua';
        $this->resetPage();
    }

    public function open(string $id): mixed
    {
        $notification = $this->find($id);
        $notification->markAsRead();

        $target = self::internalTarget($notification->data['url'] ?? null);

        if ($target !== null) {
            return $this->redirect($target);
        }

        return $this->redirectRoute('notifikasi.index', $this->filter === 'belum' ? ['filter' => 'belum'] : []);
    }

    public function markRead(string $id): void
    {
        $this->find($id)->markAsRead();
    }

    public function markAllRead(): void
    {
        $this->user()->unreadNotifications()->update(['read_at' => now()]);

        // No page reload any more (D-127): render() tells the bell the new count, like after "Tandai dibaca".
        $this->resetPage();
        $this->dispatch('sth-toast', message: 'Semua notifikasi ditandai sudah dibaca.', tone: 'success');
    }

    public function render(): View
    {
        $user = $this->user();
        $query = fn () => $user->notifications()->when($this->filter === 'belum', fn ($q) => $q->whereNull('read_at'));

        $notifications = $query()->paginate(self::PER_PAGE);

        // QA10: marking the last unread row of the last "Belum dibaca" page read left an empty page ("Tidak ada
        // notifikasi baru", no pager) while other pages still had rows. Show the last page that has rows.
        if ($notifications->isEmpty() && $notifications->currentPage() > 1) {
            $this->setPage($notifications->lastPage());
            $notifications = $query()->paginate(self::PER_PAGE);
        }

        $unreadCount = $user->unreadNotifications()->count();

        // The bell belongs to the layout, which Livewire renders once per page load: tell it the count on every
        // render, so it follows "Tandai dibaca", "Tandai semua dibaca" and a refresh without a reload (D-127, app.js).
        $this->dispatch('sth-badges', badges: [Navigation::BELL => $unreadCount]);

        return view('livewire.akun.notifikasi', [
            'notifications' => $notifications,
            'items' => collect($notifications->items())->map(fn (DatabaseNotification $n): array => [
                'id' => (string) $n->getKey(),
                'title' => self::text($n->data['title'] ?? null) ?? 'Notifikasi',
                'body' => self::text($n->data['message'] ?? $n->data['body'] ?? null),
                'unread' => $n->read_at === null,
                'hasTarget' => self::internalTarget($n->data['url'] ?? null) !== null,
                'at' => $n->created_at,
            ]),
            'unreadCount' => $unreadCount,
            'totalCount' => $user->notifications()->count(),
        ]);
    }

    /**
     * Path (+ query) of a stored notification URL when it belongs to this application, else null.
     * Accepts absolute URLs on the app host or the current request host, and relative paths ("/siswa/…").
     */
    public static function internalTarget(mixed $url): ?string
    {
        if (! is_string($url) || $url === '' || mb_strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $path = $parts['path'] ?? '/';

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        if (isset($parts['host']) || isset($parts['scheme'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $allowed = array_filter([
                strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
                strtolower(request()->getHost()),
            ]);

            if (! in_array($scheme, ['http', 'https'], true) || ! in_array($host, $allowed, true)) {
                return null;
            }
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function find(string $id): DatabaseNotification
    {
        /** @var DatabaseNotification|null $notification */
        $notification = $this->user()->notifications()->whereKey($id)->first();

        abort_if($notification === null, 404);

        return $notification;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
