{{-- Aktivasi akun (urutan keputusan lead 2026-10-04): 1 email → 2 kode → 3 kata sandi (siswa boleh lewati) → selesai. --}}
<div class="flex flex-col gap-6">
    @if ($step < 4)
        <div class="flex flex-col gap-2">
            <p class="text-sm font-semibold muted">Langkah {{ $step }} dari 3</p>
            <ol class="grid grid-cols-3 gap-2" aria-label="Langkah aktivasi">
                @foreach (['Hubungkan email', 'Kode verifikasi', 'Kata sandi'] as $i => $label)
                    <li @class([
                        'h-1.5 rounded-full',
                        'bg-primary' => $i + 1 <= $step,
                        'bg-track' => $i + 1 > $step,
                    ]) @if ($i + 1 === $step) aria-current="step" @endif>
                        <span class="sr-only">{{ $label }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    @if (session('status'))
        <x-sth.alert tone="success">{{ session('status') }}</x-sth.alert>
    @endif

    @if ($step === 1)
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Hubungkan email</h1>
            <p class="muted">Untuk memulihkan akun kalau kamu lupa kata sandi.</p>
        </div>

        <div class="flex items-center gap-3">
            <x-sth.avatar :name="$user->name" />
            <div class="min-w-0">
                <p class="font-semibold">{{ $user->name }}</p>
                @if ($user->student)
                    <p class="text-sm muted">NIS {{ $user->student->nis }} · {{ $user->student->classroom?->name }}</p>
                @endif
            </div>
        </div>

        <form wire:submit="sendCode" class="flex flex-col gap-4" novalidate>
            <x-sth.input
                wire:model="email"
                type="email"
                label="Email"
                hint="Kode verifikasi dikirim ke email ini."
                autocomplete="email"
                inputmode="email"
                autocapitalize="none"
                spellcheck="false"
                size="lg"
            />
            <x-sth.button type="submit" size="lg" block wire:loading.attr="disabled">Kirim kode</x-sth.button>
        </form>
    @elseif ($step === 2)
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Kode verifikasi</h1>
            <p class="muted">Kode 6 angka dikirim ke <strong>{{ $sentTo }}</strong>.</p>
        </div>

        @if ($testMode)
            <x-sth.alert tone="warning">Mode uji: kode apa pun diterima.</x-sth.alert>
        @endif

        <form wire:submit="verifyCode" class="flex flex-col gap-4" novalidate>
            <x-sth.input
                wire:model="code"
                label="Kode verifikasi"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="[0-9]*"
                size="lg"
            />
            <x-sth.button type="submit" size="lg" block wire:loading.attr="disabled">Verifikasi</x-sth.button>
        </form>

        <div class="flex flex-wrap items-center justify-between gap-2">
            @if ($resendIn > 0)
                <p class="text-sm muted" wire:poll.5s>Kirim ulang dalam {{ intdiv($resendIn, 60) }}:{{ str_pad((string) ($resendIn % 60), 2, '0', STR_PAD_LEFT) }}</p>
            @else
                <button type="button" class="lnk" wire:click="resendCode">Kirim ulang kode</button>
            @endif
            <button type="button" class="lnk" wire:click="changeEmail">Ganti email</button>
        </div>
    @elseif ($step === 3)
        <div class="flex flex-col gap-2">
            @if ($canKeepPassword)
                <h1 class="title-page">Kata sandi</h1>
                <p class="muted">Email {{ $sentTo }} sudah terhubung. Ganti kata sandi supaya akunmu lebih aman, atau lewati langkah ini.</p>
            @else
                <h1 class="title-page">Buat kata sandi baru</h1>
                <p class="muted">Email {{ $sentTo }} sudah terhubung. Ganti kata sandi sementara dari Admin.</p>
            @endif
        </div>

        <form wire:submit="savePassword" class="flex flex-col gap-4" novalidate>
            <x-sth.input
                wire:model="password"
                type="password"
                label="Kata sandi baru"
                hint="Minimal {{ $minLength }} karakter, ada huruf dan angka. Jangan pakai tanggal lahir, NIS/NISN, atau nama."
                autocomplete="new-password"
                size="lg"
                revealable
            />
            <x-sth.input
                wire:model="password_confirmation"
                type="password"
                label="Ulangi kata sandi"
                autocomplete="new-password"
                size="lg"
                revealable
            />
            <x-sth.button type="submit" size="lg" block wire:loading.attr="disabled">Ganti kata sandi</x-sth.button>
        </form>

        @if ($canKeepPassword)
            <div class="flex flex-col gap-2">
                <x-sth.button variant="quiet" size="lg" block wire:click="keepPassword" wire:loading.attr="disabled">Lewati, pakai kata sandi sekarang</x-sth.button>
                <p class="text-sm muted">Kalau dilewati, kamu bisa ganti kata sandi kapan saja di menu Keamanan akun.</p>
            </div>
        @endif
    @else
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Akun kamu sudah aktif</h1>
            <p class="muted">
                @if ($isStudent)
                    Sekarang kamu bisa daftar lomba dan pantau prestasimu.
                @else
                    Aktivasi selesai. Lanjut ke halaman kerja.
                @endif
            </p>
        </div>

        <ul class="flex flex-col gap-2">
            <li class="flex items-center gap-2"><x-sth.icon name="check-circle" :size="18" /> Email {{ $sentTo }} terhubung</li>
            <li class="flex items-center gap-2">
                <x-sth.icon name="check-circle" :size="18" />
                @if ($passwordChanged)
                    Kata sandi baru tersimpan
                @else
                    Kata sandi tetap, tidak diganti
                @endif
            </li>
        </ul>

        @if ($isStudent)
            <x-sth.alert>Lain kali kamu bisa masuk pakai NIS, NISN, atau email ini.</x-sth.alert>
        @endif

        <x-sth.button size="lg" block wire:click="start">Mulai</x-sth.button>
    @endif
</div>
