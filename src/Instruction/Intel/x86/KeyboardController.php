<?php
declare(strict_types=1);

namespace PHPMachineEmulator\Instruction\Intel\x86;

use PHPMachineEmulator\Display\Window\SDLKeyMapper;
use PHPMachineEmulator\Display\Window\SDLScancode;
use PHPMachineEmulator\Display\Writer\WindowScreenWriter;
use PHPMachineEmulator\Runtime\RuntimeInterface;

class KeyboardController
{
    private array $queue = []; // [['data' => int, 'aux' => bool], ...]
    private bool $keyboardEnabled = true;
    private bool $mouseEnabled = false;
    private bool $inputBufferFull = false;
    private bool $expectingCommandByte = false;
    private bool $expectingOutputPort = false;
    private bool $expectingOutputBuffer = false;
    private bool $expectingMouseCommand = false;
    private int $commandByte = 0x00;
    private bool $mouseAwaitingData = false;

    // SDL key tracking
    private ?int $lastSDLScancode = null;
    private int $lastSDLKeyTime = 0;
    private const SDL_KEY_REPEAT_DELAY_MS = 150;

    public function __construct(private PicState $picState)
    {
    }

    public function enqueueScancode(int $code): void
    {
        if (!$this->keyboardEnabled) {
            return;
        }
        $this->enqueue($code, false);
    }

    public function enqueueMouseByte(int $byte, bool $force = false): void
    {
        if (!$force && (!$this->mouseEnabled || (($this->commandByte & 0x20) !== 0))) {
            return;
        }
        $this->enqueue($byte, true);
    }

    public function readData(RuntimeInterface $runtime): int
    {
        if (empty($this->queue)) {
            // Try SDL input first
            $this->pollSDLInput($runtime);
        }

        if (empty($this->queue)) {
            // Fallback to stdin
            try {
                $byte = $runtime->option()->IO()->input()->byte();
                if ($byte !== null) {
                    $this->enqueueScancode($byte);
                }
            } catch (\Throwable) {
                // ignore input failures
            }
        }

        if (empty($this->queue)) {
            return 0;
        }
        $item = array_shift($this->queue);
        return $item['data'];
    }

    /**
     * Poll SDL for keyboard input and enqueue scancodes
     */
    private function pollSDLInput(RuntimeInterface $runtime): void
    {
        $screenWriter = $runtime->context()->screen()->screenWriter();
        if (!($screenWriter instanceof WindowScreenWriter)) {
            return;
        }

        // Process SDL events
        $screenWriter->window()->processEvents();

        // Get pressed keys
        $pressed = $screenWriter->window()->getPressedKeys();
        if (empty($pressed)) {
            $this->lastSDLScancode = null;
            return;
        }

        $currentTimeMs = (int)(microtime(true) * 1000);

        foreach ($pressed as $sdlScancode) {
            // Skip modifier keys using enum method
            if ($sdlScancode->isModifier()) {
                continue;
            }

            // Check for key repeat
            if ($sdlScancode->value === $this->lastSDLScancode &&
                ($currentTimeMs - $this->lastSDLKeyTime) < self::SDL_KEY_REPEAT_DELAY_MS) {
                continue;
            }

            // Convert SDL scancode to BIOS scancode
            $biosScancode = SDLKeyMapper::toBiosScancode($sdlScancode->value);
            if ($biosScancode === null) {
                continue;
            }

            $this->lastSDLScancode = $sdlScancode->value;
            $this->lastSDLKeyTime = $currentTimeMs;

            // Enqueue the BIOS scancode (make code)
            $this->enqueueScancode($biosScancode);

            // Only process one key at a time
            return;
        }
    }

    public function readStatus(): int
    {
        $status = 0;
        if (!empty($this->queue)) {
            $status |= 0x01; // OBF
            if (($this->queue[0]['aux'] ?? false) === true) {
                $status |= 0x20; // mouse data pending
            }
        }
        if ($this->inputBufferFull) {
            $status |= 0x02; // IBF
        }
        $status |= 0x04; // system flag set
        if (!$this->keyboardEnabled || (($this->commandByte & 0x10) !== 0)) {
            $status |= 0x10; // keyboard inhibited
        }
        return $status;
    }

    /**
     * Poll SDL and update queue before status check
     * Call this from port 0x64 read to ensure SDL input is captured
     */
    public function pollAndReadStatus(RuntimeInterface $runtime): int
    {
        // Poll SDL input if queue is empty
        if (empty($this->queue)) {
            $this->pollSDLInput($runtime);
        }
        return $this->readStatus();
    }

