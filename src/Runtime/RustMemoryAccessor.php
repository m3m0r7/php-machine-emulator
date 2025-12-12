<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use FFI;
use PHPMachineEmulator\Collection\MemoryAccessorObserverCollectionInterface;
use PHPMachineEmulator\Exception\FaultException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Stream\RustMemoryStream;

/**
 * Rust-backed high-performance memory accessor implementation.
 *
 * This class wraps the Rust MemoryAccessor implementation via FFI for
 * significantly improved performance in register and flag operations.
 */
class RustMemoryAccessor implements MemoryAccessorInterface
{
    private static ?FFI $ffi = null;

    /** @var FFI\CData Pointer to the Rust MemoryAccessor */
    private FFI\CData $handle;

    /**
     * Initialize the FFI interface.
     */
    private static function initFFI(): void
    {
        if (self::$ffi !== null) {
            return;
        }

        // Use the same FFI instance as RustMemoryStream
        self::$ffi = RustMemoryStream::getFFI();

        // Add MemoryAccessor function definitions
        $basePath = dirname(__DIR__, 2);
        $os = PHP_OS_FAMILY;

        if ($os === 'Darwin') {
            $libPath = $basePath . '/rust/target/release/libphp_machine_emulator_native.dylib';
        } elseif ($os === 'Windows') {
            $libPath = $basePath . '/rust/target/release/php_machine_emulator_native.dll';
        } else {
            $libPath = $basePath . '/rust/target/release/libphp_machine_emulator_native.so';
        }

        self::$ffi = FFI::cdef(
            self::getHeaderDefinitions(),
            $libPath
        );
    }

