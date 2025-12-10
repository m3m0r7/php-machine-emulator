<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

interface IsolatedAsyncLoopInterface
{
    public function send(SerializableAsyncMessageInterface $message);
    public function start(string $cbClassString, int $interval): void;
    public function close(): void;
}
