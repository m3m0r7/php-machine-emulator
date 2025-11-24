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

class Group3 implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return [0xF6, 0xF7];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        $enhancedStreamReader = new EnhanceStreamReader($runtime->streamReader());
        $modRegRM = $enhancedStreamReader
            ->byteAsModRegRM();

        $isByte = $opcode === 0xF6;

        match ($modRegRM->digit()) {
            0x0 => $this->test($runtime, $enhancedStreamReader, $modRegRM, $isByte),
            0x2 => $this->not($runtime, $enhancedStreamReader, $modRegRM, $isByte),
            0x3 => $this->neg($runtime, $enhancedStreamReader, $modRegRM, $isByte),
            0x4 => $this->mul($runtime, $enhancedStreamReader, $modRegRM, $isByte),
            0x5 => $this->imul($runtime, $enhancedStreamReader, $modRegRM, $isByte),
            0x6 => $this->div($runtime, $enhancedStreamReader, $modRegRM, $isByte),
            0x7 => $this->idiv($runtime, $enhancedStreamReader, $modRegRM, $isByte),
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


    protected function test(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte): ExecutionStatus
    {
        if ($isByte) {
            $immediate = $streamReader->streamReader()->byte();
            $value = $this->readRm8($runtime, $streamReader, $modRegRM);
        } else {
            $immediate = $streamReader->short();
            $value = $this->readRm16($runtime, $streamReader, $modRegRM);
        }

        $runtime
            ->memoryAccessor()
            ->updateFlags($value & $immediate, $isByte ? 8 : 16)
            ->setCarryFlag(false);

        return ExecutionStatus::SUCCESS;
    }

    protected function not(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte): ExecutionStatus
    {
        if ($isByte) {
            $value = $this->readRm8($runtime, $streamReader, $modRegRM);
            $this->writeRm8($runtime, $streamReader, $modRegRM, ~$value);
        } else {
            $value = $this->readRm16($runtime, $streamReader, $modRegRM);
            $this->writeRm16($runtime, $streamReader, $modRegRM, ~$value);
        }
        $runtime->memoryAccessor()->setCarryFlag(false);
        return ExecutionStatus::SUCCESS;
    }

    protected function neg(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte): ExecutionStatus
    {
        if ($isByte) {
            $value = $this->readRm8($runtime, $streamReader, $modRegRM);
            $result = (0 - $value) & 0xFF;
            $this->writeRm8($runtime, $streamReader, $modRegRM, $result);
        } else {
            $value = $this->readRm16($runtime, $streamReader, $modRegRM);
            $result = (0 - $value) & 0xFFFF;

            $this->writeRm16($runtime, $streamReader, $modRegRM, $result);
        }
        $runtime->memoryAccessor()->setCarryFlag($value !== 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function mul(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte): ExecutionStatus
    {
        if ($isByte) {
            $operand = $this->readRm8($runtime, $streamReader, $modRegRM);
            $al = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $product = $al * $operand;
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $product & 0xFFFF);
            $runtime->memoryAccessor()->setCarryFlag(($product & 0xFF00) !== 0);
            return ExecutionStatus::SUCCESS;
        }

        $operand = $this->readRm16($runtime, $streamReader, $modRegRM);
        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();

        $product = $ax * $operand;

        $runtime
            ->memoryAccessor()
            ->write16Bit(RegisterType::EAX, $product & 0xFFFF)
            ->enableUpdateFlags(false)
            ->write16Bit(RegisterType::EDX, ($product >> 16) & 0xFFFF);

        $runtime->memoryAccessor()->setCarryFlag(($product >> 16) !== 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function imul(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte): ExecutionStatus
    {
        if ($isByte) {
            $operandRaw = $this->readRm8($runtime, $streamReader, $modRegRM);
            $alRaw = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asLowBit();
            $operand = $operandRaw >= 0x80 ? $operandRaw - 0x100 : $operandRaw;
            $al = $alRaw >= 0x80 ? $alRaw - 0x100 : $alRaw;
            $product = $al * $operand;
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $product & 0xFFFF);
            $runtime->memoryAccessor()->setCarryFlag(($product < -128) || ($product > 127));
            return ExecutionStatus::SUCCESS;
        }

        $operand = $this->readRm16($runtime, $streamReader, $modRegRM);
        $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();

        // interpret as signed 16-bit
        $sOperand = $operand >= 0x8000 ? $operand - 0x10000 : $operand;
        $sAx = $ax >= 0x8000 ? $ax - 0x10000 : $ax;
        $product = $sAx * $sOperand;

        $runtime
            ->memoryAccessor()
            ->write16Bit(RegisterType::EAX, $product & 0xFFFF)
            ->enableUpdateFlags(false)
            ->write16Bit(RegisterType::EDX, ($product >> 16) & 0xFFFF);

        $runtime->memoryAccessor()->setCarryFlag(($product >> 16) !== 0);

        return ExecutionStatus::SUCCESS;
    }

    protected function div(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte): ExecutionStatus
    {
        if ($isByte) {
            $divider = $this->readRm8($runtime, $streamReader, $modRegRM);
            $ax = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();
            $quotient = intdiv($ax, $divider);
            $remainder = $ax % $divider;
            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $quotient & 0xFF);
            $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, $remainder & 0xFF);
            return ExecutionStatus::SUCCESS;
        }

        $divider = $this->readRm16($runtime, $streamReader, $modRegRM);

        $ax = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EAX)
            ->asByte();

        $dx = $runtime
            ->memoryAccessor()
            ->fetch(RegisterType::EDX)
            ->asByte();

        $dividee = ($dx << 16) + $ax;

        $quotient = (int) ($dividee / $divider);
        $remainder = $dividee % $divider;

        $runtime
            ->memoryAccessor()
            ->write16Bit(
                RegisterType::EAX,
                $quotient & 0xFFFF,
            );

        $runtime
            ->memoryAccessor()
            ->enableUpdateFlags(false)
            ->write16Bit(
                RegisterType::EDX,
                $remainder & 0xFFFF,
            );


        return ExecutionStatus::SUCCESS;
    }

    protected function idiv(RuntimeInterface $runtime, EnhanceStreamReader $streamReader, ModRegRMInterface $modRegRM, bool $isByte): ExecutionStatus
    {
        if ($isByte) {
            $dividerRaw = $this->readRm8($runtime, $streamReader, $modRegRM);
            $divider = $dividerRaw >= 0x80 ? $dividerRaw - 0x100 : $dividerRaw;

            $axRaw = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();
            $ax = $axRaw >= 0x8000 ? $axRaw - 0x10000 : $axRaw;

            $quotient = (int) ($ax / $divider);
            $remainder = $ax % $divider;

            $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, $quotient & 0xFF);
            $runtime->memoryAccessor()->writeToHighBit(RegisterType::EAX, $remainder & 0xFF);
            $runtime->memoryAccessor()->setCarryFlag(false);
            return ExecutionStatus::SUCCESS;
        } else {
            $dividerRaw = $this->readRm16($runtime, $streamReader, $modRegRM);
            $divider = $dividerRaw >= 0x8000 ? $dividerRaw - 0x10000 : $dividerRaw;

            $axRaw = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asByte();
            $dxRaw = $runtime->memoryAccessor()->fetch(RegisterType::EDX)->asByte();
            $ax = $axRaw >= 0x8000 ? $axRaw - 0x10000 : $axRaw;
            $dx = $dxRaw >= 0x8000 ? $dxRaw - 0x10000 : $dxRaw;

            $dividee = ($dx << 16) + ($ax & 0xFFFF);

            $quotient = (int) ($dividee / $divider);
            $remainder = $dividee % $divider;

            $runtime
                ->memoryAccessor()
                ->write16Bit(RegisterType::EAX, $quotient & 0xFFFF)
                ->enableUpdateFlags(false)
                ->write16Bit(RegisterType::EDX, $remainder & 0xFFFF);

            $runtime->memoryAccessor()->setCarryFlag(false);

            return ExecutionStatus::SUCCESS;
        }
    }
}
