<?php

declare(strict_types=1);

namespace GermanPath;

use GermanPath\Config\Config;
use GermanPath\Http\Response;
use GermanPath\Support\Logger;
use Throwable;

final class ErrorHandler
{
    public static function register(Config $config, Logger $logger): void
    {
        set_error_handler(
            static function (int $severity, string $message, string $file, int $line): bool {
                if (!(error_reporting() & $severity)) {
                    return false;
                }
                throw new \ErrorException($message, 0, $severity, $file, $line);
            }
        );

        set_exception_handler(static function (Throwable $exception) use ($config, $logger): void {
            $logger->error('Unhandled application exception', [
                'type' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
                exit(1);
            }

            http_response_code(500);
            $message = $config->isDevelopment()
                ? 'Development error: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8')
                : 'Ein interner Fehler ist aufgetreten. Bitte versuche es später erneut.';
            (new Response('<h1>Fehler</h1><p>' . $message . '</p>', 500))->send();
        });
    }
}
