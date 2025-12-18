<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class PushReg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes(array_keys($this->registersAndOPCodes()));
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $hasOperandSizeOverridePrefix = in_array(self::PREFIX_OPERAND_SIZE, $opcodes, true);
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $cpu = $runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $pushSize = $hasOperandSizeOverridePrefix ? 16 : 64;
            $regCode = $opcode & 0x7;
            $regType = Register::findGprByCode($regCode, $cpu->rexB());
            $value = $runtime->memoryAccessor()->fetch($regType)->asBytesBySize($pushSize);
            $runtime->memoryAccessor()->push(RegisterType::ESP, $value, $pushSize);
            return ExecutionStatus::SUCCESS;
        }

        $size = $cpu->operandSize();
        $regType = $this->registersAndOPCodes()[$opcode];
        $fetchResult = $runtime->memoryAccessor()->fetch($regType)->asBytesBySize($size);
        $runtime->memoryAccessor()->push(RegisterType::ESP, $fetchResult, $size);

        return ExecutionStatus::SUCCESS;
    }

    private function registersAndOPCodes(): array
    {
        return [
            0x50 + ($this->instructionList->register())::addressBy(RegisterType::EAX) => RegisterType::EAX,
            0x50 + ($this->instructionList->register())::addressBy(RegisterType::ECX) => RegisterType::ECX,
            0x50 + ($this->instructionList->register())::addressBy(RegisterType::EDX) => RegisterType::EDX,
            0x50 + ($this->instructionList->register())::addressBy(RegisterType::EBX) => RegisterType::EBX,

            0x50 + ($this->instructionList->register())::addressBy(RegisterType::ESP) => RegisterType::ESP,
            0x50 + ($this->instructionList->register())::addressBy(RegisterType::EBP) => RegisterType::EBP,
            0x50 + ($this->instructionList->register())::addressBy(RegisterType::ESI) => RegisterType::ESI,
            0x50 + ($this->instructionList->register())::addressBy(RegisterType::EDI) => RegisterType::EDI,
        ];
    }
}
