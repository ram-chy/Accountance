<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Password Policy
    |--------------------------------------------------------------------------
    |
    | Minimum length enforced by App\Rules\StrongPassword. The same rule is
    | applied to registration, admin-created users and password changes so the
    | policy can never be bypassed.
    |
    */

    'password_min_length' => (int) env('PASSWORD_MIN_LENGTH', 8),

];