    /**
     * Get FFI header definitions.
     */
    private static function getHeaderDefinitions(): string
    {
        return <<<'C'
// MemoryStream functions (needed for handle)
void* memory_stream_new(size_t size, size_t physical_max_memory_size, size_t swap_size);
void memory_stream_free(void* stream);
size_t memory_stream_logical_max_memory_size(const void* stream);
size_t memory_stream_physical_max_memory_size(const void* stream);
size_t memory_stream_swap_size(const void* stream);
size_t memory_stream_size(const void* stream);
bool memory_stream_ensure_capacity(void* stream, size_t required_offset);
size_t memory_stream_offset(const void* stream);
bool memory_stream_set_offset(void* stream, size_t new_offset);
bool memory_stream_is_eof(const void* stream);
uint8_t memory_stream_byte(void* stream);
int8_t memory_stream_signed_byte(void* stream);
uint16_t memory_stream_short(void* stream);
uint32_t memory_stream_dword(void* stream);
uint64_t memory_stream_qword(void* stream);
size_t memory_stream_read(void* stream, uint8_t* buffer, size_t length);
void memory_stream_write(void* stream, const uint8_t* buffer, size_t length);
void memory_stream_write_byte(void* stream, uint8_t value);
void memory_stream_write_short(void* stream, uint16_t value);
void memory_stream_write_dword(void* stream, uint32_t value);
void memory_stream_write_qword(void* stream, uint64_t value);
uint8_t memory_stream_read_byte_at(const void* stream, size_t address);
void memory_stream_write_byte_at(void* stream, size_t address, uint8_t value);
uint16_t memory_stream_read_short_at(const void* stream, size_t address);
void memory_stream_write_short_at(void* stream, size_t address, uint16_t value);
uint32_t memory_stream_read_dword_at(const void* stream, size_t address);
void memory_stream_write_dword_at(void* stream, size_t address, uint32_t value);
uint64_t memory_stream_read_qword_at(const void* stream, size_t address);
void memory_stream_write_qword_at(void* stream, size_t address, uint64_t value);
void memory_stream_copy_internal(void* stream, size_t src_offset, size_t dest_offset, size_t size);
void memory_stream_copy_from_external(void* stream, const uint8_t* src, size_t src_len, size_t dest_offset);

// MemoryAccessor functions
void* memory_accessor_new(void* memory);
void memory_accessor_free(void* accessor);
bool memory_accessor_allocate(void* accessor, size_t address, size_t size, bool safe);
int64_t memory_accessor_fetch(const void* accessor, size_t address);
int64_t memory_accessor_fetch_by_size(const void* accessor, size_t address, uint32_t size);
int64_t memory_accessor_try_to_fetch(const void* accessor, size_t address);
void memory_accessor_write_16bit(void* accessor, size_t address, int64_t value);
void memory_accessor_write_by_size(void* accessor, size_t address, int64_t value, uint32_t size);
void memory_accessor_write_to_high_bit(void* accessor, size_t address, int64_t value);
void memory_accessor_write_to_low_bit(void* accessor, size_t address, int64_t value);
void memory_accessor_update_flags(void* accessor, int64_t value, uint32_t size);
void memory_accessor_increment(void* accessor, size_t address);
void memory_accessor_decrement(void* accessor, size_t address);
void memory_accessor_add(void* accessor, size_t address, int64_t value);
void memory_accessor_sub(void* accessor, size_t address, int64_t value);

// Flag getters
bool memory_accessor_zero_flag(const void* accessor);
bool memory_accessor_sign_flag(const void* accessor);
bool memory_accessor_overflow_flag(const void* accessor);
bool memory_accessor_carry_flag(const void* accessor);
bool memory_accessor_parity_flag(const void* accessor);
bool memory_accessor_auxiliary_carry_flag(const void* accessor);
bool memory_accessor_direction_flag(const void* accessor);
bool memory_accessor_interrupt_flag(const void* accessor);
bool memory_accessor_instruction_fetch(const void* accessor);

// Flag setters
void memory_accessor_set_zero_flag(void* accessor, bool value);
void memory_accessor_set_sign_flag(void* accessor, bool value);
void memory_accessor_set_overflow_flag(void* accessor, bool value);
void memory_accessor_set_carry_flag(void* accessor, bool value);
void memory_accessor_set_parity_flag(void* accessor, bool value);
void memory_accessor_set_auxiliary_carry_flag(void* accessor, bool value);
void memory_accessor_set_direction_flag(void* accessor, bool value);
void memory_accessor_set_interrupt_flag(void* accessor, bool value);
void memory_accessor_set_instruction_fetch(void* accessor, bool value);

// Control registers
uint32_t memory_accessor_read_control_register(const void* accessor, size_t index);
void memory_accessor_write_control_register(void* accessor, size_t index, uint32_t value);

// EFER
uint64_t memory_accessor_read_efer(const void* accessor);
void memory_accessor_write_efer(void* accessor, uint64_t value);

// Memory operations
uint8_t memory_accessor_read_from_memory(const void* accessor, size_t address);
void memory_accessor_write_to_memory(void* accessor, size_t address, uint8_t value);
uint8_t memory_accessor_read_raw_byte(const void* accessor, size_t address);
void memory_accessor_write_raw_byte(void* accessor, size_t address, uint8_t value);
uint8_t memory_accessor_read_physical_8(const void* accessor, size_t address);
uint16_t memory_accessor_read_physical_16(const void* accessor, size_t address);
uint32_t memory_accessor_read_physical_32(const void* accessor, size_t address);
void memory_accessor_write_physical_32(void* accessor, size_t address, uint32_t value);
uint64_t memory_accessor_read_physical_64(const void* accessor, size_t address);
void memory_accessor_write_physical_64(void* accessor, size_t address, uint64_t value);

// Linear address translation and memory access with paging
void memory_accessor_translate_linear(void* accessor, uint64_t linear, bool is_write, bool is_user, bool paging_enabled, uint64_t linear_mask, uint64_t* result_physical, uint32_t* result_error);
bool memory_accessor_is_mmio_address(size_t address);
void memory_accessor_read_memory_8(void* accessor, uint64_t linear, bool is_user, bool paging_enabled, uint64_t linear_mask, uint8_t* result_value, uint32_t* result_error);
void memory_accessor_read_memory_16(void* accessor, uint64_t linear, bool is_user, bool paging_enabled, uint64_t linear_mask, uint16_t* result_value, uint32_t* result_error);
void memory_accessor_read_memory_32(void* accessor, uint64_t linear, bool is_user, bool paging_enabled, uint64_t linear_mask, uint32_t* result_value, uint32_t* result_error);
void memory_accessor_read_memory_64(void* accessor, uint64_t linear, bool is_user, bool paging_enabled, uint64_t linear_mask, uint64_t* result_value, uint32_t* result_error);
uint32_t memory_accessor_write_memory_8(void* accessor, uint64_t linear, uint8_t value, bool is_user, bool paging_enabled, uint64_t linear_mask);
uint32_t memory_accessor_write_memory_16(void* accessor, uint64_t linear, uint16_t value, bool is_user, bool paging_enabled, uint64_t linear_mask);
uint32_t memory_accessor_write_memory_32(void* accessor, uint64_t linear, uint32_t value, bool is_user, bool paging_enabled, uint64_t linear_mask);
uint32_t memory_accessor_write_memory_64(void* accessor, uint64_t linear, uint64_t value, bool is_user, bool paging_enabled, uint64_t linear_mask);
void memory_accessor_write_physical_16(void* accessor, size_t address, uint16_t value);
C;
    }

