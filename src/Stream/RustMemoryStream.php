<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use FFI;
use PHPMachineEmulator\Instruction\Stream\ModRegRM;
use PHPMachineEmulator\Instruction\Stream\ModRegRMInterface;
use PHPMachineEmulator\Instruction\Stream\SIB;
use PHPMachineEmulator\Instruction\Stream\SIBInterface;

/**
 * Rust-backed high-performance memory stream implementation.
 *
 * This class wraps the Rust MemoryStream implementation via FFI for
 * significantly improved performance in memory operations.
 */
class RustMemoryStream implements MemoryStreamInterface
{
    private static ?FFI $ffi = null;
    private static ?string $libPath = null;

    /** @var FFI\CData Pointer to the Rust MemoryStream */
    private FFI\CData $handle;

    /**
     * Initialize the FFI interface.
     */
    private static function initFFI(): void
    {
        if (self::$ffi !== null) {
            return;
        }

        // Determine library path
        if (self::$libPath === null) {
            $basePath = dirname(__DIR__, 2);
            $os = PHP_OS_FAMILY;

            if ($os === 'Darwin') {
                self::$libPath = $basePath . '/rust/target/release/libphp_machine_emulator_native.dylib';
            } elseif ($os === 'Windows') {
                self::$libPath = $basePath . '/rust/target/release/php_machine_emulator_native.dll';
            } else {
                self::$libPath = $basePath . '/rust/target/release/libphp_machine_emulator_native.so';
            }
        }

        if (!file_exists(self::$libPath)) {
            throw new \RuntimeException(
                "Rust native library not found at: " . self::$libPath .
                "\nPlease run: cargo build --release"
            );
        }

        self::$ffi = FFI::cdef(
            self::getHeaderDefinitions(),
            self::$libPath
        );
    }

    /**
     * Get FFI header definitions.
     */
    private static function getHeaderDefinitions(): string
    {
        return <<<'C'
// MemoryStream functions
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
const uint8_t* memory_stream_as_ptr(const void* stream);
uint8_t* memory_stream_as_mut_ptr(void* stream);
C;
    }

    /**
     * Set the library path manually (for testing or custom builds).
     */
    public static function setLibraryPath(string $path): void
    {
        self::$libPath = $path;
        self::$ffi = null; // Reset FFI to reload with new path
    }

    /**
     * Get the FFI instance (for RustMemoryAccessor).
     */
    public static function getFFI(): FFI
    {
        self::initFFI();
        return self::$ffi;
    }

    /**
     * @param int $size Initial memory size (default 1MB)
     * @param int $physicalMaxMemorySize Maximum physical memory size (default 16MB)
     * @param int $swapSize Swap size for overflow (default 256MB)
     */
    public function __construct(
        private int $size = 0x100000,
        private int $physicalMaxMemorySize = 0x1000000,
        private int $swapSize = 0x10000000
    ) {
        self::initFFI();

        $this->handle = self::$ffi->memory_stream_new(
            $size,
            $physicalMaxMemorySize,
            $swapSize
        );

        if ($this->handle === null) {
            throw new \RuntimeException('Failed to create Rust MemoryStream');
        }
    }

    public function __destruct()
    {
        if (isset($this->handle) && self::$ffi !== null) {
            self::$ffi->memory_stream_free($this->handle);
        }
    }

    /**
     * Get the raw handle (for RustMemoryAccessor).
     */
    public function getHandle(): FFI\CData
    {
        return $this->handle;
    }

    // ========================================
    // MemoryStreamInterface implementation
    // ========================================

    public function ensureCapacity(int $requiredOffset): bool
    {
        return self::$ffi->memory_stream_ensure_capacity($this->handle, $requiredOffset);
    }

    public function size(): int
    {
        return self::$ffi->memory_stream_size($this->handle);
    }

    public function logicalMaxMemorySize(): int
    {
        return self::$ffi->memory_stream_logical_max_memory_size($this->handle);
    }

    public function physicalMaxMemorySize(): int
    {
        return self::$ffi->memory_stream_physical_max_memory_size($this->handle);
    }

    public function swapSize(): int
    {
        return self::$ffi->memory_stream_swap_size($this->handle);
    }

    public function byteAsSIB(): SIBInterface
    {
        return SIB::fromByte($this->byte());
    }

    public function byteAsModRegRM(): ModRegRMInterface
    {
        return ModRegRM::fromByte($this->byte());
    }

    public function modRegRM(int $byte): ModRegRMInterface
    {
        return ModRegRM::fromByte($byte);
    }

    // ========================================
    // StreamReaderInterface implementation
    // ========================================

    public function offset(): int
    {
        return self::$ffi->memory_stream_offset($this->handle);
    }

    public function setOffset(int $newOffset): self
    {
        if (!self::$ffi->memory_stream_set_offset($this->handle, $newOffset)) {
            throw new \RuntimeException(sprintf(
                'Cannot set offset beyond bounds: offset=0x%X',
                $newOffset
            ));
        }
        return $this;
    }

    public function isEOF(): bool
    {
        return self::$ffi->memory_stream_is_eof($this->handle);
    }

    public function char(): string
    {
        return chr(self::$ffi->memory_stream_byte($this->handle));
    }

