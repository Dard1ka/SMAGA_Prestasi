<x-mail::layout>
{{-- Preheader --}}
@isset($preheader)
<x-slot:preheader>
{!! $preheader !!}
</x-slot:preheader>
@endisset

{{-- Header: text only, no link, no image --}}
<x-slot:header>
<x-mail::header />
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
{{ App\Notifications\Support\PlainMailMessage::FOOTER }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
