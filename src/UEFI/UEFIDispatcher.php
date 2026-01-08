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

    private int $logCount = 0;
    private int $unalignedLogCount = 0;
    private bool $skipReturnPop = false;

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

    public function resolveTarget(int $address): ?array
    {
        if (isset($this->handlers[$address])) {
            return ['addr' => $address, 'aligned' => true];
        }

        if ($address >= $this->base && $address < $this->cursor) {
            $slot = $this->base + intdiv($address - $this->base, $this->stride) * $this->stride;
            if (isset($this->handlers[$slot])) {
                return ['addr' => $slot, 'aligned' => false];
            }
        }

        return null;
    }

    public function handlesTarget(int $address): bool
    {
        return $this->resolveTarget($address) !== null;
    }

    public function dispatch(RuntimeInterface $runtime, int $address): void
    {
        $resolved = $this->resolveTarget($address);
        if ($resolved === null) {
            return;
        }

        if (!$resolved['aligned'] && $this->unalignedLogCount < 50) {
            $runtime->option()->logger()->warning(sprintf(
                'UEFI_CALL: unaligned target=0x%08X resolved=0x%08X',
                $address & 0xFFFFFFFF,
                $resolved['addr'] & 0xFFFFFFFF,
            ));
            $this->unalignedLogCount++;
        }

        $handler = $this->handlers[$resolved['addr']] ?? null;
        if ($handler === null) {
            return;
        }
        if ($this->logCount < 200) {
            $name = $this->names[$resolved['addr']] ?? 'unknown';
            $runtime->option()->logger()->warning(sprintf(
                'UEFI_CALL: %s addr=0x%08X',
                $name,
                $resolved['addr'] & 0xFFFFFFFF,
            ));
            $this->logCount++;
        }
        $handler($runtime);
    }

    public function requestSkipReturnPop(): void
    {
        $this->skipReturnPop = true;
    }

    public function consumeSkipReturnPop(): bool
    {
        $skip = $this->skipReturnPop;
        $this->skipReturnPop = false;
        return $skip;
    }


    public function nameFor(int $address): ?string
    {
        return $this->names[$address] ?? null;
    }
}
