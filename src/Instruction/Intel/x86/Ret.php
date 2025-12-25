<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Instruction\PrefixClass;

use PHPMachineEmulator\Exception\ExecutionException;
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
        $opcodes = $this->parsePrefixes($runtime, $opcodes);
        $opcode = $opcodes[0];
        $cpu = $runtime->context()->cpu();

        // In 64-bit mode, near RET pops a 64-bit RIP regardless of operand-size override.
        // Far returns (RETF) are valid and are used for segment/mode transitions.
        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $ma = $runtime->memoryAccessor();
            $rspBefore = $ma->fetch(RegisterType::ESP)->asBytesBySize(64);
            $popBytes = ($opcode === 0xC2 || $opcode === 0xCA) ? $runtime->memory()->short() : 0;

            if ($opcode === 0xCA || $opcode === 0xCB) {
                // RETF in 64-bit mode:
                // - default: pop RIP and CS using 64-bit stack slots (popq/popq)
                // - 0x66 prefix: pop 16-bit (popw/popw)
                $popSize = $cpu->consumeOperandSizeOverride() ? 16 : 64;

                $returnRip = $ma->pop(RegisterType::ESP, $popSize)->asBytesBySize($popSize);
                $targetCsRaw = $ma->pop(RegisterType::ESP, $popSize)->asBytesBySize($popSize);
                $targetCs = $targetCsRaw & 0xFFFF;

                $descriptor = null;
                $nextCpl = null;
                if ($cpu->isProtectedMode()) {
                    $descriptor = $this->resolveCodeDescriptor($runtime, $targetCs);
                    $nextCpl = $this->computeCplForTransfer($runtime, $targetCs, $descriptor);
                }

                // Returning to an outer privilege pops a new stack pointer and SS.
                $currentCpl = $cpu->cpl();
                $newCpl = $targetCs & 0x3;
                if ($cpu->isProtectedMode() && $newCpl > $currentCpl) {
                    $newRsp = $ma->pop(RegisterType::ESP, $popSize)->asBytesBySize($popSize);
                    $newSsRaw = $ma->pop(RegisterType::ESP, $popSize)->asBytesBySize($popSize);
                    $newSs = $newSsRaw & 0xFFFF;

                    $ma->write16Bit(RegisterType::SS, $newSs);
                    $ma->writeBySize(RegisterType::ESP, $newRsp, 64);
                }

                if ($popBytes > 0) {
                    $rsp = $ma->fetch(RegisterType::ESP)->asBytesBySize(64);
                    $ma->writeBySize(RegisterType::ESP, $rsp + $popBytes, 64);
                }

                $this->writeCodeSegment($runtime, $targetCs, $nextCpl, $descriptor);

                if ($runtime->option()->shouldChangeOffset()) {
                    $max = $runtime->memory()->logicalMaxMemorySize();
                    if ($returnRip < 0 || $returnRip >= $max) {
                        $cs = $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF;
                        $ss = $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF;
                        $rspAfter = $ma->fetch(RegisterType::ESP)->asBytesBySize(64);
                        $linearMask = $this->linearMask($runtime);
                        $stackLinear = $rspBefore & $linearMask;
                        $stackHex = '';
                        for ($i = 0; $i < 32; $i++) {
                            try {
                                $stackHex .= sprintf('%02X', $this->readMemory8($runtime, $stackLinear + $i));
                            } catch (\Throwable) {
                                break;
                            }
                        }
                        $runtime->option()->logger()->error(sprintf(
                            'BAD RETF64: rip=0x%016X max=0x%08X rspBefore=0x%016X rspAfter=0x%016X cs=0x%04X ss=0x%04X PM=%d PG=%d LM=%d CM=%d stackLinear=0x%016X stack=%s',
                            $returnRip,
                            $max & 0xFFFFFFFF,
                            $rspBefore,
                            $rspAfter,
                            $cs,
                            $ss,
                            $cpu->isProtectedMode() ? 1 : 0,
                            $cpu->isPagingEnabled() ? 1 : 0,
                            $cpu->isLongMode() ? 1 : 0,
                            $cpu->isCompatibilityMode() ? 1 : 0,
                            $stackLinear,
                            $stackHex === '' ? 'n/a' : $stackHex,
                        ));
                        throw new \PHPMachineEmulator\Exception\HaltException('Stopped by bad RETF target');
                    }
                    if (!$cpu->isCompatibilityMode()) {
                        // 64-bit CS: RIP is a linear address (segmentation is ignored).
                        $runtime->memory()->setOffset($returnRip);
                    } else {
                        $linear = $this->linearCodeAddress($runtime, $targetCs, $returnRip, $popSize);
                        $runtime->memory()->setOffset($linear);
                    }
                }

                return ExecutionStatus::SUCCESS;
            }

            $returnRip = $ma->pop(RegisterType::ESP, 64)->asBytesBySize(64);

            if ($popBytes > 0) {
                $rsp = $ma->fetch(RegisterType::ESP)->asBytesBySize(64);
                $ma->writeBySize(RegisterType::ESP, $rsp + $popBytes, 64);
            }

            if ($runtime->option()->shouldChangeOffset()) {
                $max = $runtime->memory()->logicalMaxMemorySize();
                if ($returnRip < 0 || $returnRip >= $max) {
                    $cs = $ma->fetch(RegisterType::CS)->asByte() & 0xFFFF;
                    $ss = $ma->fetch(RegisterType::SS)->asByte() & 0xFFFF;
                    $rspAfter = $ma->fetch(RegisterType::ESP)->asBytesBySize(64);
                    $linearMask = $this->linearMask($runtime);
                    $stackLinear = $rspBefore & $linearMask;
                    $stackHex = '';
                    for ($i = 0; $i < 32; $i++) {
                        try {
                            $stackHex .= sprintf('%02X', $this->readMemory8($runtime, $stackLinear + $i));
                        } catch (\Throwable) {
                            break;
                        }
                    }
                    $runtime->option()->logger()->error(sprintf(
                        'BAD RET64: rip=0x%016X max=0x%08X rspBefore=0x%016X rspAfter=0x%016X cs=0x%04X ss=0x%04X PM=%d PG=%d LM=%d CM=%d stackLinear=0x%016X stack=%s',
                        $returnRip,
                        $max & 0xFFFFFFFF,
                        $rspBefore,
                        $rspAfter,
                        $cs,
                        $ss,
                        $cpu->isProtectedMode() ? 1 : 0,
                        $cpu->isPagingEnabled() ? 1 : 0,
                        $cpu->isLongMode() ? 1 : 0,
                        $cpu->isCompatibilityMode() ? 1 : 0,
                        $stackLinear,
                        $stackHex === '' ? 'n/a' : $stackHex,
                    ));
                    throw new \PHPMachineEmulator\Exception\HaltException('Stopped by bad RET target');
                }
                $runtime->memory()->setOffset($returnRip);
            }

            return ExecutionStatus::SUCCESS;
        }

        $popBytes = ($opcode === 0xC2 || $opcode === 0xCA)
            ? $runtime->memory()->short()
            : 0;

        $size = $cpu->operandSize();

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
            if (!$runtime->context()->cpu()->isProtectedMode() && ($opcode === 0xC3 || $opcode === 0xC2)) {
                $dbgLinear = $this->linearCodeAddress($runtime, $cs, $returnIp, $size);
                $runtime->option()->logger()->debug(sprintf(
                    'RET NEAR: returnIp=0x%04X CS=0x%04X linearTarget=0x%05X',
                    $returnIp,
                    $cs,
                    $dbgLinear
                ));
            }
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
