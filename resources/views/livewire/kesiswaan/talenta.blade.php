{{-- Talenta siswa (board Kesiswaan-Talenta). Data prepared in App\Livewire\Kesiswaan\Talenta. --}}
<div class="flex flex-col gap-[22px]">
    <section aria-label="Ringkasan tahap" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-sth.stat-tile icon="inbox" tone="gray" label="Terdaftar" :value="(string) $counts['terdaftar']" note="belum dipetakan" />
        <x-sth.stat-tile icon="users" tone="blue" label="Pemetaan" :value="(string) $counts['pemetaan']" note="sedang dipetakan" />
        <x-sth.stat-tile icon="clock" tone="orange" label="Seleksi/asesmen" :value="(string) $counts['seleksi']" note="menunggu hasil" />
        <x-sth.stat-tile icon="award" tone="green" label="Peserta pembinaan" :value="(string) $counts['pembinaan']" note="siap dibina" />
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="seg flex-wrap" role="group" aria-label="Tahap">
            @foreach (['semua' => 'Semua', 'terdaftar' => 'Terdaftar', 'pemetaan' => 'Pemetaan', 'seleksi' => 'Seleksi', 'pembinaan' => 'Peserta pembinaan', 'tidak_lolos' => 'Tidak lolos'] as $key => $label)
                <button type="button" class="seg-btn" wire:click="setTahap('{{ $key }}')" aria-pressed="{{ $tahap === $key ? 'true' : 'false' }}">
                    {{ $label }} <span class="cnt">{{ $counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
        <x-sth.button variant="quiet" size="sm" icon="download" wire:click="export">Download data (Excel)</x-sth.button>
    </div>

    <div class="grid grid-cols-1 items-start gap-[22px] xl:grid-cols-[1fr_340px] 2xl:grid-cols-[1fr_392px]">
        <x-sth.card padding="none" class="flex min-w-0 flex-col">
            <div class="flex flex-col gap-3 border-b border-line p-4">
                <div class="flex flex-wrap gap-2" role="group" aria-label="Jenis talenta">
                    <button type="button" class="fchip h-9 px-3.5 text-[13.5px]" wire:click="setJenis('')" aria-pressed="{{ $jenis === '' ? 'true' : 'false' }}">Semua</button>
                    @foreach ($types as $type)
                        <button type="button" class="fchip h-9 px-3.5 text-[13.5px]" wire:click="setJenis('{{ $type->code->value }}')" aria-pressed="{{ $jenis === $type->code->value ? 'true' : 'false' }}">
                            {{ $type->code->label() }}<span class="sr-only">, {{ $type->bidang->label() }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <x-sth.select wire:model.live="cabang" label="Cabang talenta" size="sm" :placeholder="$branches === [] ? 'Pilih jenis dulu' : 'Semua cabang'" :options="$branches" :disabled="$branches === []" />
                    <x-sth.select wire:model.live="kelas" label="Kelas" size="sm" placeholder="Semua kelas" :options="$classes" />
                    <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari" icon="search" placeholder="Nama atau NIS" />
                </div>
            </div>

            <div class="flex min-h-14 flex-wrap items-center gap-3 border-b border-line bg-subtle px-4 py-2.5" aria-live="polite">
                @if ($checked === [])
                    <span class="text-[13.5px] text-muted">{{ $shownText }} · Centang baris untuk pindah tahap sekaligus.</span>
                @elseif ($bulkRejecting)
                    <form wire:submit="bulkReject" class="flex w-full flex-wrap items-end gap-2">
                        <x-sth.input wire:model="bulkNote" label="Catatan untuk siswa" optional class="min-w-60 flex-1" placeholder="Boleh dikosongkan" maxlength="1000" />
                        <x-sth.button type="submit" variant="danger" size="sm">Kirim · {{ count($checked) }} tidak lolos</x-sth.button>
                        <x-sth.button variant="quiet" size="sm" wire:click="$set('bulkRejecting', false)">Batal</x-sth.button>
                    </form>
                @else
                    <span class="text-[13.5px] font-bold">{{ count($checked) }} dipilih</span>
                    <x-sth.button size="sm" icon="check" wire:click="bulkAdvance" wire:loading.attr="disabled">Pindahkan ke {{ $bulkNext }}</x-sth.button>
                    <x-sth.button variant="danger" size="sm" wire:click="startBulkReject">Tidak lolos</x-sth.button>
                    <x-sth.button variant="quiet" size="sm" wire:click="cancelBulk">Batal pilih</x-sth.button>
                @endif
            </div>

            @if ($rows->isEmpty())
                <div class="p-4">
                    <x-sth.empty-state icon="search" title="Tidak ada pilihan talenta" :card="false">Ubah filter atau tahap.</x-sth.empty-state>
                </div>
            @else
                <x-sth.table caption="Daftar pilihan talenta" :card="false" fixed>
                    <x-slot:head>
                        <th scope="col" class="w-12">
                            <input type="checkbox" class="cek" aria-label="Pilih semua baris yang tampil" wire:click="toggleAll" @checked(count($checked) > 0 && count($checked) >= $rows->count())>
                        </th>
                        <th scope="col">Siswa</th>
                        <th scope="col" class="w-40">Talenta</th>
                        <th scope="col" class="w-20">Prestasi</th>
                        <th scope="col" class="w-40">Status</th>
                        <th scope="col" class="w-24"><span class="sr-only">Aksi</span></th>
                    </x-slot:head>
                    @foreach ($rows as $row)
                        <tr wire:key="t-{{ $row['id'] }}" @class(['bg-primary-wash' => $row['selected']])>
                            <td><input type="checkbox" class="cek" value="{{ $row['id'] }}" wire:model.live="checked" aria-label="Pilih {{ $row['name'] }}, {{ $row['type'] }} {{ $row['branch'] }}"></td>
                            <td>
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-sth.avatar :name="$row['name']" size="sm" />
                                    <div class="flex min-w-0 flex-col">
                                        <span class="ellip font-bold">{{ $row['name'] }}</span>
                                        <span class="text-[12.5px] text-muted">{{ $row['class'] }} · <span class="num">{{ $row['nis'] }}</span></span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="flex flex-col">
                                    <span class="font-bold">{{ $row['type'] }} <span class="font-medium">· {{ $row['branch'] }}</span></span>
                                    <span class="text-[12.5px] text-muted">{{ $row['bidang']?->label() }}{{ $row['hasEvidence'] ? ' · ada bukti' : '' }}</span>
                                </div>
                            </td>
                            <td class="num whitespace-nowrap text-[13.5px]">{{ $row['points'] !== null ? $row['points'].' · '.$row['prestasi'] : '–' }}</td>
                            <td>
                                <div class="flex flex-col items-start gap-1">
                                    <x-sth.chip :tone="$row['status']->tone()" size="sm" class="h-auto min-h-6 py-0.5 whitespace-normal">{{ $row['status']->label() }}</x-sth.chip>
                                    @if ($row['reviewRequested'])
                                        <x-sth.chip tone="orange" size="sm" class="h-auto min-h-6 py-0.5 whitespace-normal">Minta ditinjau ulang</x-sth.chip>
                                    @endif
                                </div>
                            </td>
                            <td>
                                <div class="flex items-center gap-1.5">
                                    <x-sth.icon-button icon="eye" size="sm" :label="'Lihat '.$row['name']" wire:click="showPanel('{{ $row['id'] }}')" aria-pressed="{{ $row['selected'] ? 'true' : 'false' }}" />
                                    @if ($row['restorable'])
                                        <x-sth.icon-button icon="recycle" size="sm" :label="'Pulihkan '.$row['name'].', '.$row['type'].' '.$row['branch']" wire:click="askRestore('{{ $row['id'] }}')" aria-haspopup="dialog" />
                                    @else
                                        <x-sth.icon-button icon="check" size="sm" :label="$row['next'] ? 'Lolos ke '.$row['next']->label() : 'Tahap final'" wire:click="advanceRow('{{ $row['id'] }}')" :disabled="$row['next'] === null" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-sth.table>
                @if ($hasMore)
                    <div class="flex justify-center p-4">
                        <x-sth.button variant="quiet" size="sm" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
                    </div>
                @elseif ($capped)
                    <p class="m-0 p-4 text-center text-[13px] text-muted">Menampilkan {{ $rows->count() }} baris pertama. Persempit filter untuk melihat lainnya.</p>
                @endif
            @endif
        </x-sth.card>

        <x-sth.card as="aside" aria-label="Detail talenta" class="flex flex-col gap-4">
            @if ($panel === null)
                <p class="m-0 text-[13.5px] text-muted">Pilih baris untuk melihat detail.</p>
            @else
                <div class="flex items-start gap-3">
                    <x-sth.avatar :name="$panel['name']" size="lg" />
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="title-card">{{ $panel['name'] }}</span>
                        <span class="text-[13px] text-muted">{{ $panel['meta'] }}</span>
                    </div>
                    @if ($panel['points'])
                        <div class="flex flex-col items-end gap-0.5">
                            <x-sth.chip tone="gold" size="sm" class="num">{{ $panel['points'] }} poin</x-sth.chip>
                            <span class="text-[12px] text-muted">{{ $panel['prestasi'] }} prestasi di STH</span>
                        </div>
                    @endif
                </div>

                <div class="flex flex-col gap-2">
                    <h3 class="label m-0">Pilihan talenta · {{ count($panel['choices']) }}</h3>
                    <div class="flex flex-col gap-1.5">
                        @foreach ($panel['choices'] as $choice)
                            <button type="button" wire:click="showPanel('{{ $choice['id'] }}')" aria-pressed="{{ $choice['current'] ? 'true' : 'false' }}"
                                @class(['flex items-center justify-between gap-2 rounded-control border px-3 py-2 text-left', 'border-primary bg-primary-wash' => $choice['current'], 'border-line bg-surface' => ! $choice['current']])>
                                <span class="text-[13.5px] font-bold">{{ $choice['label'] }}</span>
                                <x-sth.chip :tone="$choice['status']->tone()" size="sm">{{ $choice['status']->label() }}</x-sth.chip>
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($panel['evidence'])
                    <div class="flex items-center gap-3 rounded-tile border border-line p-3">
                        <span class="inline-flex size-10 items-center justify-center rounded-tile bg-primary-tint text-primary"><x-sth.icon name="file" /></span>
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="ellip text-[13.5px] font-bold">{{ $panel['evidence']['name'] }}</span>
                            <span class="text-[12.5px] text-muted">Bukti dukung · {{ $panel['evidence']['size'] }}</span>
                        </span>
                        @if ($panel['evidence']['url'])<x-sth.button variant="quiet" size="sm" :href="$panel['evidence']['url']">Buka</x-sth.button>@endif
                    </div>
                @else
                    <p class="m-0 rounded-tile border border-dashed border-line-dashed p-3 text-[13px] text-muted">Tidak ada bukti dukung · Opsional</p>
                @endif

                <dl class="m-0 grid grid-cols-[110px_1fr] gap-x-3 gap-y-1.5 text-[13.5px]">
                    <dt class="text-muted">Minat</dt><dd class="m-0">{{ $panel['minat'] ?? '–' }}</dd>
                    <dt class="text-muted">Organisasi</dt><dd class="m-0">{{ $panel['organisasi'] ?? '–' }}</dd>
                    <dt class="text-muted">Prestasi SMP</dt><dd class="m-0">{{ $panel['smp'] ?? '–' }}</dd>
                </dl>

                @if ($panel['final'])
                    <x-sth.alert :tone="$panel['restorable'] ? 'error' : 'success'" :title="$panel['status']->label()">
                        {{ $panel['restorable'] ? 'Siswa sudah diberi tahu. Pulihkan untuk mengembalikan ke tahap '.$panel['restoreTo'].'.' : 'Pembina: '.($panel['mentorName'] ?? 'belum ditentukan') }}
                    </x-sth.alert>
                @endif

                @if ($panel['review'])
                    <x-sth.alert tone="warning" title="Siswa minta ditinjau ulang">
                        <span class="block text-[13px] text-muted">{{ $panel['review']['at'] }}</span>
                        <span class="block whitespace-pre-line break-words">{{ $panel['review']['message'] ?? 'Tanpa pesan.' }}</span>
                    </x-sth.alert>
                @endif

                @if ($panel['studentNote'])
                    <div class="flex flex-col gap-1 rounded-tile bg-subtle p-3 text-[13.5px]">
                        <span class="label">Catatan terakhir untuk siswa</span>
                        <span class="whitespace-pre-line break-words">{{ $panel['studentNote'] }}</span>
                    </div>
                @endif

                <div class="flex flex-col gap-3">
                    <x-sth.select wire:model="mentorId" label="Guru pembina" placeholder="Belum ditentukan" :options="$mentors" />
                    @unless ($panel['final'])
                        <x-sth.input type="date" wire:model="assessmentOn" label="Jadwal asesmen" />
                    @endunless
                    <x-sth.textarea wire:model="panelNote" label="Catatan untuk guru pembina" optional rows="3" maxlength="1000"
                        hint="Hanya dibaca guru pembina dan Kesiswaan. Siswa tidak melihatnya." />
                    @unless ($panel['final'])
                        <x-sth.textarea wire:model="panelStudentNote" label="Catatan untuk siswa" optional rows="3" maxlength="1000"
                            hint="Dibaca siswa di notifikasi dan Profil talenta. Terkirim saat Lolos atau Tidak lolos ditekan." />
                    @endunless
                    @error('panel')<x-sth.alert tone="error">{{ $message }}</x-sth.alert>@enderror
                </div>

                <div class="flex flex-wrap gap-2">
                    @if ($panel['next'])
                        <x-sth.button icon="check" wire:click="advance" wire:loading.attr="disabled">Lolos ke {{ $panel['next']->label() }}</x-sth.button>
                    @endif
                    @unless ($panel['final'])
                        <x-sth.button variant="danger" wire:click="rejectPanel" wire:loading.attr="disabled">Tidak lolos</x-sth.button>
                    @endunless
                    @if ($panel['restorable'])
                        <x-sth.button icon="recycle" wire:click="askRestore('{{ $panel['id'] }}')" wire:loading.attr="disabled" aria-haspopup="dialog">Pulihkan</x-sth.button>
                    @endif
                    <x-sth.button variant="quiet" wire:click="savePanel" wire:loading.attr="disabled">Simpan</x-sth.button>
                </div>
                <p class="m-0 text-[13px] text-muted">{{ $panelDone ?? 'Siswa menerima notifikasi setiap perubahan tahap.' }}</p>
            @endif
        </x-sth.card>
    </div>

    {{-- "Pulihkan": a tidak-lolos choice goes back to the stage it had before the rejection. --}}
    <x-sth.modal :id="\App\Livewire\Kesiswaan\Talenta::RESTORE_DIALOG" title="Pulihkan pilihan talenta?" description="Pilihan kembali ke tahap sebelum tidak lolos. Siswa diberi tahu." size="sm">
        <form id="pulihkan-talenta-form" wire:submit="restore" class="flex flex-col gap-4" novalidate>
            @if ($restoring)
                <p class="m-0 text-sm">
                    <span class="font-bold">{{ $restoring['name'] }}</span> · {{ $restoring['talent'] }} kembali ke tahap {{ $restoring['to'] }}.
                    @if ($restoring['requested'])Permintaan tinjau ulang siswa ikut ditutup.@endif
                </p>
            @endif
            <x-sth.textarea wire:model="restoreNote" label="Catatan untuk siswa" optional rows="3" maxlength="1000"
                hint="Dibaca siswa di notifikasi dan Profil talenta." />
        </form>
        <x-slot:footer>
            <x-sth.button variant="quiet" data-sth-close>Batal</x-sth.button>
            <x-sth.button type="submit" form="pulihkan-talenta-form" icon="recycle" wire:loading.attr="disabled" wire:target="restore">Pulihkan</x-sth.button>
        </x-slot:footer>
    </x-sth.modal>
</div>
