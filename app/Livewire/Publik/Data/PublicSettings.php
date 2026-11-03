<?php

declare(strict_types=1);

namespace App\Livewire\Publik\Data;

use App\Enums\SettingKey;
use App\Models\Setting;

/**
 * Admin switches for the public pages (tab "Halaman publik" in Admin › Pengaturan), stored in `settings`
 * under SettingKey::PublicIndexing / SettingKey::PublicCertificates (both default false, SettingSeeder).
 *
 * - public_indexing: search engines may index the aggregate public pages. Off by default (D-38). Student
 *   pages are always noindex.
 * - public_certificates: show a "Lihat sertifikat" link when every team member is named. Off by default:
 *   certificates often carry NISN/birth date (SECURITY §3m-7); the badge "Terverifikasi" is shown instead.
 */
final class PublicSettings
{
    public static function indexingAllowed(): bool
    {
        return (bool) rescue(fn () => Setting::get(SettingKey::PublicIndexing, false), false, false);
    }

    public static function certificatesAllowed(): bool
    {
        return (bool) rescue(fn () => Setting::get(SettingKey::PublicCertificates, false), false, false);
    }

    /** Value for <meta name="robots"> and X-Robots-Tag on aggregate pages. */
    public static function robots(bool $studentPage = false): string
    {
        return $studentPage || ! self::indexingAllowed() ? 'noindex, nofollow' : 'index, follow';
    }
}
