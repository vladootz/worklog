<?php

use Valet\Drivers\BasicValetDriver;

/**
 * Herd/Valet driver: docroot is public/, everything else in the repo
 * (ai-worklog.db, lib/, ingest.php) stays unreachable from the web.
 */
class LocalValetDriver extends BasicValetDriver
{
    public function serves(string $sitePath, string $siteName, string $uri): bool
    {
        return true;
    }

    public function isStaticFile(string $sitePath, string $siteName, string $uri)/*: string|false */
    {
        $path = $sitePath . '/public' . $uri;
        if ($uri !== '/' && is_file($path) && !str_contains($uri, '..')) {
            return $path;
        }
        return false;
    }

    public function frontControllerPath(string $sitePath, string $siteName, string $uri): ?string
    {
        return $sitePath . '/public/index.php';
    }
}
