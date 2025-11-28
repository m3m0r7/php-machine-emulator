<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Movsg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x8E];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader->byteAsModRegRM();

        if (ModType::from($modRegRM->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException(
                sprintf('The addressing mode (0b%02s) is not supported yet', decbin($modRegRM->mode()))
            );
        }

        $segment = $modRegRM->destination() + ($runtime->register())::getRaisedSegmentRegister();
        $selector = $runtime->memoryAccessor()->fetch($modRegRM->source())->asByte();
        $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit($segment, $selector);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->readSegmentDescriptor($runtime, $selector);
            if ($descriptor === null || !$descriptor['present']) {
                // Allow null selector (0) or invalid selectors for boot compatibility
                // In real hardware this would fault, but early boot code often sets
                // segment registers before GDT is fully configured
                if ($selector !== 0) {
                    $runtime->option()->logger()->debug(sprintf(
                        'Segment selector 0x%04X not present in GDT during MOV to segment register',
                        $selector
                    ));
                }
                return ExecutionStatus::SUCCESS;
            }
            $segmentType = $runtime->register()->find($segment);
            $dataSegments = [RegisterType::SS, RegisterType::DS, RegisterType::ES, RegisterType::FS, RegisterType::GS];
            if ($segmentType === RegisterType::CS) {
                throw new FaultException(0x0D, $selector, 'Cannot load CS with MOV Sreg,r/m');
            }
            if ($descriptor['executable'] && in_array($segmentType, $dataSegments, true)) {
                throw new FaultException(0x0D, $selector, 'Cannot load code segment into data segment register');
            }
        }

        return ExecutionStatus::SUCCESS;
    }
}
