<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\Stream\ModRegRM;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\SIB;
use PHPMachineEmulator\Instruction\Stream\SIBInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\RustMemoryStream;

/**
 * Memory stream that interprets offsets as linear addresses and performs
 * paging translation on read/write.
 */
final class PagedMemoryStream implements MemoryStreamInterface
{
    private int $offset = 0;
    private ?int $cachedReadPageBase = null;
    private int $cachedReadPhysBase = 0;
    private ?int $cachedWritePageBase = null;
    private int $cachedWritePhysBase = 0;
    private int $cachedMask = 0;
    private bool $cachedPagingEnabled = false;
    private bool $cachedIsUser = false;
    /** @var array<int, SIBInterface> */
    private array $sibCache = [];
    /** @var array<int, ModRegRMInterface> */
    private array $modRegRMCache = [];

    public function __construct(
        private readonly MemoryStreamInterface $physical,
        private readonly RuntimeInterface $runtime,
    ) {
        $this->offset = $physical->offset();
    }

    public function ensureCapacity(int $requiredOffset): bool
    {
        return $this->physical->ensureCapacity($requiredOffset);
    }

    public function size(): int
    {
        return $this->physical->size();
    }

    public function logicalMaxMemorySize(): int
    {
        $cpu = $this->runtime->context()->cpu();
        if ($cpu->isLongMode()) {
            return 0x0001000000000000;
        }
        return $cpu->isA20Enabled() ? 0x100000000 : 0x100000;
    }

    public function physicalMaxMemorySize(): int
    {
        return $this->physical->physicalMaxMemorySize();
    }

    public function swapSize(): int
    {
        return $this->physical->swapSize();
    }

    public function byteAsSIB(): SIBInterface
    {
        return $this->sibFromByte($this->byte());
    }

    public function byteAsModRegRM(): ModRegRMInterface
    {
        return $this->modRegRMFromByte($this->byte());
    }

    public function modRegRM(int $byte): ModRegRMInterface
    {
        return $this->modRegRMFromByte($byte);
    }

    private function sibFromByte(int $byte): SIBInterface
    {
        return $this->sibCache[$byte] ??= new SIB($byte);
    }

    private function modRegRMFromByte(int $byte): ModRegRMInterface
    {
        return $this->modRegRMCache[$byte] ??= new ModRegRM($byte);
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $newOffset): self
    {
        $this->offset = $newOffset;
        return $this;
    }

    public function isEOF(): bool
    {
        return $this->offset >= $this->logicalMaxMemorySize();
    }

    public function char(): string
    {
        return chr($this->byte());
    }

    public function byte(): int
    {
        $value = $this->readLinearByte($this->offset, false);
        $this->offset++;
        return $value;
    }

    public function signedByte(): int
    {
        $byte = $this->byte();
        return $byte > 127 ? $byte - 256 : $byte;
    }

    public function short(): int
    {
        return $this->byte() | ($this->byte() << 8);
    }

    public function signedShort(): int
    {
        $value = $this->short();
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function dword(): int
    {
        return $this->byte()
            | ($this->byte() << 8)
            | ($this->byte() << 16)
            | ($this->byte() << 24);
    }

    public function signedDword(): int
    {
        $value = $this->dword();
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $this->char();
        }
        return $out;
    }

    public function write(string $value): self
    {
        $len = strlen($value);
        for ($i = 0; $i < $len; $i++) {
            $this->writeByte(ord($value[$i]));
        }
        return $this;
    }

    /**
     * Fast bulk copy from a PHP string into linear memory.
     *
     * Uses the underlying physical stream directly when paging is disabled.
     */
    public function copyFromString(string $data, int $destOffset): void
    {
        $len = strlen($data);
        if ($len === 0) {
            return;
        }

        $cpu = $this->runtime->context()->cpu();
        if (!$cpu->isPagingEnabled() && $this->physical instanceof RustMemoryStream) {
            $linear = $destOffset & $this->linearMask();
            $this->physical->copyFromString($data, $linear);
            return;
        }

        for ($i = 0; $i < $len; $i++) {
            $this->writeLinearByte($destOffset + $i, ord($data[$i]));
        }
    }

