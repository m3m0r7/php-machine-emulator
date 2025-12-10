<?php

declare(strict_types=1);

namespace Tests\Unit\Stream\ELF;

use PHPMachineEmulator\Stream\ELF\ELFHeader;
use PHPMachineEmulator\Stream\ELF\ELFParser;
use PHPMachineEmulator\Stream\ELF\ELFSectionHeader;
use PHPMachineEmulator\Stream\ELF\ELFSymbol;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ELF Parser functionality.
 */
class ELFParserTest extends TestCase
{
    private const FIXTURES_DIR = __DIR__ . '/../../../Fixtures/ELF/';

    /**
     * Create a minimal valid 32-bit ELF file for testing.
     */
    private function createMinimalElf32(): string
    {
        // ELF Header (52 bytes for 32-bit)
        $header = "\x7FELF";                  // Magic number
        $header .= "\x01";                     // Class: 32-bit
        $header .= "\x01";                     // Data: Little endian
        $header .= "\x01";                     // Version: 1
        $header .= "\x00";                     // OS/ABI: UNIX
        $header .= str_repeat("\x00", 8);      // Padding
        $header .= pack('v', 2);               // Type: ET_EXEC
        $header .= pack('v', 3);               // Machine: EM_386
        $header .= pack('V', 1);               // Version
        $header .= pack('V', 0x08048000);      // Entry point
        $header .= pack('V', 0);               // Program header offset (none)
        $header .= pack('V', 52);              // Section header offset (right after ELF header)
        $header .= pack('V', 0);               // Flags
        $header .= pack('v', 52);              // ELF header size
        $header .= pack('v', 0);               // Program header entry size
        $header .= pack('v', 0);               // Number of program headers
        $header .= pack('v', 40);              // Section header entry size
        $header .= pack('v', 4);               // Number of section headers
        $header .= pack('v', 1);               // Section name string table index

        // Section headers start at offset 52
        // Section 0: NULL
        $sh_null = pack('V', 0);               // name offset
        $sh_null .= pack('V', ELFSectionHeader::SHT_NULL);  // type
        $sh_null .= pack('V', 0);              // flags
        $sh_null .= pack('V', 0);              // addr
        $sh_null .= pack('V', 0);              // offset
        $sh_null .= pack('V', 0);              // size
        $sh_null .= pack('V', 0);              // link
        $sh_null .= pack('V', 0);              // info
        $sh_null .= pack('V', 0);              // addralign
        $sh_null .= pack('V', 0);              // entsize

        // Section 1: .shstrtab (section header string table)
        $shstrtab_content = "\x00.shstrtab\x00.strtab\x00.symtab\x00";
        $shstrtab_offset = 52 + 40 * 4;  // After headers

        $sh_shstrtab = pack('V', 1);           // name offset (".shstrtab")
        $sh_shstrtab .= pack('V', ELFSectionHeader::SHT_STRTAB);
        $sh_shstrtab .= pack('V', 0);
        $sh_shstrtab .= pack('V', 0);
        $sh_shstrtab .= pack('V', $shstrtab_offset);
        $sh_shstrtab .= pack('V', strlen($shstrtab_content));
        $sh_shstrtab .= pack('V', 0);
        $sh_shstrtab .= pack('V', 0);
        $sh_shstrtab .= pack('V', 1);
        $sh_shstrtab .= pack('V', 0);

        // Section 2: .strtab (symbol string table)
        $strtab_content = "\x00main\x00_start\x00printf\x00foo_function\x00";
        $strtab_offset = $shstrtab_offset + strlen($shstrtab_content);

        $sh_strtab = pack('V', 11);            // name offset (".strtab")
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

        // Symbol entries (16 bytes each for 32-bit)
        // Symbol 0: NULL
        $sym_null = pack('V', 0);              // name
        $sym_null .= pack('V', 0);             // value
        $sym_null .= pack('V', 0);             // size
        $sym_null .= chr(0);                   // info (binding=0, type=0)
        $sym_null .= chr(0);                   // other
        $sym_null .= pack('v', 0);             // shndx

        // Symbol 1: main (function)
        $sym_main = pack('V', 1);              // name offset ("main")
        $sym_main .= pack('V', 0x08048100);    // value (address)
        $sym_main .= pack('V', 50);            // size
        $sym_main .= chr((ELFSymbol::STB_GLOBAL << 4) | ELFSymbol::STT_FUNC);  // info
        $sym_main .= chr(0);                   // other
        $sym_main .= pack('v', 1);             // shndx (defined in section 1)

        // Symbol 2: _start (function)
        $sym_start = pack('V', 6);             // name offset ("_start")
        $sym_start .= pack('V', 0x08048000);   // value (address)
        $sym_start .= pack('V', 20);           // size
        $sym_start .= chr((ELFSymbol::STB_GLOBAL << 4) | ELFSymbol::STT_FUNC);
        $sym_start .= chr(0);
        $sym_start .= pack('v', 1);

        // Symbol 3: printf (undefined external)
        $sym_printf = pack('V', 13);           // name offset ("printf")
        $sym_printf .= pack('V', 0);           // value (0 for undefined)
        $sym_printf .= pack('V', 0);           // size
        $sym_printf .= chr((ELFSymbol::STB_GLOBAL << 4) | ELFSymbol::STT_FUNC);
        $sym_printf .= chr(0);
        $sym_printf .= pack('v', ELFSymbol::SHN_UNDEF);  // undefined

        // Symbol 4: foo_function
        $sym_foo = pack('V', 20);              // name offset ("foo_function")
        $sym_foo .= pack('V', 0x08048200);     // value (address)
        $sym_foo .= pack('V', 100);            // size
        $sym_foo .= chr((ELFSymbol::STB_LOCAL << 4) | ELFSymbol::STT_FUNC);
        $sym_foo .= chr(0);
        $sym_foo .= pack('v', 1);

        $symtab_content = $sym_null . $sym_main . $sym_start . $sym_printf . $sym_foo;

        $sh_symtab = pack('V', 19);            // name offset (".symtab")
        $sh_symtab .= pack('V', ELFSectionHeader::SHT_SYMTAB);
        $sh_symtab .= pack('V', 0);
        $sh_symtab .= pack('V', 0);
        $sh_symtab .= pack('V', $symtab_offset);
        $sh_symtab .= pack('V', strlen($symtab_content));
        $sh_symtab .= pack('V', 2);            // link to .strtab (section 2)
        $sh_symtab .= pack('V', 1);            // info
        $sh_symtab .= pack('V', 4);
        $sh_symtab .= pack('V', 16);           // entry size

        return $header . $sh_null . $sh_shstrtab . $sh_strtab . $sh_symtab .
               $shstrtab_content . $strtab_content . $symtab_content;
    }