    public function byte(): int
    {
        return self::$ffi->memory_stream_byte($this->handle);
    }

    public function signedByte(): int
    {
        return self::$ffi->memory_stream_signed_byte($this->handle);
    }

    public function short(): int
    {
        return self::$ffi->memory_stream_short($this->handle);
    }

    public function signedShort(): int
    {
        $value = $this->short();
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function dword(): int
    {
        return self::$ffi->memory_stream_dword($this->handle);
    }

    public function signedDword(): int
    {
        $value = $this->dword();
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function qword(): int
    {
        return self::$ffi->memory_stream_qword($this->handle);
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $buffer = self::$ffi->new("uint8_t[$length]");
        self::$ffi->memory_stream_read($this->handle, $buffer, $length);

        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= chr($buffer[$i]);
        }

        return $result;
    }

    // ========================================
    // StreamWriterInterface implementation
    // ========================================

    public function write(string $value): self
    {
        $len = strlen($value);
        if ($len === 0) {
            return $this;
        }

        $buffer = self::$ffi->new("uint8_t[$len]");
        for ($i = 0; $i < $len; $i++) {
            $buffer[$i] = ord($value[$i]);
        }

        self::$ffi->memory_stream_write($this->handle, $buffer, $len);

        return $this;
    }

    public function writeByte(int $value): void
    {
        self::$ffi->memory_stream_write_byte($this->handle, $value & 0xFF);
    }

    public function writeShort(int $value): void
    {
        self::$ffi->memory_stream_write_short($this->handle, $value & 0xFFFF);
    }

    public function writeDword(int $value): void
    {
        self::$ffi->memory_stream_write_dword($this->handle, $value & 0xFFFFFFFF);
    }

    public function writeQword(int $value): void
    {
        self::$ffi->memory_stream_write_qword($this->handle, $value);
    }

    // ========================================
    // Additional methods for direct access
    // ========================================

    /**
     * Read a byte at a specific address without changing offset.
     */
    public function readByteAt(int $address): int
    {
        return self::$ffi->memory_stream_read_byte_at($this->handle, $address);
    }

    /**
     * Write a byte at a specific address without changing offset.
     */
    public function writeByteAt(int $address, int $value): void
    {
        self::$ffi->memory_stream_write_byte_at($this->handle, $address, $value & 0xFF);
    }

    /**
     * Read a 16-bit value at a specific address.
     */
    public function readShortAt(int $address): int
    {
        return self::$ffi->memory_stream_read_short_at($this->handle, $address);
    }

    /**
     * Write a 16-bit value at a specific address.
     */
    public function writeShortAt(int $address, int $value): void
    {
        self::$ffi->memory_stream_write_short_at($this->handle, $address, $value & 0xFFFF);
    }

    /**
     * Read a 32-bit value at a specific address.
     */
    public function readDwordAt(int $address): int
    {
        return self::$ffi->memory_stream_read_dword_at($this->handle, $address);
    }

    /**
     * Write a 32-bit value at a specific address.
     */
    public function writeDwordAt(int $address, int $value): void
    {
        self::$ffi->memory_stream_write_dword_at($this->handle, $address, $value & 0xFFFFFFFF);
    }

    /**
     * Read a 64-bit value at a specific address.
     */
    public function readQwordAt(int $address): int
    {
        return self::$ffi->memory_stream_read_qword_at($this->handle, $address);
    }

    /**
     * Write a 64-bit value at a specific address.
     */
    public function writeQwordAt(int $address, int $value): void
    {
        self::$ffi->memory_stream_write_qword_at($this->handle, $address, $value);
    }

    // ========================================
    // StreamIsCopyableInterface implementation
    // ========================================

    public function copy(StreamReaderInterface $source, int $sourceOffset, int $destOffset, int $size): void
    {
        // If source is also a RustMemoryStream, use internal copy
        if ($source === $this) {
            self::$ffi->memory_stream_copy_internal($this->handle, $sourceOffset, $destOffset, $size);
            return;
        }

        // Otherwise, read from source and copy to this stream
        $originalSourceOffset = $source->offset();
        $source->setOffset($sourceOffset);
        $data = $source->read($size);
        $source->setOffset($originalSourceOffset);

        $len = strlen($data);
        if ($len > 0) {
            $buffer = self::$ffi->new("uint8_t[$len]");
            for ($i = 0; $i < $len; $i++) {
                $buffer[$i] = ord($data[$i]);
            }
            self::$ffi->memory_stream_copy_from_external($this->handle, $buffer, $len, $destOffset);
        }
    }

    /**
     * Fast bulk copy from a PHP string into this memory stream.
     *
     * This avoids per-byte PHP loops when loading disk sectors or binaries.
     */
    public function copyFromString(string $data, int $destOffset): void
    {
        $len = strlen($data);
        if ($len === 0) {
            return;
        }

        $buffer = self::$ffi->new("uint8_t[$len]");
        FFI::memcpy($buffer, $data, $len);
        self::$ffi->memory_stream_copy_from_external($this->handle, $buffer, $len, $destOffset);
    }

    // ========================================
    // StreamIsProxyableInterface implementation
    // ========================================

    public function proxy(): StreamProxyInterface
    {
        return new StreamProxy($this);
    }
}
