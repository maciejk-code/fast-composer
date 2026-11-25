<?php

use FastComposer\StatusFingerprintCache;
use FastComposer\StatusZipComparison;

if (!class_exists(ZipArchive::class) || !function_exists('posix_geteuid')) {
    return;
}
$cacheRoot = $base.'/fingerprint-cache';
mkdir($cacheRoot, 0700);
$cache = StatusFingerprintCache::open($cacheRoot);
fc_assert($cache !== null, 'private cache available');
$archive = $base.'/archive.zip';
$path = $base.'/package';
mkdir($path);
file_put_contents($path.'/file.php', 'original');
$zip = new ZipArchive();
fc_assert($zip->open($archive, ZipArchive::CREATE) === true, 'create archive');
$zip->addFromString('package/file.php', 'original');
$zip->close();
$digest = hash_file('sha256', $archive);
fc_assert($digest !== false, 'archive hash');
fc_assert(StatusZipComparison::identical($archive, $path, $cache), 'cold comparison');
fc_assert($cache->load($archive, $digest) === ['package/file.php' => hash('sha256', 'original')], 'cached original hash');
fc_assert(StatusZipComparison::identical($archive, $path, $cache), 'warm comparison');
$time = filemtime($path.'/file.php');
file_put_contents($path.'/file.php', 'modified');
touch($path.'/file.php', $time);
fc_assert(!StatusZipComparison::identical($archive, $path, $cache), 'same-size same-mtime change is not clean');
file_put_contents($path.'/file.php', 'original');
$manifest = $cacheRoot.'/fast-composer-status-v1/'.hash('sha256', $archive).'.json';
file_put_contents($manifest, '{"archive":"'.$digest.'","hashes":{}}');
fc_assert($cache->load($archive, $digest) === null, 'corrupt manifest rejected');
fc_assert(StatusZipComparison::identical($archive, $path, $cache), 'corrupt manifest rebuilt');
fc_assert($zip->open($archive) === true, 'reopen archive');
$zip->addFromString('package/file.php', 'modified');
$zip->close();
fc_assert(!StatusZipComparison::identical($archive, $path, $cache), 'changed archive invalidates old fingerprints');
chmod($cacheRoot.'/fast-composer-status-v1', 0777);
fc_assert(StatusFingerprintCache::open($cacheRoot) === null, 'shared cache refused');
chmod($cacheRoot.'/fast-composer-status-v1', 0700);
