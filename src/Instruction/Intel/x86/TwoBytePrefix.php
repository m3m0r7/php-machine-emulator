<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class TwoBytePrefix implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0x0F];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->streamReader());
        $next = $reader->streamReader()->byte();

        return match ($next) {
            0x20 => $this->movFromControl($runtime, $reader),
            0x22 => $this->movToControl($runtime, $reader),
            0x01 => $this->group6($runtime, $reader),
            default => ExecutionStatus::SUCCESS, // unimplemented 2-byte opcode acts as NOP for now
        };
    }

    private function movFromControl(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('MOV from CR requires register addressing');
        }
        $cr = $modrm->registerOrOPCode() & 0b111;
        $val = $runtime->memoryAccessor()->readControlRegister($cr);
        $size = $runtime->runtimeOption()->context()->operandSize();
        $runtime->memoryAccessor()->enableUpdateFlags(false)->writeBySize($modrm->registerOrMemoryAddress(), $val, $size);
        return ExecutionStatus::SUCCESS;
    }

    private function movToControl(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();
        if (ModType::from($modrm->mode()) !== ModType::REGISTER_TO_REGISTER) {
            throw new ExecutionException('MOV to CR requires register addressing');
        }
        $cr = $modrm->registerOrOPCode() & 0b111;
        $size = $runtime->runtimeOption()->context()->operandSize();
        $val = $runtime->memoryAccessor()->fetch($modrm->registerOrMemoryAddress())->asBytesBySize($size);
        $runtime->memoryAccessor()->writeControlRegister($cr, $val);

        if ($cr === 0) {
            // update protected mode flag from CR0.PE
            $runtime->runtimeOption()->context()->setProtectedMode((bool) ($val & 0x1));
        }

        return ExecutionStatus::SUCCESS;
    }

    private function group6(RuntimeInterface $runtime, EnhanceStreamReader $reader): ExecutionStatus
    {
        $modrm = $reader->byteAsModRegRM();

        return match ($modrm->registerOrOPCode()) {
            0b010 => $this->lgdt($runtime, $reader, $modrm),
            0b011 => $this->lidt($runtime, $reader, $modrm),
            default => ExecutionStatus::SUCCESS,
        };
    }

    private function lgdt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $limit = $this->readMemory16($runtime, $address);
        $base = $this->readMemory16($runtime, $address + 2);
        $base |= ($this->readMemory16($runtime, $address + 4) << 16) & 0xFFFF0000;
        $runtime->runtimeOption()->context()->setGdtr($base, $limit);
        return ExecutionStatus::SUCCESS;
    }

    private function lidt(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modrm): ExecutionStatus
    {
        $address = $this->rmLinearAddress($runtime, $reader, $modrm);
        $limit = $this->readMemory16($runtime, $address);
        $base = $this->readMemory16($runtime, $address + 2);
        $base |= ($this->readMemory16($runtime, $address + 4) << 16) & 0xFFFF0000;
        $runtime->runtimeOption()->context()->setIdtr($base, $limit);
        return ExecutionStatus::SUCCESS;
    }
}
