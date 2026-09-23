<?php
// Refreshing reads every ref from the mirror, like Composer: correct metadata per ref, Composer's
// release time, and refs without composer.json (e.g. docs/gh-pages) are skipped, not fatal.
use FastComposer\Snapshot;

['repo' => $repo, 'root' => $root, 'config' => $config] = fc_fixture_acme_a($base);
$state = [];
(new Snapshot($root))->sync($state, $config);

fc_git($repo, 'checkout', '-q', '--orphan', 'docs');
fc_git($repo, 'rm', '-q', '-rf', '.');
file_put_contents($repo.'/README.md', "docs\n");
fc_git($repo, 'add', 'README.md');
fc_git($repo, 'commit', '-q', '-m', 'docs');
fc_git($repo, 'checkout', '-q', '-f', 'main');

// A new process (Snapshot instance) must fetch again.
$refreshed = (new Snapshot($root))->refreshPackages($state, ['acme/a'], $config);
fc_assert(isset($state['repos'][$repo]['refs']['heads']['docs']), 'refresh did not fetch the new docs branch');

$versions = Snapshot::versions($state)['acme/a'] ?? [];
fc_assert(
    $refreshed === 1 && isset($versions['1.0.0'], $versions['dev-main'], $versions['dev-unused-a'], $versions['dev-unused-b']),
    'mirror hydration missed versions: '.implode(', ', array_keys($versions))
);
fc_assert(!isset($versions['dev-docs']), 'ref without composer.json produced a version');
fc_assert(
    ($versions['dev-unused-a']['extra']['unused'] ?? null) === 'a' && ($versions['dev-unused-b']['extra']['unused'] ?? null) === 'b',
    'mirror hydration read metadata from the wrong ref'
);
$expectedTime = (new DateTimeImmutable('@'.trim(fc_git($repo, 'log', '-1', '--format=%at', 'unused-a'))))->format(DATE_RFC3339);
fc_assert(
    ($versions['dev-unused-a']['time'] ?? null) === $expectedTime,
    'release time differs from Composer (author date): '.json_encode($versions['dev-unused-a']['time'] ?? null)
);
