<?php
// Tag and branch names become versions exactly as in Composer's VcsRepository; tags Composer
// cannot parse (e.g. "0.3-no-vendor") are skipped instead of breaking the snapshot repository.
use FastComposer\ComposerVersion;
use FastComposer\Snapshot;

$expectTag = [
    '1.0.0' => ['1.0.0', '1.0.0.0'],
    'v1.2.0' => ['v1.2.0', '1.2.0.0'],
    'release-1.3.0' => ['1.3.0', '1.3.0.0'],
    '2.0.0-RC1' => ['2.0.0-RC1', '2.0.0.0-RC1'],
    '0.3-no-vendor' => null,
    'nightly' => null,
    '2.1-dev' => null,
];
foreach ($expectTag as $tag => $expected) {
    fc_assert(ComposerVersion::fromTag($tag) === $expected, "tag $tag: ".json_encode(ComposerVersion::fromTag($tag)));
}
$expectBranch = ['main' => 'dev-main', '1.x' => '1.x-dev', 'v2.x' => 'v2.x-dev', '3.1' => '3.1.x-dev', '4.*' => '4.x-dev', 'feat#1' => 'dev-feat+1'];
foreach ($expectBranch as $branch => $expected) {
    fc_assert(ComposerVersion::fromBranch($branch) === $expected, "branch $branch: ".json_encode(ComposerVersion::fromBranch($branch)));
}

$repo = $base.'/versions';
fc_init_repo($repo, ['name' => 'acme/versions']);
foreach (['0.3-no-vendor', 'v1.2.0', '1.0', '1.0.0'] as $tag) {
    fc_git($repo, 'tag', $tag);
}
$root = $base.'/versions-root';
mkdir($root);
$config = ['repositories' => [['type' => 'vcs', 'url' => $repo]]];
file_put_contents($root.'/composer.json', json_encode($config)."\n");
$state = [];
$snapshot = new Snapshot($root);
$snapshot->sync($state, $config);
$versions = array_keys($state['packages']['acme/versions'] ?? []);
sort($versions);
// "1.0" and "1.0.0" both normalize to 1.0.0.0: the first tag in ref order wins, like Composer.
fc_assert($versions === ['1.0', 'dev-main', 'v1.2.0'], 'unexpected versions: '.json_encode($versions));

$snapshot->writeFastComposer($config, $state);
$packages = file_get_contents($snapshot->dir().'/packages.json');
fc_assert(!str_contains($packages, 'no-vendor'), 'unparseable tag reached packages.json');
$snapshot->cleanupWorkFiles();
