<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Disk\Bootloader;
use PHPMachineEmulator\Disk\HardDisk;
use PHPMachineEmulator\Exception\ExitException;
use PHPMachineEmulator\Exception\HaltException;
use PHPMachineEmulator\Frame\Frame;
use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\Instruction\ServiceInterface;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;
use PHPMachineEmulator\Video\VideoInterface;

class Runtime implements RuntimeInterface
{
    protected RegisterInterface $register;
    protected FrameInterface $frame;
    protected MemoryAccessorInterface $memoryAccessor;
    protected AddressMapInterface $addressMap;
    protected array $shutdown = [];

    public function __construct(
        protected MachineInterface $machine,
        protected RuntimeOptionInterface $runtimeOption,
        protected ArchitectureProviderInterface $architectureProvider,
        protected StreamReaderIsProxyableInterface $streamReader,
    ) {
        $this->register = $this
            ->architectureProvider
            ->instructionList()
            ->register();

        $this->frame = new Frame($this);
        $this->addressMap = new AddressMap($this);
        $this->memoryAccessor = new MemoryAccessor(
            $this,
            $this->architectureProvider
                ->observers(),
        );
    }

    public function start(): void
    {
        $this->initialize();

        while (!$this->streamReader->isEOF()) {
            $result = $this->execute(
                $this->streamReader->byte(),
            );
            if ($result === ExecutionStatus::EXIT) {
                $this->machine->option()->logger()->info('Exited program');

                $frameSet = $this->frame->pop();

                throw new ExitException(
                    'The executor received exit code',

                    // NOTE: Use value as a status code if appended frame is available.
                    //       If but not returned, use zero (successfully number) always.
                    (int) $frameSet?->value() ?? 0,
                );
            }
            if ($result === ExecutionStatus::HALT) {
                $this->machine->option()->logger()->info('Halted program');
                throw new HaltException('The executor halted');
            }
        }
    }

    public function __destruct()
    {
        foreach ($this->shutdown as $callback) {
            $callback($this);
        }
    }

    public function shutdown(callable $callback): self
    {
        $this->shutdown[] = $callback;
        return $this;
    }

    public function addressMap(): AddressMapInterface
    {
        return $this->addressMap;
    }

    public function runtimeOption(): RuntimeOptionInterface
    {
        return $this->runtimeOption;
    }

    public function option(): OptionInterface
    {
        return $this->machine->option();
    }

    public function streamReader(): StreamReaderIsProxyableInterface
    {
        return $this->streamReader;
    }

    public function memoryAccessor(): MemoryAccessorInterface
    {
        return $this->memoryAccessor;
    }

    public function register(): RegisterInterface
    {
        return $this->register;
    }

    public function frame(): FrameInterface
    {
        return $this->frame;
    }

    public function video(): VideoInterface
    {
        return $this->architectureProvider
            ->video();
    }

    public function execute(int $opcode): ExecutionStatus
    {
        $this->machine->option()->logger()->debug(sprintf('Reached the opcode is 0x%04X', $opcode));

        $instruction = $this
            ->architectureProvider
            ->instructionList()
            ->getInstructionByOperationCode($opcode);

        $this->machine->option()->logger()->info(sprintf('Process the instruction %s', get_class($instruction)));

        return $instruction
            ->process($this, $opcode);
    }

    protected function initialize(): void
    {
        $this
            ->addressMap
            ->register(
                $this->runtimeOption->entrypoint(),
                new Bootloader(),
            );
        $this->machine->option()->logger()->info(sprintf('Started machine emulating which entrypoint is 0x%04X', $this->runtimeOption->entrypoint()));

        foreach ([...($this->register)::map(), $this->video()->videoTypeFlagAddress()] as $address) {
            $this->memoryAccessor->allocate($address);

            $this->machine->option()->logger()->debug(sprintf('Address allocated 0x%03s', decbin($address)));
        }

        foreach ($this->architectureProvider->services() as $service) {
            assert($service instanceof ServiceInterface);

            $service->initialize($this);
            $this->machine->option()->logger()->debug(sprintf('Initialize %s service', get_class($service)));
        }

    }
}
