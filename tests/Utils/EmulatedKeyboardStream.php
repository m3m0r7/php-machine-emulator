<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Stream\KeyboardReaderStream;
use PHPMachineEmulator\Stream\StreamReaderInterface;

class EmulatedKeyboardStream extends KeyboardReaderStream implements StreamReaderInterface
{
    private int $pos = 0;

    public function __construct(private readonly string $input = "Hello World!\r")
    {
    }

    public function char(): string
    {
        return $this->input[$this->pos++ % strlen($this->input)];
    }
}
