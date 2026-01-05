<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI\UEFIEnvironment;

use PHPMachineEmulator\Runtime\Device\KeyboardContextInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;

trait UEFIEnvironmentConsole
{
    private function buildTextInput(): void
    {
        $reset = $this->dispatcher->register('TextIn.Reset', fn(RuntimeInterface $runtime) => $this->textInReset($runtime));
        $readKey = $this->dispatcher->register('TextIn.ReadKeyStroke', fn(RuntimeInterface $runtime) => $this->textInReadKeyStroke($runtime));
        $readKeyEx = $this->dispatcher->register('TextInEx.ReadKeyStrokeEx', fn(RuntimeInterface $runtime) => $this->textInReadKeyStrokeEx($runtime));
        $setState = $this->dispatcher->register('TextInEx.SetState', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $registerNotify = $this->dispatcher->register('TextInEx.RegisterKeyNotify', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));
        $unregisterNotify = $this->dispatcher->register('TextInEx.UnregisterKeyNotify', fn(RuntimeInterface $runtime) => $this->returnStatus($runtime, 0));

        $this->waitForKeyEvent = $this->allocateHandle();
        $this->events[$this->waitForKeyEvent] = [
            'signaled' => false,
            'timer_deadline' => null,
            'timer_period' => 0.0,
            'type' => 0,
        ];

        $ptrSize = $this->pointerSize;
        $this->simpleTextIn = $this->allocator->allocateZeroed($ptrSize * 3, $this->pointerAlign);
        $this->writePtr($this->simpleTextIn, $reset);
        $this->writePtr($this->simpleTextIn + $ptrSize, $readKey);
        $this->writePtr($this->simpleTextIn + ($ptrSize * 2), $this->waitForKeyEvent);

        $this->registerHandleProtocol($this->consoleInHandle, self::GUID_SIMPLE_TEXT_IN, $this->simpleTextIn);
        $this->protocolRegistry[self::GUID_SIMPLE_TEXT_IN] = $this->simpleTextIn;

        $this->simpleTextInEx = $this->allocator->allocateZeroed($ptrSize * 6, $this->pointerAlign);
        $this->writePtr($this->simpleTextInEx, $reset);
        $this->writePtr($this->simpleTextInEx + $ptrSize, $readKeyEx);
        $this->writePtr($this->simpleTextInEx + ($ptrSize * 2), $this->waitForKeyEvent);
        $this->writePtr($this->simpleTextInEx + ($ptrSize * 3), $setState);
        $this->writePtr($this->simpleTextInEx + ($ptrSize * 4), $registerNotify);
        $this->writePtr($this->simpleTextInEx + ($ptrSize * 5), $unregisterNotify);

        $this->registerHandleProtocol($this->consoleInHandle, self::GUID_SIMPLE_TEXT_IN_EX, $this->simpleTextInEx);
        $this->protocolRegistry[self::GUID_SIMPLE_TEXT_IN_EX] = $this->simpleTextInEx;
    }

    private function buildTextOutput(): void
    {
        $reset = $this->dispatcher->register('TextOut.Reset', fn(RuntimeInterface $runtime) => $this->textOutReset($runtime));
        $output = $this->dispatcher->register('TextOut.OutputString', fn(RuntimeInterface $runtime) => $this->textOutOutputString($runtime));
        $test = $this->dispatcher->register('TextOut.TestString', fn(RuntimeInterface $runtime) => $this->textOutTestString($runtime));
        $query = $this->dispatcher->register('TextOut.QueryMode', fn(RuntimeInterface $runtime) => $this->textOutQueryMode($runtime));
        $setMode = $this->dispatcher->register('TextOut.SetMode', fn(RuntimeInterface $runtime) => $this->textOutSetMode($runtime));
        $setAttr = $this->dispatcher->register('TextOut.SetAttribute', fn(RuntimeInterface $runtime) => $this->textOutSetAttribute($runtime));
        $clear = $this->dispatcher->register('TextOut.ClearScreen', fn(RuntimeInterface $runtime) => $this->textOutClearScreen($runtime));
        $setCursor = $this->dispatcher->register('TextOut.SetCursorPosition', fn(RuntimeInterface $runtime) => $this->textOutSetCursorPosition($runtime));
        $enableCursor = $this->dispatcher->register('TextOut.EnableCursor', fn(RuntimeInterface $runtime) => $this->textOutEnableCursor($runtime));

        $this->simpleTextOutMode = $this->allocator->allocateZeroed(32, $this->pointerAlign);
        $this->mem->writeU32($this->simpleTextOutMode, 1);
        $this->mem->writeU32($this->simpleTextOutMode + 4, 0);
        $this->mem->writeU32($this->simpleTextOutMode + 8, 0x07);
        $this->mem->writeU32($this->simpleTextOutMode + 12, 0);
        $this->mem->writeU32($this->simpleTextOutMode + 16, 0);
        $this->mem->writeU32($this->simpleTextOutMode + 20, 1);

        $ptrSize = $this->pointerSize;
        $this->simpleTextOut = $this->allocator->allocateZeroed($ptrSize * 10, $this->pointerAlign);
        $offset = $this->simpleTextOut;
        $this->writePtr($offset, $reset);
        $offset += $ptrSize;
        $this->writePtr($offset, $output);
        $offset += $ptrSize;
        $this->writePtr($offset, $test);
        $offset += $ptrSize;
        $this->writePtr($offset, $query);
        $offset += $ptrSize;
        $this->writePtr($offset, $setMode);
        $offset += $ptrSize;
        $this->writePtr($offset, $setAttr);
        $offset += $ptrSize;
        $this->writePtr($offset, $clear);
        $offset += $ptrSize;
        $this->writePtr($offset, $setCursor);
        $offset += $ptrSize;
        $this->writePtr($offset, $enableCursor);
        $offset += $ptrSize;
        $this->writePtr($offset, $this->simpleTextOutMode);

        $this->registerHandleProtocol($this->consoleOutHandle, self::GUID_SIMPLE_TEXT_OUT, $this->simpleTextOut);
        $this->protocolRegistry[self::GUID_SIMPLE_TEXT_OUT] = $this->simpleTextOut;
    }