    public function __construct(
        protected RuntimeInterface $runtime,
        protected MemoryAccessorObserverCollectionInterface $memoryAccessorObserverCollection
    ) {
        self::initFFI();

        // Get the memory handle from the runtime
        $memory = $runtime->memory();

        if ($memory instanceof RustMemoryStream) {
            $memoryHandle = $memory->getHandle();
        } else {
            throw new \RuntimeException(
                'RustMemoryAccessor requires RustMemoryStream. ' .
                'Please use RustMemoryStream instead of MemoryStream.'
            );
        }

        $this->handle = self::$ffi->memory_accessor_new($memoryHandle);

        if ($this->handle === null) {
            throw new \RuntimeException('Failed to create Rust MemoryAccessor');
        }
    }

    public function __destruct()
    {
        if (isset($this->handle) && self::$ffi !== null) {
            self::$ffi->memory_accessor_free($this->handle);
        }
    }

    /**
     * Check if address is a register address (skip observer processing).
     */
    private function isRegisterAddress(int $address): bool
    {
        return ($address >= 0 && $address <= 13) || ($address >= 16 && $address <= 25);
    }

    /**
     * Process observers after a write operation.
     */
    private function postProcessWhenWrote(int $address, int|null $previousValue, int|null $value): void
    {
        // Skip observer processing for register addresses - massive performance gain
        if ($this->isRegisterAddress($address)) {
            return;
        }

        $wroteValue = ($value ?? 0) & 0xFF;

        foreach ($this->memoryAccessorObserverCollection as $observer) {
            assert($observer instanceof MemoryAccessorObserverInterface);

            // Fast path: check address range before calling shouldMatch
            $range = $observer->addressRange();
            if ($range !== null) {
                if ($address < $range['min'] || $address > $range['max']) {
                    continue;
                }
            }

            if (!$observer->shouldMatch($this->runtime, $address, $previousValue, $wroteValue)) {
                continue;
            }

            $observer->observe(
                $this->runtime,
                $address,
                $previousValue === null ? $previousValue : ($previousValue & 0xFF),
                $wroteValue,
            );
        }
    }

    /**
     * Convert RegisterType to address.
     */
    private function asAddress(int|RegisterType $registerType): int
    {
        if ($registerType instanceof RegisterType) {
            return ($this->runtime->register())::addressBy($registerType);
        }
        return $registerType;
    }

    // ========================================
    // MemoryAccessorInterface implementation
    // ========================================

    public function allocate(int $address, int $size = 1, bool $safe = true): self
    {
        self::$ffi->memory_accessor_allocate($this->handle, $address, $size, $safe);
        return $this;
    }

    public function fetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface
    {
        $address = $this->asAddress($registerType);
        $value = self::$ffi->memory_accessor_fetch($this->handle, $address);

        // Determine stored size
        $isGpr = ($address >= 0 && $address <= 7) || ($address >= 16 && $address <= 24);
        $storedSize = $isGpr ? 64 : 16;

        return MemoryAccessorFetchResult::fromCache($value, $storedSize);
    }

