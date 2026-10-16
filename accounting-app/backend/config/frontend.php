<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public Frontend Origin
    |--------------------------------------------------------------------------
    |
    | Absolute origin (scheme + host, optional path) of the public frontend
    | application. It is used to build links that are delivered out of band -
    | currently the password-reset link in the recovery email, which must open
    | the SPA form at /reset-password rather than the API endpoint.
    |
    | This is a deliberate, explicit configuration value: it must never be
    | derived from an inbound request (Host / X-Forwarded-Host), or a spoofed
    | header could redirect an emailed link to an attacker-controlled host.
    |
    | When it is missing or is not an absolute http(s) URL, App\Support\FrontendUrl
    | refuses to build a link rather than emitting an unusable one.
    |
    */

    'url' => env('FRONTEND_URL'),

];
