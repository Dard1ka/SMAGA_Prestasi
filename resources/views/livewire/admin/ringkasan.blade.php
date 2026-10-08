{{-- Admin "Status sistem": counts, blockers, quick links, latest Admin audit entries (aggregate data only). --}}
<div class="flex flex-col gap-6">
    <div class="grid auto-rows-fr grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        <x-sth.stat-tile icon="users" tone="green" label="Siswa aktif" :value="(string) $stats['active']" note="sudah aktivasi" />
        <x-sth.stat-tile icon="clock" tone="orange" label="Belum aktivasi" :value="(string) $stats['pending']" note="akun siswa" />
        <x-sth.stat-tile icon="shield" tone="blue" label="Staf aktif" :value="(string) $stats['staff']" :note="$stats['staffPending'] > 0 ? $stats['staffPending'].' belum aktivasi' : 'semua peran staf'" :href="route('admin.pengguna.index')" />
        <x-sth.stat-tile icon="archive" tone="gray" label="Diarsipkan 30 hari lagi" :value="(string) $stats['dueSoon']" note="arsip otomatis" :href="route('admin.pengaturan', ['tab' => 'batas'])" />
    </div>

    {{-- No "Pintasan" column: its four links were the menu items again. --}}
    <div class="flex flex-col gap-6">
        <section aria-labelledby="h-perhatian" class="flex flex-col gap-3">
            <x-sth.page-header id="h-perhatian" title="Perlu perhatian" />
            @forelse ($attention as $item)
                <x-sth.alert :tone="$item['tone']">
                    {{ $item['text'] }}
                    <x-sth.link :href="$item['href']" icon-right="chevron-right">{{ $item['link'] }}</x-sth.link>
                </x-sth.alert>
            @empty
                <x-sth.empty-state icon="check-circle" title="Semua beres">Tidak ada pengaturan yang menghambat alur sekolah.</x-sth.empty-state>
            @endforelse

            <x-sth.page-header id="h-audit" title="Aktivitas Admin terbaru" class="mt-3" />
            @if ($audit->isEmpty())
                <x-sth.empty-state icon="file" title="Belum ada aktivitas">Perubahan akun dan pengaturan tercatat di sini.</x-sth.empty-state>
            @else
                <x-sth.table caption="Aktivitas Admin terbaru, 8 entri">
                    <x-slot:head>
                        <th scope="col">Aktivitas</th>
                        <th scope="col">Oleh</th>
                        <th scope="col" class="w-40">Waktu</th>
                    </x-slot:head>
                    @foreach ($audit as $entry)
                        <tr wire:key="audit-{{ $entry->id }}">
                            <td class="font-semibold">{{ $events[$entry->event] ?? $entry->event }}</td>
                            <td>{{ $entry->causer?->name ?? 'Sistem' }}</td>
                            <td class="num muted">{{ $entry->created_at?->translatedFormat('j M Y H:i') }}</td>
                        </tr>
                    @endforeach
                </x-sth.table>
            @endif
        </section>

    </div>
</div>
