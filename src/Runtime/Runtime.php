<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Frame\Frame;
use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\RegisterInterface;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\Stream\StreamReaderInterface;

class Runtime implements RuntimeInterface
{
    protected RegisterInterface $register;
    protected FrameInterface $frame;
    protected MemoryAccessorInterface $memoryAccessor;

    public function __construct(protected MachineInterface $machine, protected InstructionListInterface $instructionList, protected StreamReaderInterface $streamReader)
    {
        $this->register = $this->instructionList->register();
        $this->frame = new Frame($this);
        $this->memoryAccessor = new MemoryAccessor($this);
    }

    public function start(int $entrypoint = 0x0000): void
    {
        $this->machine->option()->logger()->info(sprintf('Started machine emulating which entrypoint is 0x%04X', $entrypoint));

        foreach (($this->register)::map() as $name => $address) {
            $this->memoryAccessor->allocate($address);

            $this->machine->option()->logger()->debug(sprintf('Address allocated 0x%03s', decbin($address)));
        }

        while (!$this->streamReader->isEOF()) {
            $this->execute(
                $this->streamReader->byte(),
            );
        }
    }

    public function streamReader(): StreamReaderInterface
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

    public function execute(int $opcode): ExecutionStatus
    {
        $this->machine->option()->logger()->debug(sprintf('Reached the opcode is 0x%04X', $opcode));

        $instruction = $this->instructionList
            ->getInstructionByOperationCode($opcode);


        $this->machine->option()->logger()->info(sprintf('Process the instruction %s', get_class($instruction)));

        return $instruction
            ->process($opcode, $this);
    }
}
