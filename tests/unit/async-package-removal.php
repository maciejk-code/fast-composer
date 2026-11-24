<?php

use FastComposer\DeferredPackageFilesystem;
use FastComposer\InProcessComposer;

if (!in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true) || !InProcessComposer::loadClasses()) {
    return;
}
$root = realpath($base);
$package = $root.'/plugins/example';
mkdir($package.'/node_modules', 0700, true);
file_put_contents($package.'/node_modules/generated', 'data');
$fs = new DeferredPackageFilesystem($root, new \Composer\Util\ProcessExecutor());
$fs->allow($package);
fc_assert($fs->removeDirectory($package) && !file_exists($package), 'Package path freed');
mkdir($package, 0700, true);
file_put_contents($package.'/new', 'replacement');
fc_assert($fs->finish(), 'Deferred cleanup completed');
fc_assert(is_file($package.'/new'), 'Replacement is untouched');

$outside = $root.'/outside';
mkdir($outside.'/node_modules', 0700, true);
file_put_contents($outside.'/node_modules/keep', 'safe');
$link = $root.'/link';
symlink($outside, $link);
$fs->allow($link);
fc_assert($fs->removeDirectory($link), 'Ordinary symlink removal');
fc_assert(is_file($outside.'/node_modules/keep'), 'Symlink target survives');

// A package replaced between approval and deletion is handled by ordinary Composer.
mkdir($package.'/node_modules', 0700, true);
$fs->allow($package);
rename($package, $package.'-old');
mkdir($package.'/node_modules', 0700, true);
fc_assert($fs->removeDirectory($package), 'Ordinary removal after inode change');
fc_assert(is_file($package.'-old/new'), 'Previously approved directory is untouched');
fc_assert($fs->finish(), 'No stale cleanup');
