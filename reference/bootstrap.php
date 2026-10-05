<?php

declare(strict_types=1);

/**
 * The PSR-4 autoloader, shared by the web front controller and the SMTP server. The runtime never
 * needs vendor/: Composer is dev tooling only.
 */

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'Msgpit\\')) {
        return;
    }

    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 7)) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
