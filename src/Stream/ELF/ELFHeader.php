<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ELF;

/**
 * ELF Header structure
 */
class ELFHeader
{
    public const ELF_MAGIC = "\x7FELF";

    // ELF Class (32-bit or 64-bit)
    public const ELFCLASS32 = 1;
    public const ELFCLASS64 = 2;

    // ELF Data encoding
    public const ELFDATA2LSB = 1; // Little endian
    public const ELFDATA2MSB = 2; // Big endian

    // ELF Type
    public const ET_NONE = 0;
    public const ET_REL = 1;   // Relocatable
    public const ET_EXEC = 2;  // Executable
    public const ET_DYN = 3;   // Shared object
    public const ET_CORE = 4;  // Core file

    // ELF Machine
    public const EM_386 = 3;      // Intel 80386
    public const EM_X86_64 = 62;  // AMD x86-64

    public function __construct(
        public readonly int $class,        // 32-bit or 64-bit
        public readonly int $data,         // Endianness
        public readonly int $version,      // ELF version
        public readonly int $osabi,        // OS/ABI identification
        public readonly int $type,         // Object file type
        public readonly int $machine,      // Machine type
        public readonly int $entry,        // Entry point address
        public readonly int $phoff,        // Program header offset
        public readonly int $shoff,        // Section header offset
        public readonly int $flags,        // Processor-specific flags
        public readonly int $ehsize,       // ELF header size
        public readonly int $phentsize,    // Size of program header entry
        public readonly int $phnum,        // Number of program header entries
        public readonly int $shentsize,    // Size of section header entry
        public readonly int $shnum,        // Number of section header entries
        public readonly int $shstrndx,     // Section name string table index
    ) {
    }

    public function is32Bit(): bool
    {
        return $this->class === self::ELFCLASS32;
    }

    public function is64Bit(): bool
    {
        return $this->class === self::ELFCLASS64;
    }

    public function isLittleEndian(): bool
    {
        return $this->data === self::ELFDATA2LSB;
    }
}
