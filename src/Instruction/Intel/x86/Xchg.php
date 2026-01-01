<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Xchg implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0x86, 0x87, 0x90, 0x91, 0x92, 0x93, 0x94, 0x95, 0x96, 0x97]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $opSize = $runtime->context()->cpu()->operandSize();

        if ($opcode >= 0x90) {
            // XCHG EAX, r32 / XCHG AX, r16 (0x90 + reg)
            $reg = ($opcode - 0x90);
            $target = ($this->instructionList->register())::find($reg);
            $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);
            $rv = $runtime->memoryAccessor()->fetch($target)->asBytesBySize($opSize);

            $runtime->memoryAccessor()->writeBySize(RegisterType::EAX, $rv, $opSize);
            $runtime->memoryAccessor()->writeBySize($target, $ax, $opSize);
            return ExecutionStatus::SUCCESS;
        }

        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        if ($opcode === 0x86) {
            // For XCHG r8, r/m8: must calculate address ONCE to avoid consuming displacement twice
            $isRegister8 = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
            $linearAddress8 = $isRegister8 ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);

            // Read values
            if ($isRegister8) {
                $rm = $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress());
            } else {
                $rm = $this->readMemory8($runtime, $linearAddress8);
            }
            $reg = $this->read8BitRegister($runtime, $modRegRM->registerOrOPCode());

            // Write swapped values
            if ($isRegister8) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $reg);
            } else {
                $this->writeMemory8($runtime, $linearAddress8, $reg);
            }
            $this->write8BitRegister($runtime, $modRegRM->registerOrOPCode(), $rm);

            return ExecutionStatus::SUCCESS;
        }

        // 0x87: XCHG r/m16, r16 or XCHG r/m32, r32 depending on operand size
        // Important: We need to read the address ONCE and reuse it, otherwise displacement bytes
        // will be consumed twice (once for read, once for write).
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $linearAddress = $isRegister ? null : $this->rmLinearAddress($runtime, $memory, $modRegRM);

        // Read values
        if ($isRegister) {
            $rm = $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $opSize);
        } else {
            $rm = $opSize === 32 ? $this->readMemory32($runtime, $linearAddress) : $this->readMemory16($runtime, $linearAddress);
        }
        $reg = $runtime->memoryAccessor()->fetch($modRegRM->registerOrOPCode())->asBytesBySize($opSize);

        // Write swapped values
        if ($isRegister) {
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $reg, $opSize);
        } else {
            if ($opSize === 32) {
                $this->writeMemory32($runtime, $linearAddress, $reg);
            } else {
                $this->writeMemory16($runtime, $linearAddress, $reg);
            }
        }
        $runtime->memoryAccessor()->writeBySize($modRegRM->registerOrOPCode(), $rm, $opSize);

        return ExecutionStatus::SUCCESS;
    }
}
