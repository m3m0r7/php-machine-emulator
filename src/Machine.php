<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\Architecture\ArchitectureProvider;
use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Exception\PHPMachineEmulatorException;
use PHPMachineEmulator\Instruction\Intel;
use PHPMachineEmulator\Runtime\Runtime;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\RuntimeOption;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;

class Machine implements MachineInterface
{
    protected array $runtimes = [];

    public function __construct(protected StreamReaderIsProxyableInterface $streamReader, protected OptionInterface $option = new Option())
    {
        $this->runtimes[MachineType::Intel_x86->name] = [
            Intel\x86::class,
            Intel\VideoInterrupt::class,
            Intel\MemoryAccessorObserverCollection::class,
            Intel\ServiceCollection::class,
        ];
    }

    public function option(): OptionInterface
    {
        return $this->option;
    }

    public function runtime(MachineType $useMachineType = MachineType::Intel_x86): RuntimeInterface
    {
        foreach ($this->runtimes as $machineType => [$runtimeClassName, $runtimeVideoClassName, $runtimeObserverCollection, $runtimeServiceCollection]) {
            if ($machineType === $useMachineType->name) {
                $this->option()->logger()->info("Selected runtime is {$useMachineType->name}");
                return $this->createRuntime(
                    new ArchitectureProvider(
                        new $runtimeVideoClassName(),
                        new $runtimeClassName(),
                        new $runtimeObserverCollection(),
                        new $runtimeServiceCollection(),
                    ),
                );
            }
        }

        throw new PHPMachineEmulatorException('Runtime not found');
    }

    protected function createRuntime(ArchitectureProviderInterface $architectureProvider): RuntimeInterface
    {
        return new ($this->option->runtimeClass())(
            $this,
            new RuntimeOption(),
            $architectureProvider,
            $this->streamReader,
        );
    }
}
