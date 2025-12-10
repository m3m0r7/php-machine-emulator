<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ELF;

/**
 * ELF Section Header structure
 */
class ELFSectionHeader
{
    // Section types
    public const SHT_NULL = 0;          // Unused section
    public const SHT_PROGBITS = 1;      // Program data
    public const SHT_SYMTAB = 2;        // Symbol table
    public const SHT_STRTAB = 3;        // String table
    public const SHT_RELA = 4;          // Relocation entries with addends
    public const SHT_HASH = 5;          // Symbol hash table
    public const SHT_DYNAMIC = 6;       // Dynamic linking information
    public const SHT_NOTE = 7;          // Notes
    public const SHT_NOBITS = 8;        // Program space with no data (bss)
    public const SHT_REL = 9;           // Relocation entries, no addends
    public const SHT_SHLIB = 10;        // Reserved
    public const SHT_DYNSYM = 11;       // Dynamic linker symbol table

    // Section flags
    public const SHF_WRITE = 0x1;       // Writable
    public const SHF_ALLOC = 0x2;       // Occupies memory during execution
    public const SHF_EXECINSTR = 0x4;   // Executable

    public function __construct(
        public readonly int $nameOffset,   // Section name (index into string table)
        public readonly int $type,         // Section type
        public readonly int $flags,        // Section flags
        public readonly int $addr,         // Virtual address in memory
        public readonly int $offset,       // Offset in file
        public readonly int $size,         // Size of section
        public readonly int $link,         // Link to another section
        public readonly int $info,         // Additional section information
        public readonly int $addralign,    // Section alignment
        public readonly int $entsize,      // Entry size if section holds table
        public string $name = '',          // Resolved section name
    ) {
    }

    public function isSymbolTable(): bool
    {
        return $this->type === self::SHT_SYMTAB || $this->type === self::SHT_DYNSYM;
    }

    public function isStringTable(): bool
    {
        return $this->type === self::SHT_STRTAB;
    }
}
