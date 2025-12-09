<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

/**
 * Unified memory stream with hybrid storage.
 *
 * Uses a segmented approach with php://memory for fast access
 * and php://temp for overflow storage when exceeding physical memory.
 *
 * Storage structure: [[min_range, max_range, resource], ...]
 * - Memory segments use php://memory for fast access
 * - Swap segments use php://temp for disk-backed storage
 *
 * Implements both read and write operations on a single memory space.
 * Boot data is copied here at startup, and all subsequent operations
 * (INT 13h disk reads, REP MOVSB, instruction fetch, etc.) work on this same memory.
 */
class MemoryStream implements MemoryStreamInterface
{
    /** @var int Expansion chunk size (1MB) */
    public const EXPANSION_CHUNK_SIZE = 0x100000;

    /** @var array<int, int> Lookup table for ord() optimization (char -> int) */
    private static array $ordMap = [];

    /** @var array<int, string> Lookup table for chr() optimization (int -> char) */
    private static array $chrMap = [];

    /** @var array<int, array{min: int, max: int, resource: resource, type: string}> Storage segments */
    private array $segments = [];

    private int $offset = 0;

    /** @var int|null Cached current segment index */
    private ?int $currentSegmentIndex = null;

    /** @var string Read-ahead buffer */
    private string $readBuffer = '';

    /** @var int Start offset of read buffer */
    private int $bufferStart = -1;

    /** @var int End offset of read buffer (exclusive) */
    private int $bufferEnd = -1;

    /** @var int Read-ahead buffer size */
    private const READ_BUFFER_SIZE = 8192;

    /**
     * @param int $size Initial memory size (default 1MB)
     * @param int $physicalMaxMemorySize Maximum physical memory size (default 16MB)
     * @param int $swapSize Swap size for overflow to temp file (default 256MB)
     */
    public function __construct(
        private int $size = self::EXPANSION_CHUNK_SIZE,
        private int $physicalMaxMemorySize = 0x1000000,
        private int $swapSize = 0x10000000
    ) {
        // Initialize lookup tables if not already done
        if (empty(self::$ordMap)) {
            for ($i = 0; $i < 256; $i++) {
                self::$ordMap[chr($i)] = $i;
                self::$chrMap[$i] = chr($i);
            }
        }

        // Create initial memory segments in chunks
        $offset = 0;
        while ($offset < $size) {
            $chunkEnd = min($offset + self::EXPANSION_CHUNK_SIZE, $size);
            // Use memory segments until we exceed physical max, then use temp (swap)
            $type = $chunkEnd <= $this->physicalMaxMemorySize ? 'memory' : 'temp';
            $this->createSegment($offset, $chunkEnd, $type);
            $offset = $chunkEnd;
        }
    }

    /** @var int Maximum chunk size for pre-allocation (use half of typical memory limit to be safe) */
    private const PREALLOC_CHUNK_SIZE = 0x4000000; // 64MB

    /**
     * Create a new storage segment.
     *
     * @param int $min Start offset (inclusive)
     * @param int $max End offset (exclusive)
     * @param string $type 'memory' for php://memory, 'temp' for php://temp
     */
    private function createSegment(int $min, int $max, string $type): void
    {
        $resource = $type === 'memory'
            ? fopen('php://memory', 'r+b')
            : fopen('php://temp', 'r+b');

        if ($resource === false) {
            throw new \RuntimeException("Failed to create {$type} segment");
        }

        // Pre-allocate with zeros in chunks to avoid memory exhaustion
        $segmentSize = $max - $min;
        $remaining = $segmentSize;
        while ($remaining > 0) {
            $chunkSize = min($remaining, self::PREALLOC_CHUNK_SIZE);
            fwrite($resource, str_repeat("\x00", $chunkSize));
            $remaining -= $chunkSize;
        }
        rewind($resource);

        $this->segments[] = [
            'min' => $min,
            'max' => $max,
            'resource' => $resource,
            'type' => $type,
        ];
    }

