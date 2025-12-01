<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Exception\ExecutionException;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Instruction\Stream\EnhanceStreamReader;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\ModType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Group3 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF6, 0xF7];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->memory());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();

        $isByte = $opcode === 0xF6;
        $opSize = $isByte ? 8 : $runtime->context()->cpu()->operandSize();

        match ($modRegRM->digit()) {
            0x0 => $this->test($runtime, $enhancedStreamReader, $modRegRM, $isByte, $opSize),
            0x2 => $this->not($runtime, $enhancedStreamReader, $modRegRM, $isByte, $opSize),
            0x3 => $this->neg($runtime, $enhancedStreamReader, $modRegRM, $isByte, $opSize),
            0x4 => $this->mul($runtime, $enhancedStreamReader, $modRegRM, $isByte, $opSize),
            0x5 => $this->imul($runtime, $enhancedStreamReader, $modRegRM, $isByte, $opSize),
            0x6 => $this->div($runtime, $enhancedStreamReader, $modRegRM, $isByte, $opSize),
            0x7 => $this->idiv($runtime, $enhancedStreamReader, $modRegRM, $isByte, $opSize),
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


    protected function test(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        $size = $isByte ? 8 : $opSize;
        $immediate = $isByte
            ? $streamReader->streamReader()->byte()
            : ($opSize === 32 ? $streamReader->dword() : $streamReader->short());
        $value = $this->readRm($runtime, $streamReader, $modRegRM, $size);

        $runtime
            ->memoryAccessor()
            ->updateFlags($value & $immediate, $size)
            ->setCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function not(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        $size = $isByte ? 8 : $opSize;
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
            $address = $this->rmLinearAddress($runtime, $streamReader, $modRegRM);
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

        $runtime->memoryAccessor()->setCarryFlag(false);
        return ExecutionStatus::SUCCESS;
    }

    protected function neg(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        $size = $isByte ? 8 : $opSize;
        $mask = $this->maskForSize($size);
        $isRegister = ModType::from($modRegRM->mode()) === ModType::REGISTER_TO_REGISTER;

        if ($isRegister) {
            $value = $isByte
                ? $this->read8BitRegister($runtime, $modRegRM->registerOrMemoryAddress())
                : $this->readRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $size);
            $value &= $mask;
            $result = (-$value) & $mask;

            // Debug NEG for LZMA distance
            $runtime->option()->logger()->debug(sprintf(
                'NEG r%d: value=0x%X result=0x%X (reg=%d)',
                $size, $value & 0xFFFFFFFF, $result & 0xFFFFFFFF, $modRegRM->registerOrMemoryAddress()
            ));

            if ($isByte) {
                $this->write8BitRegister($runtime, $modRegRM->registerOrMemoryAddress(), $result);
            } else {
                $this->writeRegisterBySize($runtime, $modRegRM->registerOrMemoryAddress(), $result, $size);
            }
        } else {
            // Calculate address once to avoid consuming displacement bytes twice
            $address = $this->rmLinearAddress($runtime, $streamReader, $modRegRM);
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

        $runtime->memoryAccessor()->setCarryFlag($value !== 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function mul(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        if ($isByte) {
            $operand = $this->readRm8($runtime, $streamReader, $modRegRM);
            $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $product = $al * $operand;
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $product & 0xFFFF);
            $flag = ($product & 0xFF00) !== 0;
            $runtime->memoryAccessor()->setCarryFlag($flag)->setOverflowFlag($flag);
            return ExecutionStatus::SUCCESS;
        }

        $operand = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
        $acc = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);
        $ma = $runtime->memoryAccessor()->enableUpdateFlags(false);

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
        } else {
            $product = ($acc & 0xFFFFFFFF) * ($operand & 0xFFFFFFFF);
            $low = $product & 0xFFFFFFFF;
            $high = ($product >> 32) & 0xFFFFFFFF;

            $runtime->option()->logger()->debug(sprintf(
                'MUL32: EAX=0x%08X × operand=0x%08X = 0x%016X (EAX=0x%08X, EDX=0x%08X)',
                $acc, $operand, $product, $low, $high
            ));

            $ma
                ->writeBySize(RegisterType::EAX, $low, 32)
                ->writeBySize(RegisterType::EDX, $high, 32);

            $flag = $high !== 0;
        }

        $runtime->memoryAccessor()->setCarryFlag($flag)->setOverflowFlag($flag);

        return ExecutionStatus::SUCCESS;
    }

    protected function imul(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        if ($isByte) {
            $operandRaw = $this->readRm8($runtime, $streamReader, $modRegRM);
            $alRaw = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $operand = $this->signExtend($operandRaw, 8);
            $al = $this->signExtend($alRaw, 8);
            $product = $al * $operand;
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $product & 0xFFFF);
            $flag = ($product < -128) || ($product > 127);
            $runtime->memoryAccessor()->setCarryFlag($flag)->setOverflowFlag($flag);
            return ExecutionStatus::SUCCESS;
        }

        $operand = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
        $acc = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asBytesBySize($opSize);

        $sOperand = $this->signExtend($operand, $opSize);
        $sAcc = $this->signExtend($acc, $opSize);
        $product = $sAcc * $sOperand;

        $mask = $this->maskForSize($opSize);
        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
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

    protected function div(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        if ($isByte) {
            $divider = $this->readRm8($runtime, $streamReader, $modRegRM);
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

        $divider = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
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
                ->enableUpdateFlags(false)
                ->write16Bit(
                    RegisterType::EDX,
                    $remainder & 0xFFFF,
                );
        } else {
            $ax = $ma->fetch(RegisterType::EAX)->asBytesBySize(32);
            $dx = $ma->fetch(RegisterType::EDX)->asBytesBySize(32);
            $dividee = (($dx & 0xFFFFFFFF) << 32) | ($ax & 0xFFFFFFFF);

            $quotient = intdiv($dividee, $divider);
            $remainder = $dividee % $divider;
            if ($quotient > 0xFFFFFFFF) {
                throw new FaultException(0x00, 0, 'Divide overflow');
            }

            $ma
                ->enableUpdateFlags(false)
                ->writeBySize(RegisterType::EAX, $quotient & 0xFFFFFFFF, 32)
                ->writeBySize(RegisterType::EDX, $remainder & 0xFFFFFFFF, 32);
        }


        return ExecutionStatus::SUCCESS;
    }

    protected function idiv(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte, int $opSize): ExecutionStatus
    {
        if ($isByte) {
            $dividerRaw = $this->readRm8($runtime, $streamReader, $modRegRM);
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
            $dividerRaw = $this->readRm($runtime, $streamReader, $modRegRM, $opSize);
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
                    ->enableUpdateFlags(false)
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
                    ->enableUpdateFlags(false)
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
            default => 0xFFFFFFFF,
        };
    }

    private function signExtend(int $value, int $bits): int
    {
        if ($bits >= 32) {
            $value &= 0xFFFFFFFF;
            return ($value & 0x80000000) ? $value - 0x100000000 : $value;
        }

        $mask = 1 << ($bits - 1);
        $fullMask = (1 << $bits) - 1;
        $value &= $fullMask;

        return ($value & $mask) ? $value - (1 << $bits) : $value;
    }
}
