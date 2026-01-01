<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Util\UInt64;

trait GroupShiftRotate
{
    use GroupRmOperand;

    protected function count(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM): int
    {
        return match ($opcode) {
            0xC0, 0xC1 => $runtime->memory()->byte(),
            0xD0, 0xD1 => 1,
            0xD2, 0xD3 => $runtime->memoryAccessor()->fetch(RegisterType::ECX)->asLowBit(),
            default => 0,
        };
    }

    protected function isByteOp(int $opcode): bool
    {
        return in_array($opcode, [0xC0, 0xD0, 0xD2], true);
    }

    private function countMask(int $size): int
    {
        // In 64-bit mode with 64-bit operand, shift/rotate counts are masked to 6 bits (0-63).
        return $size === 64 ? 0x3F : 0x1F;
    }

    protected function shiftCountValue(
        RuntimeInterface $runtime,
        int $opcode,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        int $size,
    ): int {
        return $this->count($runtime, $opcode, $memory, $modRegRM) & $this->countMask($size);
    }

    protected function scalarMask(int $size): int
    {
        return match ($size) {
            32 => 0xFFFFFFFF,
            16 => 0xFFFF,
            default => 0xFF,
        };
    }

    protected function updateResultFlags(RuntimeInterface $runtime, int|UInt64 $result, int $size): void
    {
        $ma = $runtime->memoryAccessor();
        $value = $result instanceof UInt64 ? $result->toInt() : $result;

        if ($size === 64) {
            if ($result instanceof UInt64) {
                $ma->setZeroFlag($result->isZero());
                $ma->setSignFlag($result->isNegativeSigned());
                $lowByte = $result->low32() & 0xFF;
            } else {
                $ma->setZeroFlag($value === 0);
                $ma->setSignFlag($value < 0);
                $lowByte = $value & 0xFF;
            }

            $ones = 0;
            for ($i = 0; $i < 8; $i++) {
                $ones += ($lowByte >> $i) & 1;
            }
            $ma->setParityFlag(($ones % 2) === 0);
            return;
        }

        $ma->updateFlags($value, $size);
    }

    /**
     * @return array{0:int,1:bool} [result, carry flag]
     */
    protected function shiftLeftScalar(int $value, int $count, int $size): array
    {
        $mask = $this->scalarMask($size);
        if ($count >= $size) {
            $result = 0;
            $cfBit = $count === $size ? (($value & 0x1) !== 0) : false;
        } else {
            $result = ($value << $count) & $mask;
            $cfBit = (($value >> ($size - $count)) & 0x1) !== 0;
        }
        return [$result, $cfBit];
    }

    /**
     * @return array{0:int,1:bool} [result, carry flag]
     */
    protected function shiftRightLogicalScalar(int $value, int $count, int $size): array
    {
        $mask = $this->scalarMask($size);
        $result = ($value >> $count) & $mask;
        $cfBit = $count > 0 ? (($value >> ($count - 1)) & 0x1) !== 0 : false;
        return [$result, $cfBit];
    }

    /**
     * @return array{0:int,1:bool} [result, carry flag]
     */
    protected function shiftRightArithmeticScalar(int $value, int $count, int $size): array
    {
        $mask = $this->scalarMask($size);
        $signBit = 1 << ($size - 1);
        $sign = $value & $signBit;

        if ($count >= $size) {
            $result = $sign ? $mask : 0;
        } else {
            $result = $value >> $count;
            if ($sign && $count > 0) {
                $signFill = $mask ^ ((1 << ($size - $count)) - 1);
                $result |= $signFill;
            }
        }

        $result &= $mask;
        $cfBit = $count > 0 ? (($value >> ($count - 1)) & 0x1) !== 0 : false;

        return [$result, $cfBit];
    }

    /**
     * @param callable(int|UInt64,int):array{0:int|UInt64,1:bool} $calc64
     * @param callable(int,int,int):array{0:int,1:bool} $calcScalar
     * @param callable(int|UInt64,int|UInt64,bool,int):bool $overflow
     */
    protected function executeShiftOp(
        RuntimeInterface $runtime,
        int $opcode,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        int $size,
        callable $calc64,
        callable $calcScalar,
        callable $overflow,
    ): ExecutionStatus {
        [$isRegister, $address] = $this->resolveRmLocation($runtime, $memory, $modRegRM);
        $count = $this->shiftCountValue($runtime, $opcode, $memory, $modRegRM, $size);

        // x86 semantics: count==0 leaves operand and flags unchanged.
        if ($count === 0) {
            return ExecutionStatus::SUCCESS;
        }

        $value = $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $size);
        $ma = $runtime->memoryAccessor();

        if ($size === 64) {
            [$result, $cfBit] = $calc64($value, $count);
            $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $result, $size);

            $ma->setCarryFlag($cfBit);
            $this->updateResultFlags($runtime, $result, $size);
            if ($count === 1) {
                $ma->setOverflowFlag($overflow($value, $result, $cfBit, $size));
            }

