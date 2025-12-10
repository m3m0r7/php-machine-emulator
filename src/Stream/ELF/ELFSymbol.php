<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ELF;

/**
 * ELF Symbol structure
 */
class ELFSymbol
{
    // Symbol binding (upper 4 bits of st_info)
    public const STB_LOCAL = 0;   // Local symbol
    public const STB_GLOBAL = 1;  // Global symbol
    public const STB_WEAK = 2;    // Weak symbol

    // Symbol type (lower 4 bits of st_info)
    public const STT_NOTYPE = 0;   // Symbol type is unspecified
    public const STT_OBJECT = 1;   // Symbol is a data object
    public const STT_FUNC = 2;     // Symbol is a code object (function)
    public const STT_SECTION = 3;  // Symbol associated with a section
    public const STT_FILE = 4;     // Symbol's name is file name
    public const STT_COMMON = 5;   // Symbol is a common data object
    public const STT_TLS = 6;      // Symbol is thread-local data object

    // Symbol visibility (st_other)
    public const STV_DEFAULT = 0;    // Default visibility
    public const STV_INTERNAL = 1;   // Processor specific hidden class
    public const STV_HIDDEN = 2;     // Symbol unavailable in other modules
    public const STV_PROTECTED = 3;  // Not preemptible, not exported

    // Special section indices
    public const SHN_UNDEF = 0;        // Undefined section
    public const SHN_ABS = 0xFFF1;     // Absolute value
    public const SHN_COMMON = 0xFFF2;  // Common data

    public function __construct(
        public readonly int $nameOffset,  // Symbol name (index into string table)
        public readonly int $value,       // Symbol value (address)
        public readonly int $size,        // Symbol size
        public readonly int $info,        // Symbol type and binding
        public readonly int $other,       // Symbol visibility
        public readonly int $shndx,       // Section index
        public string $name = '',         // Resolved symbol name
    ) {
    }

    /**
     * Get symbol binding from st_info
     */
    public function getBinding(): int
    {
        return $this->info >> 4;
    }

    /**
     * Get symbol type from st_info
     */
    public function getType(): int
    {
        return $this->info & 0x0F;
    }

    /**
     * Get symbol visibility from st_other
     */
    public function getVisibility(): int
    {
        return $this->other & 0x03;
    }

    /**
     * Check if symbol is a function
     */
    public function isFunction(): bool
    {
        return $this->getType() === self::STT_FUNC;
    }

    /**
     * Check if symbol is a data object
     */
    public function isObject(): bool
    {
        return $this->getType() === self::STT_OBJECT;
    }

    /**
     * Check if symbol is global
     */
    public function isGlobal(): bool
    {
        return $this->getBinding() === self::STB_GLOBAL;
    }

    /**
     * Check if symbol is local
     */
    public function isLocal(): bool
    {
        return $this->getBinding() === self::STB_LOCAL;
    }

    /**
     * Check if symbol is defined (not undefined)
     */
    public function isDefined(): bool
    {
        return $this->shndx !== self::SHN_UNDEF;
    }

    /**
     * Get binding name for display
     */
    public function getBindingName(): string
    {
        return match ($this->getBinding()) {
            self::STB_LOCAL => 'LOCAL',
            self::STB_GLOBAL => 'GLOBAL',
            self::STB_WEAK => 'WEAK',
            default => 'UNKNOWN',
        };
    }

    /**
     * Get type name for display
     */
    public function getTypeName(): string
    {
        return match ($this->getType()) {
            self::STT_NOTYPE => 'NOTYPE',
            self::STT_OBJECT => 'OBJECT',
            self::STT_FUNC => 'FUNC',
            self::STT_SECTION => 'SECTION',
            self::STT_FILE => 'FILE',
            self::STT_COMMON => 'COMMON',
            self::STT_TLS => 'TLS',
            default => 'UNKNOWN',
        };
    }
}
