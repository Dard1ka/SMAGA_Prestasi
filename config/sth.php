<?php

declare(strict_types=1);

use App\Domain\Accounts\InitialPassword;

/*
| Settings of the app shell that belong to the server, not to the school (those live in the `settings` table,
| App\Enums\SettingKey). Login, OTP and 2FA switches stay in config/fortify.php (`fortify.sth.*`).
*/

return [

    /*
    | Live counts (D-127): an open page asks GET /notifikasi/jumlah for the bell and the sidebar counts.
    |
    | poll_seconds = time between two background checks of one page while its tab is visible and in use
    | (default 60). Every check is a full request to php-fpm, so do not go low: values from 1 to 29 are read as 30
    | and values above 3600 as 3600 (App\Http\Controllers\NotificationCountController::pollSeconds()). 0 switches
    | the checks off, and it is the only way to do so: no page asks the server by itself any more (the kill switch
    | for a busy server); the bell then changes on a page load and right after "Tandai dibaca" / "Tandai semua
    | dibaca" only.
    */
    'counts' => [
        'poll_seconds' => max(0, (int) env('STH_COUNTS_POLL_SECONDS', 60)),
    ],

    /*
    | Initial password of every newly imported student (D-138). Hashed at import only, never shown or logged
    | (CLAUDE.md rule 3). Kesiswaan tells students outside the app; they must replace it at the first login.
    | Spaces around the value are not part of it; empty, blank or unset = InitialPassword::DEFAULT (a blank value must
    | never break a login, FS1 fix wave). After a change: reload.bat.
    */
    'initial_student_password' => InitialPassword::normalize(env('STH_INITIAL_STUDENT_PASSWORD')),

    /*
    | Developer pages under /_dev (component showcase, routes/dev.php). Loaded only when APP_ENV=local AND this is true
    | (bootstrap/app.php); false = every /_dev address answers 404 on a local stack too (a demo shown to other people).
    | Default true. Never loaded outside APP_ENV=local, whatever this says. After a change: reload.bat.
    */
    'dev_pages' => (bool) env('STH_DEV_PAGES', true),

];
