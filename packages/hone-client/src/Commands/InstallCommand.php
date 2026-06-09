<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneClient\Commands;

use Illuminate\Console\Command;
use JsonException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class InstallCommand extends Command
{
    protected $signature = 'hone:install
                            {--url= : The Hone ingest URL}
                            {--token= : The Hone API token}';

    protected $description = 'Configure a Laravel application to send Nightwatch telemetry to Hone.';

    /**
     * @var list<string>
     */
    private array $changes = [];

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $url = $this->stringOption('url') ?: text('Hone ingest URL', required: true);
        $token = $this->stringOption('token') ?: password('Hone token', required: true);

        $this->writeEnvironment($url, $token);
        $this->pinComposerConstraint();
        $this->printSummary();

        return self::SUCCESS;
    }

    private function writeEnvironment(string $url, string $token): void
    {
        $path = $this->laravel->environmentFilePath();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        $updated = $this->setEnvironmentValue($contents, 'HONE_URL', $url);
        $updated = $this->setEnvironmentValue($updated, 'HONE_TOKEN', $token);

        if (! $this->environmentHasTruthyNightwatchEnabled($updated)) {
            $updated = $this->setEnvironmentValue($updated, 'NIGHTWATCH_ENABLED', 'true');
        }

        if ($updated === $contents) {
            return;
        }

        if (! $this->shouldWrite($path, 'Write Hone environment settings?')) {
            $this->changes[] = 'Skipped '.$path;

            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $updated);

        $this->changes[] = 'Updated '.$path;
    }

    /**
     * @throws JsonException
     */
    private function pinComposerConstraint(): void
    {
        $path = base_path('composer.json');

        if (! is_file($path)) {
            return;
        }

        $contents = (string) file_get_contents($path);
        $composer = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($composer)) {
            return;
        }

        $require = $composer['require'] ?? [];

        if (! is_array($require)) {
            $require = [];
        }

        $current = $require['artisan-build/hone-client'] ?? null;

        if (is_string($current) && $this->isCleanCaretConstraint($current)) {
            return;
        }

        $require['artisan-build/hone-client'] = '^'.$this->installedClientMajor();
        $composer['require'] = $require;

        $updated = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;

        if ($updated === $contents) {
            return;
        }

        if (! $this->shouldWrite($path, 'Pin hone-client in composer.json?')) {
            $this->changes[] = 'Skipped '.$path;

            return;
        }

        file_put_contents($path, $updated);

        $this->changes[] = 'Updated '.$path;
    }

    private function setEnvironmentValue(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$this->formatEnvironmentValue($value);
        $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace($pattern, $line, $contents) ?? $contents;
        }

        if ($contents !== '' && ! str_ends_with($contents, PHP_EOL)) {
            $contents .= PHP_EOL;
        }

        return $contents.$line.PHP_EOL;
    }

    private function environmentHasTruthyNightwatchEnabled(string $contents): bool
    {
        if (preg_match('/^NIGHTWATCH_ENABLED=(.*)$/m', $contents, $matches) !== 1) {
            return false;
        }

        return ! in_array(strtolower(trim($matches[1], " \t\n\r\0\x0B\"'")), ['false', '0', 'off', 'no'], true);
    }

    private function formatEnvironmentValue(string $value): string
    {
        if ($value === '' || preg_match('/\s|#|=|"|\'/', $value) === 1) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }

        return $value;
    }

    private function shouldWrite(string $path, string $message): bool
    {
        if (! $this->input->isInteractive()) {
            return true;
        }

        return confirm($message.' ['.$path.']', default: true);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function isCleanCaretConstraint(string $constraint): bool
    {
        return preg_match('/^\^\d/', $constraint) === 1
            && ! str_contains($constraint, '*')
            && ! str_contains(strtolower($constraint), 'dev')
            && ! str_contains($constraint, '@')
            && ! str_contains($constraint, '||')
            && ! str_contains($constraint, ' ');
    }

    private function installedClientMajor(): int
    {
        $installedPath = base_path('vendor/composer/installed.json');

        if (is_file($installedPath)) {
            try {
                $installed = json_decode((string) file_get_contents($installedPath), true, 512, JSON_THROW_ON_ERROR);
                $packages = is_array($installed) && isset($installed['packages']) && is_array($installed['packages'])
                    ? $installed['packages']
                    : (is_array($installed) ? $installed : []);

                foreach ($packages as $package) {
                    if (is_array($package) && ($package['name'] ?? null) === 'artisan-build/hone-client') {
                        $version = $package['version_normalized'] ?? $package['version'] ?? null;

                        if (is_string($version) && preg_match('/^(\d+)/', $version, $matches) === 1) {
                            return max(1, (int) $matches[1]);
                        }
                    }
                }
            } catch (JsonException) {
                return 1;
            }
        }

        return 1;
    }

    private function printSummary(): void
    {
        if ($this->changes === []) {
            $this->info('Hone is already configured.');

            return;
        }

        $this->info('Hone install complete:');

        foreach ($this->changes as $change) {
            $this->line('- '.$change);
        }
    }
}
