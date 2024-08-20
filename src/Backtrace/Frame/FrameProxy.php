<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Backtrace\Frame;

use PHPMachineEmulator\Backtrace\BacktraceableInterface;
use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Frame\FrameSetInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class FrameProxy implements FrameInterface, BacktraceableInterface
{
    public const APPENDED = 0;
    public const POPPED = 1;

    protected int $index = 0;
    protected array $backtraces = [];

    public function __construct(protected RuntimeInterface $runtime, protected FrameInterface $frame)
    {
        $this->backtraces[$this->index] = null;
    }

    public function append(FrameSetInterface $frameSet): FrameInterface
    {
        $this->backtraces[$this->index] = [self::APPENDED, $frameSet];
        return $this->frame->append($frameSet);
    }

    public function pop(): FrameSetInterface|null
    {
        $popped = $this->frame->pop();
        $this->backtraces[$this->index] = [self::POPPED, $popped];
        return $popped;
    }

    public function next(): void
    {
        $this->index++;
        $this->backtraces[$this->index] = [null, null];
    }

    public function last(): array|null
    {
        return $this->backtraces[$this->index];
    }

    public function frameSets(): array
    {
        return $this->frame->frameSets();
    }
}
