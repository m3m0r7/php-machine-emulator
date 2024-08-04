<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface StreamReaderIsProxyableInterface extends StreamReaderInterface
{
    public function proxy(): StreamReaderProxyInterface;
}
