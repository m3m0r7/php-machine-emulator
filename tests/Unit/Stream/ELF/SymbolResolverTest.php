<?php

declare(strict_types=1);

namespace Tests\Unit\Stream\ELF;

use PHPMachineEmulator\Stream\ELF\ELFSectionHeader;
use PHPMachineEmulator\Stream\ELF\ELFSymbol;
use PHPMachineEmulator\Stream\ELF\SymbolResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SymbolResolver functionality.
 */
class SymbolResolverTest extends TestCase
{
    /**
     * Create a minimal valid 32-bit ELF file for testing.
     */
    private function createMinimalElf32(): string
    {
        // ELF Header (52 bytes for 32-bit)
        $header = "\x7FELF";
        $header .= "\x01";                     // Class: 32-bit
        $header .= "\x01";                     // Data: Little endian
        $header .= "\x01";                     // Version: 1
        $header .= "\x00";                     // OS/ABI: UNIX
        $header .= str_repeat("\x00", 8);
        $header .= pack('v', 2);               // Type: ET_EXEC
        $header .= pack('v', 3);               // Machine: EM_386
        $header .= pack('V', 1);               // Version
        $header .= pack('V', 0x08048000);      // Entry point
        $header .= pack('V', 0);               // Program header offset
        $header .= pack('V', 52);              // Section header offset
        $header .= pack('V', 0);               // Flags
        $header .= pack('v', 52);              // ELF header size
        $header .= pack('v', 0);               // Program header entry size
        $header .= pack('v', 0);               // Number of program headers
        $header .= pack('v', 40);              // Section header entry size
        $header .= pack('v', 4);               // Number of section headers
        $header .= pack('v', 1);               // Section name string table index

        // Section 0: NULL
        $sh_null = pack('V', 0);
        $sh_null .= pack('V', ELFSectionHeader::SHT_NULL);
        $sh_null .= pack('V', 0);
        $sh_null .= pack('V', 0);
        $sh_null .= pack('V', 0);
        $sh_null .= pack('V', 0);
        $sh_null .= pack('V', 0);
        $sh_null .= pack('V', 0);
        $sh_null .= pack('V', 0);
        $sh_null .= pack('V', 0);

        // Section 1: .shstrtab
        $shstrtab_content = "\x00.shstrtab\x00.strtab\x00.symtab\x00";
        $shstrtab_offset = 52 + 40 * 4;

        $sh_shstrtab = pack('V', 1);
        $sh_shstrtab .= pack('V', ELFSectionHeader::SHT_STRTAB);
        $sh_shstrtab .= pack('V', 0);
        $sh_shstrtab .= pack('V', 0);
        $sh_shstrtab .= pack('V', $shstrtab_offset);
        $sh_shstrtab .= pack('V', strlen($shstrtab_content));
        $sh_shstrtab .= pack('V', 0);
        $sh_shstrtab .= pack('V', 0);
        $sh_shstrtab .= pack('V', 1);
        $sh_shstrtab .= pack('V', 0);

        // Section 2: .strtab
        $strtab_content = "\x00kernel_main\x00do_irq\x00init_memory\x00";
        $strtab_offset = $shstrtab_offset + strlen($shstrtab_content);

        $sh_strtab = pack('V', 11);
        $sh_strtab .= pack('V', ELFSectionHeader::SHT_STRTAB);
        $sh_strtab .= pack('V', 0);
        $sh_strtab .= pack('V', 0);
        $sh_strtab .= pack('V', $strtab_offset);
        $sh_strtab .= pack('V', strlen($strtab_content));
        $sh_strtab .= pack('V', 0);
        $sh_strtab .= pack('V', 0);
        $sh_strtab .= pack('V', 1);
        $sh_strtab .= pack('V', 0);

        // Section 3: .symtab
        $symtab_offset = $strtab_offset + strlen($strtab_content);

        // Symbol 0: NULL
        $sym_null = pack('V', 0);
        $sym_null .= pack('V', 0);
        $sym_null .= pack('V', 0);
        $sym_null .= chr(0);
        $sym_null .= chr(0);
        $sym_null .= pack('v', 0);

        // Symbol 1: kernel_main at 0x1000
        $sym1 = pack('V', 1);                  // name offset
        $sym1 .= pack('V', 0x1000);            // value
        $sym1 .= pack('V', 200);               // size
        $sym1 .= chr((ELFSymbol::STB_GLOBAL << 4) | ELFSymbol::STT_FUNC);
        $sym1 .= chr(0);
        $sym1 .= pack('v', 1);

        // Symbol 2: do_irq at 0x2000
        $sym2 = pack('V', 13);                 // name offset
        $sym2 .= pack('V', 0x2000);
        $sym2 .= pack('V', 100);
        $sym2 .= chr((ELFSymbol::STB_GLOBAL << 4) | ELFSymbol::STT_FUNC);
        $sym2 .= chr(0);
        $sym2 .= pack('v', 1);

        // Symbol 3: init_memory at 0x3000
        $sym3 = pack('V', 20);                 // name offset
        $sym3 .= pack('V', 0x3000);
        $sym3 .= pack('V', 150);
        $sym3 .= chr((ELFSymbol::STB_GLOBAL << 4) | ELFSymbol::STT_FUNC);
        $sym3 .= chr(0);
        $sym3 .= pack('v', 1);

        $symtab_content = $sym_null . $sym1 . $sym2 . $sym3;

        $sh_symtab = pack('V', 19);
        $sh_symtab .= pack('V', ELFSectionHeader::SHT_SYMTAB);
        $sh_symtab .= pack('V', 0);
        $sh_symtab .= pack('V', 0);
        $sh_symtab .= pack('V', $symtab_offset);
        $sh_symtab .= pack('V', strlen($symtab_content));
        $sh_symtab .= pack('V', 2);
        $sh_symtab .= pack('V', 1);
        $sh_symtab .= pack('V', 4);
        $sh_symtab .= pack('V', 16);

        return $header . $sh_null . $sh_shstrtab . $sh_strtab . $sh_symtab .
               $shstrtab_content . $strtab_content . $symtab_content;
    }

