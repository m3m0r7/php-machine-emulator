<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ELF;

use PHPMachineEmulator\Stream\ISO\ISO9660;
use RuntimeException;

/**
 * ELF (Executable and Linkable Format) Parser
 *
 * Parses ELF files and extracts symbol table information
 */
class ELFParser
{
    private string $data;
    private ?ELFHeader $header = null;
    /** @var ELFSectionHeader[] */
    private array $sectionHeaders = [];
    /** @var ELFSymbol[] */
    private array $symbols = [];
    /** @var array<int, ELFSymbol[]> Symbols indexed by address for fast lookup */
    private array $symbolsByAddress = [];

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    /**
     * Create parser from file path
     */
    public static function fromFile(string $path): self
    {
        $data = file_get_contents($path);
        if ($data === false) {
            throw new RuntimeException("Failed to read file: {$path}");
        }
        return new self($data);
    }

    /**
     * Create parser from raw binary data
     */
    public static function fromBinary(string $data): self
    {
        return new self($data);
    }

    /**
     * Create parser from ISO9660 filesystem
     *
     * @param ISO9660 $iso The ISO filesystem
     * @param string $path Path to the ELF file within the ISO (e.g., "/boot/vmlinuz")
     */
    public static function fromISO(ISO9660 $iso, string $path): self
    {
        $data = $iso->readFile($path);
        if ($data === null) {
            throw new RuntimeException("Failed to read file from ISO: {$path}");
        }
        return new self($data);
    }

    /**
     * Check if data appears to be a valid ELF file
     */
    public static function isELF(string $data): bool
    {
        return strlen($data) >= 4 && substr($data, 0, 4) === ELFHeader::ELF_MAGIC;
    }

