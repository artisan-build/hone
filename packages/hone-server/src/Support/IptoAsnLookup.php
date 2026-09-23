<?php

declare(strict_types=1);

namespace ArtisanBuild\HoneServer\Support;

use ArtisanBuild\HoneServer\Contracts\AsnLookup;
use SplFileObject;
use Throwable;

final class IptoAsnLookup implements AsnLookup
{
    /** @var array<string, int|null> */
    private array $cache = [];

    /** @var array<int, list<array{address: string, offset: int}>> */
    private array $index = [];

    private ?SplFileObject $file = null;

    private ?string $fingerprint = null;

    private int $lastLookupInspectedLines = 0;

    public function __construct(
        private readonly ?string $path,
        private readonly int $cacheLimit = 1024,
        private readonly int $indexStride = 1024,
    ) {}

    public function lookup(?string $ipAddress): ?int
    {
        if (! PublicIp::isValid($ipAddress)) {
            return null;
        }

        /** @var string $ipAddress */
        $this->refreshFile();

        if ($this->file === null) {
            return null;
        }

        if (array_key_exists($ipAddress, $this->cache)) {
            $asn = $this->cache[$ipAddress];
            unset($this->cache[$ipAddress]);

            return $this->cache[$ipAddress] = $asn;
        }

        $target = inet_pton($ipAddress);

        if ($target === false) {
            return null;
        }

        $entries = $this->index[strlen($target)] ?? [];
        $entryIndex = $this->entryAtOrBefore($entries, $target);

        if ($entryIndex === null) {
            return $this->remember($ipAddress, null);
        }

        $this->file->fseek($entries[$entryIndex]['offset']);
        $nextOffset = $entries[$entryIndex + 1]['offset'] ?? null;
        $this->lastLookupInspectedLines = 0;
        $matchingLines = 0;

        while (
            ! $this->file->eof()
            && ($nextOffset === null || $this->file->ftell() < $nextOffset)
            && $matchingLines < max(1, $this->indexStride)
        ) {
            $range = $this->parseRange($this->file->fgets());
            $this->lastLookupInspectedLines++;

            if ($range === null) {
                continue;
            }

            if (strlen($range['start']) !== strlen($target)) {
                if ($matchingLines > 0) {
                    break;
                }

                continue;
            }

            $matchingLines++;

            if (strcmp($target, $range['start']) < 0) {
                break;
            }

            if (strcmp($target, $range['end']) <= 0) {
                return $this->remember($ipAddress, $range['asn']);
            }
        }

        return $this->remember($ipAddress, null);
    }

    private function refreshFile(): void
    {
        $fingerprint = $this->fileFingerprint();

        if ($fingerprint === $this->fingerprint) {
            return;
        }

        $this->file = null;
        $this->fingerprint = $fingerprint;
        $this->index = [];
        $this->cache = [];

        if ($fingerprint === null || $this->path === null) {
            return;
        }

        try {
            $this->file = new SplFileObject($this->path, 'r');
            $this->buildIndex();
        } catch (Throwable) {
            $this->file = null;
            $this->index = [];
        }
    }

    private function fileFingerprint(): ?string
    {
        if ($this->path === null) {
            return null;
        }

        clearstatcache(true, $this->path);

        if (! is_file($this->path) || ! is_readable($this->path)) {
            return null;
        }

        $stat = stat($this->path);

        if ($stat === false) {
            return null;
        }

        return implode(':', [
            $stat['dev'],
            $stat['ino'],
            $stat['size'],
            $stat['mtime'],
            $stat['ctime'],
        ]);
    }

    private function buildIndex(): void
    {
        if ($this->file === null) {
            return;
        }

        /** @var array<int, int> $counts */
        $counts = [];
        $this->file->rewind();

        while (! $this->file->eof()) {
            $offset = $this->file->ftell();
            $range = $this->parseRange($this->file->fgets());

            if ($range === null) {
                continue;
            }

            $family = strlen($range['start']);
            $count = $counts[$family] ?? 0;

            if ($count % max(1, $this->indexStride) === 0) {
                $this->index[$family][] = [
                    'address' => $range['start'],
                    'offset' => $offset,
                ];
            }

            $counts[$family] = $count + 1;
        }
    }

    /**
     * @param  list<array{address: string, offset: int}>  $entries
     */
    private function entryAtOrBefore(array $entries, string $target): ?int
    {
        $low = 0;
        $high = count($entries) - 1;
        $match = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if (strcmp($entries[$middle]['address'], $target) <= 0) {
                $match = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $match;
    }

    /** @return array{start: string, end: string, asn: int|null}|null */
    private function parseRange(string|false $line): ?array
    {
        if (! is_string($line) || trim($line) === '') {
            return null;
        }

        $columns = explode("\t", trim($line));

        if (count($columns) < 3) {
            return null;
        }

        $rangeStart = inet_pton($columns[0]);
        $rangeEnd = inet_pton($columns[1]);

        if ($rangeStart === false || $rangeEnd === false || strlen($rangeStart) !== strlen($rangeEnd)) {
            return null;
        }

        $asn = (int) ltrim($columns[2], 'ASas');

        return [
            'start' => $rangeStart,
            'end' => $rangeEnd,
            'asn' => $asn > 0 ? $asn : null,
        ];
    }

    private function remember(string $ipAddress, ?int $asn): ?int
    {
        $this->cache[$ipAddress] = $asn;

        while (count($this->cache) > max(1, $this->cacheLimit)) {
            $oldest = array_key_first($this->cache);

            if ($oldest === null) {
                break;
            }

            unset($this->cache[$oldest]);
        }

        return $asn;
    }
}
