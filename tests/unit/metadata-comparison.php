<?php

use FastComposer\ComposerPackages;
use FastComposer\LockMetadataComparison;
use FastComposer\LockMetadataMismatch;

fc_assert(ComposerPackages::available(), 'Composer classes unavailable for metadata normalization tests');

$locked = [
    'name' => 'acme/example',
    'version' => 'dev-main',
    'type' => 'library',
    'require' => ['php' => '>=8.2'],
    'autoload' => ['psr-4' => ['Acme\\Example\\' => 'src/']],
    'bin' => ['bin/console'],
];

$source = [
    'name' => 'acme/example',
    // Real VCS composer.json does not normally contain the version.
    'type' => 'LIBRARY',
    'require' => ['PHP' => '>=8.2'],
    'require-dev' => [],
    'autoload' => ['psr-4' => ['Acme\\Example\\' => 'src/']],
    'autoload-dev' => [],
    'extra' => [],
    'bin' => '/bin/console',
];

fc_assert(
    LockMetadataComparison::differences($locked, $source) === [],
    'Composer-normalizable source metadata was incorrectly rejected'
);
echo "metadata-composer-normalization: PASS\n";

$changed = $source;
$changed['require']['psr/log'] = '^3.0';
$differences = LockMetadataComparison::differences($locked, $changed);
fc_assert(in_array('require.psr/log', $differences, true), 'a real dependency change was not detected');

$changed = $source;
$changed['autoload']['psr-4']['Acme\\Example\\'] = 'lib/';
$differences = LockMetadataComparison::differences($locked, $changed);
fc_assert(
    in_array('autoload.psr-4.Acme\\Example\\', $differences, true),
    'a real autoload change was not detected'
);

$changed = $source;
$changed['autoload-dev'] = ['psr-4' => ['Acme\\Test\\' => 'tests/']];
$differences = LockMetadataComparison::differences($locked, $changed);
fc_assert(in_array('autoload-dev', $differences, true), 'autoload-dev mismatch was not detected');

$exception = new LockMetadataMismatch('acme/example', 'file:///repo', str_repeat('a', 40), ['require.secret-package']);
fc_assert(
    str_contains($exception->getMessage(), 'require.secret-package'),
    'metadata mismatch did not include changed paths'
);
echo "metadata-real-differences-and-diagnostics: PASS\n";
