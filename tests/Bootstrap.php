<?php
declare(strict_types=1);

$directory = new RecursiveDirectoryIterator(__DIR__ . '/Case');
$iterator = new RecursiveIteratorIterator($directory);
$filtered = new RegexIterator($iterator, '/.*?\\/.*\\.asm$/', RegexIterator::GET_MATCH);

foreach ($filtered as [$file]) {
    $path = dirname($file) . '/' . basename($file, '.asm') . '.o';

    exec(sprintf('\\nasm %s -o %s', $file, $path));
}
