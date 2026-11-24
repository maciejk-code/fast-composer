<?php

// Internal rm shim. Invoked only with a private per-install manifest.
$manifest = getenv('FAST_COMPOSER_REMOVE_MANIFEST');
$data = is_string($manifest) ? json_decode((string) @file_get_contents($manifest), true) : null;
$rm = is_array($data) && is_string($data['rm'] ?? null) ? $data['rm'] : '/bin/rm';
$args = array_slice($argv, 1);
$runRm = static function (array $arguments) use ($rm): int {
    $process = proc_open(array_merge([$rm], array_map('strval', array_values($arguments))), [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
    return is_resource($process) ? proc_close($process) : 127;
};

if (count($args) !== 2 || $args[0] !== '-rf' || !str_starts_with($args[1], '/')) {
    exit($runRm($args));
}
$raw = rtrim($args[1], '/');
$target = realpath($raw);
if ($target === false || is_link($raw) || !in_array($target, $data['paths'] ?? [], true)
    || !is_dir($target.'/node_modules') || is_link($target.'/node_modules')
) {
    exit($runRm($args));
}
$parent = dirname($target);
$targetStat = @stat($target);
$parentStat = @stat($parent);
if ($targetStat === false || $parentStat === false || $targetStat['dev'] !== $parentStat['dev']) {
    exit($runRm($args));
}
$trash = $parent.'/.fast-composer-trash-'.bin2hex(random_bytes(12));
if (!@mkdir($trash, 0700)) {
    exit($runRm($args));
}
if (!@rename($target, $trash.'/package')) {
    @rmdir($trash);
    exit($runRm($args));
}

// Composer requested deletion; its original package path is now free.
fwrite(STDERR, '[fast-composer] deferred package removal: '.$trash."\n");
$process = proc_open(
    ['/bin/sh', '-c', '"$1" -rf -- "$2" </dev/null >/dev/null 2>&1 &', 'fast-composer-cleanup', $rm, $trash],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']],
    $pipes
);
if (!is_resource($process) || proc_close($process) !== 0) {
    exit($runRm(['-rf', '--', $trash]));
}
exit(0);