    /**
     * Try to create parser, returns null if not valid ELF
     */
    public static function tryFromBinary(string $data): ?self
    {
        if (!self::isELF($data)) {
            return null;
        }
        try {
            return (new self($data))->parse();
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Parse the ELF file
     */
    public function parse(): self
    {
        $this->parseHeader();
        $this->parseSectionHeaders();
        $this->parseSymbolTables();
        return $this;
    }

    /**
     * Parse ELF header
     */
    private function parseHeader(): void
    {
        // Check magic number
        if (substr($this->data, 0, 4) !== ELFHeader::ELF_MAGIC) {
            throw new RuntimeException('Invalid ELF magic number');
        }

        $class = ord($this->data[4]);
        $data = ord($this->data[5]);
        $version = ord($this->data[6]);
        $osabi = ord($this->data[7]);

        if ($class === ELFHeader::ELFCLASS32) {
            $this->header = $this->parseHeader32($class, $data, $version, $osabi);
        } elseif ($class === ELFHeader::ELFCLASS64) {
            $this->header = $this->parseHeader64($class, $data, $version, $osabi);
        } else {
            throw new RuntimeException("Unsupported ELF class: {$class}");
        }
    }

    /**
     * Parse 32-bit ELF header
     */
    private function parseHeader32(int $class, int $data, int $version, int $osabi): ELFHeader
    {
        return new ELFHeader(
            class: $class,
            data: $data,
            version: $version,
            osabi: $osabi,
            type: $this->readUint16(16),
            machine: $this->readUint16(18),
            entry: $this->readUint32(24),
            phoff: $this->readUint32(28),
            shoff: $this->readUint32(32),
            flags: $this->readUint32(36),
            ehsize: $this->readUint16(40),
            phentsize: $this->readUint16(42),
            phnum: $this->readUint16(44),
            shentsize: $this->readUint16(46),
            shnum: $this->readUint16(48),
            shstrndx: $this->readUint16(50),
        );
    }

    /**
     * Parse 64-bit ELF header
     */
    private function parseHeader64(int $class, int $data, int $version, int $osabi): ELFHeader
    {
        return new ELFHeader(
            class: $class,
            data: $data,
            version: $version,
            osabi: $osabi,
            type: $this->readUint16(16),
            machine: $this->readUint16(18),
            entry: $this->readUint64(24),
            phoff: $this->readUint64(32),
            shoff: $this->readUint64(40),
            flags: $this->readUint32(48),
            ehsize: $this->readUint16(52),
            phentsize: $this->readUint16(54),
            phnum: $this->readUint16(56),
            shentsize: $this->readUint16(58),
            shnum: $this->readUint16(60),
            shstrndx: $this->readUint16(62),
        );
    }

    /**
     * Parse section headers
     */
    private function parseSectionHeaders(): void
    {
        $shoff = $this->header->shoff;
        $shentsize = $this->header->shentsize;
        $shnum = $this->header->shnum;

        for ($i = 0; $i < $shnum; $i++) {
            $offset = $shoff + ($i * $shentsize);

            if ($this->header->is32Bit()) {
                $this->sectionHeaders[] = $this->parseSectionHeader32($offset);
            } else {
                $this->sectionHeaders[] = $this->parseSectionHeader64($offset);
            }
        }

        // Resolve section names from .shstrtab
        $this->resolveSectionNames();
    }

    /**
     * Parse 32-bit section header
     */
    private function parseSectionHeader32(int $offset): ELFSectionHeader
    {
        return new ELFSectionHeader(
            nameOffset: $this->readUint32($offset),
            type: $this->readUint32($offset + 4),
            flags: $this->readUint32($offset + 8),
            addr: $this->readUint32($offset + 12),
            offset: $this->readUint32($offset + 16),
            size: $this->readUint32($offset + 20),
            link: $this->readUint32($offset + 24),
            info: $this->readUint32($offset + 28),
            addralign: $this->readUint32($offset + 32),
            entsize: $this->readUint32($offset + 36),
        );
    }

    /**
     * Parse 64-bit section header
     */
    private function parseSectionHeader64(int $offset): ELFSectionHeader
    {
        return new ELFSectionHeader(
            nameOffset: $this->readUint32($offset),
            type: $this->readUint32($offset + 4),
            flags: $this->readUint64($offset + 8),
            addr: $this->readUint64($offset + 16),
            offset: $this->readUint64($offset + 24),
            size: $this->readUint64($offset + 32),
            link: $this->readUint32($offset + 40),
            info: $this->readUint32($offset + 44),
            addralign: $this->readUint64($offset + 48),
            entsize: $this->readUint64($offset + 56),
        );
    }

    /**
     * Resolve section names using .shstrtab
     */
    private function resolveSectionNames(): void
    {
        $shstrndx = $this->header->shstrndx;
        if ($shstrndx >= count($this->sectionHeaders)) {
            return;
        }

        $strtab = $this->sectionHeaders[$shstrndx];

        foreach ($this->sectionHeaders as $section) {
            $section->name = $this->readString($strtab->offset + $section->nameOffset);
        }
    }

    /**
     * Parse symbol tables (.symtab and .dynsym)
     */
    private function parseSymbolTables(): void
    {
        foreach ($this->sectionHeaders as $section) {
            if ($section->isSymbolTable()) {
                $this->parseSymbolTable($section);
            }
        }

        // Build address lookup index
        $this->buildAddressIndex();
    }

    /**
     * Parse a symbol table section
     */
    private function parseSymbolTable(ELFSectionHeader $symtab): void
    {
        // Get associated string table
        $strtab = $this->sectionHeaders[$symtab->link] ?? null;
        if ($strtab === null) {
            return;
        }

        $entsize = $symtab->entsize;
        if ($entsize === 0) {
            $entsize = $this->header->is32Bit() ? 16 : 24;
        }

        $numSymbols = $symtab->size / $entsize;

        for ($i = 0; $i < $numSymbols; $i++) {
            $offset = $symtab->offset + ($i * $entsize);

            if ($this->header->is32Bit()) {
                $symbol = $this->parseSymbol32($offset);
            } else {
                $symbol = $this->parseSymbol64($offset);
            }

            // Resolve symbol name
            $symbol->name = $this->readString($strtab->offset + $symbol->nameOffset);

            $this->symbols[] = $symbol;
        }
    }

    /**
     * Parse 32-bit symbol entry
     */
    private function parseSymbol32(int $offset): ELFSymbol
    {
        return new ELFSymbol(
            nameOffset: $this->readUint32($offset),
            value: $this->readUint32($offset + 4),
            size: $this->readUint32($offset + 8),
            info: ord($this->data[$offset + 12]),
            other: ord($this->data[$offset + 13]),
            shndx: $this->readUint16($offset + 14),
        );
    }

    /**
     * Parse 64-bit symbol entry
     */
    private function parseSymbol64(int $offset): ELFSymbol
    {
        return new ELFSymbol(
            nameOffset: $this->readUint32($offset),
            info: ord($this->data[$offset + 4]),
            other: ord($this->data[$offset + 5]),
            shndx: $this->readUint16($offset + 6),
            value: $this->readUint64($offset + 8),
            size: $this->readUint64($offset + 16),
        );
    }

    /**
     * Build index of symbols by address for fast lookup
     */
    private function buildAddressIndex(): void
    {
        foreach ($this->symbols as $symbol) {
            if ($symbol->isDefined() && $symbol->value > 0) {
                $addr = $symbol->value;
                if (!isset($this->symbolsByAddress[$addr])) {
                    $this->symbolsByAddress[$addr] = [];
                }
                $this->symbolsByAddress[$addr][] = $symbol;
            }
        }

        // Sort by address
        ksort($this->symbolsByAddress);
    }

    /**
     * Get ELF header
     */
    public function getHeader(): ?ELFHeader
    {
        return $this->header;
    }

    /**
     * Get all section headers
     * @return ELFSectionHeader[]
     */
    public function getSectionHeaders(): array
    {
        return $this->sectionHeaders;
    }

    /**
     * Get a section header by name
     */
    public function getSectionByName(string $name): ?ELFSectionHeader
    {
        foreach ($this->sectionHeaders as $section) {
            if ($section->name === $name) {
                return $section;
            }
        }
        return null;
    }

    /**
     * Get all symbols
     * @return ELFSymbol[]
     */
    public function getSymbols(): array
    {
        return $this->symbols;
    }

    /**
     * Get only function symbols
     * @return ELFSymbol[]
     */
    public function getFunctions(): array
    {
        return array_filter($this->symbols, fn(ELFSymbol $s) => $s->isFunction() && $s->isDefined());
    }

    /**
     * Get symbol at exact address
     */
    public function getSymbolAtAddress(int $address): ?ELFSymbol
    {
        $symbols = $this->symbolsByAddress[$address] ?? null;
        if ($symbols === null) {
            return null;
        }

        // Prefer function symbols
        foreach ($symbols as $symbol) {
            if ($symbol->isFunction()) {
                return $symbol;
            }
        }

        return $symbols[0];
    }

    /**
     * Find symbol containing address (for addresses within a function)
     */
    public function findSymbolContaining(int $address): ?ELFSymbol
    {
        // Check exact match first
        $exact = $this->getSymbolAtAddress($address);
        if ($exact !== null) {
            return $exact;
        }

        // Find the symbol with the highest address that is <= target address
        $bestSymbol = null;
        $bestAddr = 0;

        foreach ($this->symbolsByAddress as $addr => $symbols) {
            if ($addr > $address) {
                break;
            }

            foreach ($symbols as $symbol) {
                // Check if address is within symbol's range
                if ($symbol->size > 0) {
                    if ($address >= $symbol->value && $address < $symbol->value + $symbol->size) {
                        // Prefer function symbols
                        if ($symbol->isFunction()) {
                            return $symbol;
                        }
                        if ($bestSymbol === null || !$bestSymbol->isFunction()) {
                            $bestSymbol = $symbol;
                            $bestAddr = $addr;
                        }
                    }
                } else {
                    // Symbol with zero size - use address comparison only
                    if ($addr > $bestAddr) {
                        $bestSymbol = $symbol;
                        $bestAddr = $addr;
                    }
                }
            }
        }

        return $bestSymbol;
    }

    /**
     * Resolve address to function name with offset
     * Returns format: "function_name+0x123" or "0x12345678" if unknown
     */
    public function resolveAddress(int $address): string
    {
        $symbol = $this->findSymbolContaining($address);

        if ($symbol === null) {
            return sprintf('0x%08X', $address);
        }

        $offset = $address - $symbol->value;

        if ($offset === 0) {
            return $symbol->name;
        }

        return sprintf('%s+0x%X', $symbol->name, $offset);
    }

    /**
     * Get symbol by name
     */
    public function getSymbolByName(string $name): ?ELFSymbol
    {
        foreach ($this->symbols as $symbol) {
            if ($symbol->name === $name) {
                return $symbol;
            }
        }
        return null;
    }

    /**
     * Search symbols by pattern (simple wildcard support)
     * @return ELFSymbol[]
     */
    public function searchSymbols(string $pattern): array
    {
        // Convert simple wildcard pattern to regex
        // Split pattern by wildcards, quote each part, then join
        $parts = preg_split('/([*?])/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '/^';
        foreach ($parts as $part) {
            if ($part === '*') {
                $regex .= '.*';
            } elseif ($part === '?') {
                $regex .= '.';
            } else {
                $regex .= preg_quote($part, '/');
            }
        }
        $regex .= '$/';

        return array_filter($this->symbols, function (ELFSymbol $symbol) use ($regex) {
            return preg_match($regex, $symbol->name);
        });
    }

    /**
     * Print symbol table (for debugging)
     */
    public function printSymbolTable(): void
    {
        printf("%-10s %-8s %-8s %-8s %s\n", 'Value', 'Size', 'Type', 'Bind', 'Name');
        printf("%s\n", str_repeat('-', 60));

        foreach ($this->symbols as $symbol) {
            if (!$symbol->isDefined() || empty($symbol->name)) {
                continue;
            }

            printf(
                "%08X   %-8d %-8s %-8s %s\n",
                $symbol->value,
                $symbol->size,
                $symbol->getTypeName(),
                $symbol->getBindingName(),
                $symbol->name
            );
        }
    }

    // Utility methods for reading binary data

    private function readUint16(int $offset): int
    {
        return unpack('v', substr($this->data, $offset, 2))[1];
    }

    private function readUint32(int $offset): int
    {
        return unpack('V', substr($this->data, $offset, 4))[1];
    }

    private function readUint64(int $offset): int
    {
        return unpack('P', substr($this->data, $offset, 8))[1];
    }

    private function readString(int $offset): string
    {
        $end = strpos($this->data, "\0", $offset);
        if ($end === false) {
            return '';
        }
        return substr($this->data, $offset, $end - $offset);
    }
}
