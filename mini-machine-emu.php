<?php
declare(strict_types=1);

class StreamReader
{
    protected mixed $resource;
    protected int $fileSize;
    public function __construct(protected string $path)
    {
        $this->resource = fopen($path, 'r');
        $this->fileSize = filesize($this->path);
    }

    public function char(): string
    {
        $char = fread($this->resource, 1);
        if ($char === false || $char === '') {
            throw new RuntimeException('An error occurred that reading it');
        }
        return $char;
    }

    public function byte(): int
    {
        return unpack('C', $this->char())[1];
    }

    public function signedByte(): int
    {
        return unpack('c', $this->char())[1];
    }

    public function offset(): int
    {
        $offset = ftell($this->resource);
        if ($offset === false) {
            throw new RuntimeException('An error occurred that getting the offset');
        }
        return $offset;
    }

    public function setOffset(int $offset, int $whence = SEEK_SET): void
    {
        $result = fseek($this->resource, $offset, $whence);
        if ($result > 0) {
            throw new RuntimeException('An error occurred that changing the offset');
        }
    }

    public function isEOF(): bool
    {
        return $this->offset() === $this->fileSize || feof($this->resource);
    }
}

enum RegisterType: int
{
    case EAX = 0b000;
    case ECX = 0b001;
    case EDX = 0b010;
    case EBX = 0b011;
    case ESP = 0b100;
    case EBP = 0b101;
    case ESI = 0b110;
    case EDI = 0b111;
}

class Register
{
    protected array $registers = [];
    protected array $segmentRegisters = [];
    protected bool $eFlags = false;

    public function __construct($registerTypeClassName)
    {
        $class = new ReflectionClass($registerTypeClassName);
        foreach ($class->getConstants() as $constant) {
            // Initialize registers
            $this->registers[$constant->value] = null;
        }
    }

    public function get(int|RegisterType $registerType): int|null
    {
        $register = $registerType;
        if ($registerType instanceof RegisterType) {
            $register = $registerType->value;
        }

        if (!array_key_exists($register, $this->registers)) {
            throw new RuntimeException('The register is not implemented');
        }

        return $this->registers[$register];

    }

    public function getAndIncrement(int|RegisterType $registerType): int|null
    {
        $register = $registerType;
        if ($registerType instanceof RegisterType) {
            $register = $registerType->value;
        }

        $result = $this->get($registerType);

        if ($register === RegisterType::ESI->value || $register === RegisterType::EDI->value) {
            $this->registers[$register]++;
        }

        return $result;
    }

    public function write(int|RegisterType $registerType, int $value): void
    {
        $register = $registerType;
        if ($registerType instanceof RegisterType) {
            $register = $registerType->value;
        }
        if (!array_key_exists($register, $this->registers)) {
            throw new RuntimeException('The register is not implemented');
        }

        $this->eFlags = $value === 0;

        $this->registers[$register] = $value;
    }

    public function shouldZeroFlag(): bool
    {
        return $this->eFlags;
    }

    public function writeSegment(int $segment, int $value): void
    {
        $this->segmentRegisters[$segment] = $value;
    }
}

interface InstructionInterface
{
    public function process(MachineInterface $machine): void;
}

class Nop implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        // Nothing to do
    }
}

class Xor_ implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $operand = $machine->streamReader()->byte();

        $addressingMode = ($operand >> 6) & 0b00000111;
        $register = ($operand >> 3) & 0b00000111;
        $registerOrMemory = $operand & 0b00000111;

        if ($addressingMode !== 0b011) {
            throw new RuntimeException('The addressing mode is not supported');
        }

        $machine->register()->write(
            $register,
            $machine->register()->get($register) ^ $machine->register()->get($registerOrMemory),
        );
    }
}

enum MovType
{
    case REGISTER;
    case SEGMENT;
}

class MovImmediatelyValue implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $operand = $machine->streamReader()->byte();

        $machine->register()->write(
            RegisterType::EAX,

            // NOTE: Emulates AH register
            ($operand << 8) + ($machine->register()->get(RegisterType::EAX) & 0b11111111),
        );
    }
}

class Mov implements InstructionInterface
{
    public function __construct(protected ?MovType $movType = null) {}

    public function process(MachineInterface $machine): void
    {
        $operand = $machine->streamReader()->byte();

        $addressingMode = ($operand >> 6) & 0b00000111;
        $to = ($operand >> 3) & 0b00000111;
        $from = $operand & 0b00000111;

        if ($addressingMode !== 0b011) {
            throw new RuntimeException('The addressing mode is not supported');
        }

        match ($this->movType) {
            MovType::SEGMENT => $machine->register()->writeSegment(
                $to,
                $machine->register()->get($from),
            ),
        };
    }
}

class MovSP implements InstructionInterface
{
    public function __construct(protected RegisterType $sp) {}

    public function process(MachineInterface $machine): void
    {
        $low = $machine->streamReader()->byte();
        $high = $machine->streamReader()->byte();

        $machine->register()->write(
            $this->sp,
            ($high << 8) + $low,
        );
    }
}

class MovSX implements InstructionInterface
{
    public function __construct(protected RegisterType $si) {}

    public function process(MachineInterface $machine): void
    {
        $operand1 = $machine->streamReader()->byte();
        $operand2 = $machine->streamReader()->byte();

        $machine->register()->write(
            $this->si,
            ($operand2 << 8) + $operand1,
        );
    }
}

