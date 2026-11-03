<?php

use App\Providers\AppServiceProvider;
use App\Providers\ErrorPageServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\NavigationServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    NavigationServiceProvider::class,
    ErrorPageServiceProvider::class,
];
