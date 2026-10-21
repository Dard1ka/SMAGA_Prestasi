{{-- Tab "Email": status only. The OTP test flag lives in the environment (local only), SMTP is set by the deploy team. --}}
<section aria-labelledby="h-email" class="flex flex-col gap-5">
    <x-sth.page-header id="h-email" title="Email" subtitle="Kode 6 digit dikirim saat siswa menghubungkan email." />

    <x-sth.card as="section" aria-labelledby="h-otp" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="h-otp" class="title-card">Mode uji OTP</h3>
            @if ($testMode)
                <x-sth.chip tone="orange" icon="alert">Menyala · semua kode diterima</x-sth.chip>
            @else
                <x-sth.chip tone="green" icon="check">Mati · kode asli lewat email</x-sth.chip>
            @endif
        </div>
        <p class="text-sm muted">
            Hanya bisa menyala di komputer pengembang (APP_ENV=local). Aplikasi menolak berjalan bila mode ini menyala di server.
            Tidak bisa diubah dari sini.
        </p>
    </x-sth.card>

    <x-sth.card as="section" aria-labelledby="h-smtp" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h3 id="h-smtp" class="title-card">Server email (SMTP)</h3>
            @if ($mailerReady)
                <x-sth.chip tone="green" icon="check">Terhubung</x-sth.chip>
            @else
                <x-sth.chip tone="gray">Belum diatur</x-sth.chip>
            @endif
        </div>
        <p class="text-sm muted">Diatur tim deploy. Hubungi mereka untuk mengubah server atau alamat pengirim.</p>
        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm font-semibold muted">Email pengirim</dt>
                <dd class="num">{{ $fromAddress !== '' ? $fromAddress : '–' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-semibold muted">Nama pengirim</dt>
                <dd>{{ $fromName !== '' ? $fromName : '–' }}</dd>
            </div>
        </dl>
    </x-sth.card>
</section>
