<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

interface FileStreamInterface extends StreamReaderIsProxyableInterface
{
    public function fileSize(): int;
    public function path(): string;
}
