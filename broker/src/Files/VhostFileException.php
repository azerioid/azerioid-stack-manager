<?php
declare(strict_types=1);

namespace AzerioidPanel\Broker\Files;

final class VhostFileException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode = 1,
    ) {
        parent::__construct($message, $errorCode);
    }
}
