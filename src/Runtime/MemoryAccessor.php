<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Exception\MemoryAccessorException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Util\BinaryInteger;

class MemoryAccessor implements MemoryAccessorInterface
{
    protected array $memory = [];
    protected bool $zeroFlag = false;
    protected bool $signFlag = false;
    protected bool $overflowFlag = false;
    protected bool $carryFlag = false;
    protected bool $parityFlag = false;
    protected bool $fireEvents = true;
    protected bool $enableUpdateFlags = false;
    protected bool $directionFlag = false;
    protected bool $interruptFlag = false;
    protected bool $instructionFetch = false;
    protected int $efer = 0;
    protected array $controlRegisters = [
        0 => 0x22, // CR0: MP + NE set to indicate FPU present
        4 => 0x0,
    ];

    public function __construct(protected RuntimeInterface $runtime, protected MemoryAccessorObserverCollectionInterface $memoryAccessorObserverCollection)
    {
    }

    public function allocate(int $address, int $size = 1, bool $safe = true): self
    {
        if ($safe && array_key_exists($address, $this->memory)) {
            throw new MemoryAccessorException('Specified memory address was allocated');
        }

        for ($i = 0; $i < $size; $i++) {
            $this->memory[$address + $i] = null;
        }

        return $this;
    }

    public function fetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface
    {
        $address = $this->asAddress($registerType);
        $this->validateMemoryAddressWasAllocated($address);

        // Determine stored size: GPRs (addresses 0-7) are stored as 32-bit
        $storedSize = ($address >= 0 && $address <= 7) ? 32 : 16;

        return MemoryAccessorFetchResult::fromCache($this->memory[$address], $storedSize);
    }

    public function tryToFetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface|null
    {
        $address = $this->asAddress($registerType);

        if (!array_key_exists($address, $this->memory)) {
            return null;
        }

        // Determine stored size: GPRs (addresses 0-7) are stored as 32-bit
        $storedSize = ($address >= 0 && $address <= 7) ? 32 : 16;

        return MemoryAccessorFetchResult::fromCache($this->memory[$address], $storedSize);
    }

    public function write16Bit(int|RegisterType $registerType, int|null $value): self
    {
        return $this->writeBySize($registerType, $value, 16);
    }

    public function writeBySize(int|RegisterType $registerType, int|null $value, int $size = 64): self
    {
        $address = $this->asAddress($registerType);
        $isGpr = $this->isGprAddress($address);

        // For GPRs, always store as 32-bit internally to preserve upper bits when writing 16-bit values
        if ($isGpr && $size === 16) {
            // Read current 32-bit value (raw, not encoded)
            $currentRaw = $this->memory[$address] ?? 0;
            // Decode current value from little-endian 32-bit
            $current = BinaryInteger::asLittleEndian($currentRaw, 32);
            // Preserve upper 16 bits, replace lower 16 bits
            $value = ($current & 0xFFFF0000) | ($value & 0xFFFF);
            $size = 32;
        }

        // GPRs are always stored as 32-bit
        if ($isGpr && $size < 32) {
            $size = 32;
        }

        [$address, $previousValue] = $this
            ->processWrite(
                $registerType,
                BinaryInteger::asLittleEndian(
                    $value ?? 0,
                    $size,
                ),
            );

        $this->postProcessWhenWrote(
            $address,
            $previousValue,
            $value,
        );

        return $this;
    }

    public function writeToHighBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $isGpr = $this->isGprAddress($address);

        // Read current value, update high byte (bits 8-15), preserve the rest
        $current = $this->fetch($registerType)->asBytesBySize($isGpr ? 32 : 16);
        $newValue = ($current & ~0xFF00) | (($value & 0xFF) << 8);

        [$address, $previousValue] = $this->processWrite(
            $registerType,
            BinaryInteger::asLittleEndian($newValue, $isGpr ? 32 : 16),
        );

        $this->postProcessWhenWrote(
            $address,
            $previousValue,
            $value,
        );

