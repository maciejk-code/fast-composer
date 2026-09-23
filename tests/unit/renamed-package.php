<?php
// An old tag with a different "name" (renamed package) belongs to the repository's package,
// exactly like Composer's VcsRepository treats it.
use FastComposer\Snapshot;

$renamed = $base.'/renamed';
fc_init_repo($renamed, ['name' => 'acme/old-name']);
fc_git($renamed, 'tag', '1.0.0');
fc_commit_composer($renamed, ['name' => 'acme/renamed'], '2');

$root = $base.'/renamed-root';
mkdir($root);
$config = ['repositories' => [['type' => 'vcs', 'url' => $renamed]]];
file_put_contents($root.'/composer.json', json_encode($config)."\n");

$state = [];
(new Snapshot($root))->sync($state, $config);
fc_assert(
    (Snapshot::versions($state)['acme/renamed']['1.0.0']['name'] ?? null) === 'acme/renamed' && !isset(Snapshot::versions($state)['acme/old-name']),
    'old tag with a different name was not attributed to the repository package: '.json_encode(array_keys(Snapshot::versions($state)))
);
