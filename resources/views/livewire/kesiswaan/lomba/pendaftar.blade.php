{{-- SV8, D-198: the registrations of one lomba with their guru pembimbing (child of Kelola lomba, opened by "N siswa"),
     and the question "Isi / Ganti guru pembimbing" (D-128). Data prepared in App\Livewire\Kesiswaan\Lomba\Pendaftar. --}}
<div>
    <x-sth.modal :id="\App\Livewire\Kesiswaan\Lomba\Pendaftar::DIALOG" size="lg" title="Pendaftar lomba"
        description="Guru pembimbing bisa diisi atau diganti sampai laporan hasil dikirim.">
        @if ($lomba !== null)
            <div class="flex flex-col gap-3">
                <p class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $lomba }}</p>
                @if ($rows === [])
                    <p class="m-0 text-md text-muted">Belum ada pendaftaran yang berjalan untuk lomba ini.</p>
                @else
                    <ul class="m-0 flex list-none flex-col gap-2 p-0" aria-label="Pendaftaran lomba ini">
                        @foreach ($rows as $row)
                            <li class="flex flex-col gap-2 rounded-tile border border-line px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between" wire:key="pendaftar-{{ $row['id'] }}">
                                <div class="flex min-w-0 flex-col gap-1 [overflow-wrap:anywhere]">
                                    <span class="text-sm font-semibold">{{ $row['who'] }}</span>
                                    <span class="text-xs text-muted">
                                        Guru pembimbing: {{ $row['mentor'] ?? 'belum ada' }}
                                    </span>
                                    <x-sth.chip :tone="$row['status'][0]" size="sm" class="self-start">{{ $row['status'][1] }}</x-sth.chip>
                                </div>
                                <div class="flex shrink-0 items-center">
                                    @if ($row['canChange'])
                                        <x-sth.button variant="soft" size="sm" wire:click="askMentor('{{ $row['id'] }}')" aria-haspopup="dialog">
                                            {{ $row['mentor'] ? 'Ganti guru pembimbing' : 'Isi guru pembimbing' }}
                                        </x-sth.button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    @if ($more)
                        <div class="flex justify-center">
                            <x-sth.button variant="quiet" size="sm" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
                        </div>
                    @endif
                @endif
            </div>
        @else
            <p class="m-0 text-md text-muted">Lomba tidak ditemukan. Tutup lalu coba lagi.</p>
        @endif
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Tutup</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>

    {{-- D-128 / D-134: the question names who is told; never the guru who is replaced; Tata Usaha only when a guru letter exists. --}}
    <x-sth.modal :id="\App\Livewire\Kesiswaan\Lomba\Pendaftar::MENTOR_DIALOG" size="sm" :title="$mentorDialog['title'] ?? 'Ganti guru pembimbing'"
        description="Surat tugas nanti memakai guru pembimbing ini.">
        <form id="ganti-guru-pembimbing-form" wire:submit="changeMentor" class="flex flex-col gap-3" novalidate>
            @if ($mentorDialog)
                <div class="rounded-tile bg-subtle px-3 py-2.5">
                    <p class="m-0 font-semibold [overflow-wrap:anywhere]">{{ $lomba }}</p>
                    <p class="m-0 text-xs text-muted [overflow-wrap:anywhere]">{{ $mentorDialog['who'] }}</p>
                </div>
                <p class="m-0 text-md [overflow-wrap:anywhere]">
                    @if ($mentorDialog['current'])
                        Guru pembimbing sekarang: <span class="font-semibold">{{ $mentorDialog['current'] }}</span>
                    @else
                        Pendaftaran ini belum punya guru pembimbing.
                    @endif
                </p>
                @if ($mentorDialog['options'] !== [])
                    <x-sth.select wire:model="mentorChoice" id="guru-pembimbing-kesiswaan" :label="$mentorDialog['current'] ? 'Guru pembimbing pengganti' : 'Guru pembimbing'"
                                  placeholder="Pilih guru" :options="$mentorDialog['options']" />
                    <ul class="m-0 flex list-disc flex-col gap-1 pl-5 text-sm text-ink-2">
                        @foreach ($mentorDialog['notices'] as $notice)
                            <li class="[overflow-wrap:anywhere]">{{ $notice }}</li>
                        @endforeach
                    </ul>
                @else
                    <x-sth.alert tone="warning">Belum ada akun guru lain yang bisa dipilih.</x-sth.alert>
                @endif
            @else
                <p class="m-0 text-md text-muted">Tidak ada pendaftaran yang dipilih. Tutup lalu coba lagi.</p>
            @endif
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            @if ($mentorDialog && $mentorDialog['options'] !== [])
                <x-sth.button type="submit" form="ganti-guru-pembimbing-form" icon="check" wire:loading.attr="disabled" wire:target="changeMentor">Tetapkan guru</x-sth.button>
            @endif
        </x-slot:footer>
    </x-sth.modal>
</div>
