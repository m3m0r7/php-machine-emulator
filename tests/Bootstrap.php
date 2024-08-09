<?php
declare(strict_types=1);

$directory = new RecursiveDirectoryIterator(__DIR__ . '/Case');
$iterator = new RecursiveIteratorIterator($directory);
$filtered = new RegexIterator($iterator, '/.*?\\/.*\\.asm$/', RegexIterator::GET_MATCH);

foreach ($filtered as [$file]) {
    $path = dirname($file) . '/' . basename($file, '.asm') . '.o';

    exec(sprintf('\\nasm %s -o %s &>/dev/null', $file, $path));
}

$bundlers = new RegexIterator($iterator, '/.*?\\/Bundler\\.json$/', RegexIterator::GET_MATCH);

foreach ($bundlers as [$bundler]) {
    $bundlerSetting = json_decode(
        file_get_contents($bundler),
        true,
    );
    $path = dirname($bundler) . '/' . $bundlerSetting['path'];

    @unlink($path);
    foreach (($bundlerSetting['files'] ?? []) as $index => $file) {
        exec(sprintf(
            '\\dd if=%s of=%s bs=512 seek=%d conv=notrunc &>/dev/null',
            dirname($bundler) . '/' . $file,
            $path,
            $index,
        ));
    }
}
