<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Ticker;

use PHPMachineEmulator\Display\Writer\WindowScreenWriter;
use PHPMachineEmulator\Instruction\RegisterType;
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

        // If CPU is waiting for key and we have one, complete the operation
        if ($ctx->isWaitingForKey() && $ctx->hasKey()) {
            $this->completeKeyboardWait($ctx, $runtime);
        }
    }

    /**
     * Poll keyboard input from SDL or stdin.
     */
    private function pollKeyboardInput(KeyboardContextInterface $ctx, RuntimeInterface $runtime): void
    {
        $screenWriter = $runtime->context()->screen()->screenWriter();

        if ($screenWriter instanceof WindowScreenWriter) {
            // SDL mode: poll events and check for key press
            $screenWriter->window()->processEvents();
            $screenWriter->flushIfNeeded();

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

    /**
     * Complete a keyboard wait operation.
     */
    private function completeKeyboardWait(KeyboardContextInterface $ctx, RuntimeInterface $runtime): void
    {
        $function = $ctx->getWaitingFunction();

        switch ($function) {
            case 0x00: // Wait for keypress
            case 0x10: // Extended keyboard read
                $key = $ctx->dequeueKey();
                if ($key !== null) {
                    $keyCode = ($key['scancode'] << 8) | $key['ascii'];
                    $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $keyCode);
                    $ctx->setWaitingForKey(false);

                    $runtime->option()->logger()->debug(sprintf(
                        'DeviceManagerTicker: key wait completed, keyCode=0x%04X (AH=0x%02X, AL=0x%02X)',
                        $keyCode,
                        $key['scancode'],
                        $key['ascii']
                    ));
                }
                break;

            case 0x01: // Check keystroke (non-blocking)
            case 0x11: // Extended keystroke status
                // These should not set waiting state, but handle just in case
                $key = $ctx->peekKey();
                if ($key !== null) {
                    $keyCode = ($key['scancode'] << 8) | $key['ascii'];
                    $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $keyCode);
                    $runtime->memoryAccessor()->setZeroFlag(false);
                } else {
                    $runtime->memoryAccessor()->setZeroFlag(true);
                }
                $ctx->setWaitingForKey(false);
                break;

            default:
                $ctx->setWaitingForKey(false);
                break;
        }
    }
}
