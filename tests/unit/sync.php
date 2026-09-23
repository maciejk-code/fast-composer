<?php
// First-run sync builds the whole snapshot; later syncs are incremental; mirrors are shared.
use FastComposer\Snapshot;

['repo' => $repo, 'root' => $root, 'config' => $config, 'lockedSha' => $lockedSha] = fc_fixture_acme_a($base);

$snapshot = new Snapshot($root);
$state = [];
$synced = $snapshot->sync($state, $config);

fc_assert($synced === 1 && ($state['repos'][$repo]['name'] ?? null) === 'acme/a', 'repo mapping failed');
fc_assert((Snapshot::versions($state)['acme/a']['1.0.0']['source']['reference'] ?? null) === $lockedSha, 'lock package snapshot failed');
fc_assert(
    isset(Snapshot::versions($state)['acme/a']['dev-unused-a'], Snapshot::versions($state)['acme/a']['dev-unused-b'], Snapshot::versions($state)['acme/a']['dev-main']),
    'sync did not index every branch: '.implode(', ', array_keys(Snapshot::versions($state)['acme/a'] ?? []))
);
fc_assert($snapshot->isCompatible($state, $config), 'sync produced an incompatible snapshot hash');
fc_assert((bool) glob($base.'/cache/mirrors/*.git'), 'mirror is not stored in the shared cache area');

// Adding a repository only synchronizes the new one; removing it drops its packages.
$repo2 = $base.'/repo2';
fc_init_repo($repo2, ['name' => 'acme/b', 'type' => 'library']);
$config2 = ['repositories' => [['type' => 'vcs', 'url' => $repo], ['type' => 'vcs', 'url' => $repo2]]];
fc_assert(!$snapshot->isCompatible($state, $config2), 'changed repositories must be detected');
$synced = (new Snapshot($root))->sync($state, $config2);
fc_assert(
    $synced === 1 && ($state['repos'][$repo2]['name'] ?? null) === 'acme/b' && isset(Snapshot::versions($state)['acme/b']['dev-main']),
    'incremental sync did not add only the new repository (synced='.$synced.')'
);
$synced = (new Snapshot($root))->sync($state, $config);
fc_assert($synced === 0 && !isset(Snapshot::versions($state)['acme/b']) && isset(Snapshot::versions($state)['acme/a']), 'removing a repository did not drop exactly its packages');

// A second project using the same repository reuses the shared mirror.
$otherRoot = $base.'/other-root';
mkdir($otherRoot);
file_put_contents($otherRoot.'/composer.json', json_encode($config)."\n");
$mirrorsBefore = glob($base.'/cache/mirrors/*.git');
$otherState = [];
(new Snapshot($otherRoot))->sync($otherState, $config);
fc_assert(
    glob($base.'/cache/mirrors/*.git') === $mirrorsBefore && isset(Snapshot::versions($otherState)['acme/a']['dev-unused-a']),
    'second project did not reuse the shared mirror'
);
