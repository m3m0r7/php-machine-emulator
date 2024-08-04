# The PHP machine emulator

The PHP machine emulator is a CPU emulator for Intel x86 written in PHP.
However, since this project is grand in scale, contributions are made by [@m3m0r7](https://github.com/m3m0r7) when they have free time and are motivated.
Since it is written roughly, functionality is not guaranteed.

## Requirements

- PHP 8.3+
- NASM

## Quick start

1. Install this project via composer
```
$ composer require m3m0r7/php-machine-emulator
```

2. Make an assembly as `HelloWorld.asm`

```asm
[bits 16]
[org 0x7C00]

main:
  cli
  xor ax, ax
  xor bx, bx
  mov ds, ax
  mov es, ax
  mov ss, ax
  mov sp, 0x7C00
  sti
mov si, hello_world
call print_string
hlt

print_string:
  lodsb
  or al, al
  jz .done
  call .char
  jmp .done
  .char:
    mov ah, 0x0E
    int 0x10
    jmp print_string
  .done:
    ret

hello_world:
  db "Hello World!", 0x0D, 0x0A, 0

times 510-($-$$) db 0
dw 0xAA55
```

3. Make BIOS Starter as a `HelloWorld.php`

```php
<?php
require __DIR__ . '/vendor/autoload.php';

try {
    \PHPMachineEmulator\BIOS::start(
        new \PHPMachineEmulator\Stream\InputPipeReaderStream(),
    );
} catch (\PHPMachineEmulator\Exception\ExitException $e) {
    exit($e->getCode());
}
```

4. Let's emulating CPU as following:

```
$ nasm HelloWorld.asm -o /dev/stdout | php HelloWorld.php
```

5. It is shown as following:

```
Hello World!
```

# Tests

```
./vendor/bin/phpunit --bootstrap ./tests/Bootstrap.php tests/Case
```

# LICENSE
MIT
