{{-- Guru · Siswa binaan (backlog #3, B4). Data prepared in App\Livewire\Guru\Binaan (own binaan only). --}}
<div class="flex flex-col gap-[22px]">
    <section aria-label="Ringkasan binaan" class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="users" tone="blue" label="Siswa binaan" :value="(string) $counts['siswa']" note="lomba dan talenta" />
        <x-sth.stat-tile icon="trophy" tone="blue" label="Lomba berjalan" :value="(string) $counts['aktif']" note="belum selesai" />
        <x-sth.stat-tile icon="clock" tone="orange" label="Menunggu laporan" :value="(string) $counts['laporan']" :note="$counts['verifikasi'].' menunggu verifikasi'" />
        {{-- QA9: students, once each, with an open choice (not "Tidak lolos"); the tab below counts its rows. --}}
        <x-sth.stat-tile icon="award" tone="green" label="Pembinaan talenta" :value="(string) $counts['dibina']" note="siswa yang dibina" />
    </section>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="seg flex-wrap" role="group" aria-label="Tampilkan">
            @foreach (['aktif' => 'Lomba berjalan', 'selesai' => 'Selesai', 'talenta' => 'Talenta'] as $key => $label)
                <button type="button" class="seg-btn" wire:click="setTab('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}">
                    {{ $label }} <span class="cnt">{{ $counts[$key] ?? 0 }}</span>
                </button>
            @endforeach
        </div>
        <x-sth.input type="search" wire:model.live.debounce.400ms="q" label="Cari siswa binaan" hide-label icon="search"
                     :placeholder="$isTalent ? 'Nama siswa' : 'Nama siswa atau lomba'" maxlength="100" class="w-full sm:w-72" />
    </div>

    <div class="flex flex-col gap-4" wire:loading.class="opacity-70">
        @if ($total === 0)
            <x-sth.empty-state :icon="$isTalent ? 'award' : 'users'" :title="$q !== '' ? 'Tidak ada yang cocok' : ($isTalent ? 'Belum ada siswa yang dibina' : 'Belum ada siswa binaan di sini')">
                @if ($q !== '')
                    Coba kata kunci lain.
                @elseif ($isTalent)
                    Kesiswaan menunjuk guru pembina saat seleksi talenta.
                @else
                    Siswa memilih guru pembimbing saat mendaftar lomba.
                @endif
                <x-slot:actions>
                    <x-sth.button variant="quiet" size="sm" icon="trophy" :href="$lombaUrl">Lihat lomba</x-sth.button>
                </x-slot:actions>
            </x-sth.empty-state>
        @elseif ($isTalent)
            <x-sth.table caption="Pembinaan talenta, {{ $talentStudents }} siswa" fixed table-class="min-w-[560px]">
                <x-slot:head>
                    <th scope="col">Siswa</th>
                    <th scope="col" class="w-44">Talenta</th>
                    <th scope="col" class="w-40">Tahap</th>
                    <th scope="col" class="hidden w-36 md:table-cell">Asesmen</th>
                </x-slot:head>
                @foreach ($talents as $row)
                    <tr wire:key="tal-{{ $row['id'] }}">
                        <td>
                            <div class="flex min-w-0 items-center gap-2.5">
                                <x-sth.avatar :name="$row['name']" size="sm" />
                                <div class="flex min-w-0 flex-col">
                                    <span class="ellip font-bold">{{ $row['name'] }}</span>
                                    <span class="text-xs text-muted">{{ $row['kelas'] }}</span>
                                </div>
                            </div>
                            @if ($row['note'])
                                <p class="m-0 mt-1.5 text-xs break-words text-ink-2"><span class="font-bold">Catatan untuk pembina:</span> {{ $row['note'] }}</p>
                            @endif
                        </td>
                        <td>
                            <div class="flex flex-col">
                                <span class="font-bold">{{ $row['type'] }}@if ($row['branch'])<span class="font-medium"> · {{ $row['branch'] }}</span>@endif</span>
                                <span class="text-xs text-muted">{{ $row['bidang']?->label() ?? 'Lainnya' }}</span>
                            </div>
                        </td>
                        <td>
                            <div class="flex flex-col items-start gap-1">
                                <x-sth.chip :tone="$row['tone']" size="sm">{{ $row['status'] }}</x-sth.chip>
                                @if ($row['step'])<span class="num text-xs text-muted">Tahap {{ $row['step'] }} dari 4</span>@endif
                            </div>
                        </td>
                        <td class="hidden text-sm md:table-cell">{{ $row['assessment'] ?? '–' }}</td>
                    </tr>
                @endforeach
            </x-sth.table>
        @else
            <ul class="m-0 grid list-none grid-cols-1 gap-4 p-0 xl:grid-cols-2" aria-label="Pendaftaran siswa binaan">
                @foreach ($registrations as $card)
                    <li wire:key="reg-{{ $card['id'] }}">
                        <x-sth.card as="article" class="flex h-full flex-col gap-3">
                            <div class="flex flex-wrap items-center gap-2">
                                @if ($card['bidang'])<x-sth.chip :bidang="$card['bidang']" size="sm" icon />@endif
                                <x-sth.chip :tone="$card['tone']" size="sm">{{ $card['chip'] }}</x-sth.chip>
                            </div>
                            <div class="flex flex-col gap-1">
                                <h2 class="m-0 font-sans text-base leading-tight font-bold">
                                    <a href="{{ $card['href'] }}" class="text-ink hover:text-primary">{{ $card['title'] }}</a>
                                </h2>
                                <p class="m-0 text-sm text-muted">{{ collect([$card['type'], $card['date']])->filter()->implode(' · ') }}</p>
                            </div>
                            <p class="m-0 text-sm">{{ implode(', ', $card['members']) }}</p>
                            <x-sth.tracker :status="$card['status']" compact />
                            <div class="mt-auto">
                                <x-sth.link :href="$card['href']" icon-right="chevron-right">Lihat detail<span class="sr-only"> {{ $card['title'] }}</span></x-sth.link>
                            </div>
                        </x-sth.card>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($hasMore)
            <div class="flex justify-center">
                <x-sth.button variant="quiet" size="sm" icon="chevron-down" wire:click="showMore">Tampilkan lebih banyak</x-sth.button>
            </div>
        @endif
    </div>
</div>
