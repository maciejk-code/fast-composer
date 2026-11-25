<?php

use FastComposer\StatusZipComparison;

if (!class_exists(ZipArchive::class)) {
    return;
}
$archive = $base.'/status.zip';
$path = $base.'/status';
mkdir($path);
mkdir($path.'/src');
file_put_contents($path.'/src/file.php', 'original');
file_put_contents($path.'/composer.json', '{}');
$zip = new ZipArchive();
fc_assert($zip->open($archive, ZipArchive::CREATE) === true, 'create ZIP');
$zip->addFromString('package/src/file.php', 'original');
$zip->addFromString('package/composer.json', '{}');
$zip->close();
fc_assert(StatusZipComparison::identical($archive, $path), 'ordinary rooted ZIP is clean');
file_put_contents($path.'/src/file.php', 'modified');
fc_assert(!StatusZipComparison::identical($archive, $path), 'same-length modification must be detected');
file_put_contents($path.'/src/file.php', 'original');
file_put_contents($path.'/extra', 'added');
fc_assert(!StatusZipComparison::identical($archive, $path), 'extra file falls back');
unlink($path.'/extra');
unlink($path.'/src/file.php');
fc_assert(!StatusZipComparison::identical($archive, $path), 'missing file falls back');
symlink('../composer.json', $path.'/src/file.php');
fc_assert(!StatusZipComparison::identical($archive, $path), 'symlink falls back');
unlink($path.'/src/file.php');
file_put_contents($path.'/src/file.php', 'original');
$zip = new ZipArchive();
fc_assert($zip->open($archive) === true, 'reopen ZIP');
$zip->addFromString('../unsafe', 'no');
$zip->close();
fc_assert(!StatusZipComparison::identical($archive, $path), 'traversal entry falls back');
fc_assert(!file_exists($base.'/unsafe'), 'nothing extracted');
