<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ELF;

use PHPMachineEmulator\Stream\ISO\ISO9660;

/**
 * Symbol Resolver for runtime debugging
 *
 * Loads symbol information from ELF files in ISO images and provides
 * address-to-symbol resolution for debugging purposes.
 */
class SymbolResolver
{
    /** @var ELFParser[] Loaded ELF parsers */
    private array $parsers = [];

    /** @var array<string, int> Base addresses for each loaded file */
    private array $baseAddresses = [];

    /**
     * Load symbols from an ELF file in an ISO
     *
     * @param ISO9660 $iso The ISO filesystem
     * @param string $path Path to the ELF file within the ISO
     * @param int $baseAddress Base address where the file is loaded in memory (default: 0)
     * @return bool True if successfully loaded
     */
    public function loadFromISO(ISO9660 $iso, string $path, int $baseAddress = 0): bool
    {
        try {
            $parser = ELFParser::fromISO($iso, $path)->parse();
            $this->parsers[$path] = $parser;
            $this->baseAddresses[$path] = $baseAddress;
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Load symbols from a file path
     *
     * @param string $path Path to the ELF file
     * @param int $baseAddress Base address where the file is loaded in memory
     * @return bool True if successfully loaded
     */
    public function loadFromFile(string $path, int $baseAddress = 0): bool
    {
        try {
            $parser = ELFParser::fromFile($path)->parse();
            $this->parsers[$path] = $parser;
            $this->baseAddresses[$path] = $baseAddress;
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Load symbols from binary data
     *
     * @param string $name Identifier for this data
     * @param string $data Binary ELF data
     * @param int $baseAddress Base address where the file is loaded in memory
     * @return bool True if successfully loaded
     */
    public function loadFromBinary(string $name, string $data, int $baseAddress = 0): bool
    {
        $parser = ELFParser::tryFromBinary($data);
        if ($parser === null) {
            return false;
        }
        $this->parsers[$name] = $parser;
        $this->baseAddresses[$name] = $baseAddress;
        return true;
    }

    /**
     * Auto-discover and load ELF files from an ISO
     *
     * Searches common kernel/bootloader paths and loads any valid ELF files found.
     *
     * @param ISO9660 $iso The ISO filesystem
     * @return int Number of ELF files loaded
     */
    public function autoLoadFromISO(ISO9660 $iso): int
    {
        $loaded = 0;
        $searchPaths = [
            '/boot/vmlinux',
            '/boot/vmlinuz',
            '/vmlinux',
            '/vmlinuz',
            '/boot/kernel',
            '/kernel',
            '/boot/isolinux/isolinux.bin',
            '/isolinux/isolinux.bin',
            '/boot/syslinux/syslinux.bin',
            '/syslinux/syslinux.bin',
            '/ldlinux.sys',
            '/boot/ldlinux.sys',
        ];

        foreach ($searchPaths as $path) {
            if ($this->loadFromISO($iso, $path)) {
                $loaded++;
            }
        }

        // Also search for .elf files in common directories
        $searchDirs = ['/', '/boot', '/boot/isolinux', '/boot/syslinux'];
        foreach ($searchDirs as $dir) {
            $entries = $iso->readDirectory($dir);
            if ($entries === null) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry['isDir']) {
                    continue;
                }

                $name = strtolower($entry['name']);
                if (str_ends_with($name, '.elf') || str_ends_with($name, '.o')) {
                    $fullPath = rtrim($dir, '/') . '/' . $entry['name'];
                    if ($this->loadFromISO($iso, $fullPath)) {
                        $loaded++;
                    }
                }
            }
        }

        return $loaded;
    }

    /**
     * Resolve an address to a symbol name
     *
     * @param int $address The memory address to resolve
     * @return string Symbol name with offset, or hex address if not found
     */
    public function resolve(int $address): string
    {
        foreach ($this->parsers as $path => $parser) {
            $baseAddress = $this->baseAddresses[$path];

            // Adjust address for base address offset
            $relativeAddress = $address - $baseAddress;

            // Skip if address is before this module's base
            if ($relativeAddress < 0) {
                continue;
            }

            $symbol = $parser->findSymbolContaining($relativeAddress);
            if ($symbol !== null) {
                $offset = $relativeAddress - $symbol->value;
                if ($offset === 0) {
                    return $symbol->name;
                }
                return sprintf('%s+0x%X', $symbol->name, $offset);
            }
        }

        return sprintf('0x%08X', $address);
    }

    /**
     * Get symbol at exact address
     *
     * @param int $address The memory address
     * @return ELFSymbol|null The symbol if found
     */
    public function getSymbolAt(int $address): ?ELFSymbol
    {
        foreach ($this->parsers as $path => $parser) {
            $baseAddress = $this->baseAddresses[$path];
            $relativeAddress = $address - $baseAddress;

            if ($relativeAddress < 0) {
                continue;
            }

            $symbol = $parser->getSymbolAtAddress($relativeAddress);
            if ($symbol !== null) {
                return $symbol;
            }
        }

        return null;
    }

    /**
     * Find symbol containing address
     *
     * @param int $address The memory address
     * @return ELFSymbol|null The symbol if found
     */
    public function findSymbolContaining(int $address): ?ELFSymbol
    {
        foreach ($this->parsers as $path => $parser) {
            $baseAddress = $this->baseAddresses[$path];
            $relativeAddress = $address - $baseAddress;

            if ($relativeAddress < 0) {
                continue;
            }

            $symbol = $parser->findSymbolContaining($relativeAddress);
            if ($symbol !== null) {
                return $symbol;
            }
        }

        return null;
    }

    /**
     * Get address of a symbol by name
     *
     * @param string $name Symbol name
     * @return int|null Address if found, null otherwise
     */
    public function getAddressOf(string $name): ?int
    {
        foreach ($this->parsers as $path => $parser) {
            $symbol = $parser->getSymbolByName($name);
            if ($symbol !== null && $symbol->isDefined()) {
                return $symbol->value + $this->baseAddresses[$path];
            }
        }

        return null;
    }

    /**
     * Search for symbols matching a pattern
     *
     * @param string $pattern Wildcard pattern (e.g., "*init*")
     * @return array<string, ELFSymbol> Map of full path => symbol
     */
    public function search(string $pattern): array
    {
        $results = [];

        foreach ($this->parsers as $path => $parser) {
            foreach ($parser->searchSymbols($pattern) as $symbol) {
                $key = $path . ':' . $symbol->name;
                $results[$key] = $symbol;
            }
        }

        return $results;
    }

    /**
     * Get all loaded functions
     *
     * @return array<string, ELFSymbol[]> Map of path => functions
     */
    public function getAllFunctions(): array
    {
        $result = [];

        foreach ($this->parsers as $path => $parser) {
            $result[$path] = $parser->getFunctions();
        }

        return $result;
    }

    /**
     * Get statistics about loaded symbols
     *
     * @return array{files: int, symbols: int, functions: int}
     */
    public function getStats(): array
    {
        $symbols = 0;
        $functions = 0;

        foreach ($this->parsers as $parser) {
            $symbols += count($parser->getSymbols());
            $functions += count($parser->getFunctions());
        }

        return [
            'files' => count($this->parsers),
            'symbols' => $symbols,
            'functions' => $functions,
        ];
    }

    /**
     * Check if any symbols are loaded
     */
    public function hasSymbols(): bool
    {
        return !empty($this->parsers);
    }

    /**
     * Get list of loaded files
     *
     * @return string[]
     */
    public function getLoadedFiles(): array
    {
        return array_keys($this->parsers);
    }

    /**
     * Clear all loaded symbols
     */
    public function clear(): void
    {
        $this->parsers = [];
        $this->baseAddresses = [];
    }

    /**
     * Print symbol table for debugging
     */
    public function dump(): void
    {
        foreach ($this->parsers as $path => $parser) {
            printf("\n=== %s (base: 0x%08X) ===\n", $path, $this->baseAddresses[$path]);
            $parser->printSymbolTable();
        }
    }
}
