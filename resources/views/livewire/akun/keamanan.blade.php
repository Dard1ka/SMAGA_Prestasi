{{-- Akun › Keamanan (no board; UI-MAP §2). Data and rules in App\Livewire\Akun\Keamanan + App\Actions\Accounts\*. --}}
<div class="grid grid-cols-1 items-start gap-[22px] xl:grid-cols-2">
    <div class="flex flex-col gap-[22px]">
        {{-- Kata sandi --}}
        <x-sth.card as="section" aria-labelledby="h-sandi" class="flex flex-col gap-4">
            <x-sth.page-header id="h-sandi" title="Kata sandi" :subtitle="$passwordChangedAt ? 'Terakhir diganti '.$passwordChangedAt->translatedFormat('j M Y H:i').'.' : 'Ganti secara berkala dan jangan dipakai di situs lain.'" />
            <form wire:submit="changePassword" class="flex flex-col gap-4" novalidate>
                <x-sth.input wire:model="currentPassword" type="password" label="Kata sandi sekarang" autocomplete="current-password" revealable />
                <x-sth.input
                    wire:model="password"
                    type="password"
                    label="Kata sandi baru"
                    :hint="$minLength !== null
                        ? 'Minimal '.$minLength.' karakter, ada huruf dan angka. Jangan pakai tanggal lahir, NIS/NISN, atau nama.'
                        : 'Pakai kata sandi panjang yang hanya kamu tahu.'"
                    autocomplete="new-password"
                    revealable
                />
                <x-sth.input wire:model="password_confirmation" type="password" label="Ulangi kata sandi baru" autocomplete="new-password" revealable />
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="m-0 text-[13.5px] text-muted">Perangkat lain akan dikeluarkan.</p>
                    <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="changePassword">Ganti kata sandi</x-sth.button>
                </div>
            </form>
        </x-sth.card>

        {{-- Email --}}
        <x-sth.card as="section" aria-labelledby="h-email" class="flex flex-col gap-4">
            <x-sth.page-header id="h-email" title="Email" subtitle="Untuk memulihkan akun dan menerima pemberitahuan." />
            <div class="flex flex-wrap items-center gap-2.5">
                <x-sth.icon name="mail" class="text-muted" />
                @if ($email)
                    <span class="font-semibold">{{ $email }}</span>
                    @if ($emailVerified)
                        <x-sth.chip tone="green" size="sm" icon="check">Terverifikasi</x-sth.chip>
                    @else
                        <x-sth.chip tone="orange" size="sm">Belum terverifikasi</x-sth.chip>
                    @endif
                @else
                    <span class="text-muted">Belum ada email</span>
                @endif
            </div>

            @if ($codeSentTo === null)
                <form wire:submit="sendEmailCode" class="flex flex-col gap-4" novalidate>
                    <x-sth.input wire:model="newEmail" type="email" label="Email baru" hint="Kode 6 angka dikirim ke email ini." autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false" />
                    <x-sth.input wire:model="emailPassword" type="password" label="Kata sandi" hint="Untuk memastikan ini kamu." autocomplete="current-password" revealable />
                    <div class="flex justify-end">
                        <x-sth.button type="submit" variant="soft" wire:loading.attr="disabled" wire:target="sendEmailCode">Kirim kode</x-sth.button>
                    </div>
                </form>
            @else
                <form wire:submit="confirmEmail" class="flex flex-col gap-4" novalidate>
                    <p class="m-0 text-[14px]">Kode 6 angka dikirim ke <strong>{{ $codeSentTo }}</strong>. Berlaku 10 menit.</p>
                    @if ($testMode)
                        <x-sth.alert tone="warning">Mode uji: kode apa pun diterima.</x-sth.alert>
                    @endif
                    <x-sth.input wire:model="emailCode" label="Kode verifikasi" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]*" />
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <button type="button" class="lnk" wire:click="cancelEmailChange">Batal ganti email</button>
                        <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="confirmEmail">Simpan email</x-sth.button>
                    </div>
                </form>
            @endif
            <p class="m-0 text-[13px] text-muted">Email lama dan email baru mendapat pemberitahuan dengan tautan "Bukan saya?".</p>
        </x-sth.card>
    </div>

    <div class="flex flex-col gap-[22px]">
        {{-- Perangkat --}}
        <x-sth.card as="section" aria-labelledby="h-perangkat" class="flex flex-col gap-4">
            <x-sth.page-header id="h-perangkat" title="Perangkat yang masuk" subtitle="Keluarkan perangkat yang tidak kamu kenal." />
            @if (! $sessionsAvailable)
                <x-sth.alert>Daftar perangkat tidak tersedia di server ini.</x-sth.alert>
            @elseif ($sessions === [])
                <p class="m-0 text-[13.5px] text-muted">Tidak ada perangkat lain.</p>
            @else
                <ul class="flex flex-col divide-y divide-line" aria-label="Perangkat">
                    @foreach ($sessions as $session)
                        <li wire:key="sesi-{{ $session['key'] }}" class="flex flex-wrap items-center gap-3 py-3 first:pt-0 last:pb-0">
                            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-tile bg-subtle text-muted"><x-sth.icon name="user" /></span>
                            <div class="flex min-w-0 flex-1 flex-col">
                                <span class="font-semibold">{{ $session['device'] }}</span>
                                <span class="text-[12.5px] text-muted">
                                    @if ($session['ip'])<span class="num">{{ $session['ip'] }}</span> · @endif
                                    aktif {{ $session['lastActive']->diffForHumans() }}
                                </span>
                            </div>
                            @if ($session['current'])
                                <x-sth.chip tone="blue" size="sm">Perangkat ini</x-sth.chip>
                            @else
                                <x-sth.button variant="quiet" size="sm" wire:click="revokeSession('{{ $session['key'] }}')" wire:loading.attr="disabled">Keluarkan</x-sth.button>
                            @endif
                        </li>
                    @endforeach
                </ul>
                @if (count($sessions) > 1)
                    <div class="flex justify-end">
                        <x-sth.button variant="danger" size="sm" icon="logout" wire:click="revokeOtherSessions" wire:loading.attr="disabled">Keluarkan semua perangkat lain</x-sth.button>
                    </div>
                @endif
            @endif
        </x-sth.card>

        @if ($isStaff)
            {{-- Verifikasi dua langkah (staf) --}}
            <x-sth.card as="section" aria-labelledby="h-2fa" class="flex flex-col gap-4">
                <x-sth.page-header id="h-2fa" title="Verifikasi dua langkah" subtitle="Kode dari aplikasi autentikator setiap kali masuk." />
                <div class="flex flex-wrap items-center justify-between gap-3">
                    @if ($twoFactorConfirmed)
                        <x-sth.chip tone="green" icon="check">Aktif</x-sth.chip>
                    @else
                        <x-sth.chip tone="orange">Belum aktif</x-sth.chip>
                    @endif
                    @if ($twoFactorUrl)
                        <x-sth.button variant="soft" size="sm" icon="shield" :href="$twoFactorUrl">{{ $twoFactorConfirmed ? 'Kelola 2FA' : 'Pasang 2FA' }}</x-sth.button>
                    @endif
                </div>
            </x-sth.card>
        @endif

        <x-sth.alert title="Ada yang mencurigakan?">Jika ada perangkat atau perubahan yang bukan kamu, ganti kata sandi lalu hubungi Admin atau Kesiswaan.</x-sth.alert>
    </div>
</div>
