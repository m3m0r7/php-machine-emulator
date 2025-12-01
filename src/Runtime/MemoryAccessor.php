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
    /**
     * Register storage (addresses 0-15 for CPU registers).
     * General memory is handled by MemoryStream.
     */
    protected array $registers = [];

    protected bool $zeroFlag = false;
    protected bool $signFlag = false;
    protected bool $overflowFlag = false;
    protected bool $carryFlag = false;
    protected bool $parityFlag = false;
    protected bool $auxiliaryCarryFlag = false;
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

    /**
     * Read a byte from memory at the given address, preserving the current offset.
     */
    private function readFromMemory(int $address): int
    {
        $memory = $this->runtime->memory();
        $savedOffset = $memory->offset();
        $memory->setOffset($address);
        $value = $memory->byte();
        $memory->setOffset($savedOffset);
        return $value;
    }

    /**
     * Write a byte to memory at the given address, preserving the current offset.
     */
    private function writeToMemory(int $address, int $value): void
    {
        $memory = $this->runtime->memory();
        $savedOffset = $memory->offset();
        $memory->setOffset($address);
        $memory->writeByte($value);
        $memory->setOffset($savedOffset);
    }

    public function allocate(int $address, int $size = 1, bool $safe = true): self
    {
        // Register addresses (0-31) are stored in $registers array
        if ($this->isRegisterAddress($address)) {
            if ($safe && array_key_exists($address, $this->registers)) {
                throw new MemoryAccessorException('Specified register address was allocated');
            }
            for ($i = 0; $i < $size; $i++) {
                if ($this->isRegisterAddress($address + $i)) {
                    $this->registers[$address + $i] = null;
                }
            }
            return $this;
        }

        // General memory is handled by MemoryStream - no explicit allocation needed
        // MemoryStream pre-allocates all memory at construction
        return $this;
    }

    public function fetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface
    {
        $address = $this->asAddress($registerType);

        // Register addresses use $registers array
        if ($this->isRegisterAddress($address)) {
            $this->validateRegisterAddressWasAllocated($address);
            // Determine stored size: GPRs (addresses 0-7) are stored as 32-bit
            $storedSize = $this->isGprAddress($address) ? 32 : 16;
            return MemoryAccessorFetchResult::fromCache($this->registers[$address], $storedSize);
        }

        // General memory uses MemoryStream
        $value = $this->readFromMemory($address);
        return MemoryAccessorFetchResult::fromCache($value, 8);
    }

    public function tryToFetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface|null
    {
        $address = $this->asAddress($registerType);

        // Register addresses use $registers array
        if ($this->isRegisterAddress($address)) {
            if (!array_key_exists($address, $this->registers)) {
                return null;
            }
            $storedSize = $this->isGprAddress($address) ? 32 : 16;
            return MemoryAccessorFetchResult::fromCache($this->registers[$address], $storedSize);
        }

        // General memory uses MemoryStream
        $value = $this->readFromMemory($address);
        return MemoryAccessorFetchResult::fromCache($value, 8);
    }

    public function write16Bit(int|RegisterType $registerType, int|null $value): self
    {
        return $this->writeBySize($registerType, $value, 16);
    }

    public function writeBySize(int|RegisterType $registerType, int|null $value, int $size = 64): self
    {
        $address = $this->asAddress($registerType);

        // Register addresses use $registers array
        if ($this->isRegisterAddress($address)) {
            $isGpr = $this->isGprAddress($address);

            // For GPRs, always store as 32-bit internally to preserve upper bits when writing 16-bit values
            if ($isGpr && $size === 16) {
                $current = $this->registers[$address] ?? 0;
                $value = ($current & 0xFFFF0000) | ($value & 0xFFFF);
                $size = 32;
            }

            // GPRs are always stored as 32-bit
            if ($isGpr && $size < 32) {
                $size = 32;
            }

            // Store register values directly without byte swapping
            // The value from memory reads is already in native format
            [$address, $previousValue] = $this->processRegisterWrite(
                $registerType,
                $value ?? 0,
            );

            $this->postProcessWhenWrote($address, $previousValue, $value);
            return $this;
        }

        // General memory uses MemoryStream
        $previousValue = $this->readFromMemory($address);
        $bytes = intdiv($size, 8);
        for ($i = 0; $i < $bytes; $i++) {
            $this->writeToMemory($address + $i, ($value >> ($i * 8)) & 0xFF);
        }
        $this->postProcessWhenWrote($address, $previousValue, $value);

        return $this;
    }

    public function writeToHighBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $isGpr = $this->isGprAddress($address);

        // Read current value, update high byte (bits 8-15), preserve the rest
        $current = $this->fetch($registerType)->asBytesBySize($isGpr ? 32 : 16);
        $newValue = ($current & ~0xFF00) | (($value & 0xFF) << 8);

        // Store directly without byte swapping
        [$address, $previousValue] = $this->processRegisterWrite(
            $registerType,
            $newValue,
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

        // Store directly without byte swapping
        [$address, $previousValue] = $this->processRegisterWrite(
            $registerType,
            $newValue,
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
        $previousValue = $this->readFromMemory($address);
        $this->writeToMemory($address, $value & 0xFF);
        $this->postProcessWhenWrote($address, $previousValue, $value);

        return $this;
    }

    /**
     * Read a raw byte value directly from memory without any decoding.
     */
    public function readRawByte(int $address): ?int
    {
        return $this->readFromMemory($address);
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

        // Overflow flag: set if the signed result is outside the representable range
        // For subtraction/comparison: OF is set when the result overflows the signed range
        // Signed range for N bits: -(2^(N-1)) to (2^(N-1) - 1)
        $signedMin = -(1 << ($size - 1));        // e.g., -32768 for 16-bit
        $signedMax = (1 << ($size - 1)) - 1;     // e.g., 32767 for 16-bit
        $this->overflowFlag = $value < $signedMin || $value > $signedMax;

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

    public function shouldAuxiliaryCarryFlag(): bool
    {
        return $this->auxiliaryCarryFlag;
    }

    public function setAuxiliaryCarryFlag(bool $which): self
    {
        $this->auxiliaryCarryFlag = $which;
        return $this;
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
                $value |= $this->readFromMemory($address + $i) << ($i * 8);
            }
            $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
            $newSp = ($sp + $bytes) & $mask;

            $this->writeBySize(RegisterType::ESP, $newSp, $size);

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

    private function processRegisterWrite(int|RegisterType $registerType, int|null $value): array
    {
        $address = $this->asAddress($registerType);
        $this->validateRegisterAddressWasAllocated($address);

        $previousValue = $this->registers[$address];
        $this->registers[$address] = $value;

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

    private function validateRegisterAddressWasAllocated(int $address): void
    {
        if (array_key_exists($address, $this->registers)) {
            return;
        }

        // Lazily allocate register if not yet allocated
        $this->registers[$address] = null;
    }

    /**
     * Check if address is a register address (0-31).
     */
    private function isRegisterAddress(int $address): bool
    {
        return $address >= 0 && $address < 32;
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
            $value |= $this->readFromMemory($address + $i) << ($i * 8);
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
        for ($i = 0; $i < 4; $i++) {
            $this->writeToMemory($address + $i, ($value >> ($i * 8)) & 0xFF);
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

        $b0 = $this->readFromMemory($offset);
        $b1 = $this->readFromMemory($offset + 1);
        $b2 = $this->readFromMemory($offset + 2);
        $b3 = $this->readFromMemory($offset + 3);
        $b4 = $this->readFromMemory($offset + 4);
        $b5 = $this->readFromMemory($offset + 5);
        $b6 = $this->readFromMemory($offset + 6);
        $b7 = $this->readFromMemory($offset + 7);

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
