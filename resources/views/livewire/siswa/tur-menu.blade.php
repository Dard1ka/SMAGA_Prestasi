{{--
    Tur singkat menu (boards Tur-Menu-Desktop + Tur-Menu-Mobile). One native modal <dialog> (opened on load by
    data-sth-autoopen, resources/js/app.js); the page behind is inert. Desktop tips sit next to the sidebar,
    mobile tips above the tab bar; each tip draws a replica of its menu item above the scrim.
--}}
<div>
    @if ($show)
        <dialog id="tur-menu" data-sth-autoopen wire:ignore.self aria-label="Tur singkat menu"
                class="fixed inset-0 m-0 h-dvh max-h-none w-full max-w-none overflow-hidden border-0 bg-transparent p-0 text-ink backdrop:bg-ink/55">
            @if ($step === 0)
                <div class="flex h-full items-end justify-center p-4 sm:items-center">
                    <div class="flex w-full max-w-[440px] flex-col gap-5 rounded-[24px] bg-surface p-6 shadow-pop">
                        <div class="flex gap-2" aria-hidden="true">
                            @foreach (['search' => 'bg-primary-tint text-primary', 'clipboard' => 'bg-sains-bg text-sains', 'award' => 'bg-gold-bg text-gold', 'bars' => 'bg-olahraga-bg text-olahraga', 'user' => 'bg-riset-bg text-riset'] as $icon => $tone)
                                <span class="inline-flex size-11 items-center justify-center rounded-tile {{ $tone }}"><x-sth.icon :name="$icon" /></span>
                            @endforeach
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <h2 class="m-0 font-display text-[26px] leading-tight font-extrabold">Halo, {{ $firstName }}</h2>
                            <p class="m-0 text-ink-2">Kenalan dulu dengan menunya, sebentar saja.</p>
                        </div>
                        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                            <x-sth.button variant="quiet" wire:click="selesai">Lewati</x-sth.button>
                            <x-sth.button wire:click="mulai" autofocus>Mulai tur</x-sth.button>
                        </div>
                    </div>
                </div>
            @else
                {{-- Desktop (lg+): popover right of the sidebar --}}
                @php($tip = $desktop[$step] ?? $desktop[\App\Livewire\Siswa\TurMenu::DONE])
                <div class="hidden lg:block">
                    @if ($tip['cta'] === '1')
                        <span class="btn btn-primary pointer-events-none fixed left-4 w-[231px] ring-4 ring-white {{ $tip['spot'] }}" aria-hidden="true"><x-sth.icon :name="$tip['icon']" />{{ $tip['label'] }}</span>
                    @else
                        <span class="nav pointer-events-none fixed left-4 w-[231px] bg-surface text-ink ring-[3px] ring-focus {{ $tip['spot'] }}" aria-hidden="true"><x-sth.icon :name="$tip['icon']" /><span>{{ $tip['label'] }}</span></span>
                    @endif

                    <div class="fixed left-[282px] flex w-[340px] flex-col gap-3 rounded-[20px] bg-surface p-5 shadow-pop {{ $tip['pop'] }}">
                        <span @class(['absolute -left-2 size-4 rotate-45 bg-surface', 'top-[34px]' => $step !== \App\Livewire\Siswa\TurMenu::DONE, 'top-[123px]' => $step === \App\Livewire\Siswa\TurMenu::DONE]) aria-hidden="true"></span>
                        @if ($step === \App\Livewire\Siswa\TurMenu::DONE)
                            <div class="flex items-center gap-3">
                                <span class="inline-flex size-10 items-center justify-center rounded-full bg-green-bg text-green" aria-hidden="true"><x-sth.icon name="check" /></span>
                                <h2 class="m-0 font-display text-xl font-extrabold">{{ $tip['title'] }}</h2>
                            </div>
                            <p class="m-0 text-ink-2">{{ $tip['text'] }}</p>
                            <div class="flex justify-end gap-2">
                                <x-sth.button variant="quiet" size="sm" wire:click="kembali({{ \App\Livewire\Siswa\TurMenu::DESKTOP_TIPS }})">Kembali</x-sth.button>
                                <x-sth.button size="sm" wire:click="selesai">Mulai</x-sth.button>
                            </div>
                        @else
                            <div class="flex items-center justify-between gap-3">
                                <span class="num text-[13px] font-bold text-primary">{{ $step }} dari {{ \App\Livewire\Siswa\TurMenu::DESKTOP_TIPS }}</span>
                                <span class="flex gap-1.5" aria-hidden="true">
                                    @for ($i = 1; $i <= \App\Livewire\Siswa\TurMenu::DESKTOP_TIPS; $i++)
                                        <span @class(['h-1.5 rounded-full', 'w-5 bg-primary' => $i === $step, 'w-1.5 bg-track' => $i !== $step])></span>
                                    @endfor
                                </span>
                            </div>
                            <div class="flex flex-col gap-1" aria-live="polite">
                                <h2 class="m-0 font-display text-xl font-extrabold">{{ $tip['title'] }}</h2>
                                <p class="m-0 text-ink-2">{{ $tip['text'] }}</p>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <button type="button" class="lnk text-sm text-muted" wire:click="selesai">Lewati</button>
                                <div class="flex gap-2">
                                    <x-sth.button variant="quiet" size="sm" wire:click="kembali({{ \App\Livewire\Siswa\TurMenu::DESKTOP_TIPS }})">Kembali</x-sth.button>
                                    <x-sth.button size="sm" wire:click="lanjut({{ \App\Livewire\Siswa\TurMenu::DESKTOP_TIPS }})">Lanjut</x-sth.button>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Phones/tablets (< lg): card above the tab bar --}}
                @php($mtip = $mobile[$step] ?? null)
                <div class="lg:hidden">
                    @if ($mtip)
                        <span class="tab tab-on pointer-events-none fixed bottom-[calc(16px+env(safe-area-inset-bottom))] h-14 w-[calc((100vw-12px)/5)] bg-surface ring-[3px] ring-focus {{ $mtip['spot'] }}" aria-hidden="true">
                            <span class="tab-ic"><x-sth.icon :name="$mtip['icon']" :size="22" /></span><span>{{ $mtip['label'] }}</span>
                        </span>
                        <div class="fixed inset-x-4 bottom-[calc(96px+env(safe-area-inset-bottom))] mx-auto flex max-w-[440px] flex-col gap-3 rounded-[20px] bg-surface p-5 shadow-pop">
                            <span class="absolute -bottom-2 size-4 rotate-45 bg-surface {{ $mtip['arrow'] }}" aria-hidden="true"></span>
                            <div class="flex items-center justify-between gap-3">
                                <span class="num text-[13px] font-bold text-primary">{{ $step }} dari {{ \App\Livewire\Siswa\TurMenu::MOBILE_TIPS }}</span>
                                <span class="flex gap-1.5" aria-hidden="true">
                                    @for ($i = 1; $i <= \App\Livewire\Siswa\TurMenu::MOBILE_TIPS; $i++)
                                        <span @class(['h-1.5 rounded-full', 'w-5 bg-primary' => $i === $step, 'w-1.5 bg-track' => $i !== $step])></span>
                                    @endfor
                                </span>
                            </div>
                            <div class="flex flex-col gap-1" aria-live="polite">
                                <h2 class="m-0 font-display text-xl font-extrabold">{{ $mtip['title'] }}</h2>
                                <p class="m-0 text-ink-2">{{ $mtip['text'] }}</p>
                            </div>
                            <div class="flex items-center justify-between gap-2">
                                <button type="button" class="lnk text-sm text-muted" wire:click="selesai">Lewati</button>
                                <div class="flex gap-2">
                                    <x-sth.button variant="quiet" size="sm" wire:click="kembali({{ \App\Livewire\Siswa\TurMenu::MOBILE_TIPS }})">Kembali</x-sth.button>
                                    <x-sth.button size="sm" wire:click="lanjut({{ \App\Livewire\Siswa\TurMenu::MOBILE_TIPS }})">Lanjut</x-sth.button>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="flex h-full items-end justify-center p-4 sm:items-center">
                            <div class="flex w-full max-w-[440px] flex-col items-center gap-3 rounded-[24px] bg-surface p-6 text-center shadow-pop">
                                <span class="inline-flex size-14 items-center justify-center rounded-full bg-green-bg text-green" aria-hidden="true"><x-sth.icon name="check" :size="28" /></span>
                                <h2 class="m-0 font-display text-2xl font-extrabold">Siap dipakai!</h2>
                                <p class="m-0 text-ink-2">Tur ini bisa dibuka lagi di menu Bantuan.</p>
                                <x-sth.button block wire:click="selesai">Mulai</x-sth.button>
                                <x-sth.button variant="quiet" size="sm" wire:click="kembali({{ \App\Livewire\Siswa\TurMenu::MOBILE_TIPS }})">Kembali</x-sth.button>
                            </div>
                        </div>
                    @endif
                </div>
            @endif
        </dialog>
    @endif
</div>
