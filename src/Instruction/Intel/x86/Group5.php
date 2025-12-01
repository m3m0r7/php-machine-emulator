<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group5 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xFF];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $reader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $reader->byteAsModRegRM();

        return match ($modRegRM->digit()) {
            0x0 => $this->inc($runtime, $reader, $modRegRM),
            0x1 => $this->dec($runtime, $reader, $modRegRM),
            0x2 => $this->callNearRm($runtime, $reader, $modRegRM),
            0x3 => $this->callFarRm($runtime, $reader, $modRegRM),
            0x4 => $this->jmpNearRm($runtime, $reader, $modRegRM),
            0x5 => $this->jmpFarRm($runtime, $reader, $modRegRM),
            0x6 => $this->push($runtime, $reader, $modRegRM),
            default => $this->handleUnimplementedDigit($runtime, $modRegRM),
        };
    }

    protected function inc(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

        if ($isRegister) {
            $value = $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
            $result = ($value + 1) & $mask;
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
            $value = $size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address);
            $result = ($value + 1) & $mask;
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }

        $runtime->memoryAccessor()->updateFlags($result, $size);
        // NOTE: Carry flag unaffected by INC.
        return ExecutionStatus::SUCCESS;
    }

    protected function dec(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

        if ($isRegister) {
            $value = $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
            $result = ($value - 1) & $mask;
            $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $reader, $modRegRM);
            $value = $size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address);
            $result = ($value - 1) & $mask;
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }

        $runtime->memoryAccessor()->updateFlags($result, $size);
        return ExecutionStatus::SUCCESS;
    }

    protected function callNearRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $target = $this->readRm($runtime, $reader, $modRegRM, $size);
        $pos = $runtime->memory()->offset();

        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $pos, $runtime->context()->cpu()->operandSize());

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->memory()->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function jmpNearRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $target = $this->readRm($runtime, $reader, $modRegRM, $size);

        $runtime->option()->logger()->debug(sprintf(
            'JMP [r/m] (Group5): target=0x%08X mode=%d rm=%d',
            $target,
            $modRegRM->mode(),
            $modRegRM->registerOrMemoryAddress()
        ));

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->memory()->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function callFarRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $addr = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $opSize = $runtime->context()->cpu()->operandSize();
        $offset = $opSize === 32 ? $this->readMemory32($runtime, $addr) : $this->readMemory16($runtime, $addr);
        $segment = $this->readMemory16($runtime, $addr + ($opSize === 32 ? 4 : 2));

        // Debug
        $bx = $runtime->memoryAccessor()->fetch(RegisterType::EBX)->asByte();
        $si = $runtime->memoryAccessor()->fetch(RegisterType::ESI)->asByte();
        $ds = $runtime->memoryAccessor()->fetch(RegisterType::DS)->asByte();
        $runtime->option()->logger()->debug(sprintf(
            'CALL FAR: DS=0x%X BX=0x%X SI=0x%X addr=0x%X offset=0x%X segment=0x%X',
            $ds, $bx, $si, $addr, $offset, $segment
        ));

        $pos = $runtime->memory()->offset();

        $size = $runtime->context()->cpu()->operandSize();

        $currentCs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
        $returnOffset = $this->codeOffsetFromLinear($runtime, $currentCs, $pos, $size);

        if ($runtime->context()->cpu()->isProtectedMode()) {
            $gate = $this->readCallGateDescriptor($runtime, $segment);
            if ($gate !== null) {
                $this->callThroughGate($runtime, $gate, $returnOffset, $currentCs, $size);
                return ExecutionStatus::SUCCESS;
            }
        }

        // push return CS:IP on current stack
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $currentCs, $size);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $returnOffset, $size);

        if ($runtime->option()->shouldChangeOffset()) {
            if ($runtime->context()->cpu()->isProtectedMode()) {
                $descriptor = $this->resolveCodeDescriptor($runtime, $segment);
                $newCpl = $this->computeCplForTransfer($runtime, $segment, $descriptor);
                $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                $runtime->memory()->setOffset($linearTarget);
                $this->writeCodeSegment($runtime, $segment, $newCpl, $descriptor);
            } else {
                $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                $runtime->memory()->setOffset($linearTarget);
                $this->writeCodeSegment($runtime, $segment);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function jmpFarRm(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $addr = $this->rmLinearAddress($runtime, $reader, $modRegRM);
        $opSize = $runtime->context()->cpu()->operandSize();
        $offset = $opSize === 32 ? $this->readMemory32($runtime, $addr) : $this->readMemory16($runtime, $addr);
        $segment = $this->readMemory16($runtime, $addr + ($opSize === 32 ? 4 : 2));

        if ($runtime->option()->shouldChangeOffset()) {
            if ($runtime->context()->cpu()->isProtectedMode()) {
                $gate = $this->readCallGateDescriptor($runtime, $segment);
                if ($gate !== null) {
                    $currentCs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
                    $returnOffset = $this->codeOffsetFromLinear($runtime, $currentCs, $runtime->memory()->offset(), $opSize);
                    $this->callThroughGate($runtime, $gate, $returnOffset, $currentCs, $opSize, pushReturn: false, copyParams: false);
                } else {
                    $descriptor = $this->resolveCodeDescriptor($runtime, $segment);
                    $newCpl = $this->computeCplForTransfer($runtime, $segment, $descriptor);
                    $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                    $runtime->memory()->setOffset($linearTarget);
                    $this->writeCodeSegment($runtime, $segment, $newCpl, $descriptor);
                }
            } else {
                $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
                $runtime->memory()->setOffset($linearTarget);
                $this->writeCodeSegment($runtime, $segment);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function push(RuntimeInterface $runtime, EnhanceStreamReader $reader, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $size = $runtime->context()->cpu()->operandSize();
        $value = $this->readRm($runtime, $reader, $modRegRM, $size);
        $runtime->memoryAccessor()->enableUpdateFlags(false)->push(RegisterType::ESP, $value, $size);
        return ExecutionStatus::SUCCESS;
    }

    protected function handleUnimplementedDigit(RuntimeInterface $runtime, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $ip = $runtime->memory()->offset() - 2;

        // Dump memory around IP
        $memory = $runtime->memory();
        $savedOffset = $memory->offset();
        $memory->setOffset($ip);
        $bytes = [];
        for ($i = 0; $i < 16; $i++) {
            $bytes[] = sprintf('%02X', $memory->byte());
        }
        $memory->setOffset($savedOffset);

        $runtime->option()->logger()->error(sprintf(
            'Group5 digit 0x%X at IP=0x%05X, memory dump: %s',
            $modRegRM->digit(),
            $ip,
            implode(' ', $bytes)
        ));

        throw new ExecutionException(sprintf(
            'Group5 digit 0x%X not implemented at IP=0x%05X',
            $modRegRM->digit(),
            $ip
        ));
    }
}
