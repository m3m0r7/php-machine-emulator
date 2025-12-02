<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Runtime;

use PHPMachineEmulator\Architecture\ArchitectureProviderInterface;
use PHPMachineEmulator\Backtrace\Frame\FrameProxy;
use PHPMachineEmulator\Backtrace\Stream\StreamReaderProxy;
use PHPMachineEmulator\Exception\OperationNotFoundException;
use PHPMachineEmulator\Frame\FrameSetInterface;
use PHPMachineEmulator\Instruction\ExecutionStatus;
use PHPMachineEmulator\MachineInterface;
use PHPMachineEmulator\Stream\KeyboardReaderStream;
use PHPMachineEmulator\Stream\StreamReaderIsProxyableInterface;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;

class DebugRuntime extends Runtime implements RuntimeInterface
{
    protected KeyboardReaderStream $keyboardReaderStream;
    protected Table $table;

    public function __construct(
        protected MachineInterface $machine,
        protected RuntimeOptionInterface $runtimeOption,
        protected ArchitectureProviderInterface $architectureProvider,
        protected StreamReaderIsProxyableInterface $streamReader,
    ) {
        $this->keyboardReaderStream = new KeyboardReaderStream(STDIN);

        parent::__construct(
            $machine,
            $runtimeOption,
            $architectureProvider,
            $streamReader,
        );

        // NOTE: To be backtraceable
        $this->streamReader = new StreamReaderProxy($this, $this->streamReader);
        $this->frame = new FrameProxy($this, $this->frame);

        $this
            ->option()
            ->IO()
            ->output()
            ->write(
                <<< _
                Started with debugging runtime. If you press <enter key> to process next, <Ctrl-C> to quit this mode.\n
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

    public function execute(int|array $opcodes): ExecutionStatus
    {
        assert($this->streamReader instanceof StreamReaderProxy);
        assert($this->frame instanceof FrameProxy);

        $result = ExecutionStatus::SUCCESS;
        $opcodeArray = is_array($opcodes) ? $opcodes : [$opcodes];
        $firstOpcode = $opcodeArray[0];

        try {
            $instructionList = $this->architectureProvider->instructionList();
            if (count($opcodeArray) > 1) {
                $matched = $instructionList->tryMatchMultiByteOpcode($opcodeArray);
                if ($matched !== null) {
                    $instruction = $matched[0];
                } else {
                    $instruction = $instructionList->getInstructionByOperationCode($firstOpcode);
                }
            } else {
                $instruction = $instructionList->getInstructionByOperationCode($firstOpcode);
            }

            $result = parent::execute($opcodes);
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

        $opcodeStr = implode(' ', array_map(fn($b) => sprintf('0x%02X', $b), $opcodeArray));
        $this
            ->table
            ->addRow([
                sprintf(
                    '%08s',
                    $this->streamReader
                        ->offset()
                ),
                $mnemonic,
                $opcodeStr,
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
        $this->table->render();

        $char = $this->keyboardReaderStream
            ->char();

        if ($char === 'q') {
            return ExecutionStatus::EXIT;
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

        return sprintf(
            '<FrameSet<%s>#%s, callee_instruction: %s<%s>, callee_offset: %d, value: %s>',
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
}