    public function writeByte(int $value): void
    {
        $this->writeLinearByte($this->offset, $value & 0xFF);
        $this->offset++;
    }

    public function writeShort(int $value): void
    {
        $this->writeByte($value & 0xFF);
        $this->writeByte(($value >> 8) & 0xFF);
    }

    public function writeDword(int $value): void
    {
        $this->writeByte($value & 0xFF);
        $this->writeByte(($value >> 8) & 0xFF);
        $this->writeByte(($value >> 16) & 0xFF);
        $this->writeByte(($value >> 24) & 0xFF);
    }

    public function proxy(): StreamProxyInterface
    {
        return new StreamProxy($this);
    }

    public function physicalStream(): MemoryStreamInterface
    {
        return $this->physical;
    }

    public function copy(StreamReaderInterface $source, int $sourceOffset, int $destOffset, int $size): void
    {
        if ($size <= 0) {
            return;
        }

        $originalSourceOffset = $source->offset();
        $originalDestOffset = $this->offset;

        $chunkSize = 0x4000;
        $overlap = $source === $this
            && $destOffset > $sourceOffset
            && $destOffset < ($sourceOffset + $size);

        if ($overlap) {
            $remaining = $size;
            while ($remaining > 0) {
                $chunk = $remaining > $chunkSize ? $chunkSize : $remaining;
                $remaining -= $chunk;
                $source->setOffset($sourceOffset + $remaining);
                $data = $source->read($chunk);
                $this->setOffset($destOffset + $remaining);
                $this->write($data);
            }
        } else {
            $offset = 0;
            while ($offset < $size) {
                $chunk = min($chunkSize, $size - $offset);
                $source->setOffset($sourceOffset + $offset);
                $data = $source->read($chunk);
                $this->setOffset($destOffset + $offset);
                $this->write($data);
                $offset += $chunk;
            }
        }

        $source->setOffset($originalSourceOffset);
        $this->setOffset($originalDestOffset);
    }

    private function linearMask(): int
    {
        $cpu = $this->runtime->context()->cpu();
        if ($cpu->isLongMode()) {
            return 0x0000FFFFFFFFFFFF;
        }
        return $cpu->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
    }

    private function syncTranslationContext(int $mask, bool $pagingEnabled, bool $isUser): void
    {
        if ($this->cachedMask === $mask
            && $this->cachedPagingEnabled === $pagingEnabled
            && $this->cachedIsUser === $isUser
        ) {
            return;
        }

        $this->cachedMask = $mask;
        $this->cachedPagingEnabled = $pagingEnabled;
        $this->cachedIsUser = $isUser;
        $this->cachedReadPageBase = null;
        $this->cachedWritePageBase = null;
    }

    private function translateLinear(int $linear, bool $isWrite): int
    {
        $cpu = $this->runtime->context()->cpu();
        $mask = $this->linearMask();
        $linearMasked = $linear & $mask;
        $pagingEnabled = $cpu->isPagingEnabled();
        $isUser = $cpu->cpl() === 3;
        $this->syncTranslationContext($mask, $pagingEnabled, $isUser);

        if (!$pagingEnabled) {
            return $linearMasked;
        }

        $pageBase = $linearMasked & ~0xFFF;
        $pageOffset = $linearMasked & 0xFFF;

        if ($isWrite) {
            if ($this->cachedWritePageBase === $pageBase) {
                return $this->cachedWritePhysBase + $pageOffset;
            }
        } else {
            if ($this->cachedReadPageBase === $pageBase) {
                return $this->cachedReadPhysBase + $pageOffset;
            }
            if ($this->cachedWritePageBase === $pageBase) {
                return $this->cachedWritePhysBase + $pageOffset;
            }
        }

        [$physical, $error] = $this->runtime->memoryAccessor()->translateLinear(
            $linearMasked,
            $isWrite,
            $isUser,
            $pagingEnabled,
            $mask,
        );

        if ($error !== 0 && $error !== 0xFFFFFFFF) {
            $this->throwPageFault($linearMasked, $error);
        }

        if ($error === 0) {
            $physicalInt = (int) ($physical ?? 0);
            $physBase = $physicalInt - $pageOffset;
            if ($isWrite) {
                $this->cachedWritePageBase = $pageBase;
                $this->cachedWritePhysBase = $physBase;
            } else {
                $this->cachedReadPageBase = $pageBase;
                $this->cachedReadPhysBase = $physBase;
            }
        }

        return (int) ($physical ?? 0);
    }