    /**
     * Test parsing ELF magic number.
     */
    public function testParseElfMagic(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $header = $parser->getHeader();
        $this->assertNotNull($header);
    }

    /**
     * Test detecting invalid ELF magic.
     */
    public function testInvalidElfMagic(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid ELF magic');

        $invalidData = "NOT_ELF" . str_repeat("\x00", 100);
        ELFParser::fromBinary($invalidData)->parse();
    }

    /**
     * Test parsing 32-bit ELF header.
     */
    public function testParse32BitHeader(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $header = $parser->getHeader();
        $this->assertTrue($header->is32Bit());
        $this->assertFalse($header->is64Bit());
        $this->assertTrue($header->isLittleEndian());
        $this->assertSame(ELFHeader::EM_386, $header->machine);
        $this->assertSame(ELFHeader::ET_EXEC, $header->type);
        $this->assertSame(0x08048000, $header->entry);
    }

    /**
     * Test parsing section headers.
     */
    public function testParseSectionHeaders(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $sections = $parser->getSectionHeaders();
        $this->assertCount(4, $sections);

        // Check section types
        $this->assertSame(ELFSectionHeader::SHT_NULL, $sections[0]->type);
        $this->assertSame(ELFSectionHeader::SHT_STRTAB, $sections[1]->type);
        $this->assertSame(ELFSectionHeader::SHT_STRTAB, $sections[2]->type);
        $this->assertSame(ELFSectionHeader::SHT_SYMTAB, $sections[3]->type);
    }

    /**
     * Test resolving section names.
     */
    public function testResolveSectionNames(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $shstrtab = $parser->getSectionByName('.shstrtab');
        $this->assertNotNull($shstrtab);
        $this->assertSame('.shstrtab', $shstrtab->name);

        $symtab = $parser->getSectionByName('.symtab');
        $this->assertNotNull($symtab);
        $this->assertTrue($symtab->isSymbolTable());
    }

    /**
     * Test parsing symbol table.
     */
    public function testParseSymbolTable(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $symbols = $parser->getSymbols();
        $this->assertCount(5, $symbols);

        // Find main symbol
        $main = $parser->getSymbolByName('main');
        $this->assertNotNull($main);
        $this->assertSame('main', $main->name);
        $this->assertSame(0x08048100, $main->value);
        $this->assertSame(50, $main->size);
        $this->assertTrue($main->isFunction());
        $this->assertTrue($main->isGlobal());
        $this->assertTrue($main->isDefined());
    }

