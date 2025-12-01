<?php

declare(strict_types=1);

namespace Tests\Utils;

use PHPMachineEmulator\Disk\DiskInterface;
use PHPMachineEmulator\Runtime\AddressMapInterface;

class TestAddressMap implements AddressMapInterface
{
    private array $disks = [];
    private int $origin = 0;

    public function register(int $address, DiskInterface $disk): self
    {
        $this->disks[$address] = $disk;
        return $this;
    }

    public function getDiskByAddress(int $address): DiskInterface|null
    {
        return $this->disks[$address] ?? null;
    }

    public function getOrigin(): int
    {
        return $this->origin;
    }

    public function getDisk(): DiskInterface
    {
        return new TestDisk();
    }
}
