<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime\Device;

/**
 * Keyboard device context implementation.
 * Holds keyboard state only - processing logic is in DeviceManager.
 */
class KeyboardContext implements KeyboardContextInterface
{
    public const NAME = 'keyboard';

    private bool $waitingForKey = false;
    private int $waitingFunction = 0x00;

    /** @var array<int, array{scancode: int, ascii: int}> */
    private array $keyBuffer = [];

    public function name(): string
    {
        return self::NAME;
    }

    public function isWaitingForKey(): bool
    {
        return $this->waitingForKey;
    }

    public function setWaitingForKey(bool $waiting, int $function = 0x00): void
    {
        $this->waitingForKey = $waiting;
        $this->waitingFunction = $function;
    }

    public function getWaitingFunction(): int
    {
        return $this->waitingFunction;
    }

    public function enqueueKey(int $scancode, int $ascii): void
    {
        $this->keyBuffer[] = [
            'scancode' => $scancode & 0xFF,
            'ascii' => $ascii & 0xFF,
        ];
    }

    public function dequeueKey(): ?array
    {
        if (empty($this->keyBuffer)) {
            return null;
        }
        return array_shift($this->keyBuffer);
    }

    public function peekKey(): ?array
    {
        if (empty($this->keyBuffer)) {
            return null;
        }
        return $this->keyBuffer[0];
    }

    public function hasKey(): bool
    {
        return !empty($this->keyBuffer);
    }

    public function clearBuffer(): void
    {
        $this->keyBuffer = [];
    }
}
