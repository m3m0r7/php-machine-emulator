<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Exception\StreamReaderException;

class InputPipeReaderStream implements StreamReaderIsProxyableInterface
{
    use GenericStream;

    /**
     * @var resource $resource
     */
    protected mixed $resource;

    /**
     * @var int $fileSize
     */
    protected int $fileSize;

    public function __construct()
    {
        if (!defined('STDIN')) {
            define('STDIN', fopen('php://stdin', 'r+'));
        }

        $this->resource = fopen('php://temporary', 'r+');

        stream_copy_to_stream(STDIN, $this->resource);

        $this->fileSize = ftell($this->resource);

        rewind($this->resource);
    }


    public function proxy(): StreamReaderProxyInterface
    {
        $offset = $this->offset();

        $resource = fopen('php://temporary', 'r+');

        rewind($this->resource);
        stream_copy_to_stream($this->resource, $resource);

        $newStream = new ResourceReaderStream($resource, $this->fileSize);
        $newStream->setOffset($offset);
        $this->setOffset($offset);

        return new StreamReaderProxy($newStream);
    }
}
