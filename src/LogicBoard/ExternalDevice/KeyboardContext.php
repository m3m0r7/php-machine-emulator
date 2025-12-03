<?php

declare(strict_types=1);

namespace PHPMachineEmulator\LogicBoard\ExternalDevice;

use PHPMachineEmulator\Stream\KeyboardReaderStream;
use PHPMachineEmulator\Stream\StreamReaderInterface;

class KeyboardContext implements KeyboardContextInterface
{
    protected StreamReaderInterface $keyboardStream;

    /**
     * @param resource|null $resource The keyboard input resource. Defaults to STDIN.
     */
    public function __construct(mixed $resource = null)
    {
        $this->keyboardStream = new KeyboardReaderStream($resource ?? STDIN);
    }

    public function stream(): StreamReaderInterface
    {
        return $this->keyboardStream;
    }

    public function deviceType(): DeviceType
    {
        return DeviceType::KEYBOARD;
    }
}
