<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Exception\KeyboardException;
use PHPMachineEmulator\Exception\ReadKeyEndedException;
use PHPMachineEmulator\Exception\StreamReaderException;

class KeyboardReaderStream implements StreamReaderInterface
{
    /**
     * @var resource $resource
     */
    protected mixed $resource;

    public function __construct($resource)
    {
        $this->resource = $resource;

        if (!is_resource($this->resource)) {
            throw new StreamReaderException('The parameter is not a resource');
        }
    }

    public function offset(): int
    {
        throw new KeyboardException(
            sprintf(
                'The keyboard reader is not supported %s',
                __FUNCTION__,
            ),
        );
    }

    public function setOffset(int $newOffset): StreamReaderInterface
    {
        throw new KeyboardException(
            sprintf(
                'The keyboard reader is not supported %s',
                __FUNCTION__,
            ),
        );
    }

    public function isEOF(): bool
    {
        throw new KeyboardException(
            sprintf(
                'The keyboard reader is not supported %s',
                __FUNCTION__,
            ),
        );
    }

    public function char(): string
    {
        $char = fread($this->resource, 1);

        if ($char === false || $char === '') {
            // When running in a non-interactive environment, treat EOF as "no key pressed".
            return "\0";
        }

        return $char;
    }

    public function byte(): int
    {
        return unpack('C', $this->char())[1];
    }

    public function signedByte(): int
    {
        throw new KeyboardException(
            sprintf(
                'The keyboard reader is not supported %s',
                __FUNCTION__,
            ),
        );
    }

    public function short(): int
    {
        throw new KeyboardException(
            sprintf(
                'The keyboard reader is not supported %s',
                __FUNCTION__,
            ),
        );
    }

    public function dword(): int
    {
        throw new KeyboardException(
            sprintf(
                'The keyboard reader is not supported %s',
                __FUNCTION__,
            ),
        );
    }

    public function read(int $length): string
    {
        throw new KeyboardException(
            sprintf(
                'The keyboard reader is not supported %s',
                __FUNCTION__,
            ),
        );
    }
}