    /**
     * Test getting function symbols only.
     */
    public function testGetFunctions(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $functions = $parser->getFunctions();

        // Should have main, _start, foo_function (printf is undefined)
        $this->assertCount(3, $functions);

        $names = array_map(fn($s) => $s->name, $functions);
        $this->assertContains('main', $names);
        $this->assertContains('_start', $names);
        $this->assertContains('foo_function', $names);
    }

    /**
     * Test symbol lookup by address.
     */
    public function testGetSymbolAtAddress(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        // Exact address
        $symbol = $parser->getSymbolAtAddress(0x08048100);
        $this->assertNotNull($symbol);
        $this->assertSame('main', $symbol->name);

        // _start
        $symbol = $parser->getSymbolAtAddress(0x08048000);
        $this->assertNotNull($symbol);
        $this->assertSame('_start', $symbol->name);
    }

    /**
     * Test finding symbol containing address (within function body).
     */
    public function testFindSymbolContaining(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        // Address within main (0x08048100 + 25 = 0x08048119)
        $symbol = $parser->findSymbolContaining(0x08048119);
        $this->assertNotNull($symbol);
        $this->assertSame('main', $symbol->name);

        // Address within foo_function
        $symbol = $parser->findSymbolContaining(0x08048250);
        $this->assertNotNull($symbol);
        $this->assertSame('foo_function', $symbol->name);
    }

    /**
     * Test address resolution to string.
     */
    public function testResolveAddress(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        // Exact match
        $this->assertSame('main', $parser->resolveAddress(0x08048100));

        // With offset
        $this->assertSame('main+0x19', $parser->resolveAddress(0x08048119));

        // Unknown address
        $this->assertSame('0x12345678', $parser->resolveAddress(0x12345678));
    }

    /**
     * Test symbol binding types.
     */
    public function testSymbolBinding(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $main = $parser->getSymbolByName('main');
        $this->assertTrue($main->isGlobal());
        $this->assertFalse($main->isLocal());
        $this->assertSame('GLOBAL', $main->getBindingName());

        $foo = $parser->getSymbolByName('foo_function');
        $this->assertTrue($foo->isLocal());
        $this->assertFalse($foo->isGlobal());
        $this->assertSame('LOCAL', $foo->getBindingName());
    }

    /**
     * Test symbol type detection.
     */
    public function testSymbolType(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $main = $parser->getSymbolByName('main');
        $this->assertTrue($main->isFunction());
        $this->assertFalse($main->isObject());
        $this->assertSame('FUNC', $main->getTypeName());
    }

    /**
     * Test undefined symbol detection.
     */
    public function testUndefinedSymbol(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        $printf = $parser->getSymbolByName('printf');
        $this->assertNotNull($printf);
        $this->assertFalse($printf->isDefined());
        $this->assertSame(0, $printf->value);
    }

    /**
     * Test symbol search with wildcards.
     */
    public function testSearchSymbols(): void
    {
        $elfData = $this->createMinimalElf32();
        $parser = ELFParser::fromBinary($elfData)->parse();

        // Search for symbols starting with underscore
        $results = $parser->searchSymbols('_*');
        $this->assertCount(1, $results);

        // Search for symbols containing 'func'
        $results = $parser->searchSymbols('*func*');
        $this->assertCount(1, $results);
        $this->assertSame('foo_function', array_values($results)[0]->name);
    }

    /**
     * Test with real ELF file if available.
     */
    public function testWithRealElfFile(): void
    {
        // Try to find a real ELF file for testing
        $testPaths = [
            '/bin/ls',
            '/usr/bin/env',
            self::FIXTURES_DIR . 'test.elf',
        ];

        $elfPath = null;
        foreach ($testPaths as $path) {
            if (file_exists($path)) {
                // Check if it's actually an ELF file
                $magic = file_get_contents($path, false, null, 0, 4);
                if ($magic === "\x7FELF") {
                    $elfPath = $path;
                    break;
                }
            }
        }

        if ($elfPath === null) {
            $this->markTestSkipped('No real ELF file available for testing');
        }

        $parser = ELFParser::fromFile($elfPath)->parse();

        $header = $parser->getHeader();
        $this->assertNotNull($header);

        $symbols = $parser->getSymbols();
        // Real ELF files may or may not have symbols (stripped)
        $this->assertIsArray($symbols);
    }
}
