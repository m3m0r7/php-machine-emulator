<?php

declare(strict_types=1);

namespace PHPMachineEmulator\Display\Window;

use PHPMachineEmulator\Exception\WindowException;

class WindowOption
{
    public const SDL_INIT_VIDEO = 0x00000020;
    public const SDL_WINDOWPOS_CENTERED = 0x2FFF0000;
    public const SDL_WINDOW_SHOWN = 0x00000004;
    public const SDL_RENDERER_ACCELERATED = 0x00000002;

    public function __construct(
        public int $width = 100,
        public int $height = 100,
        public ?string $sdlLibraryPath = null,
        public int $frameRate = 60,
        public int $sdlInitVideo = self::SDL_INIT_VIDEO,
        public int $sdlWindowPosX = self::SDL_WINDOWPOS_CENTERED,
        public int $sdlWindowPosY = self::SDL_WINDOWPOS_CENTERED,
        public int $sdlWindowFlags = self::SDL_WINDOW_SHOWN,
        public int $sdlRendererFlags = self::SDL_RENDERER_ACCELERATED,
        public bool $useFramebuffer = true,
        /** @var string[] */
        public array $librarySearchPaths = [],
    ) {
    }

    public function resolveSDLLibraryPath(): string
    {
        if ($this->sdlLibraryPath !== null && file_exists($this->sdlLibraryPath)) {
            return $this->sdlLibraryPath;
        }

        $candidates = $this->getSDLLibraryCandidates();

        foreach ($candidates as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        foreach ($this->librarySearchPaths as $dir) {
            if ($dir === '') {
                continue;
            }
            if (file_exists($dir) && !is_dir($dir)) {
                return $dir;
            }
            $libDir = rtrim($dir, '/\\');
            foreach ($this->getLibraryNames() as $libName) {
                $candidate = $libDir . '/' . $libName;
                if (file_exists($candidate)) {
                    return $candidate;
                }
            }
        }

        throw new WindowException(
            'SDL library not found. Please specify sdlLibraryPath or ensure SDL2 is installed.'
        );
    }

    /**
     * @return string[]
     */
    protected function getSDLLibraryCandidates(): array
    {
        $candidates = [];

        if (PHP_OS_FAMILY === 'Darwin') {
            $candidates = [
                '/opt/homebrew/lib/libSDL2.dylib',
                '/usr/local/lib/libSDL2.dylib',
                '/Library/Frameworks/SDL2.framework/SDL2',
            ];
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $candidates = [
                '/usr/lib/x86_64-linux-gnu/libSDL2.so',
                '/usr/lib/libSDL2.so',
                '/usr/lib64/libSDL2.so',
                '/usr/local/lib/libSDL2.so',
            ];
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $candidates = [
                'C:\\SDL2\\lib\\x64\\SDL2.dll',
                'C:\\SDL2\\lib\\x86\\SDL2.dll',
            ];
        }

        return $candidates;
    }

    /**
     * @return string[]
     */
    protected function getLibraryNames(): array
    {
        if (PHP_OS_FAMILY === 'Darwin') {
            return ['libSDL2.dylib'];
        } elseif (PHP_OS_FAMILY === 'Linux') {
            return ['libSDL2.so'];
        } elseif (PHP_OS_FAMILY === 'Windows') {
            return ['SDL2.dll'];
        }

        return [];
    }
}