    private function readLinearByte(int $linear, bool $isWrite): int
    {
        $cpu = $this->runtime->context()->cpu();
        $mask = $this->linearMask();
        $linearMasked = $linear & $mask;
        $isUser = $cpu->cpl() === 3;
        $pagingEnabled = $cpu->isPagingEnabled();

        [$value, $error] = $this->runtime->memoryAccessor()->readMemory8(
            $linearMasked,
            $isUser,
            $pagingEnabled,
            $mask,
        );

        if ($error === 0xFFFFFFFF) {
            $physical = $this->translateLinear($linearMasked, $isWrite);
            return $this->readPhysicalByte($physical);
        }

        if ($error !== 0) {
            $this->throwPageFault($linearMasked, $error);
        }

        return $value;
    }

    private function writeLinearByte(int $linear, int $value): void
    {
        $cpu = $this->runtime->context()->cpu();
        $mask = $this->linearMask();
        $linearMasked = $linear & $mask;
        $isUser = $cpu->cpl() === 3;
        $pagingEnabled = $cpu->isPagingEnabled();

        $error = $this->runtime->memoryAccessor()->writeMemory8(
            $linearMasked,
            $value,
            $isUser,
            $pagingEnabled,
            $mask,
        );

        if ($error === 0xFFFFFFFF) {
            $physical = $this->translateLinear($linearMasked, true);
            $this->writePhysicalByte($physical, $value);
            return;
        }

        if ($error !== 0) {
            $this->throwPageFault($linearMasked, $error);
        }
    }

    private function readPhysicalByte(int $address): int
    {
        if ($this->physical instanceof RustMemoryStream) {
            return $this->physical->readByteAt($address);
        }

        $saved = $this->physical->offset();
        $this->physical->setOffset($address);
        $value = $this->physical->byte();
        $this->physical->setOffset($saved);
        return $value;
    }

    private function writePhysicalByte(int $address, int $value): void
    {
        if ($this->physical instanceof RustMemoryStream) {
            $this->physical->writeByteAt($address, $value);
            return;
        }

        $saved = $this->physical->offset();
        $this->physical->setOffset($address);
        $this->physical->writeByte($value);
        $this->physical->setOffset($saved);
    }

    private function throwPageFault(int $linear, int $error): void
    {
        $vector = ($error >> 16) & 0xFF;
        $errorCode = $error & 0xFFFF;
        $cpu = $this->runtime->context()->cpu();

        if ($cpu->isLongMode() && !$cpu->isCompatibilityMode()) {
            $masked = $linear & 0x0000FFFFFFFFFFFF;
            $canonical = ($masked & 0x0000800000000000) !== 0
                ? ($masked | (-1 << 48))
                : $masked;
            $this->runtime->memoryAccessor()->writeControlRegister(2, $canonical);
        } else {
            $this->runtime->memoryAccessor()->writeControlRegister(2, $linear & 0xFFFFFFFF);
        }

        throw new FaultException($vector, $errorCode, 'Page fault');
    }
}