    public function tryToFetch(int|RegisterType $registerType): MemoryAccessorFetchResultInterface|null
    {
        $address = $this->asAddress($registerType);
        $value = self::$ffi->memory_accessor_try_to_fetch($this->handle, $address);

        if ($value === -1) {
            return null;
        }

        $isGpr = ($address >= 0 && $address <= 7) || ($address >= 16 && $address <= 24);
        $storedSize = $isGpr ? 64 : 16;

        return MemoryAccessorFetchResult::fromCache($value, $storedSize);
    }

    public function increment(int|RegisterType $registerType): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_increment($this->handle, $address);
        return $this;
    }

    public function add(int|RegisterType $registerType, int $value): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_add($this->handle, $address, $value);
        return $this;
    }

    public function sub(int|RegisterType $registerType, int $value): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_sub($this->handle, $address, $value);
        return $this;
    }

    public function decrement(int|RegisterType $registerType): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_decrement($this->handle, $address);
        return $this;
    }

    public function write16Bit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        $previousValue = self::$ffi->memory_accessor_fetch($this->handle, $address);
        self::$ffi->memory_accessor_write_16bit($this->handle, $address, $value ?? 0);
        $this->postProcessWhenWrote($address, $previousValue, $value);
        return $this;
    }

    public function writeBySize(int|RegisterType $registerType, int|null $value, int $size = 64): self
    {
        $address = $this->asAddress($registerType);
        $previousValue = self::$ffi->memory_accessor_fetch($this->handle, $address);
        self::$ffi->memory_accessor_write_by_size($this->handle, $address, $value ?? 0, $size);
        $this->postProcessWhenWrote($address, $previousValue, $value);
        return $this;
    }

    public function writeToHighBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_write_to_high_bit($this->handle, $address, $value ?? 0);
        return $this;
    }

    public function writeToLowBit(int|RegisterType $registerType, int|null $value): self
    {
        $address = $this->asAddress($registerType);
        self::$ffi->memory_accessor_write_to_low_bit($this->handle, $address, $value ?? 0);
        return $this;
    }

    public function updateFlags(int|null $value, int $size = 16): self
    {
        if ($value === null) {
            self::$ffi->memory_accessor_set_zero_flag($this->handle, true);
            self::$ffi->memory_accessor_set_sign_flag($this->handle, false);
            self::$ffi->memory_accessor_set_overflow_flag($this->handle, false);
            self::$ffi->memory_accessor_set_parity_flag($this->handle, true);
            return $this;
        }

        self::$ffi->memory_accessor_update_flags($this->handle, $value, $size);
        return $this;
    }

    public function setCarryFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_carry_flag($this->handle, $which);
        return $this;
    }

    public function pop(int|RegisterType $registerType, int $size = 16): MemoryAccessorFetchResultInterface
    {
        // Stack operations still need PHP-side handling for complex logic
        // This is a simplified version - full implementation would need more work
        $address = $this->asAddress($registerType);

        if ($registerType instanceof RegisterType && $registerType === RegisterType::ESP) {
            $sp = $this->fetch(RegisterType::ESP)->asBytesBySize($size);
            $bytes = intdiv($size, 8);
            $address = $this->stackLinearAddress($sp, $size, false);

            // Read value from stack
            $value = 0;
            for ($i = 0; $i < $bytes; $i++) {
                $value |= self::$ffi->memory_accessor_read_from_memory($this->handle, $address + $i) << ($i * 8);
            }

            // Update SP
            $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
            $newSp = ($sp + $bytes) & $mask;
            $this->writeBySize(RegisterType::ESP, $newSp, $size);

            return new MemoryAccessorFetchResult($value, $size, alreadyDecoded: true);
        }

        $fetchResult = $this->fetch($address)->asBytesBySize();
        $this->writeBySize($address, $fetchResult >> $size);

        return new MemoryAccessorFetchResult(
            $fetchResult & ((1 << $size) - 1)
        );
    }

    public function push(int|RegisterType $registerType, int|null $value, int $size = 16): self
    {
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
        $fetchResult = $this->fetch($address)->asBytesBySize();
        $value = $value & ((1 << $size) - 1);
        $this->writeBySize($address, ($fetchResult << $size) + $value);

        return $this;
    }

    public function readControlRegister(int $index): int
    {
        return self::$ffi->memory_accessor_read_control_register($this->handle, $index);
    }

    public function writeControlRegister(int $index, int $value): void
    {
        $previous = self::$ffi->memory_accessor_read_control_register($this->handle, $index);
        self::$ffi->memory_accessor_write_control_register($this->handle, $index, $value);

        // Mode changes can alter instruction decoding/execution semantics
        if ($index === 0 && $previous !== $value) {
            $this->runtime->architectureProvider()->instructionExecutor()->invalidateCaches();
        }
    }

    public function shouldZeroFlag(): bool
    {
        return self::$ffi->memory_accessor_zero_flag($this->handle);
    }

    public function shouldSignFlag(): bool
    {
        return self::$ffi->memory_accessor_sign_flag($this->handle);
    }

    public function shouldOverflowFlag(): bool
    {
        return self::$ffi->memory_accessor_overflow_flag($this->handle);
    }

    public function shouldCarryFlag(): bool
    {
        return self::$ffi->memory_accessor_carry_flag($this->handle);
    }

    public function shouldParityFlag(): bool
    {
        return self::$ffi->memory_accessor_parity_flag($this->handle);
    }

    public function shouldAuxiliaryCarryFlag(): bool
    {
        return self::$ffi->memory_accessor_auxiliary_carry_flag($this->handle);
    }

    public function shouldDirectionFlag(): bool
    {
        return self::$ffi->memory_accessor_direction_flag($this->handle);
    }

    public function shouldInterruptFlag(): bool
    {
        return self::$ffi->memory_accessor_interrupt_flag($this->handle);
    }

    public function setZeroFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_zero_flag($this->handle, $which);
        return $this;
    }

    public function setSignFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_sign_flag($this->handle, $which);
        return $this;
    }

    public function setOverflowFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_overflow_flag($this->handle, $which);
        return $this;
    }

    public function setParityFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_parity_flag($this->handle, $which);
        return $this;
    }

    public function setAuxiliaryCarryFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_auxiliary_carry_flag($this->handle, $which);
        return $this;
    }

    public function setDirectionFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_direction_flag($this->handle, $which);
        return $this;
    }

    public function setInterruptFlag(bool $which): self
    {
        self::$ffi->memory_accessor_set_interrupt_flag($this->handle, $which);
        return $this;
    }

    public function writeEfer(int $value): void
    {
        self::$ffi->memory_accessor_write_efer($this->handle, $value);
    }

    /**
     * Read EFER value.
     */
    public function readEfer(): int
    {
        return self::$ffi->memory_accessor_read_efer($this->handle);
    }

    /**
     * Write a raw byte to memory.
     */
    public function writeRawByte(int $address, int $value): self
    {
        $previousValue = self::$ffi->memory_accessor_read_raw_byte($this->handle, $address);
        self::$ffi->memory_accessor_write_raw_byte($this->handle, $address, $value & 0xFF);
        $this->postProcessWhenWrote($address, $previousValue, $value);
        return $this;
    }

    /**
     * Read a raw byte from memory.
     */
    public function readRawByte(int $address): ?int
    {
        return self::$ffi->memory_accessor_read_raw_byte($this->handle, $address);
    }

    /**
     * Set instruction fetch flag.
     */
    public function setInstructionFetch(bool $flag): self
    {
        self::$ffi->memory_accessor_set_instruction_fetch($this->handle, $flag);
        return $this;
    }

    /**
     * Get instruction fetch flag.
     */
    public function shouldInstructionFetch(): bool
    {
        return self::$ffi->memory_accessor_instruction_fetch($this->handle);
    }

    /**
     * Get the FFI instance.
     */
    public static function getFFI(): FFI
    {
        self::initFFI();
        return self::$ffi;
    }

    /**
     * Get the Rust MemoryAccessor handle.
     */
    public function getHandle(): FFI\CData
    {
        return $this->handle;
    }

    /**
     * Read 8-bit value from physical memory.
     */
    public function readPhysical8(int $address): int
    {
        return self::$ffi->memory_accessor_read_physical_8($this->handle, $address);
    }

    /**
     * Read 16-bit value from physical memory.
     */
    public function readPhysical16(int $address): int
    {
        return self::$ffi->memory_accessor_read_physical_16($this->handle, $address);
    }

    /**
     * Read 32-bit value from physical memory.
     */
    public function readPhysical32(int $address): int
    {
        return self::$ffi->memory_accessor_read_physical_32($this->handle, $address);
    }

    /**
     * Read 64-bit value from physical memory.
     */
    public function readPhysical64(int $address): int
    {
        return self::$ffi->memory_accessor_read_physical_64($this->handle, $address);
    }

    /**
     * Write 32-bit value to physical memory.
     */
    public function writePhysical32(int $address, int $value): void
    {
        self::$ffi->memory_accessor_write_physical_32($this->handle, $address, $value);
    }

    /**
     * Write 64-bit value to physical memory.
     */
    public function writePhysical64(int $address, int $value): void
    {
        self::$ffi->memory_accessor_write_physical_64($this->handle, $address, $value);
    }

    /**
     * Translate linear address to physical address through paging.
     * Returns [physical_address, error_code].
     * error_code is 0 on success, 0xFFFFFFFF for MMIO, or (vector << 16) | fault_code for page fault.
     */
    public function translateLinear(int $linear, bool $isWrite, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultPhysical = self::$ffi->new('uint64_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_translate_linear(
            $this->handle,
            $linear,
            $isWrite,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultPhysical),
            FFI::addr($resultError)
        );

        return [$resultPhysical->cdata, $resultError->cdata];
    }

    /**
     * Compute stack linear address honoring segment base/limit and cached descriptors.
     */
    private function stackLinearAddress(int $sp, int $size, bool $isWrite = false): int
    {
        $ssSelector = $this->fetch(RegisterType::SS)->asByte();
        $mask = $size === 32 ? 0xFFFFFFFF : 0xFFFF;
        $linearMask = $this->runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        $isUser = $this->runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $this->runtime->context()->cpu()->isPagingEnabled();

        if ($this->runtime->context()->cpu()->isProtectedMode()) {
            $descriptor = $this->segmentDescriptor($ssSelector);
            if ($descriptor !== null && ($descriptor['present'] ?? false)) {
                $cpl = $this->runtime->context()->cpu()->cpl();
                $rpl = $ssSelector & 0x3;
                $dpl = $descriptor['dpl'] ?? 0;
                $isWritable = ($descriptor['type'] & 0x2) !== 0;
                $isExecutable = $descriptor['executable'] ?? false;
                if ($isExecutable || !$isWritable || $dpl !== $cpl || $rpl !== $cpl) {
                    $linear = ($sp & $mask) & $linearMask;
                } else {
                    if (($sp & $mask) > $descriptor['limit']) {
                        throw new FaultException(0x0C, $ssSelector, 'Stack limit exceeded');
                    }
                    $linear = ($descriptor['base'] + ($sp & $mask)) & $linearMask;
                }
                [$physical, $error] = $this->translateLinear($linear, $isWrite, $isUser, $pagingEnabled, $linearMask);
                return $error === 0 ? $physical : $linear;
            }
            $linear = ($sp & $mask) & $linearMask;
            [$physical, $error] = $this->translateLinear($linear, $isWrite, $isUser, $pagingEnabled, $linearMask);
            return $error === 0 ? $physical : $linear;
        }

        $cached = $this->runtime->context()->cpu()->getCachedSegmentDescriptor(RegisterType::SS);
        if ($cached !== null) {
            $effSp = $sp & $mask;
            $limit = $cached['limit'] ?? $mask;
            if ($effSp > $limit) {
                $effSp = $sp & 0xFFFF;
            }
            $base = $cached['base'] ?? (($ssSelector << 4) & 0xFFFFF);
            $linear = ($base + $effSp) & $linearMask;
        } else {
            $linear = ((($ssSelector << 4) & 0xFFFFF) + ($sp & $mask)) & $linearMask;
        }

        [$physical, $error] = $this->translateLinear($linear, $isWrite, $isUser, $pagingEnabled, $linearMask);
        return $error === 0 ? $physical : $linear;
    }

    /**
     * Read a segment descriptor from GDT/LDT.
     */
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

        $linearMask = $this->runtime->context()->cpu()->isA20Enabled() ? 0xFFFFFFFF : 0xFFFFF;
        $isUser = $this->runtime->context()->cpu()->cpl() === 3;
        $pagingEnabled = $this->runtime->context()->cpu()->isPagingEnabled();

        // Fast path: fetch full 64-bit descriptor in one FFI call.
        $desc64 = self::$ffi->new('uint64_t');
        $descErr = self::$ffi->new('uint32_t');
        self::$ffi->memory_accessor_read_memory_64(
            $this->handle,
            $offset,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($desc64),
            FFI::addr($descErr)
        );

        if ($descErr->cdata === 0) {
            $val = $desc64->cdata;
            $b0 = $val & 0xFF;
            $b1 = ($val >> 8) & 0xFF;
            $b2 = ($val >> 16) & 0xFF;
            $b3 = ($val >> 24) & 0xFF;
            $b4 = ($val >> 32) & 0xFF;
            $b5 = ($val >> 40) & 0xFF;
            $b6 = ($val >> 48) & 0xFF;
            $b7 = ($val >> 56) & 0xFF;
        } else {
            // Fallback: byte-by-byte read (should rarely happen).
            $b0 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset);
            $b1 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 1);
            $b2 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 2);
            $b3 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 3);
            $b4 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 4);
            $b5 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 5);
            $b6 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 6);
            $b7 = self::$ffi->memory_accessor_read_from_memory($this->handle, $offset + 7);
        }

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

    /**
     * Check if address is in MMIO range.
     */
    public static function isMmioAddress(int $address): bool
    {
        self::initFFI();
        return self::$ffi->memory_accessor_is_mmio_address($address);
    }

    /**
     * Read 8-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory8(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = self::$ffi->new('uint8_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_8(
            $this->handle,
            $linear,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        return [$resultValue->cdata, $resultError->cdata];
    }

    /**
     * Read 16-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory16(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = self::$ffi->new('uint16_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_16(
            $this->handle,
            $linear,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        return [$resultValue->cdata, $resultError->cdata];
    }

    /**
     * Read 32-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory32(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = self::$ffi->new('uint32_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_32(
            $this->handle,
            $linear,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        return [$resultValue->cdata, $resultError->cdata];
    }

    /**
     * Read 64-bit memory with linear address translation.
     * Returns [value, error_code].
     */
    public function readMemory64(int $linear, bool $isUser, bool $pagingEnabled, int $linearMask): array
    {
        $resultValue = self::$ffi->new('uint64_t');
        $resultError = self::$ffi->new('uint32_t');

        self::$ffi->memory_accessor_read_memory_64(
            $this->handle,
            $linear,
            $isUser,
            $pagingEnabled,
            $linearMask,
            FFI::addr($resultValue),
            FFI::addr($resultError)
        );

        return [$resultValue->cdata, $resultError->cdata];
    }

    /**
     * Write 8-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory8(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        return self::$ffi->memory_accessor_write_memory_8(
            $this->handle,
            $linear,
            $value & 0xFF,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
    }

    /**
     * Write 16-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory16(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        return self::$ffi->memory_accessor_write_memory_16(
            $this->handle,
            $linear,
            $value & 0xFFFF,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
    }

    /**
     * Write 32-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory32(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        return self::$ffi->memory_accessor_write_memory_32(
            $this->handle,
            $linear,
            $value & 0xFFFFFFFF,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
    }

    /**
     * Write 64-bit memory with linear address translation.
     * Returns error_code (0 on success, 0xFFFFFFFF for MMIO).
     */
    public function writeMemory64(int $linear, int $value, bool $isUser, bool $pagingEnabled, int $linearMask): int
    {
        return self::$ffi->memory_accessor_write_memory_64(
            $this->handle,
            $linear,
            $value,
            $isUser,
            $pagingEnabled,
            $linearMask
        );
    }

    /**
     * Write 16-bit value to physical memory.
     */
    public function writePhysical16(int $address, int $value): void
    {
        self::$ffi->memory_accessor_write_physical_16($this->handle, $address, $value & 0xFFFF);
    }
}
