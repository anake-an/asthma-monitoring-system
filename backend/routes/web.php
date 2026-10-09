<?php

use Illuminate\Support\Facades\Route;

// The dashboard is the Next.js frontend; this service only serves /api/*.
Route::get('/', fn () => response()->json(['service' => 'RespiroSync API']));
