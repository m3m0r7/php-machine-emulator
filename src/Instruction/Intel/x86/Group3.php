<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Stream\MemoryStreamInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

class Group3 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xF6, 0xF7]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $memory = $runtime->memory();
        $modRegRM = $memory->byteAsModRegRM();

        $isByte = $opcode === 0xF6;
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();

        match ($modRegRM->digit()) {
            0x0, 0x1 => $this->test($runtime, $memory, $modRegRM, $isByte, $opSize),
            0x2 => $this->not($runtime, $memory, $modRegRM, $isByte, $opSize),
            0x3 => $this->neg($runtime, $memory, $modRegRM, $isByte, $opSize),
            0x4 => $this->mul($runtime, $memory, $modRegRM, $isByte, $opSize),
            0x5 => $this->imul($runtime, $memory, $modRegRM, $isByte, $opSize),
            0x6 => $this->div($runtime, $memory, $modRegRM, $isByte, $opSize),
            0x7 => $this->idiv($runtime, $memory, $modRegRM, $isByte, $opSize),
            default => throw new ExecutionException(
                sprintf(
                    'The %s#%d was not implemented yet',
                    __CLASS__,
                    $modRegRM->digit(),
                ),
            ),
        };

        return ExecutionStatus::SUCCESS;
    }


    protected function test(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        $size = $isByte ? 8 : $opSize;
        // x86 encoding order: ModR/M -> displacement -> immediate
        // readRm consumes displacement, so must be called BEFORE reading immediate
        $value = $this->readRm($runtime, $memory, $modRegRM, $size);
        $immediate = match (true) {
            $isByte => $memory->byte(),
            $opSize === 16 => $memory->short(),
            default => $memory->dword(), // 32-bit immediate; in 64-bit mode it is sign-extended
        };

        if ($size === 64) {
            $valueU = $value instanceof UInt64 ? $value : UInt64::of($value);
            $immInt = $this->signExtend($immediate, 32);
            $resultU = $valueU->and(UInt64::of($immInt));
            $runtime
                ->memoryAccessor()
                ->updateFlags($resultU->toInt(), 64)
                ->setCarryFlag(false)
                ->setOverflowFlag(false);
            return ExecutionStatus::SUCCESS;
        }

        $valueInt = $value instanceof UInt64 ? $value->toInt() : $value;
        $runtime
            ->memoryAccessor()
            ->updateFlags($valueInt & $immediate, $size)
            ->setCarryFlag(false)
            ->setOverflowFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function not(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        $size = $isByte ? 8 : $opSize;
        if ($size === 64) {
            $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

            if ($isRegister) {
                $valueInt = $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64);
                $resultU = UInt64::of($valueInt)->not();
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
                return ExecutionStatus::SUCCESS;
            }

            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            $valueU = $this->readMemory64($runtime, $address);
            $this->writeMemory64($runtime, $address, $valueU->not());
            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->maskForSize($size);
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

        if ($isRegister) {
            $value = $isByte
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
            $result = ~$value & $mask;
            if ($isByte) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
            }
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            if ($isByte) {
                $value = $this->readMemory8($runtime, $address);
                $result = ~$value & $mask;
                $this->writeMemory8($runtime, $address, $result);
            } elseif ($size === 32) {
                $value = $this->readMemory32($runtime, $address);
                $result = ~$value & $mask;
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $value = $this->readMemory16($runtime, $address);
                $result = ~$value & $mask;
                $this->writeMemory16($runtime, $address, $result);
            }
        }

        // NOT does NOT affect any flags (unlike NEG)
        return ExecutionStatus::SUCCESS;
    }

    protected function neg(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        $size = $isByte ? 8 : $opSize;
        if ($size === 64) {
            $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

            if ($isRegister) {
                $valueInt = $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), 64);
                $valueU = UInt64::of($valueInt);
                $resultU = UInt64::zero()->sub($valueU);
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $resultU->toInt(), 64);
            } else {
                $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
                $valueU = $this->readMemory64($runtime, $address);
                $resultU = UInt64::zero()->sub($valueU);
                $this->writeMemory64($runtime, $address, $resultU);
            }

            $runtime->memoryAccessor()
                ->updateFlags($resultU->toInt(), 64)
                ->setCarryFlag(!$valueU->isZero())
                ->setOverflowFlag($valueU->eq('9223372036854775808')) // 0x8000000000000000
                ->setAuxiliaryCarryFlag(($valueU->low32() & 0x0F) !== 0);

            return ExecutionStatus::SUCCESS;
        }

        $mask = $this->maskForSize($size);
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

        if ($isRegister) {
            $value = $isByte
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
            $value &= $mask;
            $result = (-$value) & $mask;

            if ($isByte) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
            }
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $memory, $modRegRM);
            if ($isByte) {
                $value = $this->readMemory8($runtime, $address) & $mask;
                $result = (-$value) & $mask;
                $this->writeMemory8($runtime, $address, $result);
            } elseif ($size === 32) {
                $value = $this->readMemory32($runtime, $address) & $mask;
                $result = (-$value) & $mask;
                $this->writeMemory32($runtime, $address, $result);
            } else {
                $value = $this->readMemory16($runtime, $address) & $mask;
                $result = (-$value) & $mask;
                $this->writeMemory16($runtime, $address, $result);
            }
        }

        // NEG sets flags: CF=1 if operand was non-zero, OF=1 if operand was most negative value
        $mostNegative = match ($size) {
            8 => 0x80,
            16 => 0x8000,
            default => 0x80000000,
        };
        $runtime->memoryAccessor()
            ->updateFlags($result, $size)
            ->setCarryFlag($value !== 0)
            ->setOverflowFlag($value === $mostNegative)
            ->setAuxiliaryCarryFlag(($value & 0x0F) !== 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function mul(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        if ($isByte) {
            $operand = $this->readRm8($runtime, $memory, $modRegRM);
            $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $product = $al * $operand;
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $product & 0xFFFF);
            $flag = ($product & 0xFF00) !== 0;
            $runtime->memoryAccessor()->setCarryFlag($flag)->setOverflowFlag($flag);
            return ExecutionStatus::SUCCESS;
        }

        $operand = $this->readRm($runtime, $memory, $modRegRM, $opSize);
        $acc = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);
        $ma = $runtime->memoryAccessor();

        if ($opSize === 16) {
            $product = ($acc & 0xFFFF) * ($operand & 0xFFFF);

            $ma
                ->write16Bit(RegisterType::EAX, $product & 0xFFFF)
                ->write16Bit(RegisterType::EDX, ($product >> 16) & 0xFFFF);

            // Debug MUL for FAT calculation
            if ($operand === 3) {
                $runtime->option()->logger()->debug(sprintf(
                    'MUL: AX=%d × %d = %d (AX=%d, DX=%d)',
                    $acc, $operand, $product, $product & 0xFFFF, ($product >> 16) & 0xFFFF
                ));
            }

            $flag = ($product >> 16) !== 0;
        } elseif ($opSize === 64) {
            $mask64 = BigInteger::of('18446744073709551615'); // 0xFFFFFFFFFFFFFFFF

            $accU = UInt64::of($acc);
            $operandU = $operand instanceof UInt64 ? $operand : UInt64::of($operand);

            $product = $accU->toBigInteger()->multipliedBy($operandU->toBigInteger());
            $lowU = UInt64::of($product->and($mask64));
            $highU = UInt64::of($product->shiftedRight(64)->and($mask64));

            $ma
                ->writeBySize(RegisterType::EAX, $lowU->toInt(), 64)
                ->writeBySize(RegisterType::EDX, $highU->toInt(), 64);

            $flag = !$highU->isZero();
        } else {
            // Use UInt64 for 32-bit × 32-bit = 64-bit result
            $product = UInt64::of($acc & 0xFFFFFFFF)->mul($operand & 0xFFFFFFFF);
            $low = $product->low32();
            $high = $product->high32();

            $runtime->option()->logger()->debug(sprintf(
                'MUL32: EAX=0x%08X × operand=0x%08X = %s (EAX=0x%08X, EDX=0x%08X)',
                $acc, $operand, $product->toHex(), $low, $high
            ));

            $ma
                ->writeBySize(RegisterType::EAX, $low, 32)
                ->writeBySize(RegisterType::EDX, $high, 32);

            $flag = $high !== 0;
        }

        $runtime->memoryAccessor()->setCarryFlag($flag)->setOverflowFlag($flag);

        return ExecutionStatus::SUCCESS;
    }

    protected function imul(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        if ($isByte) {
            $operandRaw = $this->readRm8($runtime, $memory, $modRegRM);
            $alRaw = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $operand = $this->signExtend($operandRaw, 8);
            $al = $this->signExtend($alRaw, 8);
            $product = $al * $operand;
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $product & 0xFFFF);
            $flag = ($product < -128) || ($product > 127);
            $runtime->memoryAccessor()->setCarryFlag($flag)->setOverflowFlag($flag);
            return ExecutionStatus::SUCCESS;
        }

        $operand = $this->readRm($runtime, $memory, $modRegRM, $opSize);
        $acc = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);

        if ($opSize === 64) {
            $ma = $runtime->memoryAccessor();
            $mask64 = BigInteger::of('18446744073709551615'); // 0xFFFFFFFFFFFFFFFF
            $twoPow128 = BigInteger::of(1)->shiftedLeft(128);

            $operandSigned = $operand instanceof UInt64 ? $operand->toInt() : $operand;
            $productSigned = BigInteger::of($acc)->multipliedBy(BigInteger::of($operandSigned));

            $productUnsigned = $productSigned->isNegative()
                ? $productSigned->plus($twoPow128)
                : $productSigned;

            $lowU = UInt64::of($productUnsigned->and($mask64));
            $highU = UInt64::of($productUnsigned->shiftedRight(64)->and($mask64));

            $ma
                ->writeBySize(RegisterType::EAX, $lowU->toInt(), 64)
                ->writeBySize(RegisterType::EDX, $highU->toInt(), 64);

            $expectedHighU = $lowU->isNegativeSigned()
                ? UInt64::of('18446744073709551615')
                : UInt64::zero();
            $flag = !$highU->eq($expectedHighU);
            $ma->setCarryFlag($flag)->setOverflowFlag($flag);

            return ExecutionStatus::SUCCESS;
        }

        $sOperand = $this->signExtend($operand, $opSize);
        $sAcc = $this->signExtend($acc, $opSize);
        $product = $sAcc * $sOperand;

        $mask = $this->maskForSize($opSize);
        $runtime
            ->memoryAccessor()
            ->writeBySize(RegisterType::EAX, $product & $mask, $opSize)
            ->writeBySize(
                RegisterType::EDX,
                ($product >> $opSize) & $mask,
                $opSize,
            );

        $fits = $product >= -(1 << ($opSize - 1)) && $product < (1 << ($opSize - 1));
        $runtime->memoryAccessor()->setCarryFlag(!$fits)->setOverflowFlag(!$fits);

        return ExecutionStatus::SUCCESS;
    }

    protected function div(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        if ($isByte) {
            // Debug: check segment override for DIV at IO.SYS flag check
            $ip = $memory->offset();
            $segOverride = $runtime->context()->cpu()->segmentOverride();
            if ($ip >= 0x9FAF0 && $ip <= 0x9FB00) {
                $runtime->option()->logger()->debug(sprintf(
                    'DIV DEBUG: IP=0x%05X segOverride=%s CS=0x%04X',
                    $ip, $segOverride?->name ?? 'none',
                    $runtime->memoryAccessor()->fetch(RegisterType::CS)->asByte()
                ));
            }

            $divider = $this->readRm8($runtime, $memory, $modRegRM);

            if ($ip >= 0x9FAF0 && $ip <= 0x9FB00) {
                $runtime->option()->logger()->debug(sprintf(
                    'DIV DEBUG: divider=0x%02X (expected 0x01)',
                    $divider
                ));
            }

            if ($divider === 0) {
                throw new FaultException(0x00, 0, 'Divide by zero');
            }
            $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();
            $quotient = intdiv($ax, $divider);
            $remainder = $ax % $divider;
            if ($quotient > 0xFF) {
                throw new FaultException(0x00, 0, 'Divide overflow');
            }
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $quotient & 0xFF);
            $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, $remainder & 0xFF);
            return ExecutionStatus::SUCCESS;
        }

        $dividerVal = $this->readRm($runtime, $memory, $modRegRM, $opSize);

        if ($opSize === 64) {
            $ma = $runtime->memoryAccessor();
            $mask64 = BigInteger::of('18446744073709551615'); // 0xFFFFFFFFFFFFFFFF
            $dividerU = $dividerVal instanceof UInt64 ? $dividerVal : UInt64::of($dividerVal);
            if ($dividerU->isZero()) {
                throw new FaultException(0x00, 0, 'Divide by zero');
            }

            $raxU = UInt64::of($ma->fetch(RegisterType::EAX)->asBytesBySize(64));
            $rdxU = UInt64::of($ma->fetch(RegisterType::EDX)->asBytesBySize(64));
            $dividend = $rdxU->toBigInteger()->shiftedLeft(64)->or($raxU->toBigInteger());

            $quotient = $dividend->dividedBy($dividerU->toBigInteger(), RoundingMode::DOWN);
            if ($quotient->isGreaterThan($mask64)) {
                throw new FaultException(0x00, 0, 'Divide overflow');
            }
            $remainder = $dividend->mod($dividerU->toBigInteger());

            $qU = UInt64::of($quotient->and($mask64));
            $rU = UInt64::of($remainder->and($mask64));
            $ma
                ->writeBySize(RegisterType::EAX, $qU->toInt(), 64)
                ->writeBySize(RegisterType::EDX, $rU->toInt(), 64);

            return ExecutionStatus::SUCCESS;
        }

        $divider = $dividerVal;
        if ($divider === 0) {
            throw new FaultException(0x00, 0, 'Divide by zero');
        }

        $ma = $runtime->memoryAccessor();

        if ($opSize === 16) {
            $ax = $ma->fetch(RegisterType::EAX)->asByte();
            $dx = $ma->fetch(RegisterType::EDX)->asByte();

            $dividee = ($dx << 16) + $ax;

            $quotient = (int) ($dividee / $divider);
            $remainder = $dividee % $divider;
            if ($quotient > 0xFFFF) {
                throw new FaultException(0x00, 0, 'Divide overflow');
            }

            // Debug DIV for FAT calculation
            if ($divider === 2) {
                $runtime->option()->logger()->debug(sprintf(
                    'DIV: DX:AX=%d / %d = %d remainder %d (DX before=%d)',
                    $dividee, $divider, $quotient, $remainder, $dx
                ));
            }

            $ma
                ->write16Bit(
                    RegisterType::EAX,
                    $quotient & 0xFFFF,
                )
                ->write16Bit(
                    RegisterType::EDX,
                    $remainder & 0xFFFF,
                );
        } else {
            $ax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);
            $dx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);
            // Use UInt64 for EDX:EAX (64-bit dividend)
            $dividend = UInt64::fromParts($ax, $dx);

            $quotient = $dividend->div($divider);
            $remainder = $dividend->mod($divider);
            if ($quotient->gt(0xFFFFFFFF)) {
                throw new FaultException(0x00, 0, 'Divide overflow');
            }

            $ma
                ->writeBySize(RegisterType::EAX, $quotient->low32(), 32)
                ->writeBySize(RegisterType::EDX, $remainder->low32(), 32);
        }


        return ExecutionStatus::SUCCESS;
    }

    protected function idiv(RuntimeInterface $runtime, MemoryStreamInterface $memory, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        if ($isByte) {
            $dividerRaw = $this->readRm8($runtime, $memory, $modRegRM);
            $divider = $this->signExtend($dividerRaw, 8);
            if ($divider === 0) {
                throw new FaultException(0x00, 0, 'Divide by zero');
            }

            $axRaw = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();
            $ax = $this->signExtend($axRaw, 16);

            $quotient = (int) ($ax / $divider);
            $remainder = $ax % $divider;
            if ($quotient < -128 || $quotient > 127) {
                throw new FaultException(0x00, 0, 'Divide overflow');
            }

            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $quotient & 0xFF);
            $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, $remainder & 0xFF);
            $runtime->memoryAccessor()->setCarryFlag(false)->setOverflowFlag(false);
            return ExecutionStatus::SUCCESS;
        } else {
            $dividerRaw = $this->readRm($runtime, $memory, $modRegRM, $opSize);

            if ($opSize === 64) {
                $divider = $dividerRaw instanceof UInt64 ? $dividerRaw->toInt() : $dividerRaw;
                if ($divider === 0) {
                    throw new FaultException(0x00, 0, 'Divide by zero');
                }

                $ma = $runtime->memoryAccessor();
                $raxU = UInt64::of($ma->fetch(RegisterType::EAX)->asBytesBySize(64));
                $rdxU = UInt64::of($ma->fetch(RegisterType::EDX)->asBytesBySize(64));
                $dividendUnsigned = $rdxU->toBigInteger()->shiftedLeft(64)->or($raxU->toBigInteger());

                $dividend = $dividendUnsigned;
                if ($rdxU->isNegativeSigned()) {
                    $dividend = $dividend->minus(BigInteger::of(1)->shiftedLeft(128));
                }

                $dividerBig = BigInteger::of($divider);
                $quotientBig = $dividend->dividedBy($dividerBig, RoundingMode::DOWN);
                $remainderBig = $dividend->minus($quotientBig->multipliedBy($dividerBig));

                $min64 = BigInteger::of((string) PHP_INT_MIN);
                $max64 = BigInteger::of((string) PHP_INT_MAX);
                if ($quotientBig->isLessThan($min64) || $quotientBig->isGreaterThan($max64)) {
                    throw new FaultException(0x00, 0, 'Divide overflow');
                }

                $ma
                    ->writeBySize(RegisterType::EAX, $quotientBig->toInt(), 64)
                    ->writeBySize(RegisterType::EDX, $remainderBig->toInt(), 64);

                $runtime->memoryAccessor()->setCarryFlag(false)->setOverflowFlag(false);
                return ExecutionStatus::SUCCESS;
            }

            $divider = $this->signExtend($dividerRaw, $opSize);
            if ($divider === 0) {
                throw new FaultException(0x00, 0, 'Divide by zero');
            }

            $ma = $runtime->memoryAccessor();

            if ($opSize === 16) {
                $axRaw = $ma->fetch(RegisterType::EAX)->asByte();
                $dxRaw = $ma->fetch(RegisterType::EDX)->asByte();
                $ax = $this->signExtend($axRaw, 16);
                $dx = $this->signExtend($dxRaw, 16);

                $dividee = ($dx << 16) + ($ax & 0xFFFF);

                $quotient = (int) ($dividee / $divider);
                $remainder = $dividee % $divider;
                if ($quotient < -32768 || $quotient > 32767) {
                    throw new FaultException(0x00, 0, 'Divide overflow');
                }

                $ma
                    ->write16Bit(RegisterType::EAX, $quotient & 0xFFFF)
                    ->write16Bit(RegisterType::EDX, $remainder & 0xFFFF);
            } else {
                $axRaw = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);
                $dxRaw = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);
                $ax = $this->signExtend($axRaw, 32);
                $dx = $this->signExtend($dxRaw, 32);

                $dividee = ($dx << 32) | ($ax & 0xFFFFFFFF);

                $quotient = (int) ($dividee / $divider);
                $remainder = $dividee % $divider;
                if ($quotient < -(1 << 31) || $quotient > ((1 << 31) - 1)) {
                    throw new FaultException(0x00, 0, 'Divide overflow');
                }

                $ma
                    ->writeBySize(RegisterType::EAX, $quotient & 0xFFFFFFFF, 32)
                    ->writeBySize(RegisterType::EDX, $remainder & 0xFFFFFFFF, 32);
            }

            $runtime->memoryAccessor()->setCarryFlag(false)->setOverflowFlag(false);

            return ExecutionStatus::SUCCESS;
        }
    }

    private function maskForSize(int $size): int
    {
        return match ($size) {
            8 => 0xFF,
            16 => 0xFFFF,
            64 => -1,
            default => 0xFFFFFFFF,
        };
    }
}