    /**
     * Test loading symbols from binary data.
     */
    public function testLoadFromBinary(): void
    {
        $resolver = new SymbolResolver();
        $elfData = $this->createMinimalElf32();

        $result = $resolver->loadFromBinary('test.elf', $elfData);

        $this->assertTrue($result);
        $this->assertTrue($resolver->hasSymbols());
        $this->assertContains('test.elf', $resolver->getLoadedFiles());
    }

    /**
     * Test loading invalid data returns false.
     */
    public function testLoadInvalidData(): void
    {
        $resolver = new SymbolResolver();

        $result = $resolver->loadFromBinary('invalid', 'not an elf file');

        $this->assertFalse($result);
        $this->assertFalse($resolver->hasSymbols());
    }

    /**
     * Test resolving address to symbol name.
     */
    public function testResolveAddress(): void
    {
        $resolver = new SymbolResolver();
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32());

        // Exact match
        $this->assertSame('kernel_main', $resolver->resolve(0x1000));

        // With offset
        $this->assertSame('kernel_main+0x50', $resolver->resolve(0x1050));

        // Unknown address
        $this->assertSame('0x00005000', $resolver->resolve(0x5000));
    }

    /**
     * Test resolving with base address offset.
     */
    public function testResolveWithBaseAddress(): void
    {
        $resolver = new SymbolResolver();
        // Load at base address 0x100000
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32(), 0x100000);

        // kernel_main is at 0x1000 relative, so absolute is 0x101000
        $this->assertSame('kernel_main', $resolver->resolve(0x101000));
        $this->assertSame('kernel_main+0x50', $resolver->resolve(0x101050));

        // do_irq at 0x2000 relative = 0x102000 absolute
        $this->assertSame('do_irq', $resolver->resolve(0x102000));
    }

    /**
     * Test getting address of symbol by name.
     */
    public function testGetAddressOf(): void
    {
        $resolver = new SymbolResolver();
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32(), 0x100000);

        $this->assertSame(0x101000, $resolver->getAddressOf('kernel_main'));
        $this->assertSame(0x102000, $resolver->getAddressOf('do_irq'));
        $this->assertNull($resolver->getAddressOf('nonexistent'));
    }

    /**
     * Test searching symbols.
     */
    public function testSearch(): void
    {
        $resolver = new SymbolResolver();
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32());

        // Search for symbols containing 'init'
        $results = $resolver->search('*init*');
        $this->assertCount(1, $results);
        $this->assertArrayHasKey('kernel:init_memory', $results);

        // Search for all symbols starting with 'do_'
        $results = $resolver->search('do_*');
        $this->assertCount(1, $results);
    }

    /**
     * Test getting statistics.
     */
    public function testGetStats(): void
    {
        $resolver = new SymbolResolver();
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32());

        $stats = $resolver->getStats();

        $this->assertSame(1, $stats['files']);
        $this->assertSame(4, $stats['symbols']); // Including NULL symbol
        $this->assertSame(3, $stats['functions']);
    }

    /**
     * Test clearing symbols.
     */
    public function testClear(): void
    {
        $resolver = new SymbolResolver();
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32());

        $this->assertTrue($resolver->hasSymbols());

        $resolver->clear();

        $this->assertFalse($resolver->hasSymbols());
        $this->assertEmpty($resolver->getLoadedFiles());
    }

    /**
     * Test multiple ELF files with different base addresses.
     */
    public function testMultipleFiles(): void
    {
        $resolver = new SymbolResolver();

        // Load kernel at 0x100000
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32(), 0x100000);

        // Load module at 0x200000
        $resolver->loadFromBinary('module', $this->createMinimalElf32(), 0x200000);

        $stats = $resolver->getStats();
        $this->assertSame(2, $stats['files']);

        // kernel_main in kernel
        $this->assertSame('kernel_main', $resolver->resolve(0x101000));

        // kernel_main in module
        $this->assertSame('kernel_main', $resolver->resolve(0x201000));
    }

    /**
     * Test getting symbol at address.
     */
    public function testGetSymbolAt(): void
    {
        $resolver = new SymbolResolver();
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32());

        $symbol = $resolver->getSymbolAt(0x1000);
        $this->assertNotNull($symbol);
        $this->assertSame('kernel_main', $symbol->name);

        // No exact match
        $symbol = $resolver->getSymbolAt(0x1050);
        $this->assertNull($symbol);
    }

    /**
     * Test finding symbol containing address.
     */
    public function testFindSymbolContaining(): void
    {
        $resolver = new SymbolResolver();
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32());

        // Within kernel_main (0x1000 - 0x10C8)
        $symbol = $resolver->findSymbolContaining(0x1050);
        $this->assertNotNull($symbol);
        $this->assertSame('kernel_main', $symbol->name);

        // Outside any symbol
        $symbol = $resolver->findSymbolContaining(0x5000);
        $this->assertNull($symbol);
    }

    /**
     * Test getting all functions.
     */
    public function testGetAllFunctions(): void
    {
        $resolver = new SymbolResolver();
        $resolver->loadFromBinary('kernel', $this->createMinimalElf32());

        $functions = $resolver->getAllFunctions();

        $this->assertArrayHasKey('kernel', $functions);
        $this->assertCount(3, $functions['kernel']);

        $names = array_map(fn($s) => $s->name, $functions['kernel']);
        $this->assertContains('kernel_main', $names);
        $this->assertContains('do_irq', $names);
        $this->assertContains('init_memory', $names);
    }
}
