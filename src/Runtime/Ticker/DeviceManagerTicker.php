<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Ticker;

use PHPMachineEmulator\Display\Writer\WindowScreenWriter;
use PHPMachineEmulator\Runtime\Device\DeviceManagerInterface;
use PHPMachineEmulator\Runtime\Device\KeyboardContextInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * Ticker for device manager.
 * Handles periodic device processing (keyboard polling, etc.).
 */
class DeviceManagerTicker implements TickerInterface
{
    private const TICK_INTERVAL = 100; // Execute every 100 instructions

    public function __construct(
        private DeviceManagerInterface $deviceManager,
    ) {
    }

    public function tick(RuntimeInterface $runtime): void
    {
        // Process all keyboards
        foreach ($this->deviceManager->keyboards() as $keyboard) {
            $this->tickKeyboard($keyboard, $runtime);
        }

        // Future: add more device types here
        // foreach ($this->deviceManager->mice() as $mouse) {
        //     $this->tickMouse($mouse, $runtime);
        // }
    }

    public function interval(): int
    {
        return self::TICK_INTERVAL;
    }

    /**
     * Process keyboard device tick.
     */
    private function tickKeyboard(KeyboardContextInterface $ctx, RuntimeInterface $runtime): void
    {
        // Poll for key input
        $this->pollKeyboardInput($ctx, $runtime);
    }

    /**
     * Poll keyboard input from SDL or stdin.
     */
    private function pollKeyboardInput(KeyboardContextInterface $ctx, RuntimeInterface $runtime): void
    {
        $screenWriter = $runtime->context()->screen()->screenWriter();
        $screenWriter->flushIfNeeded();

        if ($screenWriter instanceof WindowScreenWriter) {
            // SDL mode: check for key press
            $keyCode = $screenWriter->pollKeyPress();
            if ($keyCode !== null) {
                $scancode = ($keyCode >> 8) & 0xFF;
                $ascii = $keyCode & 0xFF;

                // Only enqueue if buffer is empty (avoid duplicate keys)
                if (!$ctx->hasKey()) {
                    $ctx->enqueueKey($scancode, $ascii);
                }
            }
        } else {
            // Stdin mode: check for available input (non-blocking)
            $this->pollStdinInput($ctx, $runtime);
        }
    }

    /**
     * Poll stdin for keyboard input (non-blocking).
     */
    private function pollStdinInput(KeyboardContextInterface $ctx, RuntimeInterface $runtime): void
    {
        // Only poll if buffer is empty
        if ($ctx->hasKey()) {
            return;
        }

        // Only poll when waiting for key (non-blocking read otherwise unnecessary)
        if (!$ctx->isWaitingForKey()) {
            return;
        }

        $input = $runtime->option()->IO()->input();

        // Check if input is available (non-blocking)
        // For emulated streams in tests, always try to read when waiting
        $byte = $input->byte();
        if ($byte !== null) {
            // Convert LF to CR for terminal compatibility
            if ($byte === 0x0A) {
                $byte = 0x0D;
            }
            // For stdin, scancode is 0, ascii is the byte
            $ctx->enqueueKey(0, $byte);
        }
    }

}
