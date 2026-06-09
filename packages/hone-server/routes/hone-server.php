<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Http\Controllers\IngestController;
use Illuminate\Support\Facades\Route;

Route::post('ingest', [IngestController::class, 'ingest'])->name('hone-server.ingest');
Route::get('capabilities', [IngestController::class, 'capabilities'])->name('hone-server.capabilities');
