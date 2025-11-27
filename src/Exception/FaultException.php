<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Exception;

class FaultException extends ExecutionException
{
    public function __construct(
        protected int $vector,
        protected ?int $errorCode = null,
        string $message = '',
    ) {
        parent::__construct($message ?: sprintf('CPU fault vector 0x%02X', $vector));
    }

    public function vector(): int
    {
        return $this->vector;
    }

    public function errorCode(): ?int
    {
        return $this->errorCode;
    }
}