    /**
     * Fill the read-ahead buffer starting at the given offset.
     */
    private function fillReadBuffer(int $offset): void
    {
        $segment = $this->findSegment($offset);
        if ($segment === null) {
            $this->readBuffer = '';
            $this->bufferStart = -1;
            $this->bufferEnd = -1;
            return;
        }

        // Calculate how much we can read from this segment
        $localOffset = $offset - $segment['min'];
        $segmentRemaining = $segment['max'] - $offset;
        $readSize = min(self::READ_BUFFER_SIZE, $segmentRemaining);

        fseek($segment['resource'], $localOffset, SEEK_SET);
        $this->readBuffer = fread($segment['resource'], $readSize);

        if ($this->readBuffer === false) {
            $this->readBuffer = '';
            $this->bufferStart = -1;
            $this->bufferEnd = -1;
            return;
        }

        $this->bufferStart = $offset;
        $this->bufferEnd = $offset + strlen($this->readBuffer);
    }

    /**
     * Find the segment containing the given offset.
     *
     * @return array{min: int, max: int, resource: resource, type: string}|null
     */
    private function findSegment(int $offset): ?array
    {
        // Check cached segment first
        if ($this->currentSegmentIndex !== null) {
            $segment = $this->segments[$this->currentSegmentIndex];
            if ($offset >= $segment['min'] && $offset < $segment['max']) {
                return $segment;
            }
        }

        // Search all segments
        foreach ($this->segments as $index => $segment) {
            if ($offset >= $segment['min'] && $offset < $segment['max']) {
                $this->currentSegmentIndex = $index;
                return $segment;
            }
        }

        $this->currentSegmentIndex = null;
        return null;
    }

    /**
     * Expand memory if needed to accommodate the given offset.
     */
    public function ensureCapacity(int $requiredOffset): bool
    {
        if ($requiredOffset < $this->size) {
            return true;
        }

        if ($requiredOffset >= $this->logicalMaxMemorySize()) {
            return false;
        }

        // Calculate new size in chunk increments
        $newSize = min(
            $this->logicalMaxMemorySize(),
            (int) ceil(($requiredOffset + 1) / self::EXPANSION_CHUNK_SIZE) * self::EXPANSION_CHUNK_SIZE
        );

        // Create new segments for the expanded range
        while ($this->size < $newSize) {
            $segmentStart = $this->size;
            $segmentEnd = min($this->size + self::EXPANSION_CHUNK_SIZE, $newSize);

            // Use memory segments until we exceed physical max, then use temp (swap)
            $type = $segmentEnd <= $this->physicalMaxMemorySize ? 'memory' : 'temp';

            $this->createSegment($segmentStart, $segmentEnd, $type);
            $this->size = $segmentEnd;
        }

        return true;
    }

    /**
     * Read a single byte from storage at the given offset.
     */
    private function readByteAt(int $offset): int
    {
        $segment = $this->findSegment($offset);
        if ($segment === null) {
            return 0;
        }

        $localOffset = $offset - $segment['min'];
        fseek($segment['resource'], $localOffset, SEEK_SET);
        $char = fread($segment['resource'], 1);

        if ($char === false || $char === '') {
            return 0;
        }

        return self::$ordMap[$char] ?? ord($char);
    }

    /**
     * Write a single byte to storage at the given offset.
     */
    private function writeByteAt(int $offset, int $value): void
    {
        // Invalidate read buffer if writing within its range
        if ($offset >= $this->bufferStart && $offset < $this->bufferEnd) {
            $this->bufferStart = -1;
            $this->bufferEnd = -1;
        }

        $segment = $this->findSegment($offset);
        if ($segment === null) {
            return;
        }

        $localOffset = $offset - $segment['min'];
        fseek($segment['resource'], $localOffset, SEEK_SET);
        fwrite($segment['resource'], self::$chrMap[$value & 0xFF] ?? chr($value & 0xFF));
    }

    // ========================================
    // StreamReaderInterface implementation
    // ========================================

