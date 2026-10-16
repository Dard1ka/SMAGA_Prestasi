{{-- Database talenta siswa (board Kesiswaan-Talenta; D-142: no stages). Data prepared in App\Livewire\Kesiswaan\Talenta. --}}
<div class="flex flex-col gap-[22px]">
    @include('livewire.partials.resume-download')
    <section aria-label="Ringkasan talenta" class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="award" tone="blue" label="Talenta tercatat" :value="(string) $counts['semua']" note="pilihan siswa" />
        <x-sth.stat-tile icon="user" tone="orange" label="Belum mengisi" :value="(string) $counts['belum']" note="siswa kelas X" />
    </section>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="seg flex-wrap" role="group" aria-label="Tampilkan">
            @foreach (['semua' => 'Semua', 'belum' => 'Belum mengisi (kelas X)'] as $key => $label)
                <button type="button" class="seg-btn" wire:click="setTab('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">
                    {{ $label }} <span class="cnt">{{ $counts[$key] }}</span>
                </button>
            @endforeach
        </div>
        @unless ($missingTab)
            {{-- The file follows the filters on the screen (D-142); nothing is downloaded when nothing matches. --}}
            <x-sth.button variant="quiet" size="sm" icon="download" wire:click="export" wire:loading.attr="disabled" wire:target="export">Download data (Excel)</x-sth.button>
        @endunless
    </div>

    {{-- QA5: at 1366 px (the school laptops) the panel is slimmer and the table drops the avatar and narrows its
         fixed columns, so the student's name stays readable next to the open panel. --}}
    <div @class(['grid grid-cols-1 items-start gap-[22px]', 'xl:grid-cols-[minmax(0,1fr)_300px] 2xl:grid-cols-[minmax(0,1fr)_392px]' => ! $missingTab])>
        <x-sth.card padding="none" class="flex min-w-0 flex-col">
            <div class="flex flex-col gap-3 border-b border-line p-4">
                {{-- The jenis and cabang filters belong to the choices; the tab "Belum mengisi" lists students without one, so
                     they are left out there (FS1 QA: disabled chips looked like active ones and did nothing). --}}
                @unless ($missingTab)
                    <div class="flex flex-wrap gap-2" role="group" aria-label="Jenis talenta">
                        <button type="button" class="fchip h-9 px-3.5 text-sm" wire:click="setJenis('')" aria-pressed="{{ $jenis === '' ? 'true' : 'false' }}">Semua</button>
                        @foreach ($types as $type)
                            <button type="button" class="fchip h-9 px-3.5 text-sm" wire:click="setJenis('{{ $type->code->value }}')" aria-pressed="{{ $jenis === $type->code->value ? 'true' : 'false' }}">
                                {{ $type->code->label() }}@if ($type->bidang)<span class="sr-only">, {{ $type->bidang->label() }}</span>@endif
                            </button>
                        @endforeach
                    </div>
                @endunless
                <div @class(['grid grid-cols-1 gap-3', 'md:grid-cols-3' => ! $missingTab, 'md:grid-cols-2' => $missingTab])>
                    @unless ($missingTab)
                        <x-sth.select wire:model.live="cabang" label="Cabang talenta" size="sm" :placeholder="$branches === [] ? 'Pilih jenis dulu' : 'Semua cabang'" :options="$branches" :disabled="$branches === []" />
                    @endunless
                    <x-sth.select wire:model.live="kelas" label="Kelas" size="sm" placeholder="Semua kelas" :options="$classes" />
                    <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari" icon="search" placeholder="Nama atau NISN" />
                </div>
            </div>

            <div class="flex min-h-12 flex-wrap items-center gap-3 border-b border-line bg-subtle px-4 py-2.5" aria-live="polite">
                <span class="text-sm text-muted">{{ $shownText }}</span>
            </div>

            @if ($missingTab)
                @if ($missing->isEmpty())
                    <div class="p-4">
                        <x-sth.empty-state icon="check-circle" title="Tidak ada siswa di sini" :card="false">Semua siswa kelas X yang cocok dengan filter sudah memilih talenta.</x-sth.empty-state>
                    </div>
                @else
                    {{-- FS1 QA: on a phone Kelas and NISN (what Kesiswaan chases students with) go under the name, so nothing
                         sits in a sideways scroll; from sm up they are columns again. --}}
                    <x-sth.table caption="Siswa kelas X yang belum memilih talenta" :card="false" fixed table-class="sm:min-w-[560px]">
                        <x-slot:head>
                            <th scope="col">Siswa</th>
                            <th scope="col" class="hidden w-36 sm:table-cell">Kelas</th>
                            <th scope="col" class="hidden w-40 sm:table-cell">NISN</th>
                        </x-slot:head>
                        @foreach ($missing as $student)
                            <tr wire:key="b-{{ $student['id'] }}">
                                <td>
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <x-sth.avatar :name="$student['name']" size="sm" />
                                        <div class="flex min-w-0 flex-col">
                                            <span class="font-bold [overflow-wrap:anywhere]">{{ $student['name'] }}</span>
                                            <span class="text-xs text-muted sm:hidden">{{ $student['class'] ?? '–' }} · NISN <span class="num">{{ $student['nisn'] }}</span></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="hidden text-sm sm:table-cell">{{ $student['class'] ?? '–' }}</td>
                                <td class="num hidden text-sm sm:table-cell">{{ $student['nisn'] }}</td>
                            </tr>
                        @endforeach
                    </x-sth.table>
                @endif
            @elseif ($rows->isEmpty())
                <div class="p-4">
                    <x-sth.empty-state icon="search" title="Tidak ada pilihan talenta" :card="false">Ubah filter atau kata kunci.</x-sth.empty-state>
                </div>
            @else
                {{-- FS1 QA: the floor fits the card beside the 300px panel at 1366 px (it was 720 in a 692 px card, the eye
                     button half hidden); below md the talent goes under the name and Prestasi / Guru pembina are left to
                     the panel, so the eye button stays on a phone screen. --}}
                <x-sth.table caption="Daftar pilihan talenta" :card="false" fixed table-class="md:min-w-[600px]">
                    <x-slot:head>
                        <th scope="col">Siswa</th>
                        <th scope="col" class="hidden w-44 md:table-cell 2xl:w-52">Talenta</th>
                        <th scope="col" class="hidden w-20 md:table-cell">Prestasi</th>
                        <th scope="col" class="hidden w-36 md:table-cell 2xl:w-44">Guru pembina</th>
                        {{-- FS1 QA round 2: 12px + the 44px touch button + 18px = 74px, so the button stays inside its cell (and
                             18px inside the card) on a phone; it was a 56px column the button overflowed. --}}
                        <th scope="col" class="w-20"><span class="sr-only">Aksi</span></th>
                    </x-slot:head>
                    @foreach ($rows as $row)
                        <tr wire:key="t-{{ $row['id'] }}" @class(['bg-primary-wash' => $row['selected']])>
                            <td>
                                <div class="flex min-w-0 items-center gap-2.5">
                                    <x-sth.avatar :name="$row['name']" size="sm" class="hidden 2xl:inline-flex" />
                                    <div class="flex min-w-0 flex-col">
                                        <span class="ellip font-bold">{{ $row['name'] }}</span>
                                        <span class="text-xs text-muted">{{ $row['class'] }} · <span class="num">{{ $row['nisn'] }}</span></span>
                                        <span class="text-sm font-semibold [overflow-wrap:anywhere] md:hidden">{{ $row['title'] }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="hidden md:table-cell">
                                {{-- D-143: the student's own "Lainnya" text may be one long word (QA7). One label for a talent
                                     everywhere (TalentApplication::title(), FS1 QA): "Lainnya (…)", "OSN · Lainnya (…)". --}}
                                <div class="flex min-w-0 flex-col [overflow-wrap:anywhere]">
                                    <span class="font-bold">{{ $row['title'] }}</span>
                                    <span class="text-xs text-muted">{{ $row['bidang']?->label() ?? 'Lainnya' }}{{ $row['hasEvidence'] ? ' · ada bukti' : '' }}</span>
                                </div>
                            </td>
                            <td class="num hidden text-sm whitespace-nowrap md:table-cell">{{ $row['points'] !== null ? $row['points'].' · '.$row['prestasi'] : '–' }}</td>
                            <td class="hidden text-sm [overflow-wrap:anywhere] md:table-cell">{{ $row['mentorName'] ?? '–' }}</td>
                            <td class="text-right">
                                {{-- The name alone repeats on every row of one student: the label names the talent too (FS1 QA). --}}
                                <x-sth.icon-button icon="eye" size="sm" :label="'Lihat '.$row['name'].' – '.$row['title']" wire:click="showPanel('{{ $row['id'] }}')" aria-pressed="{{ $row['selected'] ? 'true' : 'false' }}" />
                            </td>
                        </tr>
                    @endforeach
                </x-sth.table>
            @endif
            @if ($hasMore)
                <div class="flex justify-center p-4">
                    <x-sth.button variant="quiet" size="sm" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
                </div>
            @elseif ($capped)
                <p class="m-0 p-4 text-center text-xs text-muted">Menampilkan {{ $missingTab ? $missing->count() : $rows->count() }} baris pertama. Persempit filter untuk melihat lainnya.</p>
            @endif
        </x-sth.card>

        @unless ($missingTab)
            {{-- `sth-reveal` (app.js) brings it into view after a row button when it stands below the list (phones, FS1 QA). --}}
            <x-sth.card as="aside" :id="\App\Livewire\Kesiswaan\Talenta::PANEL_ID" tabindex="-1" aria-label="Detail talenta" class="flex flex-col gap-4 outline-none">
                @if ($panel === null)
                    <p class="m-0 text-sm text-muted">Pilih baris untuk melihat detail.</p>
                @else
                    <div class="flex items-start gap-3">
                        <x-sth.avatar :name="$panel['name']" size="lg" />
                        <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span class="title-card">{{ $panel['name'] }}</span>
                            <span class="text-xs text-muted">{{ $panel['meta'] }}</span>
                        </div>
                        @if ($panel['points'])
                            <div class="flex flex-col items-end gap-0.5">
                                <x-sth.chip tone="gold" size="sm" class="num">{{ $panel['points'] }} poin</x-sth.chip>
                                <span class="text-xs text-muted">{{ $panel['prestasi'] }} prestasi di STH</span>
                            </div>
                        @endif
                    </div>

                    <div class="flex flex-col gap-2">
                        <h3 class="label m-0">Pilihan talenta · {{ count($panel['choices']) }}</h3>
                        <div class="flex flex-col gap-1.5">
                            @foreach ($panel['choices'] as $choice)
                                <button type="button" wire:click="showPanel('{{ $choice['id'] }}')" aria-pressed="{{ $choice['current'] ? 'true' : 'false' }}"
                                    @class(['flex items-center gap-2 rounded-control border px-3 py-2 text-left', 'border-primary bg-primary-wash' => $choice['current'], 'border-line bg-surface' => ! $choice['current']])>
                                    <span class="min-w-0 text-sm font-bold [overflow-wrap:anywhere]">{{ $choice['label'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>

                    @if ($panel['evidence'])
                        <div class="flex items-center gap-3 rounded-tile border border-line p-3">
                            <span class="inline-flex size-10 items-center justify-center rounded-tile bg-primary-tint text-primary"><x-sth.icon name="file" /></span>
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span class="ellip text-sm font-bold">{{ $panel['evidence']['name'] }}</span>
                                <span class="text-xs text-muted">Bukti dukung · {{ $panel['evidence']['size'] }}</span>
                            </span>
                            @if ($panel['evidence']['url'])
                                <x-sth.button variant="quiet" size="sm" icon="eye" :href="$panel['evidence']['url']" aria-haspopup="dialog"
                                    :data-sth-preview="$panel['evidence']['preview']" :data-sth-preview-type="$panel['evidence']['previewType']"
                                    :data-sth-preview-name="$panel['evidence']['name']">Buka</x-sth.button>
                            @endif
                        </div>
                    @else
                        <p class="m-0 rounded-tile border border-dashed border-line-dashed p-3 text-xs text-muted">Tidak ada bukti dukung · Opsional</p>
                    @endif

                    {{-- The student types these: one long word (a link, a handle) wraps inside the card (QA6, Q2-29). --}}
                    <dl class="m-0 grid grid-cols-[110px_minmax(0,1fr)] gap-x-3 gap-y-1.5 text-sm">
                        <dt class="text-muted">Minat</dt><dd class="m-0 [overflow-wrap:anywhere]">{{ $panel['minat'] ?? '–' }}</dd>
                        <dt class="text-muted">Organisasi</dt><dd class="m-0 [overflow-wrap:anywhere]">{{ $panel['organisasi'] ?? '–' }}</dd>
                        <dt class="text-muted">Prestasi SMP</dt><dd class="m-0 [overflow-wrap:anywhere]">{{ $panel['smp'] ?? '–' }}</dd>
                    </dl>

                    <div class="flex flex-col gap-3">
                        {{-- QA9: a recorded guru pembina whose account left is not an option; the hint says so (saving keeps him). --}}
                        <x-sth.select wire:model="mentorId" label="Guru pembina" optional placeholder="Tanpa guru pembina" :options="$mentors" :hint="$panel['mentorHint']" />
                        <x-sth.textarea wire:model="panelNote" label="Catatan untuk guru pembina" optional rows="3" maxlength="1000"
                            hint="Hanya dibaca guru pembina dan Kesiswaan. Siswa tidak melihatnya." />
                        @error('panel')<x-sth.alert tone="error">{{ $message }}</x-sth.alert>@enderror
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-sth.button wire:click="savePanel" wire:loading.attr="disabled" wire:target="savePanel">Simpan</x-sth.button>
                        <span class="text-xs text-muted">Tidak ada notifikasi yang dikirim.</span>
                    </div>
                @endif
            </x-sth.card>
        @endunless
    </div>
</div>
