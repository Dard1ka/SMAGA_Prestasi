{{-- Profil saya › Data diri (D-152; no board, UI-MAP §2). Data and rules in App\Livewire\Akun\Profil +
     App\Actions\Accounts\UpdateStudentProfile. The tab "Keamanan akun" is the page Akun\Keamanan. --}}
<div class="flex flex-col gap-[22px]">
    <x-sth.tabs label="Profil saya" :items="$tabs" active="profil" />

    <div class="grid grid-cols-1 items-start gap-[22px] xl:grid-cols-2">
        <x-sth.card as="section" aria-labelledby="h-akun" class="flex flex-col gap-4">
            <div class="flex items-center gap-3">
                <x-sth.avatar :name="$user->name" size="lg" />
                <div class="min-w-0">
                    <h2 id="h-akun" class="title-card m-0 [overflow-wrap:anywhere]">{{ $user->name }}</h2>
                    <p class="m-0 text-sm text-muted">{{ $student ? 'Siswa'.($student->classroom ? ' · '.$student->classroom->name : '') : ($roles !== '' ? $roles : 'Belum ada peran') }}</p>
                </div>
            </div>

            <dl class="m-0 grid grid-cols-[120px_minmax(0,1fr)] gap-x-3 gap-y-2 text-sm">
                @if ($student)
                    <dt class="text-muted">NISN</dt>
                    <dd class="m-0 font-semibold">{{ $student->nisn }}</dd>
                    <dt class="text-muted">Kelas</dt>
                    <dd class="m-0 font-semibold">{{ $student->classroom?->name ?? '–' }}</dd>
                @else
                    <dt class="text-muted">Peran</dt>
                    <dd class="m-0 font-semibold">{{ $roles !== '' ? $roles : '–' }}</dd>
                    <dt class="text-muted">Nama pengguna</dt>
                    <dd class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $user->username ?: '–' }}</dd>
                @endif
                <dt class="text-muted">Email</dt>
                <dd class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $user->email ?: 'Belum terhubung' }}</dd>
            </dl>

            <p class="m-0 text-xs text-muted">
                @if ($student)
                    Nama, NISN, dan kelas diubah oleh sekolah. Email dan kata sandi ada di tab Keamanan akun.
                @else
                    Nama dan peran diubah oleh Admin. Email dan kata sandi ada di tab Keamanan akun.
                @endif
            </p>
        </x-sth.card>

        @if ($student)
            <x-sth.card as="section" aria-labelledby="h-data-diri" class="flex flex-col gap-4">
                <x-sth.page-header id="h-data-diri" title="Data diri" subtitle="Wajib diisi. Dipakai sekolah untuk data lomba dan untuk menghubungimu." />
                <form wire:submit="save" class="flex flex-col gap-4" novalidate>
                    <x-sth.input
                        wire:model="birth_date"
                        type="date"
                        label="Tanggal lahir"
                        :min="$birthDateMin"
                        :max="$birthDateMax"
                        autocomplete="bday"
                    />
                    <x-sth.input
                        wire:model="phone"
                        type="tel"
                        label="No HP"
                        hint="Contoh 081234567890."
                        inputmode="tel"
                        autocomplete="tel"
                    />
                    <div class="flex justify-end">
                        <x-sth.button type="submit" wire:loading.attr="disabled" wire:target="save">Simpan</x-sth.button>
                    </div>
                </form>
            </x-sth.card>
        @endif
    </div>
</div>
