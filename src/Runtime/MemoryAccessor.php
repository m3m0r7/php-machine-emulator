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

        return new MemoryAccessorFetchResult($this->memory[$address]);
    }

    public function tryToFetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface|null
    {
        $address = $this->asAddress($registerType);

        if (!array_key_exists($address, $this->memory)) {
            return null;
        }

        return new MemoryAccessorFetchResult($this->memory[$address]);
    }

    public function write16Bit(int|RegisterType $registerType, int|null $value): self
    {
        return $this->writeBySize($registerType, $value, 16);
    }

    public function writeBySize(int|RegisterType $registerType, int|null $value, int $size = 64): self
    {
        if ($registerType instanceof RegisterType && $size === 16 && $this->isGeneralPurposeRegister($registerType)) {
            // Preserve upper bits of 32-bit GPRs when writing a 16-bit value.
            $current = $this->fetch($registerType)->asBytesBySize(32);
            $value = ($current & 0xFFFF0000) | ($value & 0xFFFF);
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
        [$address, $previousValue] = $this->processWrite(
            $registerType,
            (($this->fetch($registerType)->asLowBit() << 8) & 0b11111111_00000000) + ($value & 0b11111111),
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
        [$address, $previousValue] = $this->processWrite(
            $registerType,
            (($value & 0b11111111) << 8) + ($this->fetch($registerType)->asHighBit() & 0b11111111),
        );

        $this->postProcessWhenWrote(
            $address,
            $previousValue,
            $value,
        );

        return $this;
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
            $this->writeBySize(RegisterType::ESP, ($sp + $bytes) & $mask, $size);

            return new MemoryAccessorFetchResult(
                BinaryInteger::asLittleEndian(
                    $value,
                    $size,
                ),
            );
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
        // Legacy VGA memory window.
        if ($address >= 0xA0000 && $address < 0xC0000) {
            $this->allocate($address, safe: false);
            return;
        }
        // PCI VGA BAR (e.g., 0xE0000000 region).
        if ($address >= 0xE0000000 && $address < 0xE1000000) {
            $this->allocate($address, safe: false);
            return;
        }
        // LAPIC MMIO page.
        if ($address >= 0xFEE00000 && $address < 0xFEE01000) {
            $this->allocate($address, safe: false);
            return;
        }
        // IOAPIC MMIO page.
        if ($address >= 0xFEC00000 && $address < 0xFEC01000) {
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
        $linearMask = $this->runtime->runtimeOption()->context()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;

        if ($this->runtime->runtimeOption()->context()->isProtectedMode()) {
            $descriptor = $this->segmentDescriptor($ssSelector);
            if ($descriptor === null || !$descriptor['present']) {
                throw new FaultException(0x0C, $ssSelector, 'Stack segment not present');
            }

            // SS must be writable data, and DPL == CPL == RPL.
            $cpl = $this->runtime->runtimeOption()->context()->cpl();
            $rpl = $ssSelector & 0x3;
            $dpl = $descriptor['dpl'] ?? 0;
            $isWritable = ($descriptor['type'] & 0x2) !== 0;
            $isExecutable = $descriptor['executable'] ?? false;
            if ($isExecutable || !$isWritable || $dpl !== $cpl || $rpl !== $cpl) {
                throw new FaultException(0x0C, $ssSelector, 'Invalid stack segment');
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

    private function translateLinear(int $linear, bool $isWrite = false): int
    {
        $mask = $this->runtime->runtimeOption()->context()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        $linear &= $mask;

        if (!$this->runtime->runtimeOption()->context()->isPagingEnabled()) {
            return $linear;
        }

        $cr4 = $this->readControlRegister(4);
        $pse = ($cr4 & (1 << 4)) !== 0;
        $pae = ($cr4 & (1 << 5)) !== 0;
        $user = $this->runtime->runtimeOption()->context()->cpl() === 3;

        if ($pae) {
            $cr3 = $this->readControlRegister(3) & 0xFFFFF000;
            $pdpIndex = ($linear >> 30) & 0x3;
            $dirIndex = ($linear >> 21) & 0x1FF;
            $tableIndex = ($linear >> 12) & 0x1FF;
            $offset = $linear & 0xFFF;
            $nxe = ($this->efer & (1 << 11)) !== 0;

            $pdpteAddr = ($cr3 + ($pdpIndex * 8)) & 0xFFFFFFFF;
            $pdpte = $this->readPhysical64($pdpteAddr);
            if (($pdpte & 0x1) === 0) {
                $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0));
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'PDPT entry not present');
            }
            if ($user && (($pdpte & 0x4) === 0)) {
                $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | 0b100 | 0b1);
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'PDPT entry not user accessible');
            }
            if ($isWrite && (($pdpte & 0x2) === 0)) {
                $err = $this->errorCodeWithFetch(0b10 | ($user ? 0b100 : 0) | 0b1);
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'PDPT entry not writable');
            }
            $pdpte |= 0x20;
            $this->writePhysical64($pdpteAddr, $pdpte);

            $pdeAddr = (($pdpte & 0xFFFFFF000) + ($dirIndex * 8)) & 0xFFFFFFFF;
            $pde = $this->readPhysical64($pdeAddr);
            if (($pde & 0x1) === 0) {
                $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0));
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Page directory entry not present');
            }
            $isLarge = ($pde & (1 << 7)) !== 0;
            if ($user && (($pde & 0x4) === 0)) {
                $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | 0b100 | 0b1);
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Page directory entry not user accessible');
            }
            if ($isWrite && (($pde & 0x2) === 0)) {
                $err = $this->errorCodeWithFetch(0b10 | ($user ? 0b100 : 0) | 0b1);
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Page directory entry not writable');
            }

            if ($isLarge) {
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

            $pteAddr = (($pde & 0xFFFFFF000) + ($tableIndex * 8)) & 0xFFFFFFFF;
            $pte = $this->readPhysical64($pteAddr);
            if (($pte & 0x1) === 0) {
                $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0));
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Page table entry not present');
            }
            if ($user && (($pte & 0x4) === 0)) {
                $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | 0b100 | 0b1);
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Page table entry not user accessible');
            }
            if ($isWrite && (($pte & 0x2) === 0)) {
                $err = $this->errorCodeWithFetch(0b10 | ($user ? 0b100 : 0) | 0b1);
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Page table entry not writable');
            }

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
            if ($this->shouldInstructionFetch() && $nxe && (($pte >> 63) & 0x1)) {
                $err = 0x01 | ($user ? 0b100 : 0) | 0x10;
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Execute-disable page');
            }

            $phys = ($pte & 0xFFFFFF000) + $offset;
            return $phys & 0xFFFFFFFF;
        }

        $cr3 = $this->readControlRegister(3) & 0xFFFFF000;
        $dirIndex = ($linear >> 22) & 0x3FF;
        $tableIndex = ($linear >> 12) & 0x3FF;
        $offset = $linear & 0xFFF;

        $pdeAddr = ($cr3 + ($dirIndex * 4)) & 0xFFFFFFFF;
        $pde = $this->readPhysical32($pdeAddr);
        if (($pde & 0x1) === 0) {
            $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0));
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, 'Page directory entry not present');
        }
        $is4M = $pse && (($pde & (1 << 7)) !== 0);
        if ($is4M) {
            if ($user && (($pde & 0x4) === 0)) {
                $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | 0b100 | 0b1);
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Page directory entry not user accessible');
            }
            if ($isWrite && (($pde & 0x2) === 0)) {
                $err = $this->errorCodeWithFetch(0b10 | ($user ? 0b100 : 0) | 0b1);
                $this->setCr2($linear);
                throw new FaultException(0x0E, $err, 'Page directory entry not writable');
            }
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
        if ($user && (($pde & 0x4) === 0)) {
            $err = ($isWrite ? 0b10 : 0) | 0b100 | 0b1;
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, 'Page directory entry not user accessible');
        }
        if ($isWrite && (($pde & 0x2) === 0)) {
            $err = 0b10 | ($user ? 0b100 : 0) | 0b1;
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, 'Page directory entry not writable');
        }

        $pteAddr = ($pde & 0xFFFFF000) + ($tableIndex * 4);
        $pte = $this->readPhysical32($pteAddr);
        if (($pte & 0x1) === 0) {
            $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | ($user ? 0b100 : 0));
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, 'Page table entry not present');
        }
        if ($user && (($pte & 0x4) === 0)) {
            $err = $this->errorCodeWithFetch(($isWrite ? 0b10 : 0) | 0b100 | 0b1);
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, 'Page table entry not user accessible');
        }
        if ($isWrite && (($pte & 0x2) === 0)) {
            $err = $this->errorCodeWithFetch(0b10 | ($user ? 0b100 : 0) | 0b1);
            $this->setCr2($linear);
            throw new FaultException(0x0E, $err, 'Page table entry not writable');
        }

        // Set accessed/dirty bits.
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

        $phys = ($pte & 0xFFFFF000) + $offset;
        return $phys & 0xFFFFFFFF;
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
            $ldtr = $this->runtime->runtimeOption()->context()->ldtr();
            $base = $ldtr['base'] ?? 0;
            $limit = $ldtr['limit'] ?? 0;
            if (($ldtr['selector'] ?? 0) === 0) {
                return null;
            }
        } else {
            $gdtr = $this->runtime->runtimeOption()->context()->gdtr();
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

        return [
            'base' => $baseAddr & 0xFFFFFFFF,
            'limit' => $fullLimit & 0xFFFFFFFF,
            'present' => $present,
        ];
    }
}
