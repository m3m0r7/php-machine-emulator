<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Traits;

use PHPMachineEmulator\Instruction\InstructionListInterface;

/**
 * Combined trait that provides all instruction-related functionality.
 * This trait combines all the individual traits into a single composable unit.
 *
 * Use this trait in instruction classes that need full functionality.
 * For more specific needs, use individual traits directly.
 */
trait InstructionBaseTrait
{
    use EnhanceStreamReaderTrait;
    use RegisterAccessTrait;
    use SegmentTrait;
    use MemoryAccessTrait;
    use AddressingTrait;
    use ModRmTrait;
    use FlagsTrait;
    use IoPortTrait;
    use TaskSwitchTrait;

    public function __construct(protected InstructionListInterface $instructionList)
    {
    }

    /**
     * Sign-extend a value from the given bit size to PHP int (64-bit).
     */
    protected function signExtend(int $value, int $bits): int
    {
        if ($bits >= 32) {
            $value &= 0xFFFFFFFF;
            return ($value & 0x80000000) ? $value - 0x100000000 : $value;
        }

        $mask = 1 << ($bits - 1);
        $fullMask = (1 << $bits) - 1;
        $value &= $fullMask;

        return ($value & $mask) ? $value - (1 << $bits) : $value;
    }
}
