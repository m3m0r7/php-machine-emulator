<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Backtrace\BacktraceableInterface;
use PHPMachineEmulator\Backtrace\Frame\FrameProxy;
use PHPMachineEmulator\Backtrace\Stream\StreamReaderProxy;
use PHPMachineEmulator\Exception\OperationNotFoundException;
use PHPMachineEmulator\Frame\FrameInterface;
use PHPMachineEmulator\Frame\FrameSetInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\IO\IO;
use PHPMachineEmulator\IO\NullInput;
use PHPMachineEmulator\IO\NullOutput;
use PHPMachineEmulator\IO\StdErr;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\MachineType;
use PHPMachineEmulator\Option;
use PHPMachineEmulator\OptionInterface;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;
use function PHPUnit\Framework\assertSame;

class InstructionListRuntime extends Runtime implements RuntimeInterface
{
    protected OptionInterface $internalOption;
    protected Table $table;

    public function __construct(
        protected MachineInterface $machine,
        protected RuntimeOptionInterface $runtimeOption,
        protected ArchitectureProviderInterface $architectureProvider,
        protected StreamReaderIsProxyableInterface $streamReader,
    ) {
        $this->internalOption = $this->machine->option();
        parent::__construct(
            $this->createInstructionListOnlyMachine(),
            $runtimeOption,
            $architectureProvider,
            $this->streamReader,
        );

        // NOTE: To be backtraceable
        $this->streamReader = new StreamReaderProxy($this, $this->streamReader);
        $this->frame = new FrameProxy($this, $this->frame);

        $this
            ->internalOption
            ->IO()
            ->output()
            ->write(
                <<< _
                Started with listing instructions runtime.\n
                _
            );

        $this->table = new Table(new ConsoleOutput());
        $this->table->setHeaders([
            'program counter',
            'mnemonic',
            'opcode',
            'op_size',
            'operands',
            'frame set',
        ]);
    }

    public function start(): void
    {
        parent::start();

        $this->table->render();
    }

    public function execute(int $opcode): ExecutionStatus
    {
        assert($this->streamReader instanceof StreamReaderProxy);
        assert($this->frame instanceof FrameProxy);

        $result = ExecutionStatus::SUCCESS;

        try {
            $instruction = $this
                ->architectureProvider
                ->instructionList()
                ->getInstructionByOperationCode($opcode);

            $result = parent::execute($opcode);
            $class = new \ReflectionClass($instruction);
            $mnemonic = $class->getShortName();
        } catch (OperationNotFoundException) {
            $mnemonic = 'Unknown';
        }

        $operands = array_slice(
            $this->streamReader
                ->last(),
            1,
        );

        $this
            ->table
            ->addRow([
                sprintf(
                    '%08s',
                    $this->streamReader
                        ->offset()
                ),
                $mnemonic,
                sprintf('0x%02X', $opcode),
                count($operands),
                implode(
                    ", ",
                    array_map(
                        fn (int $value) => sprintf('%1$d', $value),
                        $operands,
                    ),
                ),
                $this->createFrameInspectionString($this->frame->last()),
            ]);

        $this->frame->next();
        $this->streamReader->next();

        // NOTE: Do not stop when returning HALT or RETURN status
        if ($result === ExecutionStatus::HALT) {
            $result = ExecutionStatus::SUCCESS;
        }

        return $result;
    }

    private function createFrameInspectionString(): string
    {
        [$type, $frameSet] = $this->frame->last();

        if ($type === null) {
            return '';
        }

        assert($frameSet instanceof FrameSetInterface);
        $clasName = new \ReflectionClass($frameSet->instruction());

        if ($type === FrameProxy::POPPED) {
            return sprintf(
                '<FrameSet<Pop>>',
            );
        }

        return sprintf(
            '<FrameSet<%s>#%s, caller_instruction: %s<%s>, caller_offset: %d, value: %s>',
            match ($type) {
                FrameProxy::APPENDED => 'Append',
                FrameProxy::POPPED => 'Pop',
            },
            spl_object_id($frameSet),
            $clasName->getShortName(),
            spl_object_id($frameSet->instruction()),
            $frameSet->pos(),
            $frameSet->value() !== null
                ? $frameSet->value()
                : '(null)'
        );
    }

    private function createInstructionListOnlyMachine(): MachineInterface
    {
        return new class($this, $this->machine) implements MachineInterface
        {
            protected OptionInterface|null $option = null;

            public function __construct(private readonly InstructionListRuntime $instructionListRuntime, private readonly MachineInterface $machine)
            {}

            public function option(): OptionInterface
            {
                return $this->option ??= new Option(
                    $this->machine->option()->logger(),
                    new IO(
                        new NullInput(),
                        new NullOutput(),
                        new StdErr(),
                    ),
                    get_class($this->instructionListRuntime),
                    false,
                );
            }

            public function runtime(MachineType $useMachineType = MachineType::Intel_x86): RuntimeInterface
            {
                return $this->instructionListRuntime;
            }
        };
    }
}
