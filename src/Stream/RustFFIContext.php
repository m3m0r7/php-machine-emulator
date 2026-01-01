<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use FFI;

/**
 * @method \FFI\CData memory_stream_new(int $size, int $physical_max_memory_size, int $swap_size)
 * @method void memory_stream_free(\FFI\CData $stream)
 * @method int memory_stream_logical_max_memory_size(\FFI\CData $stream)
 * @method int memory_stream_physical_max_memory_size(\FFI\CData $stream)
 * @method int memory_stream_swap_size(\FFI\CData $stream)
 * @method int memory_stream_size(\FFI\CData $stream)
 * @method bool memory_stream_ensure_capacity(\FFI\CData $stream, int $required_offset)
 * @method int memory_stream_offset(\FFI\CData $stream)
 * @method bool memory_stream_set_offset(\FFI\CData $stream, int $new_offset)
 * @method bool memory_stream_is_eof(\FFI\CData $stream)
 * @method int memory_stream_byte(\FFI\CData $stream)
 * @method int memory_stream_signed_byte(\FFI\CData $stream)
 * @method int memory_stream_short(\FFI\CData $stream)
 * @method int memory_stream_dword(\FFI\CData $stream)
 * @method int memory_stream_qword(\FFI\CData $stream)
 * @method int memory_stream_read(\FFI\CData $stream, \FFI\CData $buffer, int $length)
 * @method void memory_stream_write(\FFI\CData $stream, \FFI\CData $buffer, int $length)
 * @method void memory_stream_write_byte(\FFI\CData $stream, int $value)
 * @method void memory_stream_write_short(\FFI\CData $stream, int $value)
 * @method void memory_stream_write_dword(\FFI\CData $stream, int $value)
 * @method void memory_stream_write_qword(\FFI\CData $stream, int $value)
 * @method int memory_stream_read_byte_at(\FFI\CData $stream, int $address)
 * @method void memory_stream_write_byte_at(\FFI\CData $stream, int $address, int $value)
 * @method int memory_stream_read_short_at(\FFI\CData $stream, int $address)
 * @method void memory_stream_write_short_at(\FFI\CData $stream, int $address, int $value)
 * @method int memory_stream_read_dword_at(\FFI\CData $stream, int $address)
 * @method void memory_stream_write_dword_at(\FFI\CData $stream, int $address, int $value)
 * @method int memory_stream_read_qword_at(\FFI\CData $stream, int $address)
 * @method void memory_stream_write_qword_at(\FFI\CData $stream, int $address, int $value)
 * @method void memory_stream_copy_internal(\FFI\CData $stream, int $src_offset, int $dest_offset, int $size)
 * @method void memory_stream_copy_from_external(\FFI\CData $stream, \FFI\CData $src, int $src_len, int $dest_offset)
 * @method \FFI\CData memory_accessor_new(\FFI\CData $memory)
 * @method void memory_accessor_free(\FFI\CData $accessor)
 * @method bool memory_accessor_allocate(\FFI\CData $accessor, int $address, int $size, bool $safe)
 * @method int memory_accessor_fetch(\FFI\CData $accessor, int $address)
 * @method int memory_accessor_fetch_by_size(\FFI\CData $accessor, int $address, int $size)
 * @method int memory_accessor_try_to_fetch(\FFI\CData $accessor, int $address)
 * @method void memory_accessor_write_16bit(\FFI\CData $accessor, int $address, int $value)
 * @method void memory_accessor_write_by_size(\FFI\CData $accessor, int $address, int $value, int $size)
 * @method void memory_accessor_write_to_high_bit(\FFI\CData $accessor, int $address, int $value)
 * @method void memory_accessor_write_to_low_bit(\FFI\CData $accessor, int $address, int $value)
 * @method void memory_accessor_update_flags(\FFI\CData $accessor, int $value, int $size)
 * @method void memory_accessor_increment(\FFI\CData $accessor, int $address)
 * @method void memory_accessor_decrement(\FFI\CData $accessor, int $address)
 * @method void memory_accessor_add(\FFI\CData $accessor, int $address, int $value)
 * @method void memory_accessor_sub(\FFI\CData $accessor, int $address, int $value)
 * @method bool memory_accessor_zero_flag(\FFI\CData $accessor)
 * @method bool memory_accessor_sign_flag(\FFI\CData $accessor)
 * @method bool memory_accessor_overflow_flag(\FFI\CData $accessor)
 * @method bool memory_accessor_carry_flag(\FFI\CData $accessor)
 * @method bool memory_accessor_parity_flag(\FFI\CData $accessor)
 * @method bool memory_accessor_auxiliary_carry_flag(\FFI\CData $accessor)
 * @method bool memory_accessor_direction_flag(\FFI\CData $accessor)
 * @method bool memory_accessor_interrupt_flag(\FFI\CData $accessor)
 * @method bool memory_accessor_instruction_fetch(\FFI\CData $accessor)
 * @method void memory_accessor_set_zero_flag(\FFI\CData $accessor, bool $value)
 * @method void memory_accessor_set_sign_flag(\FFI\CData $accessor, bool $value)
 * @method void memory_accessor_set_overflow_flag(\FFI\CData $accessor, bool $value)
 * @method void memory_accessor_set_carry_flag(\FFI\CData $accessor, bool $value)
 * @method void memory_accessor_set_parity_flag(\FFI\CData $accessor, bool $value)
 * @method void memory_accessor_set_auxiliary_carry_flag(\FFI\CData $accessor, bool $value)
 * @method void memory_accessor_set_direction_flag(\FFI\CData $accessor, bool $value)
 * @method void memory_accessor_set_interrupt_flag(\FFI\CData $accessor, bool $value)
 * @method void memory_accessor_set_instruction_fetch(\FFI\CData $accessor, bool $value)
 * @method int memory_accessor_read_control_register(\FFI\CData $accessor, int $index)
 * @method void memory_accessor_write_control_register(\FFI\CData $accessor, int $index, int $value)
 * @method int memory_accessor_read_efer(\FFI\CData $accessor)
 * @method void memory_accessor_write_efer(\FFI\CData $accessor, int $value)
 * @method int memory_accessor_read_from_memory(\FFI\CData $accessor, int $address)
 * @method void memory_accessor_write_to_memory(\FFI\CData $accessor, int $address, int $value)
 * @method int memory_accessor_read_raw_byte(\FFI\CData $accessor, int $address)
 * @method void memory_accessor_write_raw_byte(\FFI\CData $accessor, int $address, int $value)
 * @method int memory_accessor_read_physical_8(\FFI\CData $accessor, int $address)
 * @method int memory_accessor_read_physical_16(\FFI\CData $accessor, int $address)
 * @method int memory_accessor_read_physical_32(\FFI\CData $accessor, int $address)
 * @method void memory_accessor_write_physical_32(\FFI\CData $accessor, int $address, int $value)
 * @method int memory_accessor_read_physical_64(\FFI\CData $accessor, int $address)
 * @method void memory_accessor_write_physical_64(\FFI\CData $accessor, int $address, int $value)
 * @method void memory_accessor_translate_linear(\FFI\CData $accessor, int $linear, bool $is_write, bool $is_user, bool $paging_enabled, int $linear_mask, \FFI\CData $result_physical, \FFI\CData $result_error)
 * @method bool memory_accessor_is_mmio_address(int $address)
 * @method void memory_accessor_read_memory_8(\FFI\CData $accessor, int $linear, bool $is_user, bool $paging_enabled, int $linear_mask, \FFI\CData $result_value, \FFI\CData $result_error)
 * @method void memory_accessor_read_memory_16(\FFI\CData $accessor, int $linear, bool $is_user, bool $paging_enabled, int $linear_mask, \FFI\CData $result_value, \FFI\CData $result_error)
 * @method void memory_accessor_read_memory_32(\FFI\CData $accessor, int $linear, bool $is_user, bool $paging_enabled, int $linear_mask, \FFI\CData $result_value, \FFI\CData $result_error)
 * @method void memory_accessor_read_memory_64(\FFI\CData $accessor, int $linear, bool $is_user, bool $paging_enabled, int $linear_mask, \FFI\CData $result_value, \FFI\CData $result_error)
 * @method int memory_accessor_write_memory_8(\FFI\CData $accessor, int $linear, int $value, bool $is_user, bool $paging_enabled, int $linear_mask)
 * @method int memory_accessor_write_memory_16(\FFI\CData $accessor, int $linear, int $value, bool $is_user, bool $paging_enabled, int $linear_mask)
 * @method int memory_accessor_write_memory_32(\FFI\CData $accessor, int $linear, int $value, bool $is_user, bool $paging_enabled, int $linear_mask)
 * @method int memory_accessor_write_memory_64(\FFI\CData $accessor, int $linear, int $value, bool $is_user, bool $paging_enabled, int $linear_mask)
 * @method void memory_accessor_write_physical_16(\FFI\CData $accessor, int $address, int $value)
 * @method bool uint64_from_decimal(string $value, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method int uint64_to_i64(int $low, int $high)
 * @method bool uint64_to_decimal(int $low, int $high, \FFI\CData $buffer, int $buffer_len)
 * @method void uint64_add(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method void uint64_sub(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method void uint64_mul(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method bool uint64_div(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method bool uint64_mod(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method void uint64_and(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method void uint64_or(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method void uint64_xor(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method void uint64_not(int $low, int $high, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method void uint64_shl(int $low, int $high, int $bits, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method void uint64_shr(int $low, int $high, int $bits, \FFI\CData $out_low, \FFI\CData $out_high)
 * @method bool uint64_eq(int $left_low, int $left_high, int $right_low, int $right_high)
 * @method bool uint64_lt(int $left_low, int $left_high, int $right_low, int $right_high)
 * @method bool uint64_lte(int $left_low, int $left_high, int $right_low, int $right_high)
 * @method bool uint64_gt(int $left_low, int $left_high, int $right_low, int $right_high)
 * @method bool uint64_gte(int $left_low, int $left_high, int $right_low, int $right_high)
 * @method bool uint64_is_zero(int $low, int $high)
 * @method bool uint64_is_negative_signed(int $low, int $high)
 * @method void uint64_mul_full(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low_low, \FFI\CData $out_low_high, \FFI\CData $out_high_low, \FFI\CData $out_high_high)
 * @method void uint64_mul_full_signed(int $left_low, int $left_high, int $right_low, int $right_high, \FFI\CData $out_low_low, \FFI\CData $out_low_high, \FFI\CData $out_high_low, \FFI\CData $out_high_high)
 * @method bool uint128_divmod_u64(int $low_low, int $low_high, int $high_low, int $high_high, int $div_low, int $div_high, \FFI\CData $out_q_low, \FFI\CData $out_q_high, \FFI\CData $out_r_low, \FFI\CData $out_r_high)
 * @method bool int128_divmod_i64(int $low_low, int $low_high, int $high_low, int $high_high, int $divisor, \FFI\CData $out_q, \FFI\CData $out_r)
 */
