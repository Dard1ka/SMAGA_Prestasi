<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Baris bahasa paginasi
    |--------------------------------------------------------------------------
    |
    | Teks link paginasi yang dibuat oleh pustaka paginator.
    |
    */

    // The real characters, not &laquo; / &raquo;: Livewire's pagination view prints these lines unescaped as the text
    // of the buttons but escapes them in aria-label, where a screen reader then read "&raquo;" letter by letter.
    'previous' => '« Sebelumnya',
    'next' => 'Berikutnya »',

];
