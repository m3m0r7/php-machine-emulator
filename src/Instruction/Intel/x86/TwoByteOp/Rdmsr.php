<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\TwoByteOp;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionInterface;
use PHPMachineEmulator\Instruction\Intel\x86\Instructable;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Util\UInt64;

/**
 * RDMSR (0x0F 0x32)
 * Read Model-Specific Register.
 */
class Rdmsr implements InstructionInterface
{
    use Instructable;

    /** @var array<int, UInt64|int> */
    private static array $msr = [];

    public function opcodes(): array
    {
        return [[0x0F, 0x32]];
    }

    public function process(RuntimeInterface $runtime, int $opcode): ExecutionStatus
    {
        if ($runtime->context()->cpu()->cpl() !== 0) {
            throw new FaultException(0x0D, 0, 'RDMSR privilege check failed');
        }

        $ma = $runtime->memoryAccessor();
        $ecx = $ma->fetch(RegisterType::ECX)->asBytesBySize(32);
        $value = self::$msr[$ecx] ?? UInt64::zero();
        if (!$value instanceof UInt64) {
            $value = UInt64::of($value);
        }

        if ($ecx === 0x10) { // TSC MSR
            $value = UInt64::of((int) (microtime(true) * 1_000_000));
        } elseif ($ecx === 0x1B) { // APIC_BASE
            $value = UInt64::of($runtime->context()->cpu()->apicState()->readMsrApicBase());
        } elseif ($ecx === 0xC0000080) { // EFER
            $value = UInt64::of($ma->readEfer());
        } elseif (in_array($ecx, [0x174, 0x175, 0x176], true)) { // SYSENTER_CS/ESP/EIP
            $stored = self::$msr[$ecx] ?? 0;
            $value = $stored instanceof UInt64 ? $stored : UInt64::of($stored);
        }

        $this->writeRegisterBySize($runtime, RegisterType::EAX, $value->low32(), 32);
        $this->writeRegisterBySize($runtime, RegisterType::EDX, $value->high32(), 32);

        return ExecutionStatus::SUCCESS;
    }

    public static function writeMsr(int $index, UInt64|int $value): void
    {
        self::$msr[$index] = $value instanceof UInt64 ? $value : UInt64::of($value);
    }

    public static function readMsr(int $index): UInt64
    {
        $value = self::$msr[$index] ?? 0;
        return $value instanceof UInt64 ? $value : UInt64::of($value);
    }
}
