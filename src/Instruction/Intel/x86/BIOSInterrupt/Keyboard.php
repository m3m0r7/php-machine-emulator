<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86\BIOSInterrupt;

use PHPMachineEmulator\Display\Writer\WindowScreenWriter;
use PHPMachineEmulator\Instruction\RegisterType;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class Keyboard implements InterruptInterface
{
    protected bool $isTty;
    protected bool $useSDL = false;
    protected ?int $lastKeyPressed = null;
    protected int $lastKeyTime = 0;
    protected const KEY_REPEAT_DELAY_MS = 150; // Delay before key can be registered again

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

        if ($this->useSDL) {
            $this->processSDL($runtime, $ah);
        } else {
            $this->processStdin($runtime, $ah);
        }
    }

    protected function processSDL(RuntimeInterface $runtime, int $function): void
    {
        $screenWriter = $runtime->context()->screen()->screenWriter();
        assert($screenWriter instanceof WindowScreenWriter);

        // $runtime->option()->logger()->debug(sprintf('INT 16h: SDL mode, AH=0x%02X', $function));

        // Process SDL events to update keyboard state
        $screenWriter->window()->processEvents();

        switch ($function) {
            case 0x00: // Wait for keypress and return it
            case 0x10: // Extended keyboard read (same as 0x00 but for enhanced keyboard)
                $this->waitForKeypress($runtime, $screenWriter);
                break;

            case 0x01: // Check for keypress (non-blocking)
            case 0x11: // Extended keystroke status (same as 0x01 but for enhanced keyboard)
                $this->checkKeypress($runtime, $screenWriter);
                break;

            default:
                // Unsupported function, return 0
                $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::EAX, 0);
                break;
        }
    }

    protected function waitForKeypress(RuntimeInterface $runtime, WindowScreenWriter $screenWriter): void
    {
        // Reset state at start of wait
        $this->lastKeyPressed = null;

        $runtime->option()->logger()->debug('INT 16h: waitForKeypress started');

        // First, wait until all keys are released to avoid detecting
        // keys that were pressed before this call (e.g., Enter from menu selection)
        $releaseWaitStart = microtime(true);
        while (true) {
            $screenWriter->window()->processEvents();
            $keyCode = $screenWriter->pollKeyPress();
            if ($keyCode === null) {
                // All keys released
                break;
            }
            // Timeout after 500ms to avoid infinite loop
            if ((microtime(true) - $releaseWaitStart) > 0.5) {
                $runtime->option()->logger()->debug('INT 16h: timeout waiting for key release');
                break;
            }
            usleep(1000);
        }

        $runtime->option()->logger()->debug('INT 16h: waiting for new keypress');

        // Wait until a key is pressed
        while (true) {
            $screenWriter->window()->processEvents();
            $keyCode = $screenWriter->pollKeyPress();

            if ($keyCode !== null) {
                // Accept any new key
                if ($keyCode !== $this->lastKeyPressed) {
                    $this->lastKeyPressed = $keyCode;

                    $runtime->option()->logger()->debug(sprintf(
                        'INT 16h: key pressed, keyCode=0x%04X (AH=0x%02X, AL=0x%02X char=%s)',
                        $keyCode,
                        ($keyCode >> 8) & 0xFF,
                        $keyCode & 0xFF,
                        chr($keyCode & 0xFF)
                    ));

                    // AH = scan code, AL = ASCII
                    $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::EAX, $keyCode & 0xFFFF);
                    return;
                }
            } else {
                // No key pressed, reset tracking
                $this->lastKeyPressed = null;
            }

            usleep(1000); // 1ms delay to avoid busy loop
        }
    }

    protected function checkKeypress(RuntimeInterface $runtime, WindowScreenWriter $screenWriter): void
    {
        $currentTimeMs = (int)(microtime(true) * 1000);
        $keyCode = $screenWriter->pollKeyPress();

        if ($keyCode !== null) {
            // Check if this is a new keypress or enough time has passed
            if ($keyCode !== $this->lastKeyPressed ||
                ($currentTimeMs - $this->lastKeyTime) > self::KEY_REPEAT_DELAY_MS) {
                $this->lastKeyPressed = $keyCode;
                $this->lastKeyTime = $currentTimeMs;

                // Key available: ZF=0, AX=keycode
                $runtime->memoryAccessor()->enableUpdateFlags(false)->write16Bit(RegisterType::EAX, $keyCode & 0xFFFF);
                // Clear ZF (key available)
                $runtime->memoryAccessor()->setZeroFlag(false);
            } else {
                // Same key still held, treat as no new keypress
                $this->setNoKeyAvailable($runtime);
            }
        } else {
            // No key pressed, reset tracking
            $this->lastKeyPressed = null;
            $this->setNoKeyAvailable($runtime);
        }
    }

    protected function setNoKeyAvailable(RuntimeInterface $runtime): void
    {
        // No key available: ZF=1
        $runtime->memoryAccessor()->setZeroFlag(true);
    }

    protected function processStdin(RuntimeInterface $runtime, int $function): void
    {
        $byte = $runtime
            ->option()
            ->IO()
            ->input()
            ->byte();

        // NOTE: Convert the break line (0x0A) to the carriage return (0x0D)
        //       because it is applying duplication breaking lines in using terminal.
        if ($byte === 0x0A) {
            $byte = 0x0D;
        }

        $runtime
            ->memoryAccessor()
            ->writeToLowBit(
                RegisterType::EAX,
                $byte,
            );
    }
}
