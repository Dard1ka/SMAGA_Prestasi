{{-- Aktivasi akun (urutan keputusan lead 2026-10-04): 1 email → 2 kode → (siswa: 3 data diri, D-152) → kata sandi →
     selesai. Setiap akun wajib mengganti kata sandinya (D-138; D-92 berlaku untuk semua): tidak ada "Lewati".
     $steps = label per langkah (siswa 4, staf 3), $position = urutan langkah yang terbuka (0-based). --}}
<div class="flex flex-col gap-6">
    @if ($step !== \App\Livewire\Auth\Activation::STEP_DONE)
        <div class="flex flex-col gap-2">
            <p class="text-sm font-semibold muted">Langkah {{ $position + 1 }} dari {{ count($steps) }}</p>
            <ol @class(['grid gap-2', 'grid-cols-4' => count($steps) === 4, 'grid-cols-3' => count($steps) !== 4]) aria-label="Langkah aktivasi">
                @foreach (array_values($steps) as $i => $label)
                    <li @class([
                        'h-1.5 rounded-full',
                        'bg-primary' => $i <= $position,
                        'bg-track' => $i > $position,
                    ]) @if ($i === $position) aria-current="step" @endif>
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
            <p class="muted">Untuk memulihkan akun kalau lupa kata sandi.</p>
        </div>

        <div class="flex items-center gap-3">
            <x-sth.avatar :name="$user->name" />
            <div class="min-w-0">
                <p class="font-semibold">{{ $user->name }}</p>
                @if ($user->student)
                    <p class="text-sm muted">NISN {{ $user->student->nisn }} · {{ $user->student->classroom?->name }}</p>
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
                {{-- wire:poll keeps the server's count and shows the button at 0; data-sth-countdown ticks every second (QA5). --}}
                <p class="text-sm muted" wire:poll.5s data-sth-countdown="{{ $resendIn }}">Kirim ulang dalam <span class="num" data-sth-countdown-text>{{ intdiv($resendIn, 60) }}:{{ str_pad((string) ($resendIn % 60), 2, '0', STR_PAD_LEFT) }}</span></p>
            @else
                <button type="button" class="lnk" wire:click="resendCode">Kirim ulang kode</button>
            @endif
            <button type="button" class="lnk" wire:click="changeEmail">Ganti email</button>
        </div>
    @elseif ($step === \App\Livewire\Auth\Activation::STEP_PROFILE)
        {{-- D-152: siswa wajib mengisi tanggal lahir + No HP sebelum kata sandi baru; tanggal lahir terisi dari data
             sekolah dan boleh dibetulkan. Aturan di App\Domain\Accounts\StudentProfileInput. --}}
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Data diri</h1>
            <p class="muted">Email {{ $sentTo }} sudah terhubung. Periksa tanggal lahirmu dan isi No HP yang aktif.</p>
        </div>

        <div class="flex items-center gap-3">
            <x-sth.avatar :name="$user->name" />
            <div class="min-w-0">
                <p class="font-semibold">{{ $user->name }}</p>
                @if ($user->student)
                    <p class="text-sm muted">NISN {{ $user->student->nisn }} · {{ $user->student->classroom?->name }}</p>
                @endif
            </div>
        </div>

        <form wire:submit="saveProfile" class="flex flex-col gap-4" novalidate>
            <x-sth.input
                wire:model="birth_date"
                type="date"
                label="Tanggal lahir"
                :min="$birthDateMin"
                :max="$birthDateMax"
                hint="Diisi dari data sekolah. Betulkan kalau salah."
                autocomplete="bday"
                size="lg"
            />
            <x-sth.input
                wire:model="phone"
                type="tel"
                label="No HP"
                hint="Contoh 081234567890. Dipakai sekolah untuk menghubungimu soal lomba."
                inputmode="tel"
                autocomplete="tel"
                size="lg"
            />
            <x-sth.button type="submit" size="lg" block wire:loading.attr="disabled">Simpan data diri</x-sth.button>
        </form>
    @elseif ($step === 3)
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Buat kata sandi baru</h1>
            @if ($isStudent && ! $temporaryPassword)
                <p class="muted">Email {{ $sentTo }} sudah terhubung dan data dirimu tersimpan. Ganti kata sandi dari sekolah dengan kata sandi milikmu sendiri.</p>
            @else
                <p class="muted">Email {{ $sentTo }} sudah terhubung. Ganti kata sandi sementara dari Admin.</p>
            @endif
        </div>

        <form wire:submit="savePassword" class="flex flex-col gap-4" novalidate>
            <x-sth.input
                wire:model="password"
                type="password"
                label="Kata sandi baru"
                :hint="$passwordHint"
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

    @else
        <div class="flex flex-col gap-2">
            <h1 class="title-page">Akun sudah aktif</h1>
            <p class="muted">
                @if ($talentFirst)
                    {{-- D-153: every student without a talent goes to Profil saya › Talenta saya first. --}}
                    Pilih minimal satu talenta dulu di Talenta saya (menu Profil saya). Setelah itu kamu bisa daftar lomba dan pantau prestasimu.
                @elseif ($isStudent)
                    Sekarang kamu bisa daftar lomba dan pantau prestasimu.
                @else
                    Aktivasi selesai. Lanjut ke halaman kerja.
                @endif
            </p>
        </div>

        <ul class="flex flex-col gap-2">
            <li class="flex items-center gap-2"><x-sth.icon name="check-circle" :size="18" /> Email {{ $sentTo }} terhubung</li>
            @if ($isStudent)
                <li class="flex items-center gap-2"><x-sth.icon name="check-circle" :size="18" /> Data diri tersimpan</li>
            @endif
            {{-- D-138: every activation ends with a new password (no "Lewati"), so there is no kept-password line. --}}
            <li class="flex items-center gap-2"><x-sth.icon name="check-circle" :size="18" /> Kata sandi baru tersimpan</li>
        </ul>

        @if ($isStudent)
            <x-sth.alert>Lain kali kamu bisa masuk pakai NISN atau email ini.</x-sth.alert>
        @endif

        <x-sth.button size="lg" block wire:click="start">Mulai</x-sth.button>
    @endif

    {{-- QA5 (Q1-11): a plain POST form, so the account can be left on a shared school computer while activating.
         Not on the finished screen: the account is active there and "Mulai" leads on (the top bar has Keluar). --}}
    @if ($step !== \App\Livewire\Auth\Activation::STEP_DONE)
        <form method="POST" action="{{ route('logout') }}" class="flex flex-wrap items-center justify-center gap-x-2 border-t border-line pt-4 text-sm">
            @csrf
            <span class="muted">Salah akun?</span>
            <x-sth.button type="submit" variant="quiet" size="sm" icon="logout">Keluar</x-sth.button>
        </form>
    @endif
</div>
