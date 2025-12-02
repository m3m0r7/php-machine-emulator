<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Exception;

/**
 * Exception thrown when an invalid or undefined opcode is encountered.
 * Corresponds to #UD (Invalid Opcode) exception, vector 6.
 */
class InvalidOpcodeException extends FaultException
{
    public function __construct(int $opcode, string $context = '')
    {
        $message = sprintf(
            'Invalid opcode: 0x%02X%s',
            $opcode,
            $context ? " ({$context})" : ''
        );
        parent::__construct(0x06, null, $message);
    }
}
