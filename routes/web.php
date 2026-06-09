<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'name' => 'Hone',
    'status' => 'ok',
]))->name('home');
