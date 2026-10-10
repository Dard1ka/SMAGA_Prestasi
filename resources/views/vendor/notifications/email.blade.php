<x-mail::message>
{{--
    Every notification mail (App\Notifications\Support\PlainMailMessage), HTML and text part from this one view.
    The mail components live in resources/views/vendor/mail/{html,text}; the look is html/themes/sth.css.
    - Keep every line flush left and leave no blank line inside an HTML block: this view is read as Markdown.
    - Greeting and lines are printed through PlainMailMessage::plain(): text stays text however this view was
      compiled. Production compiles it beforehand with the ordinary Blade echo (view:cache), where the Markdown
      "secured encoding" does nothing. Anything else printed here is written by the code (button label, address).
    - One link per mail: the action button. No salutation (the footer names the sender), no image.
--}}
{{-- Inbox preview line (HTML part only): the first sentence instead of the header words --}}
@if (! empty($introLines))
<x-slot:preheader>
{{ App\Notifications\Support\PlainMailMessage::plain($introLines[0]) }}
</x-slot:preheader>
@endif

{{-- Greeting: every notification sets one (MailGreeting); "Halo," is the neutral fallback --}}
<x-mail::greeting>
@if (! empty($greeting))
{{ App\Notifications\Support\PlainMailMessage::plain($greeting) }}
@else
Halo,
@endif
</x-mail::greeting>

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ App\Notifications\Support\PlainMailMessage::plain($line) }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color" align="left">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ App\Notifications\Support\PlainMailMessage::plain($line) }}

@endforeach

{{-- Fallback address: HTML part only, printed as text (never a second link) --}}
@isset($actionText)
<x-slot:subcopy>
<x-mail::fallback :url="$displayableActionUrl" :label="$actionText" />
</x-slot:subcopy>
@endisset
</x-mail::message>
