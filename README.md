# The PHP machine emulator

The PHP machine emulator is a CPU emulator for Intel x86 written in PHP.
However, since this project is grand in scale, contributions are made by [@m3m0r7](https://github.com/m3m0r7) when they have free time and are motivated.
Since it is written roughly, functionality is not guaranteed.

## Requirements

- PHP 8.3+
- NASM

## Quick start

```
$ git clone https://github.com/m3m0r7/php-machine-emulator.git
$ cd php-machine-emulator
$ nasm HelloWorld.asm -o HelloWorld.bin
$ php mini-machine-emu.php HelloWorld.bin
```

It is shown as following:

```
Start to emulates machine
-------------------------------------
Hello World!

-------------------------------------
Finish to emulates machine
```

# LICENSE
MIT
