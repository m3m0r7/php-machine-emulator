<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI\UEFIEnvironment;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\PagedMemoryStream;

trait UEFIEnvironmentKernelFastBoot
{
    private ?array $grubBootEntry = null;

    public function maybeFastDecompressKernel(RuntimeInterface $runtime, int $ip, int $hitCount): bool
    {
        if (!$this->fastKernelEnabled()) {
            return false;
        }

        [$handle, $kernel] = $this->findKernelByIp($ip);
        if ($kernel === null) {
            if ($this->linuxKernelImages !== []) {
                return false;
            }
            if (!$this->linuxKernelCandidateLoaded) {
                return false;
            }
            if ($this->linuxKernelScanCooldown > 0) {
                $this->linuxKernelScanCooldown--;
                return false;
            }
            $this->linuxKernelScanCooldown = 50;
            $found = $this->scanLinuxKernelFromMemory($runtime, $ip);
            if ($found === null) {
                return false;
            }
            [$handle, $kernel] = $found;
            if ($kernel === null) {
                return false;
            }
            $base = (int) ($kernel['base'] ?? 0);
            $size = (int) ($kernel['size'] ?? 0);
            if ($base <= 0 || $size <= 0 || $ip < $base || $ip >= ($base + $size)) {
                return false;
            }
        }

        $kernelBase = (int) ($kernel['base'] ?? 0);
        $kernelSize = (int) ($kernel['size'] ?? 0);
        $kernelOffset = (int) ($kernel['kernel_offset'] ?? 0);
        $payloadOffset = (int) ($kernel['payload_offset'] ?? 0);
        $payloadLength = (int) ($kernel['payload_length'] ?? 0);
        $payloadStart = 0;
        $payloadEnd = 0;
        if ($kernelBase > 0 && $payloadLength > 0) {
            $payloadStart = $kernelBase + $kernelOffset + $payloadOffset;
            $payloadEnd = $payloadStart + $payloadLength;
        }
        $kernelEnd = $kernelBase + $kernelSize;

        $this->maybeCaptureBootParams($runtime, $handle, $kernel);

        if (empty($kernel['fast_done'])) {
            $bootParams = $this->resolveBootParams($runtime, $kernel);
            if ($bootParams === null) {
                $bootParamsHint = $kernel['boot_params_hint'] ?? null;
                if ($bootParamsHint === null) {
                    $bootParamsHint = $this->findBootParams($runtime, $kernel, 0);
                    if ($bootParamsHint !== null) {
                        $this->linuxKernelImages[$handle]['boot_params_hint'] = $bootParamsHint;
                        $kernel['boot_params_hint'] = $bootParamsHint;
                    }
                }
                if ($bootParamsHint !== null && empty($kernel['boot_params_patched'])) {
                    if ($this->patchBootParamsFromGrub($runtime, $bootParamsHint, $kernel)) {
                        $this->linuxKernelImages[$handle]['boot_params_patched'] = true;
                        $kernel['boot_params_patched'] = true;
                        $this->linuxKernelImages[$handle]['boot_params'] = $bootParamsHint;
                        $kernel['boot_params'] = $bootParamsHint;
                    }
                }
                if ($this->linuxKernelFastBootLogCount < 20) {
                    $runtime->option()->logger()->warning(sprintf(
                        'FAST_KERNEL_BOOT_PARAMS_MISS: ip=0x%08X base=0x%08X hint=0x%08X',
                        $ip & 0xFFFFFFFF,
                        $kernelBase & 0xFFFFFFFF,
                        ($bootParamsHint ?? 0) & 0xFFFFFFFF,
                    ));
                    $this->linuxKernelFastBootLogCount++;
                }
            } else {
                $this->linuxKernelImages[$handle]['boot_params'] = $bootParams;
                $kernel['boot_params'] = $bootParams;
            }
        }

        if (!empty($kernel['fast_done'])) {
            if (empty($kernel['fast_jump_done'])) {
                $fastReturn = (int) ($kernel['fast_return'] ?? 0);
                $inPayload = $payloadEnd > $payloadStart
                    && $ip >= $payloadStart
                    && $ip < $payloadEnd;
                if ($fastReturn > 0 || $inPayload) {
                    $cpu = $runtime->context()->cpu();
                    $ptrSize = $cpu->addressSize() === 64 ? 8 : 4;
                    $ma = $runtime->memoryAccessor();

                    $fastProlog = $kernel['fast_prolog'] ?? null;
                    $foundFastReturn = false;
                    $pushes = [];
                    $locals = 0;
                    if ($fastReturn > 0 && is_array($fastProlog)) {
                        $pushes = $fastProlog['pushes'] ?? [];
                        $locals = (int) ($fastProlog['locals'] ?? 0);
                        if ($pushes !== [] || $locals > 0) {
                            $frameSize = $locals + (count($pushes) * $ptrSize);
                            $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($ptrSize * 8);
                            $scanMax = $sp + 0x20000;
                            for ($addr = $sp; $addr <= $scanMax; $addr += $ptrSize) {
                                $candidate = $this->readPointer($runtime, $addr, $ptrSize);
                                if ($candidate !== $fastReturn) {
                                    continue;
                                }
                                $foundFastReturn = true;
                                $frameBase = $addr - $frameSize;
                                if ($frameBase < $sp) {
                                    continue;
                                }
                                $offset = 0;
                                $base = $frameBase + $locals;
                                for ($i = count($pushes) - 1; $i >= 0; $i--) {
                                    $reg = $pushes[$i];
                                    $value = $this->readPointer($runtime, $base + $offset, $ptrSize);
                                    $ma->writeBySize($reg, $value, $ptrSize * 8);
                                    $offset += $ptrSize;
                                }
                                $ma->writeBySize(RegisterType::ESP, $addr + $ptrSize, $ptrSize * 8);
                                $ma->writeBySize(RegisterType::EAX, 0, $ptrSize * 8);
                                $runtime->memory()->setOffset($fastReturn);
                                $this->linuxKernelImages[$handle]['fast_jump_done'] = true;
                                if ($this->linuxKernelFastBootLogCount < 20) {
                                    $runtime->option()->logger()->warning(sprintf(
                                        'FAST_KERNEL_SKIP: return=0x%08X',
                                        $fastReturn & 0xFFFFFFFF,
                                    ));
                                    $this->linuxKernelFastBootLogCount++;
                                }
                                return true;
                            }
                        }
                    }

                    if ($fastReturn > 0 && !$foundFastReturn && ($pushes !== [] || $locals > 0)) {
                        $frameSize = $locals + (count($pushes) * $ptrSize);
                        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($ptrSize * 8);
                        $newSp = $sp + $frameSize + $ptrSize;
                        if ($newSp > $sp) {
                            $ma->writeBySize(RegisterType::ESP, $newSp, $ptrSize * 8);
                        }
                        $bootParamsHint = $kernel['boot_params_hint'] ?? null;
                        if ($bootParamsHint !== null) {
                            $ma->writeBySize(RegisterType::ESI, $bootParamsHint, $ptrSize * 8);
                        }
                        $ma->writeBySize(RegisterType::EAX, 0, $ptrSize * 8);
                        $runtime->memory()->setOffset($fastReturn);
                        $this->linuxKernelImages[$handle]['fast_jump_done'] = true;
                        if ($this->linuxKernelFastBootLogCount < 20) {
                            $runtime->option()->logger()->warning(sprintf(
                                'FAST_KERNEL_FORCE_SKIP: return=0x%08X sp=0x%08X new_sp=0x%08X',
                                $fastReturn & 0xFFFFFFFF,
                                $sp & 0xFFFFFFFF,
                                $newSp & 0xFFFFFFFF,
                            ));
                            $this->linuxKernelFastBootLogCount++;
                        }
                        return true;
                    }

                    if ($fastReturn > 0 && !$foundFastReturn && $this->linuxKernelSkipLogCount < 5) {
                        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($ptrSize * 8);
                        $peek = [];
                        for ($i = 0; $i < 4; $i++) {
                            $peek[] = sprintf('0x%08X', $this->readPointer($runtime, $sp + ($i * $ptrSize), $ptrSize) & 0xFFFFFFFF);
                        }
                        $runtime->option()->logger()->warning(sprintf(
                            'FAST_KERNEL_SKIP_MISS: ip=0x%08X fast=0x%08X sp=0x%08X top=%s',
                            $ip & 0xFFFFFFFF,
                            $fastReturn & 0xFFFFFFFF,
                            $sp & 0xFFFFFFFF,
                            implode(',', $peek),
                        ));
                        $this->linuxKernelSkipLogCount++;
                    }

                    $currentIp = $ip;
                    $framesUnwound = 0;
                    $maxFrames = 6;

                    while ($framesUnwound < $maxFrames) {
                        $prolog = $this->findX86Prolog($runtime, $currentIp);
                        if ($prolog === null) {
                            $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($ptrSize * 8);
                            $returnAddr = $this->readPointer($runtime, $sp, $ptrSize);
                            if ($returnAddr <= 0) {
                                break;
                            }
                            $ma->writeBySize(RegisterType::ESP, $sp + $ptrSize, $ptrSize * 8);
                            $ma->writeBySize(RegisterType::EAX, 0, $ptrSize * 8);
                            $runtime->memory()->setOffset($returnAddr);
                            $currentIp = $returnAddr;
                            $framesUnwound++;
                        } else {
                            $unwind = $this->unwindKernelProlog($runtime, $kernel, $prolog);
                            if ($unwind === null) {
                                break;
                            }
                            $returnAddr = (int) ($unwind['return_addr'] ?? 0);
                            $returnSlot = (int) ($unwind['return_slot'] ?? 0);
                            $frameBase = (int) ($unwind['frame_base'] ?? 0);
                            $pushes = $prolog['pushes'] ?? [];
                            $locals = (int) ($prolog['locals'] ?? 0);
                            $base = $frameBase + $locals;
                            $offset = 0;
                            for ($i = count($pushes) - 1; $i >= 0; $i--) {
                                $reg = $pushes[$i];
                                $value = $this->readPointer($runtime, $base + $offset, $ptrSize);
                                $ma->writeBySize($reg, $value, $ptrSize * 8);
                                $offset += $ptrSize;
                            }
                            $newSp = $returnSlot + $ptrSize;
                            $ma->writeBySize(RegisterType::ESP, $newSp, $ptrSize * 8);
                            $ma->writeBySize(RegisterType::EAX, 0, $ptrSize * 8);
                            if ($returnAddr > 0) {
                                $runtime->memory()->setOffset($returnAddr);
                            }
                            $currentIp = $returnAddr;
                            $framesUnwound++;
                            if ($returnAddr <= 0) {
                                break;
                            }
                        }

                        if ($fastReturn > 0) {
                            if ($currentIp === $fastReturn) {
                                $this->linuxKernelImages[$handle]['fast_jump_done'] = true;
                                if ($this->linuxKernelFastBootLogCount < 20) {
                                    $runtime->option()->logger()->warning(sprintf(
                                        'FAST_KERNEL_SKIP: return=0x%08X',
                                        $currentIp & 0xFFFFFFFF,
                                    ));
                                    $this->linuxKernelFastBootLogCount++;
                                }
                                return true;
                            }
                            if ($kernelBase > 0 && $kernelEnd > $kernelBase) {
                                if ($currentIp < $kernelBase || $currentIp >= $kernelEnd) {
                                    break;
                                }
                            }
                        } else {
                            $this->linuxKernelImages[$handle]['fast_jump_done'] = true;
                            if ($this->linuxKernelFastBootLogCount < 20) {
                                $runtime->option()->logger()->warning(sprintf(
                                    'FAST_KERNEL_SKIP: return=0x%08X',
                                    $currentIp & 0xFFFFFFFF,
                                ));
                                $this->linuxKernelFastBootLogCount++;
                            }
                            return true;
                        }
                    }
                }
            }
            $skipFail = (int) ($kernel['fast_skip_fail'] ?? 0) + 1;
            $this->linuxKernelImages[$handle]['fast_skip_fail'] = $skipFail;
            if ($skipFail >= 1) {
                $elfEntry = (int) ($kernel['elf_entry'] ?? 0);
                $elfBits = (int) ($kernel['elf_bits'] ?? 0);
                if ($elfEntry > 0 && $elfBits === 32) {
                    $latest = $this->linuxKernelImages[$handle] ?? $kernel;
                    if (
                        $this->fastKernelJumpToEntry($runtime, $handle, $latest, [
                        'entry' => $elfEntry,
                        'bits' => $elfBits,
                        ])
                    ) {
                        return true;
                    }
                }
            }

            return false;
        }

        if (!empty($kernel['fast_failed'])) {
            return false;
        }

        if ($this->linuxKernelProbeLogCount < 5) {
            $runtime->option()->logger()->warning(sprintf(
                'FAST_KERNEL_CHECK: ip=0x%08X base=0x%08X size=0x%X',
                $ip & 0xFFFFFFFF,
                ($kernel['base'] ?? 0) & 0xFFFFFFFF,
                $kernel['size'] ?? 0,
            ));
            $this->linuxKernelProbeLogCount++;
        }

        if ($kernelBase > 0 && $kernelSize > 0) {
            $kernelStart = $kernelBase + ($kernelOffset > 0 ? $kernelOffset : 0);
            $kernelEnd = $kernelBase + $kernelSize;
            $startWindow = max(0x200000, $payloadOffset);
            if ($startWindow > $kernelSize) {
                $startWindow = $kernelSize;
            }
            $endWindow = $kernelSize > 0 ? min($kernelSize, 0x200000) : 0;
            $inStart = $ip >= $kernelStart && $ip < ($kernelStart + $startWindow);
            $inEnd = $ip >= ($kernelEnd - $endWindow) && $ip < $kernelEnd;
            if (!$inStart && !$inEnd) {
                if ($this->linuxKernelProbeLogCount < 5) {
                    $runtime->option()->logger()->warning(sprintf(
                        'FAST_KERNEL_SKIP: ip=0x%08X base=0x%08X start=0x%08X end=0x%08X winStart=0x%X winEnd=0x%X',
                        $ip & 0xFFFFFFFF,
                        $kernelBase & 0xFFFFFFFF,
                        $kernelStart & 0xFFFFFFFF,
                        $kernelEnd & 0xFFFFFFFF,
                        $startWindow,
                        $endWindow,
                    ));
                    $this->linuxKernelProbeLogCount++;
                }
                return false;
            }
        }

        $payload = $kernel['payload'] ?? '';
        if ($payload === '') {
            $this->linuxKernelImages[$handle]['fast_failed'] = true;
            $this->logKernelFastBoot($runtime, 'payload missing', $kernel);
            return false;
        }

        $decoded = @gzdecode($payload);
        if ($decoded === false) {
            $this->linuxKernelImages[$handle]['fast_failed'] = true;
            $this->logKernelFastBoot($runtime, 'payload decode failed', $kernel);
            return false;
        }

        $elf = $this->parseElfImage($decoded);
        if ($elf === null) {
            $this->linuxKernelImages[$handle]['fast_failed'] = true;
            $this->logKernelFastBoot($runtime, 'ELF parse failed', $kernel);
            return false;
        }

        $this->loadElfSegments($runtime, $decoded, $elf);

        $kernelLimit = $kernelBase + $kernelSize;

        $currentIp = $ip;
        $framesUnwound = 0;
        $maxFrames = 3;
        $lastProlog = null;

        while ($framesUnwound < $maxFrames) {
            $prolog = $this->findX86Prolog($runtime, $currentIp);
            if ($prolog === null) {
                $cpu = $runtime->context()->cpu();
                $ptrSize = $cpu->addressSize() === 64 ? 8 : 4;
                $ma = $runtime->memoryAccessor();
                $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($ptrSize * 8);
                $returnAddr = $this->readPointer($runtime, $sp, $ptrSize);
                if ($returnAddr > 0) {
                    $ma->writeBySize(RegisterType::ESP, $sp + $ptrSize, $ptrSize * 8);
                    $ma->writeBySize(RegisterType::EAX, 0, $ptrSize * 8);
                    $runtime->memory()->setOffset($returnAddr);
                    $currentIp = $returnAddr;
                    $framesUnwound++;
                    continue;
                }

                if ($this->linuxKernelUnwindLogCount < 10) {
                    $runtime->option()->logger()->warning(sprintf(
                        'FAST_KERNEL_UNWIND: no prolog at ip=0x%08X frame=%d',
                        $currentIp & 0xFFFFFFFF,
                        $framesUnwound,
                    ));
                    $this->linuxKernelUnwindLogCount++;
                }
                break;
            }

            $unwind = $this->unwindKernelProlog($runtime, $kernel, $prolog);
            if ($unwind === null) {
                if ($this->linuxKernelUnwindLogCount < 10) {
                    $runtime->option()->logger()->warning(sprintf(
                        'FAST_KERNEL_UNWIND: no frame at ip=0x%08X frame=%d',
                        $currentIp & 0xFFFFFFFF,
                        $framesUnwound,
                    ));
                    $this->linuxKernelUnwindLogCount++;
                }
                break;
            }

            $ptrSize = $unwind['ptr_size'];
            $returnAddr = $unwind['return_addr'];
            $returnSlot = $unwind['return_slot'];
            $frameBase = $unwind['frame_base'];
            $lastProlog = $prolog;

            if ($kernelBase > 0 && $kernelLimit > $kernelBase) {
                $outside = $returnAddr < $kernelBase || $returnAddr >= $kernelLimit;
                if ($outside && $framesUnwound > 0) {
                    break;
                }
            }

            if ($this->linuxKernelUnwindLogCount < 10) {
                $runtime->option()->logger()->warning(sprintf(
                    'FAST_KERNEL_UNWIND: frame=%d ip=0x%08X return=0x%08X',
                    $framesUnwound,
                    $currentIp & 0xFFFFFFFF,
                    $returnAddr & 0xFFFFFFFF,
                ));
                $this->linuxKernelUnwindLogCount++;
            }

            $ma = $runtime->memoryAccessor();
            $base = $frameBase + (int) $prolog['locals'];
            $offset = 0;

            $pushes = $prolog['pushes'];
            for ($i = count($pushes) - 1; $i >= 0; $i--) {
                $reg = $pushes[$i];
                $value = $this->readPointer($runtime, $base + $offset, $ptrSize);
                $ma->writeBySize($reg, $value, $ptrSize * 8);
                $offset += $ptrSize;
            }

            $newSp = $returnSlot + $ptrSize;
            $ma->writeBySize(RegisterType::ESP, $newSp, $ptrSize * 8);
            $ma->writeBySize(RegisterType::EAX, 0, $ptrSize * 8);

            if ($returnAddr > 0) {
                $runtime->memory()->setOffset($returnAddr);
            }

            $currentIp = $returnAddr;
            $framesUnwound++;

            if ($payloadStart > 0 && $payloadEnd > $payloadStart) {
                if ($returnAddr < $payloadStart || $returnAddr >= $payloadEnd) {
                    break;
                }
            }

            if ($returnAddr <= 0) {
                break;
            }
        }

        if ($framesUnwound === 0) {
            if ($this->fastKernelJumpToEntry($runtime, $handle, $kernel, $elf)) {
                return true;
            }
            $this->logKernelFastBoot($runtime, 'stack unwind failed', $kernel);
            return false;
        }

        $this->linuxKernelImages[$handle]['fast_return'] = $currentIp;
        if ($lastProlog !== null) {
            $this->linuxKernelImages[$handle]['fast_prolog'] = [
                'pushes' => $lastProlog['pushes'] ?? [],
                'locals' => $lastProlog['locals'] ?? 0,
            ];
        }
        $this->linuxKernelImages[$handle]['fast_done'] = true;
        $this->linuxKernelImages[$handle]['elf_entry'] = $elf['entry'];
        $this->linuxKernelImages[$handle]['elf_bits'] = $elf['bits'] ?? 0;

        if ($this->linuxKernelFastBootLogCount < 20) {
            $runtime->option()->logger()->warning(sprintf(
                'FAST_KERNEL_DECOMPRESS: ip=0x%08X frames=%d return=0x%08X entry=0x%08X',
                $ip & 0xFFFFFFFF,
                $framesUnwound,
                $currentIp & 0xFFFFFFFF,
                $elf['entry'] & 0xFFFFFFFF,
            ));
            $this->linuxKernelFastBootLogCount++;
        }

        return true;
    }