    private function textOutReset(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textOutOutputString(RuntimeInterface $runtime): void
    {
        $stringPtr = $this->arg($runtime, 1);
        $text = $this->mem->readUtf16String($stringPtr);
        if ($text !== '') {
            if ($this->textOutLogCount < 200) {
                $runtime->option()->logger()->warning(sprintf('TEXT_OUT: %s', $text));
                $this->textOutLogCount++;
            }
            $runtime->context()->screen()->write($text);
        }
        $this->returnStatus($runtime, 0);
    }

    private function textOutTestString(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textOutQueryMode(RuntimeInterface $runtime): void
    {
        $columnsPtr = $this->arg($runtime, 2);
        $rowsPtr = $this->arg($runtime, 3);
        $this->writeUintN($columnsPtr, 80);
        $this->writeUintN($rowsPtr, 25);
        $this->returnStatus($runtime, 0);
    }

    private function textOutSetMode(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textOutSetAttribute(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textOutClearScreen(RuntimeInterface $runtime): void
    {
        $runtime->context()->screen()->clear();
        $this->returnStatus($runtime, 0);
    }

    private function textOutSetCursorPosition(RuntimeInterface $runtime): void
    {
        $row = $this->arg($runtime, 2);
        $col = $this->arg($runtime, 1);
        $runtime->context()->screen()->setCursorPosition((int) $row, (int) $col);
        $this->returnStatus($runtime, 0);
    }

    private function textOutEnableCursor(RuntimeInterface $runtime): void
    {
        $this->returnStatus($runtime, 0);
    }

    private function textInReset(RuntimeInterface $runtime): void
    {
        $keyboard = $this->firstKeyboard();
        if ($keyboard !== null) {
            $keyboard->clearBuffer();
        }
        $this->returnStatus($runtime, 0);
    }

    private function textInReadKeyStroke(RuntimeInterface $runtime): void
    {
        $keyPtr = $this->arg($runtime, 1);
        $keyboard = $this->firstKeyboard();
        if ($keyboard === null) {
            $this->returnStatus($runtime, $this->efiError(6));
            return;
        }

        $key = $keyboard->dequeueKey();
        if ($key === null) {
            $keyboard->setWaitingForKey(true);
            $this->returnStatus($runtime, $this->efiError(6));
            return;
        }

        $keyboard->setWaitingForKey(false);
        $scan = $key['scancode'] & 0xFF;
        $ascii = $key['ascii'] & 0xFF;
        $this->mem->writeU16($keyPtr, $scan);
        $this->mem->writeU16($keyPtr + 2, $ascii);
        $this->returnStatus($runtime, 0);
    }

    private function textInReadKeyStrokeEx(RuntimeInterface $runtime): void
    {
        $keyPtr = $this->arg($runtime, 1);
        $keyboard = $this->firstKeyboard();
        if ($keyboard === null) {
            $this->returnStatus($runtime, $this->efiError(6));
            return;
        }

        $key = $keyboard->dequeueKey();
        if ($key === null) {
            $keyboard->setWaitingForKey(true);
            $this->returnStatus($runtime, $this->efiError(6));
            return;
        }

        $keyboard->setWaitingForKey(false);
        $scan = $key['scancode'] & 0xFF;
        $ascii = $key['ascii'] & 0xFF;
        $this->mem->writeU16($keyPtr, $scan);
        $this->mem->writeU16($keyPtr + 2, $ascii);
        $this->mem->writeU32($keyPtr + 4, 0);
        $this->mem->writeU32($keyPtr + 8, 0);
        $this->returnStatus($runtime, 0);
    }

    private function firstKeyboard(): ?KeyboardContextInterface
    {
        foreach ($this->runtime->context()->devices()->keyboards() as $keyboard) {
            return $keyboard;
        }
        return null;
    }
}
