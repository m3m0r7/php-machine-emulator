<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Disk\DiskInterface;

interface AddressMapInterface
{
    public function register(int $address, DiskInterface $disk): self;
    public function getDiskByAddress(int $address): DiskInterface|null;
    public function getOrigin(): int;
    public function getDisk(): DiskInterface;
}
