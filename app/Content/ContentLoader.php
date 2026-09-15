<?php

declare(strict_types=1);

namespace GermanPath\Content;

use GermanPath\Support\Logger;
use JsonException;

final class ContentLoader
{
    /** @var array<string, string> */
    private const DIRECTORIES = [
        'teachers' => 'teachers',
        'courses' => 'courses',
        'playlists' => 'playlists',
        'videos' => 'videos',
        'shorts' => 'shorts',
        'pages' => 'pages',
        'site' => 'site',
    ];

    /** @var array<string, array{mtime: int, size: int, data: array<string, mixed>}> */
    private array $cache = [];

    public function __construct(
        private readonly string $contentRoot,
        private readonly ContentValidator $validator,
        private readonly Logger $logger
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function loadCollection(string $collection): array
    {
        $directory = $this->directoryFor($collection);
        if (!is_dir($directory)) {
            throw new ContentException("Content directory is missing: {$directory}");
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($files, SORT_STRING);
        $items = [];
        $errors = [];
        foreach ($files as $file) {
            try {
                $items[] = $this->loadFile($collection, $file);
            } catch (ContentException $exception) {
                $errors = [...$errors, ...$exception->errors()];
            }
        }

        if ($errors !== []) {
            $this->logger->error('Content collection validation failed', [
                'collection' => $collection,
                'error_count' => count($errors),
            ]);
            throw new ContentException(
                "Content collection '{$collection}' contains invalid files.",
                $errors
            );
        }

        return $items;
    }

    /** @return array<string, mixed>|null */
    public function findById(string $collection, string $id): ?array
    {
        foreach ($this->loadCollection($collection) as $item) {
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $collection, string $slug): ?array
    {
        foreach ($this->loadCollection($collection) as $item) {
            if (($item['slug'] ?? null) === $slug) {
                return $item;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function integrityErrors(): array
    {
        $collections = [];
        $errors = [];
        foreach (array_keys(self::DIRECTORIES) as $collection) {
            try {
                $collections[$collection] = $this->loadCollection($collection);
            } catch (ContentException $exception) {
                $collections[$collection] = [];
                $errors = [...$errors, ...$exception->errors()];
            }
        }

        return [
            ...$errors,
            ...(new ContentIntegrityValidator())->validate($collections),
        ];
    }

    public function assertIntegrity(): void
    {
        $errors = $this->integrityErrors();
        if ($errors !== []) {
            throw new ContentException('Content integrity validation failed.', $errors);
        }
    }

    /** @return array<string, array{files: int, valid: bool, errors: list<string>}> */
    public function validationReport(): array
    {
        $report = [];
        foreach (array_keys(self::DIRECTORIES) as $collection) {
            try {
                $items = $this->loadCollection($collection);
                $report[$collection] = ['files' => count($items), 'valid' => true, 'errors' => []];
            } catch (ContentException $exception) {
                $report[$collection] = [
                    'files' => $this->countFiles($collection),
                    'valid' => false,
                    'errors' => $exception->errors(),
                ];
            }
        }

        return $report;
    }

    private function loadFile(string $collection, string $file): array
    {
        $mtime = (int) filemtime($file);
        $size = (int) filesize($file);
        $cacheKey = $collection . ':' . $file;
        if (isset($this->cache[$cacheKey])
            && $this->cache[$cacheKey]['mtime'] === $mtime
            && $this->cache[$cacheKey]['size'] === $size
        ) {
            return $this->cache[$cacheKey]['data'];
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new ContentException("Unable to read content file: {$file}", ["{$file}: file is unreadable."]);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ContentException(
                "Malformed JSON in {$file}.",
                ["{$file}: {$exception->getMessage()}"]
            );
        }

        $errors = $this->validator->validate($collection, $data, $file);
        if ($errors !== []) {
            throw new ContentException("Invalid content in {$file}.", $errors);
        }

        /** @var array<string, mixed> $data */
        $this->cache[$cacheKey] = ['mtime' => $mtime, 'size' => $size, 'data' => $data];
        return $data;
    }

    private function directoryFor(string $collection): string
    {
        if (!isset(self::DIRECTORIES[$collection])) {
            throw new ContentException("Unknown content collection '{$collection}'.");
        }

        return $this->contentRoot . DIRECTORY_SEPARATOR . self::DIRECTORIES[$collection];
    }

    private function countFiles(string $collection): int
    {
        try {
            return count(glob($this->directoryFor($collection) . DIRECTORY_SEPARATOR . '*.json') ?: []);
        } catch (ContentException) {
            return 0;
        }
    }
}
