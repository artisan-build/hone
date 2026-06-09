<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient;

use ArtisanBuild\HoneContracts\Envelope;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Filesystem\Filesystem;
use Psr\Log\LoggerInterface;
use Throwable;

final class ContractsVersionNudge
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly LoggerInterface $logger,
    ) {}

    public function check(): void
    {
        try {
            $path = storage_path('framework/hone/contracts-major');
            $current = (string) Envelope::VERSION;
            $previous = $this->readPrevious($path);

            if ($previous !== null && $previous !== $current) {
                $this->logger->warning('Hone contracts major changed; run `php artisan hone:update` to verify server compatibility.');
            }

            if ($previous !== $current) {
                $this->files->ensureDirectoryExists(dirname($path));
                $this->files->put($path, $current);
            }
        } catch (Throwable) {
            return;
        }
    }

    private function readPrevious(string $path): ?string
    {
        try {
            if (! $this->files->exists($path)) {
                return null;
            }

            return trim($this->files->get($path));
        } catch (FileNotFoundException) {
            return null;
        }
    }
}
