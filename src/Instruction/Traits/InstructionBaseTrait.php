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
    use RegisterAccessTrait;
    use SegmentTrait;
    use MemoryAccessTrait;
    use AddressingTrait;
    use ModRmTrait;
    use FlagsTrait;
    use IoPortTrait;
    use TaskSwitchTrait;
    use SignTrait;
    use InstructionPrefixApplyable;

    public function __construct(protected InstructionListInterface $instructionList)
    {
    }
}
