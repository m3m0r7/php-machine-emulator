<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Stream;

use PHPMachineEmulator\Exception\StreamReaderException;

class ResourceReaderStream implements StreamReaderIsProxyableInterface
{
    use GenericStream;

    /**
     * @var resource $resource
     */
    protected mixed $resource;

    public function __construct($resource, protected int $fileSize)
    {
        $this->resource = $resource;

        if (!is_resource($this->resource)) {
            throw new StreamReaderException('The parameter is not a resource');
        }
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
