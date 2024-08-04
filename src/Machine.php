<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\Exception\PHPMachineEmulatorException;
use PHPMachineEmulator\Instruction\InstructionListInterface;
use PHPMachineEmulator\Instruction\Intel;
use PHPMachineEmulator\Runtime\Runtime;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;

class Machine implements MachineInterface
{
    protected array $runtimes = [];

    public function __construct(protected StreamReaderIsProxyableInterface $streamReader, protected OptionInterface $option)
    {
        $this->runtimes[Intel\x86::class] = MachineType::Intel_x86;
    }

    public function option(): OptionInterface
    {
        return $this->option;
    }

    public function runtime(MachineType $useMachineType = MachineType::Intel_x86): RuntimeInterface
    {
        foreach ($this->runtimes as $runtimeClassName => $runtimeType) {
            if ($runtimeType === $useMachineType) {
                $this->option()->logger()->info("Selected runtime is {$useMachineType->name}");
                return new Runtime($this, new $runtimeClassName(), $this->streamReader);
            }
        }

        throw new PHPMachineEmulatorException('Runtime not found');
    }

    protected function createRuntime(InstructionListInterface $instructionList): RuntimeInterface
    {
        return new Runtime($this, $instructionList, $this->streamReader);
    }
}
