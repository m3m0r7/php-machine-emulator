<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface StreamWriterIsProxyableInterface extends StreamWriterInterface
{
    // proxy() method is defined in StreamReaderIsProxyableInterface
    // When implementing both, use StreamProxyInterface as return type
}