    public function char(): string
    {
        // Fast path: check read-ahead buffer first
        if ($this->offset >= $this->bufferStart && $this->offset < $this->bufferEnd) {
            $char = $this->readBuffer[$this->offset - $this->bufferStart];
            $this->offset++;
            return $char;
        }

        // Safety check: don't allow access beyond logical max (swap inclusive)
        if ($this->offset >= $this->logicalMaxMemorySize()) {
            throw new \RuntimeException(sprintf('Memory read out of bounds: offset=0x%X logicalMaxMemorySize=0x%X', $this->offset, $this->logicalMaxMemorySize()));
        }

        // Auto-expand if reading beyond current size
        if ($this->offset >= $this->size) {
            $this->ensureCapacity($this->offset);
        }

        // Fill read-ahead buffer
        $this->fillReadBuffer($this->offset);

        // Read from buffer
        if ($this->offset >= $this->bufferStart && $this->offset < $this->bufferEnd) {
            $char = $this->readBuffer[$this->offset - $this->bufferStart];
            $this->offset++;
            return $char;
        }

        // Fallback (should not happen normally)
        $this->offset++;
        return "\x00";
    }

    public function byte(): int
    {
        // Fast path: check read-ahead buffer first
        if ($this->offset >= $this->bufferStart && $this->offset < $this->bufferEnd) {
            $char = $this->readBuffer[$this->offset - $this->bufferStart];
            $this->offset++;
            return self::$ordMap[$char] ?? ord($char);
        }

        // Safety check: don't allow access beyond logical max (swap inclusive)
        if ($this->offset >= $this->logicalMaxMemorySize()) {
            throw new \RuntimeException(sprintf('Memory read out of bounds: offset=0x%X logicalMaxMemorySize=0x%X', $this->offset, $this->logicalMaxMemorySize()));
        }

        // Auto-expand if reading beyond current size
        if ($this->offset >= $this->size) {
            $this->ensureCapacity($this->offset);
        }

        // Fill read-ahead buffer
        $this->fillReadBuffer($this->offset);

        // Read from buffer
        if ($this->offset >= $this->bufferStart && $this->offset < $this->bufferEnd) {
            $char = $this->readBuffer[$this->offset - $this->bufferStart];
            $this->offset++;
            return self::$ordMap[$char] ?? ord($char);
        }

        // Fallback (should not happen normally)
        $this->offset++;
        return 0;
    }

    public function signedByte(): int
    {
        $byte = $this->byte();
        return $byte > 127 ? $byte - 256 : $byte;
    }

    public function short(): int
    {
        $low = $this->byte();
        $high = $this->byte();
        return $low | ($high << 8);
    }

    public function dword(): int
    {
        $b0 = $this->byte();
        $b1 = $this->byte();
        $b2 = $this->byte();
        $b3 = $this->byte();
        return $b0 | ($b1 << 8) | ($b2 << 16) | ($b3 << 24);
    }

