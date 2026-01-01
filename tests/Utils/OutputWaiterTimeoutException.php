<?php

declare(strict_types=1);

namespace Tests\Utils;

final class OutputWaiterTimeoutException extends \RuntimeException
{
    public function __construct(private float $elapsedSeconds)
    {
        parent::__construct(sprintf('Timed out after %.2f seconds waiting for output', $elapsedSeconds));
    }

    public function elapsedSeconds(): float
    {
        return $this->elapsedSeconds;
    }
}
