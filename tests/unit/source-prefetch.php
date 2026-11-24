<?php

use FastComposer\SourcePrefetch;

$sha = str_repeat('a', 40);
$package = [
    'name' => 'acme/plugin',
    'version' => '1.0.0',
    'source' => ['type' => 'git', 'url' => 'git@example.org:acme/plugin.git', 'reference' => $sha],
];
$dev = array_replace($package, ['name' => 'acme/dev']);
$dist = array_replace($package, ['name' => 'acme/dist', 'dist' => ['url' => 'https://example.org/archive.zip']]);
$https = array_replace($package, ['name' => 'acme/https', 'source' => array_replace($package['source'], ['url' => 'https://example.org/acme/plugin.git'])]);
$lock = ['packages' => [$package, $dist, $https], 'packages-dev' => [$dev]];
$installedPath = $base.'/vendor/composer/installed.json';
mkdir(dirname($installedPath), 0700, true);
mkdir($base.'/plugins/plugin', 0700, true);
$installed = ['packages' => [$package + ['install-path' => '../../plugins/plugin']]];

fc_assert(SourcePrefetch::candidates($lock, [], $installedPath, false) === [$package, $dev], 'Only SSH source-only packages are selected');
fc_assert(SourcePrefetch::candidates($lock, [], $installedPath, true) === [$package], 'No-dev skips development dependencies');
fc_assert(SourcePrefetch::candidates($lock, $installed, $installedPath, false) === [$dev], 'Unchanged custom installer paths are skipped');
$changed = $package;
$changed['source']['reference'] = str_repeat('b', 40);
fc_assert(SourcePrefetch::candidates(['packages' => [$changed]], $installed, $installedPath, false) === [$changed], 'Changed refs are selected');
rmdir($base.'/plugins/plugin');
fc_assert(SourcePrefetch::candidates($lock, $installed, $installedPath, true) === [$package], 'Missing install paths are selected');

foreach (['--dry-run', '--no-cache', '--help', '--working-dir=foo', '-dfoo'] as $flag) {
    fc_assert(!SourcePrefetch::enabled(['install', $flag]), 'Bypass '.$flag);
}
