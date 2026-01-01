<?php

declare(strict_types=1);

namespace Tests\Unit\Device;

use PHPMachineEmulator\Runtime\Device\KeyboardContext;
use PHPMachineEmulator\Runtime\Ticker\DeviceManagerTicker;
use PHPUnit\Framework\TestCase;
use Tests\Utils\TestRuntime;

final class DeviceManagerTickerTest extends TestCase
{
    public function testDoesNotConsumeBufferedKeyWhileCpuIsWaiting(): void
    {
        $runtime = new TestRuntime();

        $keyboard = new KeyboardContext();
        $keyboard->setWaitingForKey(true, 0x00);
        $keyboard->enqueueKey(0x1C, 0x0D);

        $runtime->context()->devices()->register($keyboard);

        $ticker = new DeviceManagerTicker($runtime->context()->devices());
        $ticker->tick($runtime);

        $this->assertTrue($keyboard->isWaitingForKey());
        $this->assertTrue($keyboard->hasKey());
        $this->assertSame(['scancode' => 0x1C, 'ascii' => 0x0D], $keyboard->peekKey());
    }
}
