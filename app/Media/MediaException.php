<?php

declare(strict_types=1);

namespace GermanPath\Media;

use RuntimeException;

final class MediaException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 503
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}