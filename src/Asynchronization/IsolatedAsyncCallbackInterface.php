<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Asynchronization;

interface IsolatedAsyncCallbackInterface
{
    public function process(array|int|string|bool|\stdClass|float|null $payload);
}
