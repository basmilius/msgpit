<?php

declare(strict_types=1);

namespace Msgpit\Core;

/**
 * Reads the reference docs from docs/. They are plain Markdown so they stay readable on GitHub
 * and in an editor; the UI renders the same files.
 */
final readonly class Docs
{
    public function __construct(private string $directory) {}

    /** @return list<array{slug: string, title: string}> */
    public function index(): array
    {
        $pages = [];

        foreach ($this->files() as $file) {
            $pages[] = [
                'slug' => basename($file, '.md'),
                'title' => self::titleOf($file),
            ];
        }

        return $pages;
    }

    public function page(string $slug): ?string
    {
        // Slug comes from the URL, so keep it to a plain filename.
        if (preg_match('/^[a-z0-9-]+$/', $slug) !== 1) {
            return null;
        }

        $path = $this->directory . '/' . $slug . '.md';

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Ordered by a numeric filename prefix, which the slug drops again: the reading order is a
     * property of the docs, not something the UI should hardcode.
     *
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->directory . '/*.md') ?: [];
        sort($files);

        return $files;
    }

    /** The first heading is the title; falls back to the filename. */
    private static function titleOf(string $path): string
    {
        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (str_starts_with($line, '# ')) {
                return trim(substr($line, 2));
            }
        }

        return ucfirst(str_replace('-', ' ', basename($path, '.md')));
    }
}
