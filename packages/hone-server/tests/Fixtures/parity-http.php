<?php

declare(strict_types=1);

use ArtisanBuild\HoneServer\Tests\Support\ParityApplication;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';

try {
    $app = (new ParityApplication('parity'))->createApplication();

    if (PHP_SAPI === 'cli') {
        exit(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
    }

    $kernel = $app->make(Kernel::class);
    $request = Request::capture();
    $response = $kernel->handle($request);
    $response->send();
    $kernel->terminate($request, $response);
} catch (Throwable) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Parity application migration/bootstrap failed; check local Postgres and PHP extensions.\n");
        exit(1);
    }

    http_response_code(500);
    echo 'Parity application bootstrap failed.';
}
