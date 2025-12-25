<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use FFI;

final class RustFfiContext
{
    private ?FFI $ffi = null;
    private ?string $libPath;

    public function __construct(?string $libPath = null)
    {
        $this->libPath = $libPath;
    }

    public function ffi(): FFI
    {
        if ($this->ffi === null) {
            $this->ffi = FFI::cdef($this->headerDefinitions(), $this->resolveLibraryPath());
        }
        return $this->ffi;
    }

    public function setLibraryPath(string $path): void
    {
        if ($this->ffi !== null) {
            throw new \RuntimeException('Cannot change library path after FFI initialization.');
        }
        $this->libPath = $path;
    }

    private function resolveLibraryPath(): string
    {
        $path = $this->libPath;
        if ($path === null) {
            $basePath = dirname(__DIR__, 2);
            $os = PHP_OS_FAMILY;

            if ($os === 'Darwin') {
                $path = $basePath . '/rust/target/release/libphp_machine_emulator_native.dylib';
            } elseif ($os === 'Windows') {
                $path = $basePath . '/rust/target/release/php_machine_emulator_native.dll';
            } else {
                $path = $basePath . '/rust/target/release/libphp_machine_emulator_native.so';
            }
        }

        if (!file_exists($path)) {
            throw new \RuntimeException(
                "Rust native library not found at: " . $path .
                "\nPlease run: cargo build --release"
            );
        }

        $this->libPath = $path;
        return $path;
    }

    private function headerDefinitions(): string
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
int64_t memory_accessor_read_control_register(const void* accessor, size_t index);
void memory_accessor_write_control_register(void* accessor, size_t index, int64_t value);

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
}
