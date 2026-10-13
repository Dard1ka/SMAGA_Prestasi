{{-- Lomba saya: Tim (members who did not decline, confirmation state) + guru pembimbing. --}}
<x-sth.card as="section" aria-labelledby="tim-judul" class="flex flex-col gap-3">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 id="tim-judul" class="title-card">{{ $d['isTeam'] ? 'Tim' : 'Peserta' }}</h3>
        <span class="muted text-sm"><span class="num">{{ count($d['members']) }}</span> {{ $d['isTeam'] ? 'anggota' : 'siswa' }}</span>
    </div>
    <ul class="m-0 flex list-none flex-col gap-2.5 p-0">
        @foreach ($d['members'] as $member)
            <li class="flex items-center gap-3">
                <x-sth.avatar :name="$member['name']" size="sm" />
                <div class="flex min-w-0 flex-1 flex-col items-start gap-1">
                    <span class="flex min-w-0 flex-col">
                        <span class="text-sm font-bold">{{ $member['name'] }}@if ($member['me'])<span class="muted font-semibold"> (kamu)</span>@endif</span>
                        <span class="muted text-xs">{{ $member['meta'] }}</span>
                    </span>
                    @if ($d['isTeam'])
                        @if ($member['confirmed'])
                            <x-sth.chip tone="green" icon="check" size="sm">Sudah konfirmasi</x-sth.chip>
                        @else
                            <x-sth.chip icon="clock" size="sm">Menunggu konfirmasi</x-sth.chip>
                        @endif
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
    @if ($d['inviteHref'] ?? null)
        <x-sth.button variant="soft" size="sm" icon="plus" :href="$d['inviteHref']" class="self-start">Undang anggota pengganti</x-sth.button>
    @endif
    @if ($d['mentor'])
        <div class="flex flex-col gap-2 border-t border-line pt-3">
            <span class="label">Guru pembimbing</span>
            <div class="flex items-center gap-3">
                <x-sth.avatar :name="$d['mentor']" size="sm" />
                <span class="flex min-w-0 flex-col [overflow-wrap:anywhere]">
                    <span class="text-sm font-bold">{{ $d['mentor'] }}</span>
                    {{-- D-132: said only when the approver replaced the guru the student chose; D-150 names the role. --}}
                    @if ($d['mentorByPrincipal'] ?? false)
                        <span class="muted text-xs">Ditetapkan {{ $d['approverRole'] }}</span>
                    @endif
                </span>
            </div>
        </div>
    @endif
</x-sth.card>
