<?php

use FastComposer\InstallCache;
use FastComposer\InProcessComposer;
use FastComposer\JsonFile;

if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_geteuid') || !InProcessComposer::loadClasses()) {
    return;
}
$root = $base.'/project';
mkdir($root);
$config = new \Composer\Config();
$config->merge(['config' => ['cache-dir' => $base.'/cache']]);
$package = static fn (string $url): array => ['source' => ['url' => $url]];
$write = static function (array $packages) use ($root): void {
    JsonFile::atomicWrite($root.'/composer.lock', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
};
$one = $package('git@example.invalid:group/a.git');
$two = $package('git@example.invalid:group/b.git');
$write([$one, $two]);
$first = InstallCache::open($root, $config);
fc_assert($first !== null, 'Initial private cache');
$path = $first->path;
$first->close();
$write([$two]);
$second = InstallCache::open($root, $config);
fc_assert($second !== null && $second->path === $path, 'Removing dependency preserves cache namespace');
$second->close();
$write([$one, $two, $package('git@example.invalid:group/c.git')]);
$third = InstallCache::open($root, $config);
fc_assert($third !== null && $third->path === $path, 'Adding dependency preserves cache namespace');
$third->close();
// Slash and hyphen produce the same native Composer cache key.
$write([$package('git@example.invalid:group-a.git')]);
fc_assert(InstallCache::open($root, $config) === null, 'Historical collision fails closed');
$write([$one]);
$again = InstallCache::open($root, $config);
fc_assert($again !== null && $again->path === $path, 'Collision fallback releases operation lock');
$again->close();
