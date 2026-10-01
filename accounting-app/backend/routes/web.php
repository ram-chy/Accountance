<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'success' => true,
    'message' => 'Accounting Web Application API.',
    'data' => [
        'api' => url('/api'),
        'health' => url('/api/health'),
    ],
]));
