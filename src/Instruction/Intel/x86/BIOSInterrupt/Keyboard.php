<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Display\Writer\WindowScreenWriter;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\Device\KeyboardContextInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

/**
 * INT 16h - Keyboard BIOS interrupt handler.
 * Uses non-blocking async approach via DeviceManager/KeyboardContext.
 */
class Keyboard implements InterruptInterface
{
    protected bool $isTty = false;
    protected bool $useSDL = false;

    public function __construct(protected RuntimeInterface $runtime)
    {
        // Check if we're using WindowScreenWriter (SDL)
        $screenWriter = $runtime->context()->screen()->screenWriter();
        if ($screenWriter instanceof WindowScreenWriter) {
            $this->useSDL = true;
        } else {
            $this->isTty = function_exists('posix_isatty') && posix_isatty(STDIN);

            if ($this->isTty) {
                // NOTE: Disable canonical mode and echo texts
                system('stty -icanon -echo');
            }

            $this->runtime->shutdown(
                // NOTE: Rollback to sane for stty
                fn () => $this->isTty ? system('stty sane') : null
            );
        }
    }

    public function process(RuntimeInterface $runtime): void
    {
        $ah = $runtime->memoryAccessor()->fetch(RegisterType::EAX)->asHighBit();

        // Get the first keyboard context
        $keyboards = $runtime->context()->devices()->keyboards();
        $keyboard = $keyboards[0] ?? null;

        if ($keyboard === null) {
            // No keyboard registered, return 0
            $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, 0);
            return;
        }

        $runtime->option()->logger()->debug(sprintf('INT 16h: AH=0x%02X', $ah));

        switch ($ah) {
            case 0x00: // Wait for keypress and return it
            case 0x10: // Extended keyboard read (same as 0x00 but for enhanced keyboard)
                $this->handleWaitForKeypress($runtime, $keyboard, $ah);
                break;

            case 0x01: // Check for keypress (non-blocking)
            case 0x11: // Extended keystroke status (same as 0x01 but for enhanced keyboard)
                $this->handleCheckKeypress($runtime, $keyboard);
                break;

            case 0x02: // Get shift flags
                // Return shift key status (simplified: no modifiers pressed)
                $runtime->memoryAccessor()->writeToLowBit(RegisterType::EAX, 0x00);
                break;

            case 0x12: // Extended shift flags
                // Return extended shift key status
                $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, 0x0000);
                break;

            default:
                // Unsupported function, return 0
                $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, 0);
                break;
        }
    }

    /**
     * Handle INT 16h AH=0x00/0x10 - Wait for keypress.
     * Non-blocking: sets waiting state and returns immediately.
     * DeviceManagerTicker will complete the operation when key is available.
     */
    private function handleWaitForKeypress(
        RuntimeInterface $runtime,
        KeyboardContextInterface $keyboard,
        int $function
    ): void {
        // Check if key is already available
        if ($keyboard->hasKey()) {
            $key = $keyboard->dequeueKey();
            if ($key !== null) {
                $keyCode = ($key['scancode'] << 8) | $key['ascii'];
                $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $keyCode);

                $runtime->option()->logger()->debug(sprintf(
                    'INT 16h: immediate key available, keyCode=0x%04X',
                    $keyCode
                ));
                return;
            }
        }

        // No key available, try to poll input directly
        if ($this->useSDL) {
            // SDL mode: poll events and check for key press
            $screenWriter = $runtime->context()->screen()->screenWriter();
            if ($screenWriter instanceof WindowScreenWriter) {
                $screenWriter->window()->processEvents();
                $screenWriter->flushIfNeeded();

                $keyCode = $screenWriter->pollKeyPress();
                if ($keyCode !== null) {
                    $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $keyCode);

                    $runtime->option()->logger()->debug(sprintf(
                        'INT 16h: SDL polled key, keyCode=0x%04X',
                        $keyCode
                    ));
                    return;
                }
            }
        } else {
            // Non-SDL mode: poll from IO input
            $byte = $runtime->option()->IO()->input()->byte();
            if ($byte !== null && $byte !== 0) {
                // Convert LF to CR for terminal compatibility
                if ($byte === 0x0A) {
                    $byte = 0x0D;
                }
                // Return key in AX (scancode 0, ascii = byte)
                $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $byte);

                $runtime->option()->logger()->debug(sprintf(
                    'INT 16h: polled key, ascii=0x%02X',
                    $byte
                ));
                return;
            }
        }

        // Set waiting state for async completion by DeviceManagerTicker
        $stopEnv = getenv('PHPME_STOP_ON_INT16_WAIT');
        if ($stopEnv !== false && $stopEnv !== '' && $stopEnv !== '0') {
            $runtime->option()->logger()->warning(sprintf('INT 16h: waiting for key (AH=0x%02X)', $function));
            throw new HaltException('Stopped by PHPME_STOP_ON_INT16_WAIT');
        }

        $keyboard->setWaitingForKey(true, $function);

        $runtime->option()->logger()->debug('INT 16h: waiting for key (async)');

        // Rewind IP to re-execute INT 16h instruction on next cycle
        // INT 16h is a 2-byte instruction (CD 16)
        $currentOffset = $runtime->memory()->offset();
        $runtime->memory()->setOffset($currentOffset - 2);

        // Return with AX=0 for now, will be updated by DeviceManagerTicker
        $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, 0);
    }

    /**
     * Handle INT 16h AH=0x01/0x11 - Check for keypress (non-blocking).
     */
    private function handleCheckKeypress(
        RuntimeInterface $runtime,
        KeyboardContextInterface $keyboard
    ): void {
        if ($keyboard->hasKey()) {
            $key = $keyboard->peekKey();
            if ($key !== null) {
                $keyCode = ($key['scancode'] << 8) | $key['ascii'];
                $runtime->memoryAccessor()->write16Bit(RegisterType::EAX, $keyCode);
                // Clear ZF - key available
                $runtime->memoryAccessor()->setZeroFlag(false);

                $runtime->option()->logger()->debug(sprintf(
                    'INT 16h: key check - available, keyCode=0x%04X, ZF=0',
                    $keyCode
                ));
                return;
            }
        }

        // No key available
        // Set ZF - no key available
        $runtime->memoryAccessor()->setZeroFlag(true);

        $runtime->option()->logger()->debug('INT 16h: key check - none available, ZF=1');
    }
}
