<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI\UEFIEnvironment;

use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\PagedMemoryStream;

trait UEFIEnvironmentKernelFastBoot
{
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
                    if ($fastReturn > 0 && is_array($fastProlog)) {
                        $pushes = $fastProlog['pushes'] ?? [];
                        $locals = (int) ($fastProlog['locals'] ?? 0);
                        if ($pushes !== [] || $locals > 0) {
                            $frameSize = $locals + (count($pushes) * $ptrSize);
                            $sp = $ma->fetch(RegisterType::ESP)->asBytesBySize($ptrSize * 8);
                            $scanMax = $sp + 0x800;
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

        if ($this->fastKernelJumpToEntry($runtime, $handle, $kernel, $elf)) {
            return true;
        }

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
        $this->linuxKernelImages[$handle]['fast_jump_done'] = true;
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
        if (!$this->isLikelyLinuxKernelPath($path)) {
            return;
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
        $bootParams = $kernel['boot_params'] ?? null;
        if ($bootParams === null || $bootParams <= 0) {
            $bootParams = $ma->fetch(RegisterType::ESI)->asBytesBySize(32);
        }
        if ($bootParams === null || $bootParams <= 0) {
            $bootParams = $this->findBootParams($runtime);
        }
        if ($bootParams !== null && $bootParams > 0) {
            $this->linuxKernelImages[$handle]['boot_params'] = $bootParams;
        }

        $this->setupFlatGdt32($runtime);
        $ma->writeBySize(RegisterType::EAX, 0, 32);
        if ($bootParams !== null) {
            $ma->writeBySize(RegisterType::ESI, $bootParams, 32);
        }
        $runtime->memory()->setOffset($entry);

        $this->linuxKernelImages[$handle]['fast_done'] = true;
        $this->linuxKernelImages[$handle]['fast_jump_done'] = true;
        $this->linuxKernelImages[$handle]['elf_entry'] = $entry;
        $this->linuxKernelImages[$handle]['elf_bits'] = $bits;

        if ($this->linuxKernelFastBootLogCount < 20) {
            $runtime->option()->logger()->warning(sprintf(
                'FAST_KERNEL_ENTRY: entry=0x%08X boot_params=0x%08X',
                $entry & 0xFFFFFFFF,
                ($bootParams ?? 0) & 0xFFFFFFFF,
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

    private function readByte(RuntimeInterface $runtime, int $address): int
    {
        $memory = $runtime->memory();
        $saved = $memory->offset();
        $memory->setOffset($address);
        $value = $memory->byte() & 0xFF;
        $memory->setOffset($saved);
        return $value;
    }

    private function readWord(RuntimeInterface $runtime, int $address): int
    {
        $lo = $this->readByte($runtime, $address);
        $hi = $this->readByte($runtime, $address + 1);
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

    private function findBootParams(RuntimeInterface $runtime): ?int
    {
        $memory = $runtime->memory();
        $saved = $memory->offset();

        $max = 0x2000000;
        $chunk = 0x10000;
        for ($base = 0; $base < $max; $base += $chunk) {
            $memory->setOffset($base);
            $data = $memory->read($chunk);
            $pos = strpos($data, 'HdrS');
            while ($pos !== false) {
                $hdr = $base + $pos;
                $bootParams = $hdr - 0x202;
                if ($bootParams >= 0) {
                    $bootFlag = $this->readWord($runtime, $bootParams + 0x1fe);
                    if ($bootFlag === 0xAA55) {
                        $memory->setOffset($saved);
                        return $bootParams;
                    }
                }
                $pos = strpos($data, 'HdrS', $pos + 1);
            }
        }

        $memory->setOffset($saved);
        return null;
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
