<?php

declare(strict_types=1);

namespace PHPMachineEmulator;

use PHPMachineEmulator\Architecture\ArchitectureProvider;
use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Exception\PHPMachineEmulatorException;
use PHPMachineEmulator\Instruction\Intel;
use PHPMachineEmulator\LogicBoard\LogicBoardInterface;
use PHPMachineEmulator\Runtime\RuntimeInterface;
use PHPMachineEmulator\Runtime\RuntimeOption;

class Machine implements MachineInterface
{
    protected array $runtimes = [];

    public function __construct(
        protected LogicBoardInterface $logicBoardContext,
        protected OptionInterface $option = new Option(),
    ) {
        $this->runtimes[ArchitectureType::Intel_x86->value] = [
            Intel\x86::class,
            Intel\VideoInterrupt::class,
            Intel\MemoryAccessorObserverCollection::class,
            Intel\ServiceCollection::class,
        ];

        $this->runtimes[ArchitectureType::Intel_x86_64->value] = [
            Intel\x86_64::class,
            Intel\VideoInterrupt::class,
            Intel\MemoryAccessorObserverCollection::class,
            Intel\ServiceCollection::class,
        ];
    }

    public function option(): OptionInterface
    {
        return $this->option;
    }

    public function logicBoard(): LogicBoardInterface
    {
        return $this->logicBoardContext;
    }

    public function runtime(int $entrypoint = 0x0000): RuntimeInterface
    {
        $architectureType = $this->logicBoardContext->cpu()->architectureType();

        foreach ($this->runtimes as $archType => [$runtimeClassName, $runtimeVideoClassName, $runtimeObserverCollection, $runtimeServiceCollection]) {
            if ($archType === $architectureType->value) {
                $this->option()->logger()->info("Selected runtime is {$architectureType->value}");
                return $this->createRuntime(
                    new ArchitectureProvider(
                        new $runtimeVideoClassName(),
                        new $runtimeClassName(),
                        new $runtimeObserverCollection(),
                        new $runtimeServiceCollection(),
                    ),
                    $entrypoint,
                );
            }
        }

        throw new PHPMachineEmulatorException("Runtime not found for architecture: {$architectureType->value}");
    }

    protected function createRuntime(ArchitectureProviderInterface $architectureProvider, int $entrypoint = 0x0000): RuntimeInterface
    {
        $runtime = new ($this->option->runtimeClass())(
            $this,
            new RuntimeOption($entrypoint),
            $architectureProvider,
        );

        // Inject runtime into instruction list for mode detection
        $architectureProvider->instructionList()->setRuntime($runtime);

        return $runtime;
    }
}
