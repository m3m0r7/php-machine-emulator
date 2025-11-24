<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Disk\DiskInterface;
use PHPMachineEmulator\Exception\AddressMapException;

class AddressMap implements AddressMapInterface
{
    protected array $addresses = [];

    public function __construct(protected RuntimeInterface $runtime)
    {}

    public function register(int $address, DiskInterface $disk): self
    {
        $this->addresses[] = [$address, $disk];
        return $this;
    }

    public function getDiskByAddress(int $address): DiskInterface|null
    {
        $closest = null;
        foreach ($this->addresses as [$targetAddress, $disk]) {
            if ($address === $targetAddress) {
                return $disk;
            }
            if ($targetAddress <= $address) {
                $closest = [$targetAddress, $disk];
            }
        }
        return $closest[1] ?? null;
    }

    public function getAddressAndDisk(): array
    {
        $last = null;

        /**
         * @var DiskInterface $disk
         */
        foreach ($this->addresses as [$targetAddress, $disk]) {
            if ($this->runtime->streamReader()->offset() >= $disk->offset()) {
                $last = [$targetAddress, $disk];
            }
        }

        if ($last !== null) {
            return $last;
        }

        throw new AddressMapException('Unknown origin');
    }

    public function getDisk(): DiskInterface
    {
        [, $disk] = $this->getAddressAndDisk();
        return $disk;
    }

    public function getOrigin(): int
    {
        [$origin] = $this->getAddressAndDisk();
        return $origin;
    }
}
