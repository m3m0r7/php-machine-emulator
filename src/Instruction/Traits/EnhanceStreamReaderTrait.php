<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Trait for creating enhanced stream readers.
 * Used by both x86 and x86_64 instructions.
 */
trait EnhanceStreamReaderTrait
{
    protected function enhanceReader(RuntimeInterface $runtime): EnhanceStreamReader
    {
        return new EnhanceStreamReader($runtime->memory());
    }
}