    public function maybeRecoverKernelJump(RuntimeInterface $runtime, int $ip, int $vector): bool
    {
        if (!$this->fastKernelEnabled()) {
            return false;
        }
        if ($vector !== 0x0D || $ip > 0x10000) {
            return false;
        }

        foreach ($this->linuxKernelImages as $handle => $info) {
            if (empty($info['fast_done']) || empty($info['elf_entry'])) {
                continue;
            }
            if (!empty($info['fast_recover_done'])) {
                return false;
            }
            if (!empty($info['fast_jump_done'])) {
                return false;
            }
            $bits = (int) ($info['elf_bits'] ?? 0);
            if ($bits !== 32) {
                return false;
            }

            $entry = (int) ($info['elf_entry'] ?? 0);
            if ($entry <= 0) {
                return false;
            }

            $this->setupFlatGdt32($runtime);
            $ma = $runtime->memoryAccessor();
            $bootParams = $this->resolveBootParams($runtime, $info);
            if ($bootParams === null) {
                return false;
            }
            $ma->writeBySize(RegisterType::ESI, $bootParams, 32);
            $this->linuxKernelImages[$handle]['boot_params'] = $bootParams;
            $ma->writeBySize(RegisterType::EAX, 0, 32);
            $runtime->memory()->setOffset($entry);
            $this->linuxKernelImages[$handle]['fast_recover_done'] = true;
            if ($this->linuxKernelFastBootLogCount < 20) {
                $runtime->option()->logger()->warning(sprintf(
                    'FAST_KERNEL_RECOVER: ip=0x%08X entry=0x%08X',
                    $ip & 0xFFFFFFFF,
                    $entry & 0xFFFFFFFF,
                ));
                $this->linuxKernelFastBootLogCount++;
            }
            return true;
        }

        return false;
    }

