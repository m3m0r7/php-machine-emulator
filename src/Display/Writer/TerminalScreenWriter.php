<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Writer;

use PHPMachineEmulator\Display\Cursor;
use PHPMachineEmulator\Display\CursorInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Video\VideoTypeInfo;

class TerminalScreenWriter implements ScreenWriterInterface
{
    public function __construct(protected RuntimeInterface $runtime, protected VideoTypeInfo $videoTypeInfo)
    {
    }

    public function write(string $value): void
    {
        $this->runtime
            ->option()
            ->IO()
            ->output()
            ->write($value);
    }
}