    public function writeCommand(int $command, RuntimeInterface $runtime): void
    {
        $this->inputBufferFull = true;
        $command &= 0xFF;

        switch ($command) {
            case 0x20: // read command byte
                $this->enqueue($this->commandByte, false);
                break;
            case 0x60: // write command byte next
                $this->expectingCommandByte = true;
                break;
            case 0xAA: // controller self-test
                $this->enqueue(0x55, false);
                break;
            case 0xAB: // keyboard test
                $this->enqueue(0x00, false);
                break;
            case 0xA7: // disable mouse
                $this->mouseEnabled = false;
                break;
            case 0xA8: // enable mouse
                $this->mouseEnabled = true;
                break;
            case 0xA9: // mouse port test
                $this->enqueue(0x00, false);
                break;
            case 0xAD: // disable keyboard
                $this->keyboardEnabled = false;
                break;
            case 0xAE: // enable keyboard
                $this->keyboardEnabled = true;
                break;
            case 0xD1: // write output port
                $this->expectingOutputPort = true;
                $runtime->context()->cpu()->setWaitingA20OutputPort(true);
                break;
            case 0xD2: // write output buffer for CPU (keyboard)
            case 0xD3: // write output buffer for keyboard interface
                $this->expectingOutputBuffer = true;
                break;
            case 0xD4: // write to mouse
                $this->expectingMouseCommand = true;
                break;
            default:
                // unhandled commands are ignored
                break;
        }

        if (!$this->expectingCommandByte && !$this->expectingOutputPort && !$this->expectingOutputBuffer && !$this->expectingMouseCommand) {
            $this->inputBufferFull = false;
        }
    }

    public function writeDataPort(int $value, RuntimeInterface $runtime): void
    {
        $this->inputBufferFull = true;
        $value &= 0xFF;

        if ($this->expectingCommandByte) {
            $this->commandByte = $value;
            // bits 4/5 disable keyboard/mouse clock
            $this->keyboardEnabled = ($this->commandByte & 0x10) === 0;
            $this->mouseEnabled = ($this->commandByte & 0x20) === 0;
            $this->expectingCommandByte = false;
            $this->inputBufferFull = false;
            return;
        }

        if ($this->expectingOutputPort) {
            $this->expectingOutputPort = false;
            $runtime->context()->cpu()->setWaitingA20OutputPort(false);
            $runtime->context()->cpu()->enableA20(($value & 0x02) !== 0);
            $this->inputBufferFull = false;
            return;
        }

        if ($this->expectingOutputBuffer) {
            $this->enqueue($value, false);
            $this->expectingOutputBuffer = false;
            $this->inputBufferFull = false;
            return;
        }

        if ($this->expectingMouseCommand) {
            $this->expectingMouseCommand = false;
            $this->handleMouseCommand($value);
            $this->inputBufferFull = false;
            return;
        }

        if ($this->mouseAwaitingData) {
            $this->mouseAwaitingData = false;
            $this->enqueueAck(true);
            $this->inputBufferFull = false;
            return;
        }

        $this->handleKeyboardCommand($value);
        $this->inputBufferFull = false;
    }

    private function handleKeyboardCommand(int $command): void
    {
        $command &= 0xFF;
        switch ($command) {
            case 0xFF: // reset
                $this->enqueueAck(false);
                $this->enqueue(0xAA, false);
                $this->keyboardEnabled = true;
                break;
            case 0xEE: // echo
                $this->enqueue(0xEE, false);
                break;
            case 0xF2: // identify
                $this->enqueueAck(false);
                $this->enqueue(0xAB, false);
                $this->enqueue(0x83, false);
                break;
            case 0xF4: // enable scanning
                $this->enqueueAck(false);
                $this->keyboardEnabled = true;
                break;
            case 0xF5: // disable scanning
                $this->enqueueAck(false);
                $this->keyboardEnabled = false;
                break;
            case 0xF6: // set defaults
                $this->enqueueAck(false);
                $this->keyboardEnabled = true;
                break;
            default:
                $this->enqueueAck(false);
                break;
        }
    }

    private function handleMouseCommand(int $command): void
    {
        $command &= 0xFF;
        switch ($command) {
            case 0xFF: // reset
                $this->enqueueAck(true);
                $this->mouseEnabled = true;
                $this->enqueueMouseByte(0xAA, true);
                $this->enqueueMouseByte(0x00, true); // standard PS/2 mouse ID
                break;
            case 0xF2: // identify
                $this->enqueueAck(true);
                $this->enqueueMouseByte(0x00, true); // standard mouse ID
                break;
            case 0xF4: // enable streaming
            case 0xF5: // disable streaming
            case 0xF6: // set defaults
            case 0xE6: // scaling 1:1
            case 0xE7: // scaling 2:1
            case 0xF3: // set sample rate (ignore data)
                $this->enqueueAck(true);
                if ($command === 0xF3) {
                    $this->mouseAwaitingData = true;
                }
                break;
            default:
                $this->enqueueAck(true);
                break;
        }
    }

    private function enqueueAck(bool $aux): void
    {
        $this->enqueue(0xFA, $aux);
    }

    private function enqueue(int $byte, bool $aux): void
    {
        $this->queue[] = [
            'data' => $byte & 0xFF,
            'aux' => $aux,
        ];

        // Interrupt gate controlled by command byte bits 0/1
        $irqEnabled = $aux
            ? (($this->commandByte & 0x02) === 0x02)
            : (($this->commandByte & 0x01) === 0x01);

        if ($irqEnabled) {
            $this->picState->raiseIrq($aux ? 12 : 1);
        }
    }
}
