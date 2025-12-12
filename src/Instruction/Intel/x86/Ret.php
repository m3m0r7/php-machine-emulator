<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Instruction\InstructionInterface;

class Ret implements InstructionInterface
{
    use Instructable;

    public function opcodes(): array
    {
        return $this->applyPrefixes([0xC3, 0xC2, 0xCB, 0xCA]);
    }

    public function process(RuntimeInterface $runtime, array $opcodes): ExecutionStatus
    {
        $opcodes = $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $popBytes = ($opcode === 0xC2 || $opcode === 0xCA)
            ? $runtime->memory()->short()
            : 0;

        $size = $runtime->context()->cpu()->operandSize();

        $ma = $runtime->memoryAccessor();
        $espBefore = $ma->fetch(RegisterType::ESP)->asBytesBySize($size);
        $stackPeek = $this->segmentOffsetAddress($runtime, RegisterType::SS, $espBefore);
        $stackPeekVal = $this->readMemory16($runtime, $stackPeek);
        $returnIp = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);

        $targetCs = $ma->fetch(RegisterType::CS)->asByte();
        $currentCpl = $runtime->context()->cpu()->cpl();
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $descriptor = null;
        $nextCpl = null;

        if ($opcode === 0xCB || $opcode === 0xCA) {
            $espBeforeFar = $ma->fetch(RegisterType::ESP)->asBytesBySize(32);
            // FAR ret: pop CS as well
            $targetCs = $ma->pop(RegisterType::ESP, 16)->asBytesBySize(16);
            if ($runtime->context()->cpu()->isProtectedMode()) {
                $descriptor = $this->resolveCodeDescriptor($runtime, $targetCs);
                $nextCpl = $this->computeCplForTransfer($runtime, $targetCs, $descriptor);
            }
            $newCpl = $targetCs & 0x3;
            $runtime->option()->logger()->debug(sprintf(
                'RET FAR: returnIp=0x%04X targetCs=0x%04X ESP(before)=0x%08X',
                $returnIp,
                $targetCs,
                $espBeforeFar
            ));

            if ($runtime->context()->cpu()->isProtectedMode() && $newCpl > $currentCpl) {
                // Returning to outer privilege: pop new ESP/SS from current stack before switching.
                $newEsp = $ma->pop(RegisterType::ESP, $size)->asBytesBySize($size);
                $newSs = $ma->pop(RegisterType::ESP, 16)->asBytesBySize(16);
                $ma->write16Bit(RegisterType::SS, $newSs & 0xFFFF);
                $ma->writeBySize(RegisterType::ESP, $newEsp & $mask, $size);
            }
        }

        if ($popBytes > 0) {
            $ma->writeBySize(RegisterType::ESP, ($ma->fetch(RegisterType::ESP)->asBytesBySize($size) + $popBytes) & $mask, $size);
        }

        if ($opcode === 0xCB || $opcode === 0xCA) {
            $this->writeCodeSegment($runtime, $targetCs, $nextCpl, $descriptor);
        }

        if ($runtime->option()->shouldChangeOffset()) {
            // Always use linearCodeAddress for both near and far returns
            // This properly handles protected mode by resolving segment base from descriptor
            $cs = ($opcode === 0xCB || $opcode === 0xCA) ? ($targetCs & 0xFFFF) : $ma->fetch(RegisterType::CS)->asByte();
            if ($opcode === 0xC3 || $opcode === 0xC2) {
                if ($returnIp < 0x0800 && !$runtime->context()->cpu()->isProtectedMode()) {
                    $stackAddr = $this->segmentOffsetAddress($runtime, RegisterType::SS, $espBefore);
                    $stackTop = $this->readMemory16($runtime, $stackAddr);
                    $stackNext = $this->readMemory16($runtime, $stackAddr + 2);
                    $cachedSs = $runtime->context()->cpu()->getCachedSegmentDescriptor(RegisterType::SS);
                    $ss = $ma->fetch(RegisterType::SS)->asByte();
                    $runtime->option()->logger()->debug(sprintf(
                        'RET NEAR: returnIp=0x%04X CS=0x%04X SS=0x%04X ESP(before)=0x%08X ESP(after)=0x%08X opSize=%d defaultOp=%d stackTop=0x%04X next=0x%04X stackAddr=0x%08X cachedSS[base=0x%08X limit=0x%08X present=%s]',
                        $returnIp,
                        $cs,
                        $ss,
                        $espBefore,
                        $ma->fetch(RegisterType::ESP)->asBytesBySize(32),
                        $size,
                        $runtime->context()->cpu()->defaultOperandSize(),
                        $stackPeekVal,
                        $stackNext,
                        $stackAddr,
                        $cachedSs['base'] ?? 0,
                        $cachedSs['limit'] ?? 0,
                        $cachedSs ? 'yes' : 'no'
                    ));
                }
            }
            $linear = $this->linearCodeAddress($runtime, $cs, $returnIp, $size);
            $runtime->memory()->setOffset($linear);
        }

        return ExecutionStatus::SUCCESS;
    }
}
