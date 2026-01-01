<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Exception;

/**
 * Fault exception raised during instruction fetch/decode.
 * Carries the instruction pointer that initiated the fetch.
 */
class FetchFaultException extends FaultException
{
    public function __construct(
        private int $fetchIp,
        int $vector,
        ?int $errorCode = null,
        string $message = '',
    ) {
        parent::__construct($vector, $errorCode, $message);
    }

    public function fetchIp(): int
    {
        return $this->fetchIp;
    }
}