class Call implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $byte1 = $machine->streamReader()->byte();
        $byte2 = $machine->streamReader()->byte();

        $pos = $machine->streamReader()->offset();

        $machine->streamReader()->setOffset($pos + ($byte2 << 8) + $byte1);

        $machine->frame()->append($pos);
    }
}

class Lodsb implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $previousPos = $machine->streamReader()->offset();
        $si = $machine->register()->getAndIncrement(RegisterType::ESI);

        $machine->streamReader()->setOffset($si - $machine->register()->get(RegisterType::ESP));

        $machine->register()->write(
            RegisterType::EAX,
            $machine->streamReader()->byte(),
        );

        $machine->streamReader()->setOffset($previousPos);
    }
}

class Or_ implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $operand = $machine->streamReader()->byte();

        $addressingMode = ($operand >> 6) & 0b00000111;
        $register = ($operand >> 3) & 0b00000111;
        $registerOrMemory = $operand & 0b00000111;

        if ($addressingMode !== 0b011) {
            throw new RuntimeException('The addressing mode is not supported');
        }

        $machine->register()->write(
            $register,
            // NOTE: 8 bit only
            ($machine->register()->get($register) & 0b11111111) | ($machine->register()->get($registerOrMemory) & 0b11111111),
        );
    }
}

class Jz implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $operand = $machine->streamReader()->signedByte();

        $pos = $machine->streamReader()->offset();

        if ($machine->register()->shouldZeroFlag()) {
            $machine->streamReader()->setOffset($pos + $operand);
        }
    }
}

class Jmp implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $operand = $machine->streamReader()->signedByte();

        $pos = $machine->streamReader()->offset();
        $machine->streamReader()->setOffset($pos + $operand);
    }
}

class Int_ implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $operand = $machine->streamReader()->byte();

        // The BIOS video interrupt
        if ($operand === 0x10) {
            echo chr($machine->register()->get(RegisterType::EAX));
            return;
        }

        throw new RuntimeException('Not implemented interrupt types');
    }
}

class Ret implements InstructionInterface
{
    public function process(MachineInterface $machine): void
    {
        $pos = $machine->frame()->pop();

        // NOTE: This is equals to HLT instruction
        if ($pos === null) {
            throw new ExitException();
        }

        // NOTE: Back to previous frame stack
        $machine->streamReader()->setOffset($pos);
    }
}

class ExitException extends Exception
{}

interface MachineInterface
{
    public function frame(): Frame;
    public function register(): Register;
    public function streamReader(): StreamReader;
}

class Frame
{
    public array $stacks = [];

    public function append(int $pos): void
    {
        $this->stacks[] = $pos;
    }

    public function pop(): int|null
    {
        return array_pop($this->stacks);
    }
}

class BIOS implements MachineInterface
{
    public function __construct(protected StreamReader $streamReader, protected Register $register, protected Frame $frame)
    {
        $this->verifySignature();
    }

    public function frame(): Frame
    {
        return $this->frame;
    }

    public function register(): Register
    {
        return $this->register;
    }

    public function streamReader(): StreamReader
    {
        return $this->streamReader;
    }

    public function process(): void
    {
        // @see https://en.wikipedia.org/wiki/X86_instruction_listings
        while (!$this->streamReader->isEOF()) {
            $byte = $this->streamReader->byte();
            $instruction = match ($byte) {
                // cli
                0xFA => new Nop(),

                // xor
                0x31 => new Xor_(),

                // mov
                0x8E => new Mov(MovType::SEGMENT),

                // mov
                0xB4 => new MovImmediatelyValue(),

                // mov for sp
                0xB8 + RegisterType::ESP->value  => new MovSP(RegisterType::ESP),
                0xB8 + RegisterType::EBP->value  => new MovSP(RegisterType::EBP),

                // mov for sx
                0xB8 + RegisterType::ESI->value => new MovSX(RegisterType::ESI),

                // call
                0xE8 => new Call(),

                // sti
                0xFB => new Nop(),

                // Lodsb
                0xAC => new Lodsb(),

                // or
                0x08 => new Or_(),

                // jz
                0x74 => new Jz(),

                // int
                0xCD => new Int_(),

                // jmp
                0xEB => new Jmp(),

                // ret
                0xC3 => new Ret(),

                // hlt
                0xF4 => throw new ExitException(),

                default => throw new RuntimeException(sprintf('0x%02X is not implemented yet', $byte))
            };

            $instruction->process($this);
        }
    }

    protected function verifySignature(): void
    {
        $currentOffset = $this->streamReader->offset();

        $this->streamReader->setOffset(510);

        $high = $this->streamReader->byte();
        $low = $this->streamReader->byte();

        // Verify magic byte
        if ($high !== 0x55 && $low !== 0xAA) {
            throw new RuntimeException('Cannot verify BIOS signature. Maybe the BIOS program was broken');
        }

        if ($this->streamReader->offset() !== 512) {
            throw new RuntimeException('Cannot verify BIOS signature. The file size is invalid');
        }

        if ($this->streamReader->isEOF() === false) {
            throw new RuntimeException('Cannot verify BIOS signature. The BIOS program does not reach the EOF');
        }

        // Reset offset
        $this->streamReader->setOffset($currentOffset);
    }
}


echo "Start to emulates machine\n";
echo "-------------------------------------\n";

try {
    (new BIOS(new StreamReader($argv[1] ?? throw new RuntimeException('The file was not specified')), new Register(RegisterType::class), new Frame()))
        ->process();
} catch (ExitException) {

    echo "\n";
    echo "-------------------------------------\n";
    echo "Finish to emulates machine\n";
    exit(0);
}
