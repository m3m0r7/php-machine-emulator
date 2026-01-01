<?php

declare(strict_types=1);

namespace PHPMachineEmulator\UEFI;

use PHPMachineEmulator\Runtime\RuntimeInterface;

final class UEFIDispatcher
{
    private int $cursor;

    /** @var array<int, callable(RuntimeInterface): void> */
    private array $handlers = [];

    /** @var array<int, string> */
    private array $names = [];

    public function __construct(
        private int $base = 0x0FF00000,
        private int $stride = 0x20,
    ) {
        $this->cursor = $base;
    }

    /**
     * Register a handler and return the callable address.
     *
     * @param callable(RuntimeInterface): void $handler
     */
    public function register(string $name, callable $handler): int
    {
        $addr = $this->cursor;
        $this->cursor += $this->stride;
        $this->handlers[$addr] = $handler;
        $this->names[$addr] = $name;
        return $addr;
    }

    public function handlesTarget(int $address): bool
    {
        return isset($this->handlers[$address]);
    }

    public function dispatch(RuntimeInterface $runtime, int $address): void
    {
        $handler = $this->handlers[$address] ?? null;
        if ($handler === null) {
            return;
        }
        $handler($runtime);
    }

    public function nameFor(int $address): ?string
    {
        return $this->names[$address] ?? null;
    }
}
