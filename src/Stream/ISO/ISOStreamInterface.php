<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream\ISO;

/**
 * Interface for ISO stream operations.
 */
interface ISOStreamInterface
{
    public function iso(): ISO9660;

    public function elTorito(): ElTorito;

    public function bootImage(): BootImage;
}
