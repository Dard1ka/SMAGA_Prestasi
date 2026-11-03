<x-mail::layout>
    {{-- Body. No header line and no fallback address: the text button already prints "Label: address". --}}
    {{ $slot }}

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ App\Notifications\Support\PlainMailMessage::FOOTER }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