final class RustFFIContext
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

    public function __call(string $name, array $arguments): mixed
    {
        return $this->ffi()->{$name}(...$arguments);
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

// UInt64 helpers
bool uint64_from_decimal(const char* value, uint32_t* out_low, uint32_t* out_high);
int64_t uint64_to_i64(uint32_t low, uint32_t high);
bool uint64_to_decimal(uint32_t low, uint32_t high, char* buffer, size_t buffer_len);
void uint64_add(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low, uint32_t* out_high);
void uint64_sub(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low, uint32_t* out_high);
void uint64_mul(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low, uint32_t* out_high);
bool uint64_div(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low, uint32_t* out_high);
bool uint64_mod(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low, uint32_t* out_high);
void uint64_and(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low, uint32_t* out_high);
void uint64_or(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low, uint32_t* out_high);
void uint64_xor(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low, uint32_t* out_high);
void uint64_not(uint32_t low, uint32_t high, uint32_t* out_low, uint32_t* out_high);
void uint64_shl(uint32_t low, uint32_t high, uint32_t bits, uint32_t* out_low, uint32_t* out_high);
void uint64_shr(uint32_t low, uint32_t high, uint32_t bits, uint32_t* out_low, uint32_t* out_high);
bool uint64_eq(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high);
bool uint64_lt(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high);
bool uint64_lte(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high);
bool uint64_gt(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high);
bool uint64_gte(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high);
bool uint64_is_zero(uint32_t low, uint32_t high);
bool uint64_is_negative_signed(uint32_t low, uint32_t high);
void uint64_mul_full(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low_low, uint32_t* out_low_high, uint32_t* out_high_low, uint32_t* out_high_high);
void uint64_mul_full_signed(uint32_t left_low, uint32_t left_high, uint32_t right_low, uint32_t right_high, uint32_t* out_low_low, uint32_t* out_low_high, uint32_t* out_high_low, uint32_t* out_high_high);
bool uint128_divmod_u64(uint32_t low_low, uint32_t low_high, uint32_t high_low, uint32_t high_high, uint32_t div_low, uint32_t div_high, uint32_t* out_q_low, uint32_t* out_q_high, uint32_t* out_r_low, uint32_t* out_r_high);
bool int128_divmod_i64(uint32_t low_low, uint32_t low_high, uint32_t high_low, uint32_t high_high, int64_t divisor, int64_t* out_q, int64_t* out_r);
C;
    }
}
