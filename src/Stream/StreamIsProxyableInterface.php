<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface StreamIsProxyableInterface extends StreamReaderInterface, StreamWriterInterface
{
    public function proxy(): StreamProxyInterface;
}
