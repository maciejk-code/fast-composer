<?php
// Versions come from Composer's own VcsRepository (through MirrorDriver): unparseable tags are
// skipped, "v" prefixes kept, the first of two equal tags wins, and the default branch is marked.
use FastComposer\Snapshot;

$repo = $base.'/versions';
fc_init_repo($repo, ['name' => 'acme/versions']);
foreach (['0.3-no-vendor', 'v1.2.0', '1.0', '1.0.0', 'release-1.3.0'] as $tag) {
    fc_git($repo, 'tag', $tag);
}
fc_git($repo, 'branch', 'feature');
$root = $base.'/versions-root';
mkdir($root);
$config = ['repositories' => [['type' => 'vcs', 'url' => $repo]]];
file_put_contents($root.'/composer.json', json_encode($config)."\n");

$state = [];
$snapshot = new Snapshot($root);
$snapshot->sync($state, $config);
$versions = Snapshot::versions($state)['acme/versions'] ?? [];
$names = array_keys($versions);
sort($names);
fc_assert($names === ['1.0', '1.3.0', 'dev-feature', 'dev-main', 'v1.2.0'], 'unexpected versions: '.json_encode($names));
fc_assert(($versions['dev-main']['default-branch'] ?? false) === true, 'default branch not marked like Composer');
fc_assert(!isset($versions['dev-feature']['default-branch']), 'non-default branch marked as default');

$snapshot->writeFastComposer($config, $state);
foreach ($snapshot->repositoryFiles() as $file) {
    fc_assert(!str_contains((string) file_get_contents($file), 'no-vendor'), 'unparseable tag reached the solver input');
}
$snapshot->cleanupWorkFiles();
