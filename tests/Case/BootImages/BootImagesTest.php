<?php

declare(strict_types=1);

namespace Tests\Case\BootImages;

use PHPMachineEmulator\BootType;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\IO\Buffer;
use PHPMachineEmulator\Stream\ISO\ISOBootImageStream;
use PHPMachineEmulator\Stream\ISO\ISOStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\CreateApplication;
use Tests\Utils\OutputWaiterMatchedException;
use Tests\Utils\OutputWaiterTicker;
use Tests\Utils\OutputWaiterTimeoutException;

class BootImagesTest extends TestCase
{
    use CreateApplication;

    private const TIMEOUT_SECONDS = 300.0;

    public static function bootImageProvider(): array
    {
        $root = dirname(__DIR__, 3);

        return [
            'ms-dos' => [$root . '/images/MS-DOS_6.22.iso', ['MSCDEX Version 2.23']],
            'tinycore' => [$root . '/images/TinyCore-16.2.iso', ['GRUB', 'Press ENTER to boot']],
            'mikeos' => [$root . '/images/mikeos.iso', ['Thanks for trying out MIKEOS!']],
        ];
    }

    #[DataProvider('bootImageProvider')]
    public function testBootImageShowsExpectedText(string $imagePath, array $needles): void
    {
        if (!is_file($imagePath)) {
            $this->markTestSkipped('Boot image not found: ' . $imagePath);
        }

        $isoStream = new ISOStream($imagePath);
        $bootStream = new ISOBootImageStream($isoStream);

        $option = self::createOption();
        $machine = self::createMachine($bootStream, $option, BootType::EL_TORITO);

        $output = $option->IO()->output();
        assert($output instanceof Buffer);

        $ticker = new OutputWaiterTicker($output, $needles, self::TIMEOUT_SECONDS);
        $machine->runtime()->tickerRegistry()->register($ticker);

        try {
            $machine->runtime()->start();
        } catch (OutputWaiterMatchedException) {
        } catch (OutputWaiterTimeoutException $e) {
            $this->fail($e->getMessage());
        } catch (ExitException | HaltException) {
            // Allow exit if expected text already appeared.
        }

        $buffer = $output->getBuffer();
        foreach ($needles as $needle) {
            $this->assertStringContainsString($needle, $buffer);
        }
    }
}