    public function read(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        // Ensure capacity
        $endOffset = $this->offset + $length;
        if ($endOffset > $this->size) {
            $this->ensureCapacity($endOffset);
        }

        // Read across segments
        $result = '';
        $remaining = $length;

        while ($remaining > 0) {
            $segment = $this->findSegment($this->offset);
            if ($segment === null) {
                $this->offset += $remaining;
                $result .= str_repeat("\x00", $remaining);
                break;
            }

            // Calculate how much we can read from this segment
            $segmentRemaining = $segment['max'] - $this->offset;
            $chunkSize = min($remaining, $segmentRemaining);

            // Read directly from segment
            $localOffset = $this->offset - $segment['min'];
            fseek($segment['resource'], $localOffset, SEEK_SET);
            $chunk = fread($segment['resource'], $chunkSize);

            if ($chunk === false) {
                $chunk = str_repeat("\x00", $chunkSize);
            }

            $result .= $chunk;
            $this->offset += $chunkSize;
            $remaining -= $chunkSize;
        }

        return $result;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    public function setOffset(int $newOffset): self
    {
        // Safety check: don't allow setting offset beyond logical max (swap inclusive)
        if ($newOffset < 0 || $newOffset >= $this->logicalMaxMemorySize()) {
            throw new \RuntimeException(sprintf('Cannot set offset beyond bounds: offset=0x%X logicalMaxMemorySize=0x%X', $newOffset, $this->logicalMaxMemorySize()));
        }

        // Auto-expand memory if needed
        if ($newOffset >= $this->size) {
            $this->ensureCapacity($newOffset);
        }
        $this->offset = $newOffset;
        return $this;
    }

    public function isEOF(): bool
    {
        return $this->offset >= $this->size && $this->offset >= $this->logicalMaxMemorySize();
    }

    // ========================================
    // StreamWriterInterface implementation
    // ========================================

    public function write(string $value): self
    {
        $len = strlen($value);
        $endOffset = $this->offset + $len;
        if ($endOffset >= $this->size) {
            $this->ensureCapacity($endOffset);
        }

        // Write across potentially multiple segments
        for ($i = 0; $i < $len; $i++) {
            $this->writeByteAt($this->offset + $i, self::$ordMap[$value[$i]] ?? ord($value[$i]));
        }
        $this->offset += $len;
        return $this;
    }

    public function writeByte(int $value): void
    {
        // Safety check: don't allow access beyond logical max (swap inclusive)
        if ($this->offset >= $this->logicalMaxMemorySize()) {
            throw new \RuntimeException(sprintf('Memory access out of bounds: offset=0x%X logicalMaxMemorySize=0x%X', $this->offset, $this->logicalMaxMemorySize()));
        }

        if ($this->offset >= $this->size) {
            $this->ensureCapacity($this->offset);
        }

        $this->writeByteAt($this->offset, $value);
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

    public function copy(StreamReaderInterface $source, int $sourceOffset, int $destOffset, int $size): void
    {
        // Save current positions
        $originalSourceOffset = $source->offset();
        $originalDestOffset = $this->offset;

        // Set source position
        $source->setOffset($sourceOffset);

        // Ensure destination has capacity
        if ($destOffset + $size >= $this->size) {
            $this->ensureCapacity($destOffset + $size);
        }

        // Read all data from source at once
        $data = $source->read($size);

        // Write in chunks, handling segment boundaries
        $remaining = strlen($data);
        $currentDestOffset = $destOffset;
        $dataOffset = 0;

        while ($remaining > 0) {
            $segment = $this->findSegment($currentDestOffset);
            if ($segment === null) {
                break;
            }

            // Calculate how much we can write to this segment
            $segmentRemaining = $segment['max'] - $currentDestOffset;
            $chunkSize = min($remaining, $segmentRemaining);

            // Write directly to segment
            $localOffset = $currentDestOffset - $segment['min'];
            fseek($segment['resource'], $localOffset, SEEK_SET);
            fwrite($segment['resource'], substr($data, $dataOffset, $chunkSize));

            $currentDestOffset += $chunkSize;
            $dataOffset += $chunkSize;
            $remaining -= $chunkSize;
        }

        // Restore positions
        $source->setOffset($originalSourceOffset);
        $this->offset = $originalDestOffset;
    }

    // ========================================
    // Proxyable interface implementation
    // ========================================

    public function proxy(): StreamProxyInterface
    {
        return new StreamProxy($this);
    }

    public function size(): int
    {
        return $this->size;
    }

    /**
     * Get the logical maximum memory size (physical + swap).
     * This is the total addressable memory space.
     */
    public function logicalMaxMemorySize(): int
    {
        return $this->physicalMaxMemorySize + $this->swapSize;
    }

    /**
     * Get the physical maximum memory size (without swap).
     * Data up to this size stays in RAM; beyond this goes to temp file.
     */
    public function physicalMaxMemorySize(): int
    {
        return $this->physicalMaxMemorySize;
    }

    /**
     * Get the swap size.
     */
    public function swapSize(): int
    {
        return $this->swapSize;
    }

    /**
     * Get the number of storage segments.
     */
    public function segmentCount(): int
    {
        return count($this->segments);
    }

    /**
     * Get segment info for debugging.
     *
     * @return array<int, array{min: int, max: int, type: string}>
     */
    public function getSegmentInfo(): array
    {
        $info = [];
        foreach ($this->segments as $segment) {
            $info[] = [
                'min' => $segment['min'],
                'max' => $segment['max'],
                'type' => $segment['type'],
            ];
        }
        return $info;
    }

    public function __destruct()
    {
        // Close all segment resources
        foreach ($this->segments as $segment) {
            if (is_resource($segment['resource'])) {
                fclose($segment['resource']);
            }
        }
    }
}
