<?php

declare(strict_types=1);

/*
 | Merged over the framework's own config/view.php (paths, compiled path), so only the extra key lives here.
 |
 | check_cache_timestamps: Blade normally compares every view's source with its compiled copy on each
 | request. On the Windows bind mount of the dev stack each of those checks costs ~6 ms, so the dev php-fpm
 | pool switches them off (docker/php/fpm.dev.conf) and docker/php/dev-cache.sh empties the compiled folder
 | on every reload instead. Everywhere else (tests, CI, production) the default `true` applies; production
 | precompiles the views in its entrypoint anyway.
 */
return [
    'check_cache_timestamps' => (bool) env('VIEW_CHECK_CACHE_TIMESTAMPS', true),
];
