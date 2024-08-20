<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\Stream\KeyboardReaderStream;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;

class DebugRuntime extends Runtime implements RuntimeInterface
{
    protected KeyboardReaderStream $keyboardReaderStream;

    public function __construct(
        protected MachineInterface $machine,
        protected RuntimeOptionInterface $runtimeOption,
        protected ArchitectureProviderInterface $architectureProvider,
        protected StreamReaderIsProxyableInterface $streamReader,
    ) {
        $this->keyboardReaderStream = new KeyboardReaderStream(STDIN);
        parent::__construct(
            $this->createInstructionListOnlyMachine(),
            $runtimeOption,
            $architectureProvider,
            $streamReader,
        );

        $this->option()
            ->IO()
            ->output()
            ->write(
                <<< _
                Started with debugging runtime. If you press <enter key> to process next, <Ctrl-C> to quit this mode.\n
                [   PC   ] Mnemonic<OPCode>\n
                _
            );
    }

    public function execute(int $opcode): ExecutionStatus
    {
        $instruction = $this
            ->architectureProvider
            ->instructionList()
            ->getInstructionByOperationCode($opcode);

        $result = parent::execute($opcode);

        $class = new \ReflectionClass($instruction);
        $this->option()
            ->IO()
            ->output()
            ->write(
                sprintf(
                    "[%08s] %s<0x%04X>",
                    $this->streamReader
                        ->offset(),
                    $class->getShortName(),
                    $opcode,
                )
            );

        $char = $this->keyboardReaderStream
            ->char();

        if ($char === 'q') {
            return ExecutionStatus::EXIT;
        }

        return $result;
    }
}
