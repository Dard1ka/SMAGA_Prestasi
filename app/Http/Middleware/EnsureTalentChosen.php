<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Student;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `talent.chosen`, last in the group `area.siswa` (after `activated` and `role:siswa`, so a student who still
 * has to activate reaches `aktivasi` first and a staff account never gets here). D-144 (FS1-E), widened by D-153: a
 * student of any class with no talent choice (a deleted one does not count) is held on Profil saya › Talenta saya,
 * which shows ALERT, until one is chosen; "Lainnya" with an own text is always possible. A student who just finished
 * the activation lands there first; students who were already active are held at their next page, on purpose.
 *
 * Open on the way: Talenta saya, Bantuan, Profil saya (Data diri, Keamanan akun), Notifikasi (and its counter) and logout (EXEMPT_ROUTES;
 * the last four are outside `area.siswa` today, listed so a later move of a route cannot lock the student in), and
 * every Livewire update. Livewire updates never pass through `area.siswa`: Livewire only re-runs its persistent
 * middleware, and this one is not; the route-name check is a second guard. The first-run tour lives on Beranda, so
 * it simply waits until the student is let through.
 */
final class EnsureTalentChosen
{
    public const ALERT = 'Pilih minimal satu talenta untuk melanjutkan. Belum punya? Pilih Lainnya lalu tulis minatmu.';

    /** Session key: path + query of the last page the gate held (read once by ProfilTalenta::daftar()). */
    public const INTENDED = 'sth.talent_gate_intended';

    /** @var list<string> */
    public const EXEMPT_ROUTES = ['siswa.talenta', 'siswa.bantuan', 'akun.profil', 'akun.keamanan', 'notifikasi.index', 'notifikasi.jumlah', 'logout', 'logout.get'];

    /**
     * Is $user a student who may log in and has no talent choice? One query. Also drives the alert. Since D-153 every
     * class (X, XI, XII); before, kelas X only (D-144).
     */
    public static function applies(User $user): bool
    {
        if (! $user->canLogIn()) {
            return false;
        }

        return Student::query()
            ->where('user_id', $user->getKey())
            ->whereDoesntHave('talentApplications')
            ->exists();
    }

    public function handle(Request $request, Closure $next): Response
    {
        $route = (string) $request->route()?->getName();

        if (in_array($route, self::EXEMPT_ROUTES, true) || str_ends_with($route, 'livewire.update')) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user instanceof User || ! self::applies($user)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, self::ALERT);
        }

        // FS1 fix wave: remember the page that was held (an invitation link from a mail, a lomba), so the first talent
        // chosen on Profil talenta leads back there (ProfilTalenta::daftar()). Path and query only, never a host.
        if ($request->isMethod('GET') && $request->hasSession()) {
            $request->session()->put(self::INTENDED, $request->getRequestUri());
        }

        return redirect()->route('siswa.talenta');
    }
}
