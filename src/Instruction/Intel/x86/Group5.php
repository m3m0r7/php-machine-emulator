<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\NullPointerException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\Intel\BIOSInterrupt;
use PHPMachineEmulator\Instruction\Intel\Register;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Disk;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Keyboard;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\MemorySize;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\System;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Timer;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\TimeOfDay;
use PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt\Video;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Group5 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xFF]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $hasOperandSizeOverridePrefix = in_array(self::PREFIX_OPERAND_SIZE, $opcodes, true);
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        return match ($modRegRM->digit()) {
            0x0 => $this->inc($runtime, $memory, $modRegRM),
            0x1 => $this->dec($runtime, $memory, $modRegRM),
            0x2 => $this->callNearRm($runtime, $memory, $modRegRM),
            0x3 => $this->callFarRm($runtime, $memory, $modRegRM),
            0x4 => $this->jmpNearRm($runtime, $memory, $modRegRM),
            0x5 => $this->jmpFarRm($runtime, $memory, $modRegRM),
            0x6 => $this->push($runtime, $memory, $modRegRM, $hasOperandSizeOverridePrefix),
            0x7 => $this->handleDigit7($runtime, $memory, $modRegRM),
            default => $this->handleUnimplementedDigit($runtime, $modRegRM),
        };
    }

    protected function inc(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $size = $cpu->operandSize();
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $ma = $runtime->memoryAccessor();

        // Preserve CF - INC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();

        if ($size === 64) {
            if ($isRegister) {
                $regType = Register::findGprByCode(
                    $modRegRM->registerOrMemoryAddress(),
                    $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB(),
                );
                $old = $ma->fetch($regType)->asBytesBySize(64);
                $resultU = UInt64::of($old)->add(1);
                $result = $resultU->toInt();
                $ma->writeBySize($regType, $result, 64);
            } else {
                $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
                $oldU = $this->readMemory64($runtime, $address);
                $resultU = $oldU->add(1);
                $result = $resultU->toInt();
                $this->writeMemory64($runtime, $address, $resultU);
                $old = $oldU->toInt();
            }

            $ma->updateFlags($result, 64);
            $ma->setAuxiliaryCarryFlag((($old & 0x0F) + 1) > 0x0F);
            $ma->setOverflowFlag($result === PHP_INT_MIN);
            $ma->setCarryFlag($savedCf);
            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;

        if ($isRegister) {
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
                $regType = Register::findGprByCode($modRegRM->registerOrMemoryAddress(), $cpu->rexB());
                $value = $ma->fetch($regType)->asBytesBySize($size);
                $result = ($value + 1) & $mask;
                $ma->writeBySize($regType, $result, $size);
            } else {
                $value = $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
                $result = ($value + 1) & $mask;
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
            }
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            $value = $size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address);
            $result = ($value + 1) & $mask;
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }

        $af = (($value & 0x0F) + 1) > 0x0F;
        $ma->updateFlags($result, $size);
        $ma->setAuxiliaryCarryFlag($af);
        $ma->setCarryFlag($savedCf);

        // OF for INC: set when result is SIGN_MASK (0x8000, 0x80000000)
        $signMask = 1 << ($size - 1);
        $ma->setOverflowFlag($result === $signMask);

        return ExecutionStatus::SUCCESS;
    }

    protected function dec(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();
        $size = $cpu->operandSize();
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;
        $ma = $runtime->memoryAccessor();

        // Preserve CF - DEC does not affect carry flag
        $savedCf = $ma->shouldCarryFlag();

        if ($size === 64) {
            if ($isRegister) {
                $regType = Register::findGprByCode(
                    $modRegRM->registerOrMemoryAddress(),
                    $cpu->isLongMode() && !$cpu->isCompatibilityMode() && $cpu->rexB(),
                );
                $old = $ma->fetch($regType)->asBytesBySize(64);
                $resultU = UInt64::of($old)->sub(1);
                $result = $resultU->toInt();
                $ma->writeBySize($regType, $result, 64);
            } else {
                $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
                $oldU = $this->readMemory64($runtime, $address);
                $resultU = $oldU->sub(1);
                $result = $resultU->toInt();
                $this->writeMemory64($runtime, $address, $resultU);
                $old = $oldU->toInt();
            }

            $ma->updateFlags($result, 64);
            $ma->setAuxiliaryCarryFlag(($old & 0x0F) === 0);
            $ma->setOverflowFlag($result === PHP_INT_MAX);
            $ma->setCarryFlag($savedCf);
            return ExecutionStatus::SUCCESS;
        }

        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;

        if ($isRegister) {
            if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
                $regType = Register::findGprByCode($modRegRM->registerOrMemoryAddress(), $cpu->rexB());
                $value = $ma->fetch($regType)->asBytesBySize($size);
                $result = ($value - 1) & $mask;
                $ma->writeBySize($regType, $result, $size);
            } else {
                $value = $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
                $result = ($value - 1) & $mask;
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
            }
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            $value = $size === 32 ? $this->readMemory32($runtime, $address) : $this->readMemory16($runtime, $address);
            $result = ($value - 1) & $mask;
            if ($size === 32) {
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $this->writeMemory16($runtime, $address, $result);
            }
        }

        $af = (($value & 0x0F) === 0);
        $ma->updateFlags($result, $size);
        $ma->setAuxiliaryCarryFlag($af);
        $ma->setCarryFlag($savedCf);

        // OF for DEC: set when result is SIGN_MASK - 1 (0x7FFF, 0x7FFFFFFF)
        $signMask = 1 << ($size - 1);
        $ma->setOverflowFlag($result === ($signMask - 1));

        return ExecutionStatus::SUCCESS;
    }

    protected function callNearRm(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();

        // In 64-bit mode, CALL r/m always uses a 64-bit target and pushes 64-bit RIP.
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

            if ($isRegister) {
                $regType = Register::findGprByCode($modRegRM->registerOrMemoryAddress(), $cpu->rexB());
                $target = $runtime->memoryAccessor()->fetch($regType)->asBytesBySize(64);
            } else {
                $addr = $this->rmLinearAddress($runtime, $memory, $modRegRM);
                $target = $this->readMemory64($runtime, $addr)->toInt();
            }

            $returnRip = $runtime->memory()->offset();

            if ($target === 0) {
                throw new NullPointerException(sprintf(
                    'CALL to NULL pointer (0x0000000000000000) at IP=0x%05X',
                    $returnRip - 2
                ));
            }

            $runtime->memoryAccessor()->push(RegisterType::ESP, $returnRip, 64);

            if ($runtime->option()->shouldChangeOffset()) {
                $runtime->memory()->setOffset($target);
            }

            return ExecutionStatus::SUCCESS;
        }

        $size = $cpu->operandSize();

        // Indirect near call uses an offset relative to current CS.
        $targetOffset = $this->readRm($runtime, $memory, $modRegRM, $size);
        $posLinear = $runtime->memory()->offset();
        $cs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
        $returnOffset = $this->codeOffsetFromLinear($runtime, $cs, $posLinear, $size);
        $linearTarget = $this->linearCodeAddress($runtime, $cs, (int)$targetOffset, $size);

        // Check for NULL pointer call
        if (($cs & 0xFFFF) === 0 && ((int)$targetOffset & 0xFFFF) === 0) {
            throw new NullPointerException(sprintf(
                'CALL to NULL pointer (0x00000000) at IP=0x%05X - possible uninitialized function pointer or failed module load',
                $posLinear - 2
            ));
        }

        $runtime->memoryAccessor()->push(RegisterType::ESP, $returnOffset, $size);

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->memory()->setOffset($linearTarget);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function jmpNearRm(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();

        // In 64-bit mode, JMP r/m always uses a 64-bit target.
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

            if ($isRegister) {
                $regType = Register::findGprByCode($modRegRM->registerOrMemoryAddress(), $cpu->rexB());
                $target = $runtime->memoryAccessor()->fetch($regType)->asBytesBySize(64);
            } else {
                $addr = $this->rmLinearAddress($runtime, $memory, $modRegRM);
                $target = $this->readMemory64($runtime, $addr)->toInt();
            }

            $runtime->option()->logger()->debug(sprintf(
                'JMP [r/m] (Group5 64-bit): target=0x%016X mode=%d rm=%d',
                $target,
                $modRegRM->mode(),
                $modRegRM->registerOrMemoryAddress()
            ));

            if ($target === 0) {
                throw new NullPointerException(sprintf(
                    'JMP to NULL pointer (0x0000000000000000) at IP=0x%05X',
                    $runtime->memory()->offset() - 2
                ));
            }

            if ($runtime->option()->shouldChangeOffset()) {
                $runtime->memory()->setOffset($target);
            }

            return ExecutionStatus::SUCCESS;
        }

        $size = $cpu->operandSize();
        // Indirect near jump uses an offset relative to current CS.
        $targetOffset = $this->readRm($runtime, $memory, $modRegRM, $size);
        $cs = $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte();
        $target = $this->linearCodeAddress($runtime, $cs, (int)$targetOffset, $size);

        $runtime->option()->logger()->debug(sprintf(
            'JMP [r/m] (Group5): target=0x%08X mode=%d rm=%d',
            $target,
            $modRegRM->mode(),
            $modRegRM->registerOrMemoryAddress()
        ));

        // Check for NULL pointer jump
        if ($target === 0) {
            throw new NullPointerException(sprintf(
                'JMP to NULL pointer (0x00000000) at IP=0x%05X - possible uninitialized function pointer or failed module load',
                $runtime->memory()->offset() - 2
            ));
        }

        if ($runtime->option()->shouldChangeOffset()) {
            $runtime->memory()->setOffset($target);
        }

        return ExecutionStatus::SUCCESS;
    }

    protected function callFarRm(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $addr = $this->rmLinearAddress($runtime, $memory, $modRegRM);
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

        // In real mode, some DOS initialization paths call through optional far pointers.
        // If the pointer is NULL or points into an all-zero/uninitialized region, skip it
        // instead of jumping into garbage.
        if (!$runtime->context()->cpu()->isProtectedMode()) {
            if ($segment === 0 && $offset === 0) {
                $runtime->option()->logger()->debug('CALL FAR: null far pointer, skipping');
                // If caller used PUSHF before chaining to an IRET-style handler,
                // remove that flags word to keep the stack balanced.
                $sp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(16);
                $flagsLinear = $this->segmentOffsetAddress($runtime, RegisterType::SS, $sp);
                $flagsCandidate = $this->readMemory16($runtime, $flagsLinear);
                if (($flagsCandidate & 0x2) !== 0 && ($flagsCandidate & 0x28) === 0 && ($flagsCandidate & 0x8000) === 0) {
                    $runtime->memoryAccessor()->pop(RegisterType::ESP, 16);
                }
                return ExecutionStatus::SUCCESS;
            }
            $linearTarget = $this->linearCodeAddress($runtime, $segment, $offset, $opSize);
            $allZero = true;
            for ($i = 0; $i < 8; $i++) {
                if ($this->readMemory8($runtime, $linearTarget + $i) !== 0x00) {
                    $allZero = false;
                    break;
                }
            }
            if ($allZero) {
                $runtime->option()->logger()->debug(sprintf(
                    'CALL FAR: target 0x%04X:%04X (linear 0x%05X) looks uninitialized, skipping',
                    $segment,
                    $offset,
                    $linearTarget
                ));
                $sp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize(16);
                $flagsLinear = $this->segmentOffsetAddress($runtime, RegisterType::SS, $sp);
                $flagsCandidate = $this->readMemory16($runtime, $flagsLinear);
                if (($flagsCandidate & 0x2) !== 0 && ($flagsCandidate & 0x28) === 0 && ($flagsCandidate & 0x8000) === 0) {
                    $runtime->memoryAccessor()->pop(RegisterType::ESP, 16);
                }
                return ExecutionStatus::SUCCESS;
            }
        }

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

        // push return CS:IP on current stack (CS is always 16-bit)
        $runtime->memoryAccessor()->push(RegisterType::ESP, $currentCs, 16);
        $runtime->memoryAccessor()->push(RegisterType::ESP, $returnOffset, $size);

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

    protected function jmpFarRm(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        $addr = $this->rmLinearAddress($runtime, $memory, $modRegRM);
        $opSize = $runtime->context()->cpu()->operandSize();
        $offset = $opSize === 32 ? $this->readMemory32($runtime, $addr) : $this->readMemory16($runtime, $addr);
        $segment = $this->readMemory16($runtime, $addr + ($opSize === 32 ? 4 : 2));

        // Check if jumping to the default BIOS IVT handler (F000:FF53)
        // This happens when a custom interrupt handler chains to the original BIOS handler
        // We determine the BIOS service to call by looking at the AH register value
        if (!$runtime->context()->cpu()->isProtectedMode() && $segment === 0xF000 && $offset === 0xFF53) {
            $ah = ($runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize(16) >> 8) & 0xFF;

            // Determine interrupt vector based on AH value
            // Common BIOS services:
            // AH=0x00-0x1F: Video (INT 10h)
            // AH=0x41,0x42,0x02,0x03,0x08,0x15: Disk (INT 13h)
            // AH=0x00-0x12 and AH >= 0x80: Keyboard (INT 16h)
            $intVector = match (true) {
                $ah === 0x42 || $ah === 0x02 || $ah === 0x03 || $ah === 0x08 || $ah === 0x15 || $ah === 0x41 => 0x13, // Disk
                $ah === 0x0E || $ah === 0x00 || $ah === 0x01 || $ah === 0x02 || $ah === 0x03 || $ah === 0x06 || $ah === 0x07 || $ah === 0x0B || $ah === 0x0C || $ah === 0x0F || $ah === 0x10 || $ah === 0x11 || $ah === 0x12 || $ah === 0x13 => 0x10, // Video
                default => 0x10, // Default to video
            };

            $runtime->option()->logger()->debug(sprintf(
                'JMP FAR to BIOS IVT handler [F000:FF53]: AH=0x%02X -> INT 0x%02X',
                $ah,
                $intVector
            ));

            // Execute the BIOS handler for this interrupt vector
            $this->executeBiosHandler($runtime, $intVector);

            // Now execute IRET to return to the caller
            // Pop IP, CS, FLAGS from stack and return to caller
            $ma = $runtime->memoryAccessor();
            $ip = $ma->pop(RegisterType::ESP, 16)->asBytesBySize(16);
            $cs = $ma->pop(RegisterType::ESP, 16)->asBytesBySize(16);
            $flags = $ma->pop(RegisterType::ESP, 16)->asBytesBySize(16);
            $this->writeCodeSegment($runtime, $cs);
            $linear = $this->linearCodeAddress($runtime, $cs & 0xFFFF, $ip, 16);
            $runtime->option()->logger()->debug(sprintf(
                'JMP FAR -> BIOS -> IRET: CS=0x%04X IP=0x%04X linear=0x%05X flags=0x%04X',
                $cs, $ip, $linear, $flags
            ));
            $runtime->memory()->setOffset($linear);
            $ma->setCarryFlag(($flags & 0x1) !== 0);
            $ma->setParityFlag(($flags & (1 << 2)) !== 0);
            $ma->setZeroFlag(($flags & (1 << 6)) !== 0);
            $ma->setSignFlag(($flags & (1 << 7)) !== 0);
            $ma->setOverflowFlag(($flags & (1 << 11)) !== 0);
            $ma->setDirectionFlag(($flags & (1 << 10)) !== 0);
            $ma->setInterruptFlag(($flags & (1 << 9)) !== 0);
            return ExecutionStatus::SUCCESS;
        }

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

    protected function push(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, bool $hasOperandSizeOverridePrefix = false): ExecutionStatus
    {
        $cpu = $runtime->context()->cpu();

        // In 64-bit mode, PUSH r/m defaults to 64-bit (pushq). 0x66 prefix selects 16-bit (pushw).
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $pushSize = $hasOperandSizeOverridePrefix ? 16 : 64;
            $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

            if ($isRegister) {
                $regType = Register::findGprByCode($modRegRM->registerOrMemoryAddress(), $cpu->rexB());
                $value = $runtime->memoryAccessor()->fetch($regType)->asBytesBySize($pushSize);
            } else {
                $addr = $this->rmLinearAddress($runtime, $memory, $modRegRM);
                $value = match ($pushSize) {
                    16 => $this->readMemory16($runtime, $addr),
                    default => $this->readMemory64($runtime, $addr)->toInt(),
                };
            }

            $runtime->memoryAccessor()->push(RegisterType::ESP, $value, $pushSize);
            return ExecutionStatus::SUCCESS;
        }

        $size = $cpu->operandSize();
        $value = $this->readRm($runtime, $memory, $modRegRM, $size);
        $runtime->memoryAccessor()->push(RegisterType::ESP, $value instanceof UInt64 ? $value->toInt() : $value, $size);
        return ExecutionStatus::SUCCESS;
    }

    protected function handleDigit7(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): ExecutionStatus
    {
        // Intel spec: digit 7 of 0xFF is undefined
        // Some assemblers may encode it as a long-form PUSH
        // We'll treat it as PUSH (same as digit 6) but log a warning
        $runtime->option()->logger()->debug(sprintf(
            'Group5 digit 7 encountered at IP=0x%05X, treating as PUSH (undefined in Intel spec)',
            $runtime->memory()->offset() - 2
        ));

        // Consume any displacement bytes
        if (ModType::from($modRegRM->mode()) !== ModType::REGISTER_TO_REGISTER) {
            $this->rmLinearAddress($runtime, $memory, $modRegRM);
        }

        // Skip the immediate/memory operand - just continue execution
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

    private array $biosHandlerInstances = [];

    protected function executeBiosHandler(RuntimeInterface $runtime, int $vector): void
    {
        $operand = BIOSInterrupt::tryFrom($vector);

        if ($operand === null) {
            return;
        }

        match ($operand) {
            BIOSInterrupt::TIMER_INTERRUPT => ($this->biosHandlerInstances[Timer::class] ??= new Timer())
                ->process($runtime),
            BIOSInterrupt::VIDEO_INTERRUPT => ($this->biosHandlerInstances[Video::class] ??= new Video($runtime))
                ->process($runtime),
            BIOSInterrupt::MEMORY_SIZE_INTERRUPT => ($this->biosHandlerInstances[MemorySize::class] ??= new MemorySize())
                ->process($runtime),
            BIOSInterrupt::DISK_INTERRUPT => ($this->biosHandlerInstances[Disk::class] ??= new Disk($runtime))
                ->process($runtime),
            BIOSInterrupt::KEYBOARD_INTERRUPT => ($this->biosHandlerInstances[Keyboard::class] ??= new Keyboard($runtime))
                ->process($runtime),
            BIOSInterrupt::TIME_OF_DAY_INTERRUPT => ($this->biosHandlerInstances[TimeOfDay::class] ??= new TimeOfDay())
                ->process($runtime),
            BIOSInterrupt::SYSTEM_INTERRUPT => ($this->biosHandlerInstances[System::class] ??= new System())->process($runtime),
            default => null,
        };
    }
}
