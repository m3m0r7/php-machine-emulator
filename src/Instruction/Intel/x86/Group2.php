<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;
use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Group2 implements InstructionInterface
{
    use Instructable;
    use GroupShiftRotate;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xC0, 0xC1, 0xD0, 0xD1, 0xD2, 0xD3]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory
            ->byteAsModRegRM();
        $opSize = $this->isByteOp($opcode) ? 8 : $runtime->context()->cpu()->operandSize();

        return match ($modRegRM->digit()) {
            0x0 => $this->rotateLeft($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x1 => $this->rotateRight($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x2 => $this->rotateCarryLeft($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x3 => $this->rotateCarryRight($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x4, 0x6 => $this->shiftLeft($runtime, $opcode, $memory, $modRegRM, $opSize), // 0x6 is undocumented SAL alias
            0x5 => $this->shiftRightLogical($runtime, $opcode, $memory, $modRegRM, $opSize),
            0x7 => $this->shiftRightArithmetic($runtime, $opcode, $memory, $modRegRM, $opSize),
            default => throw new ExecutionException(
                sprintf('The digit (0b%s) is not supported yet', decbin($modRegRM->digit()))
            ),
        };
    }

    protected function rotateLeft(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        return $this->executeRotateOp(
            $runtime,
            $opcode,
            $memory,
            $modRegRM,
            $size,
            static function (int|UInt64 $value, int $count): array {
                $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
                $effective = $count % 64;
                if ($effective === 0) {
                    return [$valueU, false, false, 0];
                }

                $resultU = $valueU->shl($effective)->or($valueU->shr(64 - $effective));
                $cf = ($resultU->low32() & 0x1) !== 0;
                $of = $resultU->isNegativeSigned() !== $cf;
                return [$resultU, $cf, $of, $effective];
            },
            function (int $value, int $count, int $size): array {
                $mask = $this->scalarMask($size);
                $value &= $mask;
                $effective = $count % $size;
                $result = $effective === 0
                    ? $value
                    : ((($value << $effective) | ($value >> ($size - $effective))) & $mask);
                $cf = ($result & 0x1) !== 0;
                $msb = ($result >> ($size - 1)) & 1;
                $of = $msb !== ($cf ? 1 : 0);
                return [$result, $cf, $of, $effective];
            },
        );
    }

    protected function rotateRight(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        return $this->executeRotateOp(
            $runtime,
            $opcode,
            $memory,
            $modRegRM,
            $size,
            static function (int|UInt64 $value, int $count): array {
                $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
                $effective = $count % 64;
                if ($effective === 0) {
                    return [$valueU, false, false, 0];
                }

                $resultU = $valueU->shr($effective)->or($valueU->shl(64 - $effective));
                $cf = $resultU->isNegativeSigned();
                $msb1 = ($resultU->shr(62)->low32() & 0x1) !== 0;
                $of = $cf !== $msb1;
                return [$resultU, $cf, $of, $effective];
            },
            function (int $value, int $count, int $size): array {
                $mask = $this->scalarMask($size);
                $value &= $mask;
                $effective = $count % $size;
                $result = $effective === 0
                    ? $value
                    : (($value >> $effective) | (($value << ($size - $effective)) & $mask));
                $cf = (($result >> ($size - 1)) & 0x1) !== 0;
                $msb = ($result >> ($size - 1)) & 1;
                $msb1 = ($result >> ($size - 2)) & 1;
                $of = $msb !== $msb1;
                return [$result, $cf, $of, $effective];
            },
        );
    }

    protected function rotateCarryLeft(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        return $this->executeRotateCarryOp(
            $runtime,
            $opcode,
            $memory,
            $modRegRM,
            $size,
            function (int|UInt64 $value, int $count, int $carry): array {
                $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
                return $this->rotateCarryLeftUInt64($valueU, $count, $carry);
            },
            fn (int $value, int $count, int $size, int $carry): array => $this->rotateCarryLeftScalar($value, $count, $size, $carry),
            static function (int|UInt64 $result, bool $cf, int $size): bool {
                if ($result instanceof UInt64) {
                    return $result->isNegativeSigned() !== $cf;
                }
                $msb = ($result >> ($size - 1)) & 1;
                return $msb !== ($cf ? 1 : 0);
            },
        );
    }

    protected function rotateCarryRight(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        return $this->executeRotateCarryOp(
            $runtime,
            $opcode,
            $memory,
            $modRegRM,
            $size,
            function (int|UInt64 $value, int $count, int $carry): array {
                $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
                return $this->rotateCarryRightUInt64($valueU, $count, $carry);
            },
            fn (int $value, int $count, int $size, int $carry): array => $this->rotateCarryRightScalar($value, $count, $size, $carry),
            static function (int|UInt64 $result, bool $cf, int $size): bool {
                if ($result instanceof UInt64) {
                    $msb = $result->isNegativeSigned();
                    $msb1 = ($result->shr(62)->low32() & 0x1) !== 0;
                    return $msb !== $msb1;
                }
                $msb = ($result >> ($size - 1)) & 1;
                $msb1 = ($result >> ($size - 2)) & 1;
                return $msb !== $msb1;
            },
        );
    }

    protected function shiftLeft(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        return $this->executeShiftOp(
            $runtime,
            $opcode,
            $memory,
            $modRegRM,
            $size,
            static function (int|UInt64 $value, int $count): array {
                $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
                $resultU = $valueU->shl($count);
                $cfBit = ($valueU->shr(64 - $count)->low32() & 0x1) !== 0;
                return [$resultU, $cfBit];
            },
            fn (int $value, int $count, int $size): array => $this->shiftLeftScalar($value, $count, $size),
            static function (int|UInt64 $value, int|UInt64 $result, bool $cfBit, int $size): bool {
                if ($result instanceof UInt64) {
                    return $result->isNegativeSigned() !== $cfBit;
                }
                $msb = ($result >> ($size - 1)) & 1;
                return $msb !== ($cfBit ? 1 : 0);
            },
        );
    }

    protected function shiftRightLogical(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        return $this->executeShiftOp(
            $runtime,
            $opcode,
            $memory,
            $modRegRM,
            $size,
            static function (int|UInt64 $value, int $count): array {
                $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
                $resultU = $valueU->shr($count);
                $cfBit = ($valueU->shr($count - 1)->low32() & 0x1) !== 0;
                return [$resultU, $cfBit];
            },
            fn (int $value, int $count, int $size): array => $this->shiftRightLogicalScalar($value, $count, $size),
            static function (int|UInt64 $value, int|UInt64 $result, bool $cfBit, int $size): bool {
                if ($value instanceof UInt64) {
                    return $value->isNegativeSigned();
                }
                return ((($value >> ($size - 1)) & 1) !== 0);
            },
        );
    }

    protected function shiftRightArithmetic(RuntimeInterface $runtime, int $opcode, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, int $size): ExecutionStatus
    {
        return $this->executeShiftOp(
            $runtime,
            $opcode,
            $memory,
            $modRegRM,
            $size,
            static function (int|UInt64 $value, int $count): array {
                $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
                $valueInt = $valueU->toInt();
                $resultInt = $valueInt >> $count;
                $cfBit = (($valueInt >> ($count - 1)) & 0x1) !== 0;
                return [$resultInt, $cfBit];
            },
            fn (int $value, int $count, int $size): array => $this->shiftRightArithmeticScalar($value, $count, $size),
            static fn (int|UInt64 $value, int|UInt64 $result, bool $cfBit, int $size): bool => false,
        );
    }
}
