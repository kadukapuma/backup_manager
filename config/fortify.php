<?php

use Laravel\Fortify\Features;

/*
 * Fortify is used only for two-factor authentication. Login, password reset
 * and profile flows come from the starter kit's own controllers, so Fortify's
 * routes are ignored (see FortifyServiceProvider) and re-registered in
 * routes/auth.php and routes/settings.php for the 2FA endpoints only.
 */
return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/dashboard',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],
    'views' => false,
    'features' => [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