            return ExecutionStatus::SUCCESS;
        }

        $valueInt = $value instanceof UInt64 ? $value->toInt() : $value;
        [$result, $cfBit] = $calcScalar((int) $valueInt, $count, $size);
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $result, $size);

        $ma->setCarryFlag($cfBit);
        $this->updateResultFlags($runtime, $result, $size);
        if ($count === 1) {
            $ma->setOverflowFlag($overflow($valueInt, $result, $cfBit, $size));
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * @param callable(int|UInt64,int):array{0:int|UInt64,1:bool,2:bool,3:int} $calc64
     * @param callable(int,int,int):array{0:int,1:bool,2:bool,3:int} $calcScalar
     */
    protected function executeRotateOp(
        RuntimeInterface $runtime,
        int $opcode,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        int $size,
        callable $calc64,
        callable $calcScalar,
    ): ExecutionStatus {
        [$isRegister, $address] = $this->resolveRmLocation($runtime, $memory, $modRegRM);
        $count = $this->shiftCountValue($runtime, $opcode, $memory, $modRegRM, $size);

        // x86 semantics: count==0 leaves operand and flags unchanged (and does not write memory).
        if ($count === 0) {
            return ExecutionStatus::SUCCESS;
        }

        $value = $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $size);
        $ma = $runtime->memoryAccessor();

        if ($size === 64) {
            [$result, $cf, $of, $effective] = $calc64($value, $count);
            $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $result, $size);

            if ($effective > 0) {
                $ma->setCarryFlag($cf);
                if ($count === 1) {
                    $ma->setOverflowFlag($of);
                }
            }

            return ExecutionStatus::SUCCESS;
        }

        $valueInt = $value instanceof UInt64 ? $value->toInt() : $value;
        [$result, $cf, $of, $effective] = $calcScalar((int) $valueInt, $count, $size);
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $result, $size);

        if ($effective > 0) {
            $ma->setCarryFlag($cf);
            if ($count === 1) {
                $ma->setOverflowFlag($of);
            }
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * @param callable(int|UInt64,int,int):array{0:int|UInt64,1:bool} $calc64
     * @param callable(int,int,int,int):array{0:int,1:bool} $calcScalar
     * @param callable(int|UInt64,bool,int):bool $overflow
     */
    protected function executeRotateCarryOp(
        RuntimeInterface $runtime,
        int $opcode,
        MemoryStreamInterface $memory,
        ModRegRMInterface $modRegRM,
        int $size,
        callable $calc64,
        callable $calcScalar,
        callable $overflow,
    ): ExecutionStatus {
        [$isRegister, $address] = $this->resolveRmLocation($runtime, $memory, $modRegRM);
        $count = $this->shiftCountValue($runtime, $opcode, $memory, $modRegRM, $size);

        // x86 semantics: count==0 leaves operand and flags unchanged (and does not write memory).
        if ($count === 0) {
            return ExecutionStatus::SUCCESS;
        }

        $value = $this->readRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $size);
        $ma = $runtime->memoryAccessor();
        $carry = $ma->shouldCarryFlag() ? 1 : 0;

        if ($size === 64) {
            [$result, $cf] = $calc64($value, $count, $carry);
            $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $result, $size);
            $ma->setCarryFlag($cf);
            if ($count === 1) {
                $ma->setOverflowFlag($overflow($result, $cf, $size));
            }
            return ExecutionStatus::SUCCESS;
        }

        $valueInt = $value instanceof UInt64 ? $value->toInt() : $value;
        [$result, $cf] = $calcScalar((int) $valueInt, $count, $size, $carry);
        $this->writeRmScalarBySizeFromLocation($runtime, $memory, $modRegRM, $isRegister, $address, $result, $size);
        $ma->setCarryFlag($cf);
        if ($count === 1) {
            $ma->setOverflowFlag($overflow($result, $cf, $size));
        }

        return ExecutionStatus::SUCCESS;
    }

    /**
     * @return array{0:int,1:bool} [result, carry flag]
     */
    protected function rotateCarryLeftScalar(int $value, int $count, int $size, int $carry): array
    {
        $mask = $this->scalarMask($size);
        $mod = $size + 1;
        $steps = $count % $mod;
        $result = $value & $mask;
        $cf = $carry;
        for ($i = 0; $i < $steps; $i++) {
            $newCf = ($result >> ($size - 1)) & 1;
            $result = (($result << 1) | $cf) & $mask;
            $cf = $newCf;
        }

        return [$result, $cf !== 0];
    }

    /**
     * @return array{0:int,1:bool} [result, carry flag]
     */
    protected function rotateCarryRightScalar(int $value, int $count, int $size, int $carry): array
    {
        $mask = $this->scalarMask($size);
        $mod = $size + 1;
        $steps = $count % $mod;
        $result = $value & $mask;
        $cf = $carry;
        for ($i = 0; $i < $steps; $i++) {
            $newCf = $result & 1;
            $result = (($result >> 1) | ($cf << ($size - 1))) & $mask;
            $cf = $newCf;
        }

        return [$result, $cf !== 0];
    }

    /**
     * @return array{0:UInt64,1:bool} [result, carry flag]
     */
    protected function rotateCarryLeftUInt64(UInt64 $value, int $count, int $carry): array
    {
        $steps = $count % 65;
        $result = $value;
        $cf = $carry;
        for ($i = 0; $i < $steps; $i++) {
            $newCf = ($result->shr(63)->low32() & 0x1);
            $result = $result->shl(1);
            if ($cf !== 0) {
                $result = $result->or(1);
            }
            $cf = $newCf;
        }

        return [$result, $cf !== 0];
    }

    /**
     * @return array{0:UInt64,1:bool} [result, carry flag]
     */
    protected function rotateCarryRightUInt64(UInt64 $value, int $count, int $carry): array
    {
        $steps = $count % 65;
        $result = $value;
        $cf = $carry;
        $msbMask = UInt64::of('9223372036854775808'); // 0x8000000000000000
        for ($i = 0; $i < $steps; $i++) {
            $newCf = $result->low32() & 0x1;
            $result = $result->shr(1);
            if ($cf !== 0) {
                $result = $result->or($msbMask);
            }
            $cf = $newCf;
        }

        return [$result, $cf !== 0];
    }
}