    private function scanLinuxKernelFromMemory(RuntimeInterface $runtime, int $ip): ?array
    {
        $memory = $runtime->memory();
        $saved = $memory->offset();
        $maxMemory = $runtime->logicBoard()->memory()->maxMemory();
        $maxBack = 0x1000000;
        $maxForward = 0x200000;
        $start = $ip > $maxBack ? $ip - $maxBack : 0;
        $end = min($maxMemory, $ip + $maxForward);
        $chunk = 0x10000;

        for ($base = $start; $base < $end; $base += $chunk) {
            $memory->setOffset($base);
            $data = $memory->read($chunk);
            if ($data === '') {
                continue;
            }

            $pos = strpos($data, 'HdrS');
            while ($pos !== false) {
                $hdrAddr = $base + $pos;
                $fileBase = $hdrAddr - 0x202;
                if ($fileBase >= 0 && $fileBase < $maxMemory) {
                    $bootFlag = $this->readWord($runtime, $fileBase + 0x1FE);
                    if ($bootFlag === 0xAA55) {
                        $setupSects = $this->readByte($runtime, $fileBase + 0x1F1);
                        $kernelOffset = ($setupSects + 1) * 512;
                        if ($kernelOffset > 0) {
                            $payloadOffset = $this->readPointer($runtime, $fileBase + 0x1F1 + 0x57, 4);
                            $payloadLength = $this->readPointer($runtime, $fileBase + 0x1F1 + 0x5B, 4);
                            $handoverOffset = $this->readPointer($runtime, $fileBase + 0x1F1 + 0x73, 4);
                            $prefAddress = $this->readPointer($runtime, $fileBase + 0x1F1 + 0x67, 8);
                            $initSize = $this->readPointer($runtime, $fileBase + 0x1F1 + 0x6F, 4);

                            $payloadFileOffset = $kernelOffset + $payloadOffset;
                            $payloadAddr = $fileBase + $payloadFileOffset;

                            if ($payloadFileOffset >= 0 && $payloadAddr > 0 && $payloadAddr < $maxMemory) {
                                if ($payloadLength <= 0 || ($payloadAddr + $payloadLength) > $maxMemory) {
                                    $payloadLength = $maxMemory - $payloadAddr;
                                }

                                if ($payloadLength > 0) {
                                    $magic = $this->readWord($runtime, $payloadAddr);
                                    if ($magic === 0x8B1F) {
                                        $memory->setOffset($payloadAddr);
                                        $payload = $memory->read($payloadLength);
                                        if ($payload !== '') {
                                            $size = $payloadFileOffset + $payloadLength;
                                            $tailSlack = 0x20000;
                                            if ($initSize > $size) {
                                                $extra = $initSize - $size;
                                                $tailSlack = max($tailSlack, min($extra, 0x200000));
                                            }
                                            $size += $tailSlack;
                                            if ($size > 0 && ($fileBase + $size) > $maxMemory) {
                                                $size = max(0, $maxMemory - $fileBase);
                                            }
                                            $info = [
                                                'payload' => $payload,
                                                'payload_length' => $payloadLength,
                                                'payload_offset' => $payloadOffset,
                                                'kernel_offset' => $kernelOffset,
                                                'handover_offset' => $handoverOffset,
                                                'pref_address' => $prefAddress,
                                                'init_size' => $initSize,
                                            ];
                                            $entry = $fileBase + (int) $handoverOffset;
                                            $handle = $fileBase;
                                            $this->registerLinuxKernelImage(
                                                $runtime,
                                                $handle,
                                                $fileBase,
                                                $size,
                                                'memory',
                                                $info,
                                                $entry,
                                                'memory',
                                            );
                                            $memory->setOffset($saved);
                                            return [$handle, $this->linuxKernelImages[$handle]];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
                $pos = strpos($data, 'HdrS', $pos + 1);
            }
        }

        $memory->setOffset($saved);
        return null;
    }

    private function registerLinuxKernelImage(
        RuntimeInterface $runtime,
        int $handle,
        int $base,
        int $size,
        string $path,
        array $info,
        int $entry,
        string $source,
    ): void {
        $payload = $info['payload'] ?? '';
        if ($payload === '') {
            return;
        }

        $this->linuxKernelImages[$handle] = [
            'base' => $base,
            'size' => $size,
            'entry' => $entry,
            'path' => $path,
            'payload' => $payload,
            'payload_length' => $info['payload_length'] ?? 0,
            'payload_offset' => $info['payload_offset'] ?? 0,
            'kernel_offset' => $info['kernel_offset'] ?? 0,
            'handover_offset' => $info['handover_offset'] ?? 0,
            'pref_address' => $info['pref_address'] ?? 0,
            'init_size' => $info['init_size'] ?? 0,
            'fast_done' => false,
            'fast_failed' => false,
            'fast_recover_done' => false,
        ];

        if ($this->linuxKernelFastBootLogCount < 20) {
            $runtime->option()->logger()->warning(sprintf(
                'LINUX_KERNEL_IMAGE: handle=0x%08X base=0x%08X size=0x%X path=%s payload=0x%X handover=0x%X source=%s',
                $handle & 0xFFFFFFFF,
                $base & 0xFFFFFFFF,
                $size,
                $path,
                $info['payload_length'] ?? 0,
                $info['handover_offset'] ?? 0,
                $source,
            ));
            $this->linuxKernelFastBootLogCount++;
        }
    }

    private function maybeRegisterLinuxKernel(
        RuntimeInterface $runtime,
        int $handle,
        array $loaded,
        string $path,
        string $imageData,
    ): void {
        $lowerPath = strtolower($path);
        $looksKernelPath = $this->isLikelyLinuxKernelPath($path);
        if (!$looksKernelPath) {
            if (str_contains($lowerPath, 'grub')) {
                return;
            }
            if (str_ends_with($lowerPath, '/bootx64.efi') || str_ends_with($lowerPath, '/bootia32.efi')) {
                if (stripos($imageData, 'grub') !== false) {
                    return;
                }
            }
        }

        $info = $this->parseLinuxKernelImage($imageData);
        if ($info === null) {
            return;
        }

        $this->registerLinuxKernelImage(
            $runtime,
            $handle,
            (int) $loaded['base'],
            (int) $loaded['size'],
            $path,
            $info,
            (int) $loaded['entry'],
            'load',
        );
    }

    private function isLikelyLinuxKernelPath(string $path): bool
    {
        $lower = strtolower($path);
        return str_contains($lower, 'vmlinuz')
            || str_contains($lower, 'vmlinux')
            || str_contains($lower, 'bzimage');
    }

    private function parseLinuxKernelImage(string $imageData): ?array
    {
        $hdrPos = strpos($imageData, 'HdrS');
        if ($hdrPos === false) {
            return null;
        }

        $setupOff = $hdrPos - 0x11;
        if ($setupOff < 0) {
            return null;
        }

        if ($this->u16le($imageData, $setupOff + 0x0d) !== 0xAA55) {
            return null;
        }

        $setupSects = ord($imageData[$setupOff] ?? "\x00");
        $kernelOffset = ($setupSects + 1) * 512;
        if ($kernelOffset <= 0 || $kernelOffset >= strlen($imageData)) {
            return null;
        }

        $payloadOffset = $this->u32le($imageData, $setupOff + 0x57);
        $payloadLength = $this->u32le($imageData, $setupOff + 0x5B);
        $handoverOffset = $this->u32le($imageData, $setupOff + 0x73);
        $prefAddress = $this->u64le($imageData, $setupOff + 0x67);
        $initSize = $this->u32le($imageData, $setupOff + 0x6F);

        $payloadFileOffset = $kernelOffset + $payloadOffset;
        $imageSize = strlen($imageData);
        if ($payloadFileOffset < 0 || $payloadFileOffset >= $imageSize) {
            return null;
        }

        if ($payloadLength <= 0 || ($payloadFileOffset + $payloadLength) > $imageSize) {
            $payloadLength = $imageSize - $payloadFileOffset;
        }

        if ($payloadLength <= 0) {
            return null;
        }

        $payload = substr($imageData, $payloadFileOffset, $payloadLength);
        if (substr($payload, 0, 2) !== "\x1f\x8b") {
            return null;
        }

        return [
            'payload' => $payload,
            'payload_length' => $payloadLength,
            'payload_offset' => $payloadOffset,
            'kernel_offset' => $kernelOffset,
            'handover_offset' => $handoverOffset,
            'pref_address' => $prefAddress,
            'init_size' => $initSize,
        ];
    }

    private function parseElfImage(string $data): ?array
    {
        if (strlen($data) < 0x34) {
            return null;
        }

        if (substr($data, 0, 4) !== "\x7fELF") {
            return null;
        }

        $class = ord($data[4]);
        $dataEncoding = ord($data[5]);
        if ($dataEncoding !== 1) {
            return null;
        }

        $segments = [];

        if ($class === 2) {
            $entry = $this->u64le($data, 24);
            $phoff = $this->u64le($data, 32);
            $phentsize = $this->u16le($data, 54);
            $phnum = $this->u16le($data, 56);

            if ($phoff <= 0 || $phentsize <= 0 || $phnum <= 0) {
                return null;
            }

            for ($i = 0; $i < $phnum; $i++) {
                $off = $phoff + ($i * $phentsize);
                if ($off + 56 > strlen($data)) {
                    break;
                }
                $pType = $this->u32le($data, $off);
                if ($pType !== 1) {
                    continue;
                }
                $pOffset = $this->u64le($data, $off + 8);
                $pVaddr = $this->u64le($data, $off + 16);
                $pPaddr = $this->u64le($data, $off + 24);
                $pFilesz = $this->u64le($data, $off + 32);
                $pMemsz = $this->u64le($data, $off + 40);

                if ($pFilesz <= 0 || $pMemsz <= 0) {
                    continue;
                }

                $segments[] = [
                    'offset' => $pOffset,
                    'vaddr' => $pVaddr,
                    'paddr' => $pPaddr,
                    'filesz' => $pFilesz,
                    'memsz' => $pMemsz,
                ];
            }

            return [
                'bits' => 64,
                'entry' => $entry,
                'segments' => $segments,
            ];
        }

        if ($class === 1) {
            $entry = $this->u32le($data, 24);
            $phoff = $this->u32le($data, 28);
            $phentsize = $this->u16le($data, 42);
            $phnum = $this->u16le($data, 44);

            if ($phoff <= 0 || $phentsize <= 0 || $phnum <= 0) {
                return null;
            }

            for ($i = 0; $i < $phnum; $i++) {
                $off = $phoff + ($i * $phentsize);
                if ($off + 32 > strlen($data)) {
                    break;
                }
                $pType = $this->u32le($data, $off);
                if ($pType !== 1) {
                    continue;
                }
                $pOffset = $this->u32le($data, $off + 4);
                $pVaddr = $this->u32le($data, $off + 8);
                $pPaddr = $this->u32le($data, $off + 12);
                $pFilesz = $this->u32le($data, $off + 16);
                $pMemsz = $this->u32le($data, $off + 20);

                if ($pFilesz <= 0 || $pMemsz <= 0) {
                    continue;
                }

                $segments[] = [
                    'offset' => $pOffset,
                    'vaddr' => $pVaddr,
                    'paddr' => $pPaddr,
                    'filesz' => $pFilesz,
                    'memsz' => $pMemsz,
                ];
            }

            return [
                'bits' => 32,
                'entry' => $entry,
                'segments' => $segments,
            ];
        }

        return null;
    }

    private function loadElfSegments(RuntimeInterface $runtime, string $data, array $elf): void
    {
        foreach ($elf['segments'] as $seg) {
            $offset = (int) $seg['offset'];
            $filesz = (int) $seg['filesz'];
            $memsz = (int) $seg['memsz'];
            $paddr = (int) ($seg['paddr'] ?: $seg['vaddr']);

            if ($filesz <= 0 || $memsz <= 0 || $paddr <= 0) {
                continue;
            }

            if ($offset < 0 || ($offset + $filesz) > strlen($data)) {
                continue;
            }

            $chunk = substr($data, $offset, $filesz);
            if ($chunk !== '') {
                $this->writeMemoryBulk($runtime, $paddr, $chunk);
            }

            if ($memsz > $filesz) {
                $this->zeroMemoryBulk($runtime, $paddr + $filesz, $memsz - $filesz);
            }
        }
    }

    private function writeMemoryBulk(RuntimeInterface $runtime, int $address, string $data): void
    {
        if ($data === '') {
            return;
        }

        $memory = $runtime->memory();
        if ($memory instanceof PagedMemoryStream && !$runtime->context()->cpu()->isPagingEnabled()) {
            $memory->physicalStream()->copyFromString($data, $address);
            return;
        }

        $memory->copyFromString($data, $address);
    }

    private function zeroMemoryBulk(RuntimeInterface $runtime, int $address, int $size): void
    {
        if ($size <= 0) {
            return;
        }

        $chunk = str_repeat("\x00", min($size, 0x4000));
        $written = 0;
        while ($written < $size) {
            $len = min(strlen($chunk), $size - $written);
            $this->writeMemoryBulk($runtime, $address + $written, substr($chunk, 0, $len));
            $written += $len;
        }
    }

    private function fastKernelJumpToEntry(
        RuntimeInterface $runtime,
        int $handle,
        array $kernel,
        array $elf,
    ): bool {
        $bits = (int) ($elf['bits'] ?? 0);
        if ($bits != 32) {
            return false;
        }

        $entry = (int) ($elf['entry'] ?? 0);
        if ($entry <= 0) {
            return false;
        }

        $ma = $runtime->memoryAccessor();
        $bootParams = $this->resolveBootParams($runtime, $kernel);
        if ($bootParams === null) {
            if ($this->linuxKernelFastBootLogCount < 20) {
                $currentEsi = $ma->fetch(RegisterType::ESI)->asBytesBySize(32);
                $runtime->option()->logger()->warning(sprintf(
                    'FAST_KERNEL_BOOT_PARAMS_MISS: entry=0x%08X esi=0x%08X',
                    $entry & 0xFFFFFFFF,
                    $currentEsi & 0xFFFFFFFF,
                ));
                $this->linuxKernelFastBootLogCount++;
            }
            return false;
        }

        $this->linuxKernelImages[$handle]['boot_params'] = $bootParams;

        $this->setupFlatGdt32($runtime);
        $ma->writeBySize(RegisterType::EAX, 0, 32);
        $ma->writeBySize(RegisterType::ESI, $bootParams, 32);
        $runtime->memory()->setOffset($entry);

        $this->linuxKernelImages[$handle]['fast_done'] = true;
        $this->linuxKernelImages[$handle]['fast_jump_done'] = true;
        $this->linuxKernelImages[$handle]['elf_entry'] = $entry;
        $this->linuxKernelImages[$handle]['elf_bits'] = $bits;

        if ($this->linuxKernelFastBootLogCount < 20) {
            $runtime->option()->logger()->warning(sprintf(
                'FAST_KERNEL_ENTRY: entry=0x%08X boot_params=0x%08X',
                $entry & 0xFFFFFFFF,
                $bootParams & 0xFFFFFFFF,
            ));
            $this->linuxKernelFastBootLogCount++;
        }

        return true;
    }

    private function setupFlatGdt32(RuntimeInterface $runtime): void
    {
        $cpu = $runtime->context()->cpu();
        $cpu->setLongMode(false);
        $cpu->setCompatibilityMode(false);
        $cpu->setProtectedMode(true);
        $cpu->setPagingEnabled(false);
        $cpu->setUserMode(false);
        $cpu->setCpl(0);
        $cpu->setDefaultOperandSize(32);
        $cpu->setDefaultAddressSize(32);

        $ma = $runtime->memoryAccessor();
        $cr0 = $ma->readControlRegister(0);
        $cr4 = $ma->readControlRegister(4);
        $ma->writeControlRegister(4, $cr4 & ~(1 << 5));
        $ma->writeControlRegister(0, ($cr0 | 0x1) & ~0x80000000);
        $efer = $ma->readEfer();
        $ma->writeEfer($efer & ~((1 << 8) | (1 << 10)));
        $ma->setInterruptFlag(false);

        $base = $this->findFreeRange(0x1000, null, 0x1000) ?? 0x00080000;
        $ma->allocate($base, 0x1000, safe: false);
        $ma->writePhysical64($base, 0x0000000000000000);
        $ma->writePhysical64($base + 8, 0x0000000000000000);
        $ma->writePhysical64($base + 16, 0x00CF9A000000FFFF);
        $ma->writePhysical64($base + 24, 0x00CF92000000FFFF);

        $cpu->setGdtr($base, 32 - 1);

        $ma->write16Bit(RegisterType::CS, 0x10);
        $ma->write16Bit(RegisterType::DS, 0x18);
        $ma->write16Bit(RegisterType::ES, 0x18);
        $ma->write16Bit(RegisterType::SS, 0x18);
        $ma->write16Bit(RegisterType::FS, 0x18);
        $ma->write16Bit(RegisterType::GS, 0x18);

        $cpu->cacheSegmentDescriptor(RegisterType::CS, [
            'base' => 0,
            'limit' => 0xFFFFFFFF,
            'present' => true,
            'type' => 0x0A,
            'system' => false,
            'executable' => true,
            'dpl' => 0,
            'default' => 32,
        ]);

        if ($this->linuxKernelGdtLogCount < 5) {
            $descLow = $ma->readPhysical32($base + 8);
            $descHigh = $ma->readPhysical32($base + 12);
            $cached = $cpu->getCachedSegmentDescriptor(RegisterType::CS);
            $cachedPresent = $cached['present'] ?? null;
            $runtime->option()->logger()->warning(sprintf(
                'FAST_KERNEL_GDT: base=0x%08X limit=0x%X descLow=0x%08X descHigh=0x%08X cachedPresent=%s',
                $base & 0xFFFFFFFF,
                24 - 1,
                $descLow & 0xFFFFFFFF,
                $descHigh & 0xFFFFFFFF,
                $cachedPresent === null ? 'null' : ($cachedPresent ? '1' : '0'),
            ));
            $this->linuxKernelGdtLogCount++;
        }
    }

    private function findKernelByIp(int $ip): array
    {
        foreach ($this->linuxKernelImages as $handle => $info) {
            $base = (int) ($info['base'] ?? 0);
            $size = (int) ($info['size'] ?? 0);
            if ($base > 0 && $size > 0 && $ip >= $base && $ip < ($base + $size)) {
                return [$handle, $info];
            }
        }

        return [null, null];
    }

    private function unwindKernelProlog(RuntimeInterface $runtime, array $kernel, array $prolog): ?array
    {
        $cpu = $runtime->context()->cpu();
        $addrSize = $cpu->addressSize();
        $ptrSize = $addrSize === 64 ? 8 : 4;

        $ma = $runtime->memoryAccessor();
        $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($ptrSize * 8);

        $locals = (int) $prolog['locals'];
        $pushCount = count($prolog['pushes']);
        $frameSize = $locals + ($pushCount * $ptrSize);

        $base = (int) ($kernel['base'] ?? 0);
        $limit = $base + (int) ($kernel['size'] ?? 0);
        if ($base <= 0 || $limit <= $base) {
            return null;
        }

        $payloadStart = 0;
        $payloadEnd = 0;
        $payloadOffset = (int) ($kernel['payload_offset'] ?? 0);
        $kernelOffset = (int) ($kernel['kernel_offset'] ?? 0);
        $payloadLength = (int) ($kernel['payload_length'] ?? 0);
        if ($payloadOffset > 0 && $payloadLength > 0) {
            $payloadStart = $base + $kernelOffset + $payloadOffset;
            $payloadEnd = $payloadStart + $payloadLength;
        }

        $entry = (int) ($prolog['entry'] ?? 0);
        $scanMax = $sp + 0x400;
        $fallback = null;

        for ($addr = $sp; $addr <= $scanMax; $addr += $ptrSize) {
            $candidate = $this->readPointer($runtime, $addr, $ptrSize);
            if ($candidate < $base || $candidate >= $limit) {
                continue;
            }
            $frameBase = $addr - $frameSize;
            if ($frameBase < $sp) {
                continue;
            }

            if ($payloadStart > 0 && $payloadEnd > $payloadStart) {
                if ($candidate >= $payloadStart && $candidate < $payloadEnd) {
                    continue;
                }
            }

            if ($entry > 0) {
                $callSite = $candidate - 5;
                if ($callSite > 0 && $this->readByte($runtime, $callSite) === 0xE8) {
                    $disp = $this->readSigned32($runtime, $callSite + 1);
                    $target = ($candidate + $disp) & 0xFFFFFFFF;
                    if ($target === ($entry & 0xFFFFFFFF)) {
                        return [
                            'ptr_size' => $ptrSize,
                            'return_addr' => $candidate,
                            'return_slot' => $addr,
                            'frame_base' => $frameBase,
                        ];
                    }
                }
            }

            if ($fallback === null) {
                $fallback = [
                    'ptr_size' => $ptrSize,
                    'return_addr' => $candidate,
                    'return_slot' => $addr,
                    'frame_base' => $frameBase,
                ];
            }
        }

        return $fallback;
    }

    private function findX86Prolog(RuntimeInterface $runtime, int $ip): ?array
    {
        $maxBack = 0x200;
        if ($ip <= 0) {
            return null;
        }

        $start = $ip > $maxBack ? $ip - $maxBack : 0;
        $length = $ip - $start;
        if ($length <= 0) {
            return null;
        }

        $memory = $runtime->memory();
        $saved = $memory->offset();
        $memory->setOffset($start);
        $data = $memory->read($length);
        $memory->setOffset($saved);

        if ($data === '') {
            return null;
        }

        for ($i = strlen($data) - 1; $i >= 0; $i--) {
            if (ord($data[$i]) !== 0x55) {
                continue;
            }
            $parsed = $this->parseX86Prolog($data, $i);
            if ($parsed !== null) {
                $parsed['entry'] = $start + $i;
                return $parsed;
            }
        }

        return null;
    }

    private function parseX86Prolog(string $data, int $offset): ?array
    {
        $len = strlen($data);
        $pos = $offset;
        $pushes = [];
        $locals = null;

        while ($pos < $len) {
            $byte = ord($data[$pos]);

            if ($byte >= 0x50 && $byte <= 0x57) {
                $reg = $this->registerFromPush($byte);
                if ($reg === null) {
                    return null;
                }
                $pushes[] = $reg;
                $pos++;
                continue;
            }

            if ($byte === 0x89 && ($pos + 1) < $len && ord($data[$pos + 1]) === 0xE5) {
                $pos += 2;
                continue;
            }

            if ($byte === 0x83 && ($pos + 2) < $len && ord($data[$pos + 1]) === 0xEC) {
                $locals = ord($data[$pos + 2]);
                $pos += 3;
                break;
            }

            if ($byte === 0x81 && ($pos + 5) < $len && ord($data[$pos + 1]) === 0xEC) {
                $locals = $this->u32le($data, $pos + 2);
                $pos += 6;
                break;
            }

            break;
        }

        if ($locals === null || $pushes === []) {
            return null;
        }

        return [
            'pushes' => $pushes,
            'locals' => $locals,
        ];
    }

    private function registerFromPush(int $opcode): ?RegisterType
    {
        return match ($opcode & 0x7) {
            0 => RegisterType::EAX,
            1 => RegisterType::ECX,
            2 => RegisterType::EDX,
            3 => RegisterType::EBX,
            4 => null,
            5 => RegisterType::EBP,
            6 => RegisterType::ESI,
            7 => RegisterType::EDI,
            default => null,
        };
    }

    private function readPointer(RuntimeInterface $runtime, int $address, int $size): int
    {
        $memory = $runtime->memory();
        $saved = $memory->offset();
        $memory->setOffset($address);
        if ($size === 8) {
            $lo = $memory->dword() & 0xFFFFFFFF;
            $hi = $memory->dword() & 0xFFFFFFFF;
            $value = ($hi << 32) | $lo;
        } else {
            $value = $memory->dword() & 0xFFFFFFFF;
        }
        $memory->setOffset($saved);
        return $value;
    }

    private function readPhysicalPointer(RuntimeInterface $runtime, int $address, int $size): int
    {
        $ma = $runtime->memoryAccessor();
        if ($size === 8) {
            return $ma->readPhysical64($address);
        }
        return $ma->readPhysical32($address);
    }

    private function readByte(RuntimeInterface $runtime, int $address): int
    {
        $memory = $runtime->memory();
        $saved = $memory->offset();
        $memory->setOffset($address);
        $value = $memory->byte() & 0xFF;
        $memory->setOffset($saved);
        return $value;
    }

    private function readPhysicalByte(RuntimeInterface $runtime, int $address): int
    {
        return $runtime->memoryAccessor()->readPhysical8($address) & 0xFF;
    }

    private function readWord(RuntimeInterface $runtime, int $address): int
    {
        $lo = $this->readByte($runtime, $address);
        $hi = $this->readByte($runtime, $address + 1);
        return ($lo | ($hi << 8)) & 0xFFFF;
    }

    private function readPhysicalWord(RuntimeInterface $runtime, int $address): int
    {
        $lo = $this->readPhysicalByte($runtime, $address);
        $hi = $this->readPhysicalByte($runtime, $address + 1);
        return ($lo | ($hi << 8)) & 0xFFFF;
    }

    private function readSigned32(RuntimeInterface $runtime, int $address): int
    {
        $value = $this->readPointer($runtime, $address, 4);
        if ($value & 0x80000000) {
            $value -= 0x100000000;
        }
        return $value;
    }

    private function readBytes(RuntimeInterface $runtime, int $address, int $length): string
    {
        $memory = $runtime->memory();
        $saved = $memory->offset();
        $memory->setOffset($address);
        $data = $memory->read($length);
        $memory->setOffset($saved);
        return $data;
    }

    private function readPhysicalBytes(RuntimeInterface $runtime, int $address, int $length): string
    {
        $data = '';
        $ma = $runtime->memoryAccessor();
        for ($i = 0; $i < $length; $i++) {
            $data .= chr($ma->readPhysical8($address + $i) & 0xFF);
        }
        return $data;
    }

    private function isValidBootParams(RuntimeInterface $runtime, int $address): bool
    {
        if ($address <= 0) {
            return false;
        }
        if ($this->readPhysicalWord($runtime, $address + 0x1fe) !== 0xAA55) {
            return false;
        }
        if ($this->readPhysicalBytes($runtime, $address + 0x202, 4) !== 'HdrS') {
            return false;
        }
        $version = $this->readPhysicalWord($runtime, $address + 0x206);
        return $version >= 0x0200;
    }

    private function normalizeBootParamsCandidate(RuntimeInterface $runtime, int $candidate): ?int
    {
        if ($candidate <= 0) {
            return null;
        }

        $normalized = null;
        if ($this->isValidBootParams($runtime, $candidate)) {
            $normalized = $candidate;
        } elseif ($this->readPhysicalBytes($runtime, $candidate, 4) === 'HdrS') {
            $base = $candidate - 0x202;
            if ($this->isValidBootParams($runtime, $base)) {
                $normalized = $base;
            }
        } elseif ($this->readPhysicalBytes($runtime, $candidate + 0x11, 4) === 'HdrS') {
            $base = $candidate - 0x1f1;
            if ($this->isValidBootParams($runtime, $base)) {
                $normalized = $base;
            }
        }

        if ($normalized === null) {
            return null;
        }

        if ($this->bootParamsScore($runtime, $normalized) <= 0) {
            return null;
        }

        return $normalized;
    }

    private function isLikelyCmdline(RuntimeInterface $runtime, int $address): bool
    {
        if ($address <= 0) {
            return false;
        }
        $data = $this->readPhysicalBytes($runtime, $address, 256);
        $pos = strpos($data, "\0");
        if ($pos === false) {
            return false;
        }
        $line = substr($data, 0, $pos);
        if ($line === '') {
            return false;
        }
        if (preg_match('/[\0--]/', $line)) {
            return false;
        }
        return true;
    }

    private function bootParamsScore(RuntimeInterface $runtime, int $address): int
    {
        $score = 0;
        $cmdLinePtr = $this->readPhysicalPointer($runtime, $address + 0x228, 4);
        if ($cmdLinePtr > 0 && $this->isLikelyCmdline($runtime, $cmdLinePtr)) {
            $score += 3;
        }
        $ramdiskImage = $this->readPhysicalPointer($runtime, $address + 0x218, 4);
        $ramdiskSize = $this->readPhysicalPointer($runtime, $address + 0x21c, 4);
        if ($ramdiskImage > 0 && $ramdiskSize > 0) {
            $score += 2;
        }
        $loader = $this->readPhysicalByte($runtime, $address + 0x210);
        if ($loader !== 0) {
            $score += 1;
        }
        return $score;
    }

    private function isAddressInKernel(int $address, array $kernel): bool
    {
        $base = (int) ($kernel['base'] ?? 0);
        $size = (int) ($kernel['size'] ?? 0);
        return $base > 0 && $size > 0 && $address >= $base && $address < ($base + $size);
    }

    private function bootParamsFromRegister(RuntimeInterface $runtime, array $kernel): ?int
    {
        $cpu = $runtime->context()->cpu();
        $ptrSize = $cpu->addressSize() === 64 ? 64 : 32;
        $reg = $ptrSize === 64 ? RegisterType::RSI : RegisterType::ESI;
        $candidate = $runtime->memoryAccessor()->fetch($reg)->asBytesBySize($ptrSize);
        if ($candidate <= 0) {
            return null;
        }
        $normalized = $this->normalizeBootParamsCandidate($runtime, $candidate);
        if ($normalized === null) {
            return null;
        }
        if ($this->isAddressInKernel($normalized, $kernel)) {
            return null;
        }
        return $normalized;
    }

    private function bootParamsFromStack(RuntimeInterface $runtime, array $kernel): ?int
    {
        $cpu = $runtime->context()->cpu();
        $ptrSize = $cpu->addressSize() === 64 ? 8 : 4;
        $sp = $runtime->memoryAccessor()->fetch(RegisterType::ESP)->asBytesBySize($ptrSize * 8);
        if ($sp <= 0) {
            return null;
        }
        $scanMax = $sp + 0x400;
        $allocBase = $this->allocator->base();
        $allocEnd = $this->allocator->cursor();
        for ($addr = $sp; $addr < $scanMax; $addr += $ptrSize) {
            $candidate = $this->readPointer($runtime, $addr, $ptrSize);
            if ($candidate <= 0) {
                continue;
            }
            $normalized = $this->normalizeBootParamsCandidate($runtime, $candidate);
            if ($normalized !== null && !$this->isAddressInKernel($normalized, $kernel)) {
                return $normalized;
            }
            if ($candidate >= $allocBase && $candidate < $allocEnd) {
                $scanStart = max($allocBase, $candidate - 0x2000);
                $scanEnd = min($allocEnd, $candidate + 0x4000);
                $near = $this->scanBootParamsRange($runtime, $kernel, $scanStart, $scanEnd);
                if ($near !== null) {
                    return $near;
                }
            }
        }
        return null;
    }

    private function resolveBootParams(RuntimeInterface $runtime, array $kernel): ?int
    {
        $bootParams = $kernel['boot_params'] ?? null;
        if ($bootParams !== null && $bootParams > 0) {
            $bootParams = $this->normalizeBootParamsCandidate($runtime, $bootParams);
            if ($bootParams === null || $this->isAddressInKernel($bootParams, $kernel)) {
                $bootParams = null;
            }
        }
        if ($bootParams !== null && $bootParams > 0) {
            return $bootParams;
        }
        $candidate = $this->bootParamsFromRegister($runtime, $kernel);
        if ($candidate !== null) {
            return $candidate;
        }
        $candidate = $this->bootParamsFromStack($runtime, $kernel);
        if ($candidate !== null) {
            return $candidate;
        }
        return $this->findBootParams($runtime, $kernel);
    }

    private function maybeCaptureBootParams(RuntimeInterface $runtime, int $handle, array &$kernel): void
    {
        $bootParams = $kernel['boot_params'] ?? null;
        if ($bootParams !== null && $bootParams > 0) {
            $normalized = $this->normalizeBootParamsCandidate($runtime, $bootParams);
            if ($normalized !== null && !$this->isAddressInKernel($normalized, $kernel)) {
                $this->linuxKernelImages[$handle]['boot_params'] = $normalized;
                $kernel['boot_params'] = $normalized;
                return;
            }
            unset($this->linuxKernelImages[$handle]['boot_params']);
            $kernel['boot_params'] = null;
        }

        $bootParams = $this->bootParamsFromRegister($runtime, $kernel);
        $source = 'reg';
        if ($bootParams === null) {
            $bootParams = $this->bootParamsFromStack($runtime, $kernel);
            $source = 'stack';
        }
        if ($bootParams === null) {
            return;
        }

        $this->linuxKernelImages[$handle]['boot_params'] = $bootParams;
        $kernel['boot_params'] = $bootParams;
        if ($this->linuxKernelFastBootLogCount < 20) {
            $runtime->option()->logger()->warning(sprintf(
                'FAST_KERNEL_BOOT_PARAMS: handle=0x%08X params=0x%08X source=%s',
                $handle & 0xFFFFFFFF,
                $bootParams & 0xFFFFFFFF,
                $source,
            ));
            $this->linuxKernelFastBootLogCount++;
        }
    }

    private function findBootParams(RuntimeInterface $runtime, array $kernel = [], int $minScore = 1): ?int
    {
        $memory = $runtime->memory();
        $scanMemory = $memory instanceof PagedMemoryStream ? $memory->physicalStream() : $memory;
        $saved = $scanMemory->offset();
        $best = null;
        $bestScore = -1;

        $maxMemory = $runtime->logicBoard()->memory()->maxMemory();
        if ($maxMemory <= 0) {
            $scanMemory->setOffset($saved);
            return null;
        }

        $chunk = 0x10000;
        $ranges = [];
        $ranges[] = [0, min($maxMemory, 0x2000000)];

        $allocBase = $this->allocator->base();
        $allocEnd = min($maxMemory, $this->allocator->cursor());
        if ($allocEnd > $allocBase) {
            $ranges[] = [$allocBase, $allocEnd];
        }

        foreach ($this->pageAllocations as $alloc) {
            $type = (int) ($alloc['type'] ?? 0);
            if (
                $type !== self::EFI_MEMORY_TYPE_BOOT_SERVICES_DATA
                && $type !== self::EFI_MEMORY_TYPE_BOOT_SERVICES_CODE
                && $type !== self::EFI_MEMORY_TYPE_LOADER_CODE
                && $type !== self::EFI_MEMORY_TYPE_LOADER_DATA
            ) {
                continue;
            }
            $start = (int) ($alloc['start'] ?? 0);
            $end = (int) ($alloc['end'] ?? 0);
            if ($end <= $start) {
                continue;
            }
            if ($start < 0) {
                $start = 0;
            }
            if ($end > $maxMemory) {
                $end = $maxMemory;
            }
            if ($end > $start) {
                $ranges[] = [$start, $end];
            }
        }

        foreach ($ranges as $range) {
            [$rangeStart, $rangeEnd] = $range;
            if ($rangeEnd <= $rangeStart) {
                continue;
            }
            for ($base = $rangeStart; $base < $rangeEnd; $base += $chunk) {
                $scanMemory->setOffset($base);
                $data = $scanMemory->read($chunk);
                $pos = strpos($data, 'HdrS');
                while ($pos !== false) {
                    $hdr = $base + $pos;
                    $bootParams = $hdr - 0x202;
                    if ($bootParams >= 0) {
                        $bootFlag = $this->readPhysicalWord($runtime, $bootParams + 0x1fe);
                        if ($bootFlag === 0xAA55) {
                            if (!$this->isAddressInKernel($bootParams, $kernel)) {
                                $score = $this->bootParamsScore($runtime, $bootParams);
                                if ($score > $bestScore) {
                                    $bestScore = $score;
                                    $best = $bootParams;
                                    if ($score >= 3) {
                                        $scanMemory->setOffset($saved);
                                        if ($this->linuxKernelFastBootLogCount < 20) {
                                            $cmdLinePtr = $this->readPhysicalPointer($runtime, $bootParams + 0x228, 4);
                                            $ramdiskImage = $this->readPhysicalPointer($runtime, $bootParams + 0x218, 4);
                                            $ramdiskSize = $this->readPhysicalPointer($runtime, $bootParams + 0x21c, 4);
                                            $loader = $this->readPhysicalByte($runtime, $bootParams + 0x210);
                                            $runtime->option()->logger()->warning(sprintf(
                                                'FAST_KERNEL_BOOT_PARAMS_SCAN: addr=0x%08X score=%d cmd=0x%08X ram=0x%08X size=0x%X loader=0x%02X',
                                                $bootParams & 0xFFFFFFFF,
                                                $score,
                                                $cmdLinePtr & 0xFFFFFFFF,
                                                $ramdiskImage & 0xFFFFFFFF,
                                                $ramdiskSize & 0xFFFFFFFF,
                                                $loader & 0xFF,
                                            ));
                                            $this->linuxKernelFastBootLogCount++;
                                        }
                                        return $bootParams;
                                    }
                                }
                            }
                        }
                    }
                    $pos = strpos($data, 'HdrS', $pos + 1);
                }
            }
        }

        $scanMemory->setOffset($saved);
        if ($best !== null && $this->linuxKernelFastBootLogCount < 20) {
            $cmdLinePtr = $this->readPhysicalPointer($runtime, $best + 0x228, 4);
            $ramdiskImage = $this->readPhysicalPointer($runtime, $best + 0x218, 4);
            $ramdiskSize = $this->readPhysicalPointer($runtime, $best + 0x21c, 4);
            $loader = $this->readPhysicalByte($runtime, $best + 0x210);
            $runtime->option()->logger()->warning(sprintf(
                'FAST_KERNEL_BOOT_PARAMS_SCAN: addr=0x%08X score=%d cmd=0x%08X ram=0x%08X size=0x%X loader=0x%02X',
                $best & 0xFFFFFFFF,
                $bestScore,
                $cmdLinePtr & 0xFFFFFFFF,
                $ramdiskImage & 0xFFFFFFFF,
                $ramdiskSize & 0xFFFFFFFF,
                $loader & 0xFF,
            ));
            $this->linuxKernelFastBootLogCount++;
        }
        if ($best === null || $bestScore < $minScore) {
            return null;
        }
        return $best;
    }

    private function scanBootParamsRange(RuntimeInterface $runtime, array $kernel, int $rangeStart, int $rangeEnd): ?int
    {
        $memory = $runtime->memory();
        $scanMemory = $memory instanceof PagedMemoryStream ? $memory->physicalStream() : $memory;
        $saved = $scanMemory->offset();
        $best = null;
        $bestScore = -1;
        $chunk = 0x1000;

        if ($rangeEnd <= $rangeStart) {
            return null;
        }

        for ($base = $rangeStart; $base < $rangeEnd; $base += $chunk) {
            $size = min($chunk, $rangeEnd - $base);
            $scanMemory->setOffset($base);
            $data = $scanMemory->read($size);
            $pos = strpos($data, 'HdrS');
            while ($pos !== false) {
                $hdr = $base + $pos;
                $bootParams = $hdr - 0x202;
                if ($bootParams >= 0) {
                    $bootFlag = $this->readPhysicalWord($runtime, $bootParams + 0x1fe);
                    if ($bootFlag === 0xAA55 && !$this->isAddressInKernel($bootParams, $kernel)) {
                        $score = $this->bootParamsScore($runtime, $bootParams);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = $bootParams;
                            if ($score >= 3) {
                                $scanMemory->setOffset($saved);
                                return $bootParams;
                            }
                        }
                    }
                }
                $pos = strpos($data, 'HdrS', $pos + 1);
            }
        }

        $scanMemory->setOffset($saved);
        return $best;
    }

    private function u16le(string $data, int $offset): int
    {
        if ($offset < 0 || ($offset + 2) > strlen($data)) {
            return 0;
        }
        return (ord($data[$offset]) | (ord($data[$offset + 1]) << 8)) & 0xFFFF;
    }

    private function u32le(string $data, int $offset): int
    {
        if ($offset < 0 || ($offset + 4) > strlen($data)) {
            return 0;
        }
        return (
            ord($data[$offset])
            | (ord($data[$offset + 1]) << 8)
            | (ord($data[$offset + 2]) << 16)
            | (ord($data[$offset + 3]) << 24)
        ) & 0xFFFFFFFF;
    }

    private function u64le(string $data, int $offset): int
    {
        $lo = $this->u32le($data, $offset);
        $hi = $this->u32le($data, $offset + 4);
        return ($hi << 32) | $lo;
    }

    private function loadGrubBootEntry(): ?array
    {
        if ($this->grubBootEntry !== null) {
            return $this->grubBootEntry;
        }

        $paths = [
            '/boot/grub/grub.cfg',
            '/grub/grub.cfg',
            '/EFI/BOOT/grub.cfg',
        ];

        foreach ($paths as $path) {
            $data = $this->iso->readFile($path);
            if ($data === null) {
                continue;
            }
            $entry = $this->parseGrubConfig($data);
            if ($entry !== null) {
                $this->grubBootEntry = $entry;
                return $entry;
            }
        }

        $localPath = 'boot/grub/grub.cfg';
        if (is_file($localPath)) {
            $data = file_get_contents($localPath);
            if (is_string($data)) {
                $entry = $this->parseGrubConfig($data);
                if ($entry !== null) {
                    $this->grubBootEntry = $entry;
                    return $entry;
                }
            }
        }

        return null;
    }

    /**
     * @return array{kernel: string, initrd: string|null, cmdline: string}|null
     */
    private function parseGrubConfig(string $data): ?array
    {
        $kernel = null;
        $initrd = null;
        $cmdline = '';

        foreach (
            preg_split('/
?
/', $data) as $line
        ) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if ($kernel === null && preg_match('/^linux\S*\s+(\S+)(?:\s+(.*))?$/i', $line, $match)) {
                $kernel = $match[1] ?? null;
                $cmdline = trim((string) ($match[2] ?? ''));
                continue;
            }
            if ($initrd === null && preg_match('/^initrd\S*\s+(\S+)/i', $line, $match)) {
                $initrd = $match[1] ?? null;
            }
            if ($kernel !== null && $initrd !== null) {
                break;
            }
        }

        if ($kernel === null) {
            return null;
        }

        $kernel = '/' . ltrim($kernel, '/');
        if ($initrd !== null) {
            $initrd = '/' . ltrim($initrd, '/');
        }

        return [
            'kernel' => $kernel,
            'initrd' => $initrd,
            'cmdline' => $cmdline,
        ];
    }

    private function allocateBootData(RuntimeInterface $runtime, int $size, int $align = 0x1000): int
    {
        $bytes = $this->align(max(1, $size), $align);
        $addr = $this->findFreeRange($bytes, null, $align);
        if ($addr === null) {
            throw new \RuntimeException('Boot data allocator out of space');
        }
        $this->zeroMemory($addr, $bytes);
        $this->registerPageAllocation($addr, $bytes, self::EFI_MEMORY_TYPE_LOADER_DATA);
        return $addr;
    }

    private function writePhysicalBulk(RuntimeInterface $runtime, int $address, string $data): void
    {
        if ($data === '') {
            return;
        }

        $memory = $runtime->memory();
        if ($memory instanceof PagedMemoryStream) {
            $memory->physicalStream()->copyFromString($data, $address);
            return;
        }

        $memory->copyFromString($data, $address);
    }

    private function patchBootParamsFromGrub(RuntimeInterface $runtime, int $bootParams, array &$kernel): bool
    {
        $entry = $this->loadGrubBootEntry();
        if ($entry === null) {
            return false;
        }

        $ma = $runtime->memoryAccessor();
        $patched = false;

        $cmdline = trim((string) ($entry['cmdline'] ?? ''));
        if ($cmdline !== '') {
            $cmdlineData = $cmdline . " ";
            $cmdlineAddr = $this->allocateBootData($runtime, strlen($cmdlineData), 16);
            $this->writePhysicalBulk($runtime, $cmdlineAddr, $cmdlineData);
            $ma->writePhysical32($bootParams + 0x228, $cmdlineAddr);
            $kernel['cmdline_addr'] = $cmdlineAddr;
            $kernel['cmdline_size'] = strlen($cmdlineData);
            $patched = true;
        }

        $initrdPath = $entry['initrd'] ?? null;
        if (is_string($initrdPath) && $initrdPath !== '') {
            $initrd = $this->iso->readFile($initrdPath);
            if (is_string($initrd) && $initrd !== '') {
                $initrdAddr = $this->allocateBootData($runtime, strlen($initrd), 0x1000);
                $this->writePhysicalBulk($runtime, $initrdAddr, $initrd);
                $ma->writePhysical32($bootParams + 0x218, $initrdAddr);
                $ma->writePhysical32($bootParams + 0x21C, strlen($initrd));
                $kernel['initrd_addr'] = $initrdAddr;
                $kernel['initrd_size'] = strlen($initrd);
                $patched = true;
            }
        }

        if ($patched) {
            $this->writePhysicalBulk($runtime, $bootParams + 0x210, chr(0xFF));
        }

        if ($patched && $this->linuxKernelFastBootLogCount < 20) {
            $cmdPtr = $ma->readPhysical32($bootParams + 0x228) & 0xFFFFFFFF;
            $ramdiskAddr = $ma->readPhysical32($bootParams + 0x218) & 0xFFFFFFFF;
            $ramdiskSize = $ma->readPhysical32($bootParams + 0x21C) & 0xFFFFFFFF;
            $runtime->option()->logger()->warning(sprintf(
                'FAST_KERNEL_BOOT_PARAMS_PATCH: addr=0x%08X cmd=0x%08X ram=0x%08X size=0x%X',
                $bootParams & 0xFFFFFFFF,
                $cmdPtr,
                $ramdiskAddr,
                $ramdiskSize,
            ));
            $this->linuxKernelFastBootLogCount++;
        }

        return $patched;
    }

    private function logKernelFastBoot(RuntimeInterface $runtime, string $message, array $kernel): void
    {
        if ($this->linuxKernelFastBootLogCount >= 20) {
            return;
        }

        $runtime->option()->logger()->warning(sprintf(
            'FAST_KERNEL_DECOMPRESS: %s path=%s base=0x%08X size=0x%X',
            $message,
            $kernel['path'] ?? 'unknown',
            ($kernel['base'] ?? 0) & 0xFFFFFFFF,
            $kernel['size'] ?? 0,
        ));
        $this->linuxKernelFastBootLogCount++;
    }
}
