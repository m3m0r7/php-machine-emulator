<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class RepPrefix implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF3, 0xF2];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = $runtime->memory();
        $nextOpcode = $reader->byte();

        $isCmpsOrScas = in_array($nextOpcode, [0xA6, 0xAE, 0xA7, 0xAF], true);
        $isMovsOrStos = in_array($nextOpcode, [0xA4, 0xA5, 0xAA, 0xAB], true);

        // REP count uses CX/ECX depending on address-size.
        $counter = $this->readIndex($runtime, RegisterType::ECX);

        if ($counter === 0) {
            return ExecutionStatus::SUCCESS;
        }

        // Bulk optimization for MOVS/STOS (no ZF check needed)
        if ($isMovsOrStos) {
            return $this->bulkStringOp($runtime, $nextOpcode, $counter);
        }

        // For CMPS/SCAS, we need per-iteration ZF check
        while ($counter > 0) {
            $instruction = $this->instructionList->getInstructionByOperationCode($nextOpcode);
            $result = $instruction->process($runtime, $nextOpcode);
            $counter--;
            $this->writeIndex($runtime, RegisterType::ECX, $counter);

            if ($result !== ExecutionStatus::SUCCESS) {
                return $result;
            }

            // REPNE/REPE termination for CMPS/SCAS based on ZF
            $zf = $runtime->memoryAccessor()->shouldZeroFlag();
            if ($opcode === 0xF2 && $zf) { // REPNZ/REPNE
                break;
            }
            if ($opcode === 0xF3 && !$zf) { // REP/REPE
                break;
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * Bulk execution for MOVS/STOS instructions (no ZF check needed)
     */
    private function bulkStringOp(RuntimeInterface $runtime, int $opcode, int $counter): ExecutionStatus
    {
        switch ($opcode) {
            case 0xAA: // STOSB
                $step = $this->stepForElement($runtime, 1);
                $this->bulkStos($runtime, $counter, 8, $step);
                break;
            case 0xAB: // STOSW/STOSD
                $size = $runtime->context()->cpu()->operandSize();
                $elementBytes = $size / 8;  // 2 for 16-bit, 4 for 32-bit
                $step = $this->stepForElement($runtime, $elementBytes);
                $this->bulkStos($runtime, $counter, $size, $step);
                break;
            case 0xA4: // MOVSB
                $step = $this->stepForElement($runtime, 1);
                $this->bulkMovs($runtime, $counter, 8, $step);
                break;
            case 0xA5: // MOVSW/MOVSD
                $size = $runtime->context()->cpu()->operandSize();
                $elementBytes = $size / 8;  // 2 for 16-bit, 4 for 32-bit
                $step = $this->stepForElement($runtime, $elementBytes);
                $this->bulkMovs($runtime, $counter, $size, $step);
                break;
        }

        $this->writeIndex($runtime, RegisterType::ECX, 0);
        return ExecutionStatus::SUCCESS;
    }

    private function bulkStos(RuntimeInterface $runtime, int $count, int $size, int $step): void
    {
        $ma = $runtime->memoryAccessor();
        $value = $size === 8
            ? $ma->fetch(RegisterType::EAX)->asLowBit()
            : $ma->fetch(RegisterType::EAX)->asBytesBySize($size);

        $di = $this->readIndex($runtime, RegisterType::EDI);

        // Debug: log REP STOSD for LZMA probability table initialization
        if ($size === 32 && $value === 0x400 && $count > 100) {
            $runtime->option()->logger()->debug(sprintf(
                'REP STOSD (prob table init?): count=%d value=0x%X di=0x%X',
                $count, $value, $di
            ));
        }

        for ($i = 0; $i < $count; $i++) {
            $address = $this->translateLinear(
                $runtime,
                $this->segmentOffsetAddress($runtime, RegisterType::ES, $di),
                true
            );

            $ma->allocate($address, safe: false);
            if ($size === 8) {
                $ma->writeRawByte($address, $value);
            } else {
                $ma->writeBySize($address, $value, $size);
            }
            $di += $step;
        }

        $this->writeIndex($runtime, RegisterType::EDI, $di);
    }

    private function bulkMovs(RuntimeInterface $runtime, int $count, int $size, int $step): void
    {
        $ma = $runtime->memoryAccessor();
        $sourceSegment = $runtime->context()->cpu()->segmentOverride() ?? RegisterType::DS;

        $si = $this->readIndex($runtime, RegisterType::ESI);
        $di = $this->readIndex($runtime, RegisterType::EDI);

        for ($i = 0; $i < $count; $i++) {
            $srcAddr = $this->segmentOffsetAddress($runtime, $sourceSegment, $si);
            $value = match ($size) {
                8 => $this->readMemory8($runtime, $srcAddr),
                16 => $this->readMemory16($runtime, $srcAddr),
                32 => $this->readMemory32($runtime, $srcAddr),
            };

            $destAddr = $this->translateLinear(
                $runtime,
                $this->segmentOffsetAddress($runtime, RegisterType::ES, $di),
                true
            );
            $ma->allocate($destAddr, safe: false);
            if ($size === 8) {
                $ma->writeRawByte($destAddr, $value);
            } else {
                $ma->writeBySize($destAddr, $value, $size);
            }

            $si += $step;
            $di += $step;
        }

        $this->writeIndex($runtime, RegisterType::ESI, $si);
        $this->writeIndex($runtime, RegisterType::EDI, $di);
    }
}