        return $this;
    }

    public function writeToLowBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $isGpr = $this->isGprAddress($address);

        // Read current value, update low byte (bits 0-7), preserve the rest
        $current = $this->fetch($registerType)->asBytesBySize($isGpr ? 32 : 16);
        $newValue = ($current & ~0xFF) | ($value & 0xFF);

        [$address, $previousValue] = $this->processWrite(
            $registerType,
            BinaryInteger::asLittleEndian($newValue, $isGpr ? 32 : 16),
        );

        $this->postProcessWhenWrote(
            $address,
            $previousValue,
            $value,
        );

        return $this;
    }

    /**
     * Write a raw byte value directly to memory without any encoding.
     * Used for byte-addressable memory operations.
     */
    public function writeRawByte(int $address, int $value): self
    {
        $previousValue = $this->memory[$address] ?? null;
        $this->memory[$address] = $value & 0xFF;

        $this->postProcessWhenWrote($address, $previousValue, $value);

        return $this;
    }

    /**
     * Read a raw byte value directly from memory without any decoding.
     */
    public function readRawByte(int $address): ?int
    {
        return $this->memory[$address] ?? null;
    }

    protected function postProcessWhenWrote(int $address, int|null $previousValue, int|null $value): void
    {
        $wroteValue = ($value ?? 0) & 0b11111111;


        if ($this->enableUpdateFlags) {
            $this->updateFlags($value);
        }

        $this->processObservers(
            $address,
            $previousValue === null
                ? $previousValue
                : ($previousValue & 0b11111111),
            $wroteValue,
        );
    }

    public function enableUpdateFlags(bool $which): self
    {
        $this->enableUpdateFlags = $which;
        return $this;
    }


    public function updateFlags(int|null $value, int $size = 16): self
    {
        if ($value === null) {
            $this->zeroFlag = true;
            $this->signFlag = false;
            $this->overflowFlag = false;
            $this->parityFlag = true;
            return $this;
        }

        $mask = (1 << $size) - 1;
        $masked = $value & $mask;

        $this->zeroFlag = $masked === 0;
        $this->signFlag = ($masked & (1 << ($size - 1))) !== 0;
        $this->overflowFlag = $value < 0 || $value > $mask;
        $this->parityFlag = substr_count(decbin($masked & 0b11111111), '1') % 2 === 0;

        return $this;
    }

    public function setCarryFlag(bool $which): self
    {
        $this->carryFlag = $which;

        return $this;
    }

    public function add(int|RegisterType $registerType, int $value): self
    {
        $this
            ->write16Bit(
                $registerType,
                $this->fetch($registerType)->asByte() + $value
            );

        return $this;
    }

    public function sub(int|RegisterType $registerType, int $value): self
    {
        $this->add($registerType, -$value);

        return $this;
    }

    public function increment(int|RegisterType $registerType): self
    {
        $this->add($registerType, 1);

        return $this;
    }

    public function decrement(int|RegisterType $registerType): self
    {
        $this->sub($registerType, 1);

        return $this;
    }

    public function shouldZeroFlag(): bool
    {
        return $this->zeroFlag;
    }

    public function shouldSignFlag(): bool
    {
        return $this->signFlag;
    }

    public function shouldOverflowFlag(): bool
    {
        return $this->overflowFlag;
    }

    public function shouldCarryFlag(): bool
    {
        return $this->carryFlag;
    }

    public function shouldParityFlag(): bool
    {
        return $this->parityFlag;
    }

    public function setZeroFlag(bool $which): self
    {
        $this->zeroFlag = $which;
        return $this;
    }

    public function setParityFlag(bool $which): self
    {
        $this->parityFlag = $which;
        return $this;
    }

    public function setSignFlag(bool $which): self
    {
        $this->signFlag = $which;
        return $this;
    }

    public function setOverflowFlag(bool $which): self
    {
        $this->overflowFlag = $which;
        return $this;
    }

    public function setDirectionFlag(bool $which): self
    {
        $this->directionFlag = $which;
        return $this;
    }

    public function shouldDirectionFlag(): bool
    {
        return $this->directionFlag;
    }

    public function setInterruptFlag(bool $which): self
    {
        $this->interruptFlag = $which;
        return $this;
    }

    public function shouldInterruptFlag(): bool
    {
        return $this->interruptFlag;
    }

    public function setInstructionFetch(bool $flag): self
    {
        $this->instructionFetch = $flag;
        return $this;
    }

    public function shouldInstructionFetch(): bool
    {
        return $this->instructionFetch;
    }

    protected function asAddress(int|RegisterType $address): int
    {
        if ($address instanceof RegisterType) {
            return ($this->runtime->register())::addressBy($address);
        }
        return $address;
    }

    public function pop(int|RegisterType $registerType, int $size = 16): MemoryAccessorFetchResultInterface
    {
        // Stack-aware pop when targeting ESP.
        if ($registerType instanceof RegisterType && $registerType === RegisterType::ESP) {
            $sp = $this->fetch(RegisterType::ESP)->asBytesBySize($size);
            $bytes = intdiv($size, 8);

            $address = $this->stackLinearAddress($sp, $size, false);
            $value = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $value |= ($this->memory[$address + $i] ?? 0) << ($i * 8);
            }
            $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
            $newSp = ($sp + $bytes) & $mask;

            $this->writeBySize(RegisterType::ESP, $newSp, $size);
            $verifyEsp = $this->fetch(RegisterType::ESP)->asBytesBySize($size);

            // Value is already in correct little-endian format from memory read
            // Pass alreadyDecoded=true to skip byte swap in asBytesBySize()
            return new MemoryAccessorFetchResult($value, $size, alreadyDecoded: true);
        }

        $address = $this->asAddress($registerType);
        $fetchResult = $this->fetch($address)
            ->asBytesBySize();

        $this->writeBySize(
            $address,
            $fetchResult >> $size,
        );

        return new MemoryAccessorFetchResult(
            BinaryInteger::asLittleEndian(
                $fetchResult & ((1 << $size) - 1),
                $size,
            ),
        );
    }

    public function push(int|RegisterType $registerType, int|null $value, int $size = 16): self
    {
        // Stack-aware push when targeting ESP.
        if ($registerType instanceof RegisterType && $registerType === RegisterType::ESP) {
            $sp = $this->fetch(RegisterType::ESP)->asBytesBySize($size) & ((1 << $size) - 1);
            $bytes = intdiv($size, 8);
            $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
            $newSp = ($sp - $bytes) & $mask;
            $address = $this->stackLinearAddress($newSp, $size, true);

            $this->writeBySize(RegisterType::ESP, $newSp, $size);
            $this->allocate($address, $bytes, safe: false);

            $masked = $value & ((1 << $size) - 1);
            for ($i = 0; $i < $bytes; $i++) {
                $this->writeBySize($address + $i, ($masked >> ($i * 8)) & 0xFF, 8);
            }

            return $this;
        }

        $address = $this->asAddress($registerType);
        $fetchResult = $this->fetch($address)
            ->asBytesBySize();

        $value = $value & ((1 << $size) - 1);

        $this->writeBySize(
            $address,
            $storeValue = ($fetchResult << $size) + $value,
        );

        if ((($fetchResult << $size) + $value) !== ($actualStoredValue = $this->fetch($address)->asBytesBySize())) {
            throw new MemoryAccessorException(
                sprintf(
                    'Illegal to expect storing value %d but stored actually %d (original value: %d)',
                    $storeValue,
                    $actualStoredValue,
                    $value,
                )
            );
        }

        return $this;
    }

    public function readControlRegister(int $index): int
    {
        return $this->controlRegisters[$index] ?? 0;
    }

    public function writeControlRegister(int $index, int $value): void
    {
        $this->controlRegisters[$index] = $value;
    }

    public function readEfer(): int
    {
        return $this->efer;
    }

    public function writeEfer(int $value): void
    {
        $this->efer = $value & 0xFFFFFFFFFFFFFFFF;
    }

    private function processWrite(int|RegisterType $registerType, int|null $value): array
    {
        $address = $this->asAddress($registerType);
        $this->validateMemoryAddressWasAllocated($address);

        $previousValue = $this->memory[$address];

        $this->memory[$address] = $value;

        return [$address, $previousValue];
    }

    private function processObservers(int $address, int|null $previousValue, int|null $nextValue): void
    {
        foreach ($this->memoryAccessorObserverCollection as $memoryAccessorObserverCollection) {
            assert($memoryAccessorObserverCollection instanceof MemoryAccessorObserverInterface);

            if (!$memoryAccessorObserverCollection->shouldMatch($this->runtime, $address, $previousValue, $nextValue)) {
                continue;
            }

            $memoryAccessorObserverCollection
                ->observe(
                    $this->runtime,
                    $address,
                    $previousValue,
                    $nextValue,
                );
        }
    }

    private function validateMemoryAddressWasAllocated(int $address): void
    {
        if (array_key_exists($address, $this->memory)) {
            return;
        }

        // Lazily back common VRAM/MMIO ranges so guest mappings do not fault.
        if (MemoryRegion::isKnownRegion($address)) {
            $this->allocate($address, safe: false);
            return;
        }

        throw new MemoryAccessorException(
            sprintf(
                'Specified memory address was not allocated: 0x%04X',
                $address,
            ),
        );
    }

    private function stackLinearAddress(int $sp, int $size, bool $isWrite = false): int
    {
        $ssSelector = $this->fetch(RegisterType::SS)->asByte();
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $this->runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

        if ($this->runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->segmentDescriptor($ssSelector);
            if ($descriptor === null || !$descriptor['present']) {
                // Allow null/invalid stack selector for boot compatibility
                // Early boot code may not have GDT properly set up yet
                // Use flat memory model (base 0) as fallback
                $linear = ($sp & $mask) & $linearMask;
                return $this->translateLinear($linear, $isWrite);
            }

            // SS must be writable data, and DPL == CPL == RPL.
            $cpl = $this->runtime->context()->cpu()->cpl();
            $rpl = $ssSelector & 0x3;
            $dpl = $descriptor['dpl'] ?? 0;
            $isWritable = ($descriptor['type'] & 0x2) !== 0;
            $isExecutable = $descriptor['executable'] ?? false;
            if ($isExecutable || !$isWritable || $dpl !== $cpl || $rpl !== $cpl) {
                $linear = ($sp & $mask) & $linearMask;
                return $this->translateLinear($linear, $isWrite);
            }

            if (($sp & $mask) > $descriptor['limit']) {
                throw new FaultException(0x0C, $ssSelector, 'Stack limit exceeded');
            }

            $linear = ($descriptor['base'] + ($sp & $mask)) & $linearMask;
            return $this->translateLinear($linear, $isWrite);
        }

        $linear = ((($ssSelector << 4) & 0xFFFFF) + ($sp & $mask)) & $linearMask;
        return $this->translateLinear($linear, $isWrite);
    }

    private function isGeneralPurposeRegister(RegisterType $registerType): bool
    {
        return in_array($registerType, [
            RegisterType::EAX,
            RegisterType::ECX,
            RegisterType::EDX,
            RegisterType::EBX,
            RegisterType::ESP,
            RegisterType::EBP,
            RegisterType::ESI,
            RegisterType::EDI,
        ], true);
    }

    private function isGprAddress(int $address): bool
    {
        // GPR addresses are 0-7 (EAX=0, ECX=1, EDX=2, EBX=3, ESP=4, EBP=5, ESI=6, EDI=7)
        return $address >= 0 && $address <= 7;
    }

    private function translateLinear(int $linear, bool $isWrite = false): int
    {
        $mask = $this->runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        $linear &= $mask;

        if (!$this->runtime->context()->cpu()->isPagingEnabled()) {
            return $linear;
        }

        $cr4 = $this->readControlRegister(4);
        $pae = ($cr4 & (1 << 5)) !== 0;

        if ($pae) {
            return $this->translateLinearPae($linear, $isWrite, $cr4);
        }

        return $this->translateLinear32bit($linear, $isWrite, $cr4);
    }

    /**
     * PAE (Physical Address Extension) paging translation.
     */
    private function translateLinearPae(int $linear, bool $isWrite, int $cr4): int
    {
        $user = $this->runtime->context()->cpu()->cpl() === 3;
        $cr3 = $this->readControlRegister(3) & 0xFFFFF000;
        $pdpIndex = ($linear >> 30) & 0x3;
        $dirIndex = ($linear >> 21) & 0x1FF;
        $tableIndex = ($linear >> 12) & 0x1FF;
        $offset = $linear & 0xFFF;
        $nxe = ($this->efer & (1 << 11)) !== 0;

        // Check PDPT entry
        $pdpteAddr = ($cr3 + ($pdpIndex * 8)) & 0xFFFFFFFF;
        $pdpte = $this->readPhysical64($pdpteAddr);
        $this->checkPageEntryPresent($pdpte, $linear, $isWrite, $user, 'PDPT entry');
        $this->checkPageEntryUserAccess($pdpte, $linear, $isWrite, $user, 'PDPT entry');
        $this->checkPageEntryWriteAccess($pdpte, $linear, $isWrite, $user, 'PDPT entry');
        $pdpte |= 0x20;
        $this->writePhysical64($pdpteAddr, $pdpte);

        // Check page directory entry
        $pdeAddr = (($pdpte & 0xFFFFFF000) + ($dirIndex * 8)) & 0xFFFFFFFF;
        $pde = $this->readPhysical64($pdeAddr);
        $this->checkPageEntryPresent($pde, $linear, $isWrite, $user, 'Page directory entry');
        $this->checkPageEntryUserAccess($pde, $linear, $isWrite, $user, 'Page directory entry');
        $this->checkPageEntryWriteAccess($pde, $linear, $isWrite, $user, 'Page directory entry');

        // Handle 2MB large page
        $isLarge = ($pde & (1 << 7)) !== 0;
        if ($isLarge) {
            return $this->handlePaeLargePage($pde, $pdeAddr, $linear, $isWrite, $user, $nxe);
        }

        // Check page table entry
        $pteAddr = (($pde & 0xFFFFFF000) + ($tableIndex * 8)) & 0xFFFFFFFF;
        $pte = $this->readPhysical64($pteAddr);
        $this->checkPageEntryPresent($pte, $linear, $isWrite, $user, 'Page table entry');
        $this->checkPageEntryUserAccess($pte, $linear, $isWrite, $user, 'Page table entry');
        $this->checkPageEntryWriteAccess($pte, $linear, $isWrite, $user, 'Page table entry');

        // Update accessed/dirty bits
        $this->updateAccessedDirtyBits64($pdeAddr, $pde, $pteAddr, $pte, $isWrite);

        // Check NX bit
        $this->checkExecuteDisable64($pte, $linear, $user, $nxe);

        $phys = ($pte & 0xFFFFFF000) + $offset;
        return $phys & 0xFFFFFFFF;
    }

    /**
     * 32-bit paging translation.
     */
    private function translateLinear32bit(int $linear, bool $isWrite, int $cr4): int
    {
        $user = $this->runtime->context()->cpu()->cpl() === 3;
        $pse = ($cr4 & (1 << 4)) !== 0;
        $cr3 = $this->readControlRegister(3) & 0xFFFFF000;
        $dirIndex = ($linear >> 22) & 0x3FF;
        $tableIndex = ($linear >> 12) & 0x3FF;
        $offset = $linear & 0xFFF;

        // Check page directory entry
        $pdeAddr = ($cr3 + ($dirIndex * 4)) & 0xFFFFFFFF;
        $pde = $this->readPhysical32($pdeAddr);
        $this->checkPageEntryPresent32($pde, $linear, $isWrite, $user, 'Page directory entry');

        // Handle 4MB large page
        $is4M = $pse && (($pde & (1 << 7)) !== 0);
        if ($is4M) {
            return $this->handle32bitLargePage($pde, $pdeAddr, $linear, $isWrite, $user);
        }

        $this->checkPageEntryUserAccess32($pde, $linear, $isWrite, $user, 'Page directory entry');
        $this->checkPageEntryWriteAccess32($pde, $linear, $isWrite, $user, 'Page directory entry');

        // Check page table entry
        $pteAddr = ($pde & 0xFFFFF000) + ($tableIndex * 4);
        $pte = $this->readPhysical32($pteAddr);
        $this->checkPageEntryPresent32($pte, $linear, $isWrite, $user, 'Page table entry');
        $this->checkPageEntryUserAccess32($pte, $linear, $isWrite, $user, 'Page table entry');
        $this->checkPageEntryWriteAccess32($pte, $linear, $isWrite, $user, 'Page table entry');

        // Update accessed/dirty bits
        $this->updateAccessedDirtyBits32($pdeAddr, $pde, $pteAddr, $pte, $isWrite);

        $phys = ($pte & 0xFFFFF000) + $offset;
        return $phys & 0xFFFFFFFF;
    }

    private function checkPageEntryPresent(int $entry, int $linear, bool $isWrite, bool $user, string $entryName): void
    {
        if (($entry & 0x1) === 0) {
            $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0));
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, "{$entryName} not present");
        }
    }

    private function checkPageEntryUserAccess(int $entry, int $linear, bool $isWrite, bool $user, string $entryName): void
    {
        if ($user && (($entry & 0x4) === 0)) {
            $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | 0b100 | 0b1);
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, "{$entryName} not user accessible");
        }
    }

    private function checkPageEntryWriteAccess(int $entry, int $linear, bool $isWrite, bool $user, string $entryName): void
    {
        if ($isWrite && (($entry & 0x2) === 0)) {
            $err = $this->errorCodeWithFetch(0b10 | ($user ? 0b100 : 0) | 0b1);
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, "{$entryName} not writable");
        }
    }

    private function checkPageEntryPresent32(int $entry, int $linear, bool $isWrite, bool $user, string $entryName): void
    {
        if (($entry & 0x1) === 0) {
            $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0));
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, "{$entryName} not present");
        }
    }

    private function checkPageEntryUserAccess32(int $entry, int $linear, bool $isWrite, bool $user, string $entryName): void
    {
        if ($user && (($entry & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, "{$entryName} not user accessible");
        }
    }

    private function checkPageEntryWriteAccess32(int $entry, int $linear, bool $isWrite, bool $user, string $entryName): void
    {
        if ($isWrite && (($entry & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, "{$entryName} not writable");
        }
    }

    private function handlePaeLargePage(int $pde, int $pdeAddr, int $linear, bool $isWrite, bool $user, bool $nxe): int
    {
        $prevFlag = $this->enableUpdateFlags;
        $this->enableUpdateFlags = false;
        $pde |= 0x20;
        if ($isWrite) {
            $pde |= 0x40;
        }
        $this->writePhysical64($pdeAddr, $pde);
        $this->enableUpdateFlags = $prevFlag;

        if ($this->shouldInstructionFetch() && $nxe && (($pde >> 63) & 0x1)) {
            $err = 0x01 | ($user ? 0b100 : 0) | 0x10;
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, 'Execute-disable large page');
        }

        $phys = ($pde & 0xFFE00000) + ($linear & 0x1FFFFF);
        return $phys & 0xFFFFFFFF;
    }

    private function handle32bitLargePage(int $pde, int $pdeAddr, int $linear, bool $isWrite, bool $user): int
    {
        $this->checkPageEntryUserAccess32($pde, $linear, $isWrite, $user, 'Page directory entry');
        $this->checkPageEntryWriteAccess32($pde, $linear, $isWrite, $user, 'Page directory entry');

        $prevFlag = $this->enableUpdateFlags;
        $this->enableUpdateFlags = false;
        $pde |= 0x20;
        if ($isWrite) {
            $pde |= 0x40;
        }
        $this->writePhysical32($pdeAddr, $pde);
        $this->enableUpdateFlags = $prevFlag;

        $phys = ($pde & 0xFFC00000) + ($linear & 0x3FFFFF);
        return $phys & 0xFFFFFFFF;
    }

    private function updateAccessedDirtyBits64(int $pdeAddr, int $pde, int $pteAddr, int $pte, bool $isWrite): void
    {
        $prevFlag = $this->enableUpdateFlags;
        $this->enableUpdateFlags = false;
        $pde |= 0x20;
        $this->writePhysical64($pdeAddr, $pde);
        $pte |= 0x20;
        if ($isWrite) {
            $pte |= 0x40;
        }
        $this->writePhysical64($pteAddr, $pte);
        $this->enableUpdateFlags = $prevFlag;
    }

    private function updateAccessedDirtyBits32(int $pdeAddr, int $pde, int $pteAddr, int $pte, bool $isWrite): void
    {
        $prevFlag = $this->enableUpdateFlags;
        $this->enableUpdateFlags = false;
        $pde |= 0x20;
        $this->writePhysical32($pdeAddr, $pde);
        $pte |= 0x20;
        if ($isWrite) {
            $pte |= 0x40;
        }
        $this->writePhysical32($pteAddr, $pte);
        $this->enableUpdateFlags = $prevFlag;
    }

    private function checkExecuteDisable64(int $pte, int $linear, bool $user, bool $nxe): void
    {
        if ($this->shouldInstructionFetch() && $nxe && (($pte >> 63) & 0x1)) {
            $err = 0x01 | ($user ? 0b100 : 0) | 0x10;
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, 'Execute-disable page');
        }
    }

    private function readPhysical32(int $address): int
    {
        $value = 0;
        for ($i = 0; $i < 4; $i++) {
            $value |= ($this->memory[$address + $i] ?? 0) << ($i * 8);
        }
        return $value & 0xFFFFFFFF;
    }

    private function readPhysical64(int $address): int
    {
        $lo = $this->readPhysical32($address);
        $hi = $this->readPhysical32($address + 4);
        return ($hi << 32) | $lo;
    }

    private function writePhysical32(int $address, int $value): void
    {
        $this->allocate($address, 4, safe: false);
        for ($i = 0; $i < 4; $i++) {
            $this->memory[$address + $i] = ($value >> ($i * 8)) & 0xFF;
        }
    }

    private function writePhysical64(int $address, int $value): void
    {
        $this->writePhysical32($address, $value & 0xFFFFFFFF);
        $this->writePhysical32($address + 4, ($value >> 32) & 0xFFFFFFFF);
    }

    private function setCr2(int $linear): void
    {
        $this->writeControlRegister(2, $linear & 0xFFFFFFFF);
    }

    private function errorCodeWithFetch(int $err): int
    {
        if ($this->shouldInstructionFetch()) {
            $err |= 0x10;
        }
        return $err & 0xFFFF;
    }
    private function segmentDescriptor(int $selector): ?array
    {
        $ti = ($selector >> 2) & 0x1;
        if ($ti === 1) {
            $ldtr = $this->runtime->context()->cpu()->ldtr();
            $base = $ldtr['base'] ?? 0;
            $limit = $ldtr['limit'] ?? 0;
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
        } else {
            $gdtr = $this->runtime->context()->cpu()->gdtr();
            $base = $gdtr['base'] ?? 0;
            $limit = $gdtr['limit'] ?? 0;
        }
        $index = ($selector >> 3) & 0x1FFF;
        $offset = $base + ($index * 8);

        if ($offset + 7 > $base + $limit) {
            return null;
        }

        $b0 = $this->memory[$offset] ?? 0;
        $b1 = $this->memory[$offset + 1] ?? 0;
        $b2 = $this->memory[$offset + 2] ?? 0;
        $b3 = $this->memory[$offset + 3] ?? 0;
        $b4 = $this->memory[$offset + 4] ?? 0;
        $b5 = $this->memory[$offset + 5] ?? 0;
        $b6 = $this->memory[$offset + 6] ?? 0;
        $b7 = $this->memory[$offset + 7] ?? 0;

        $limitLow = $b0 | ($b1 << 8);
        $limitHigh = $b6 & 0x0F;
        $fullLimit = $limitLow | ($limitHigh << 16);
        if (($b6 & 0x80) !== 0) {
            $fullLimit = ($fullLimit << 12) | 0xFFF;
        }

        $baseAddr = $b2 | ($b3 << 8) | ($b4 << 16) | ($b7 << 24);
        $present = ($b5 & 0x80) !== 0;
        $dpl = ($b5 >> 5) & 0x3;
        $type = $b5 & 0x0F;
        $executable = ($type & 0x08) !== 0;

        return [
            'base' => $baseAddr & 0xFFFFFFFF,
            'limit' => $fullLimit & 0xFFFFFFFF,
            'present' => $present,
            'dpl' => $dpl,
            'type' => $type,
            'executable' => $executable,
        ];
    }
}
