<?php

use FastComposer\PreservedInstall;

$preserve = new PreservedInstall($base);
$compatible = new ReflectionMethod(PreservedInstall::class, 'assertCompatible');
$package = [
    'name' => 'acme/local-package',
    'version' => 'dev-main',
    'source' => ['type' => 'git', 'url' => 'file:///repo', 'reference' => str_repeat('a', 40)],
    'require' => ['php' => '>=8.2'],
];
foreach (['library', 'wordpress-plugin', 'symfony-bundle', 'custom-type'] as $type) {
    $installed = $package + ['type' => $type];
    $locked = $installed;
    $locked['source']['reference'] = str_repeat('b', 40);
    $compatible->invoke($preserve, $installed, $locked, 'acme/local-package');
}
echo "preserve-install-all-package-types: PASS\n";

$installed = $package + ['type' => 'wordpress-plugin'];
$locked = $installed;
$locked['require']['ext-zip'] = '*';
try {
    $compatible->invoke($preserve, $installed, $locked, 'acme/local-package');
    throw new RuntimeException('Changed dependency metadata was accepted');
} catch (RuntimeException $e) {
    fc_assert(str_contains($e->getMessage(), 'locked require differs'), 'wrong metadata refusal: '.$e->getMessage());
}

$locked = $installed;
$locked['type'] = 'library';
try {
    $compatible->invoke($preserve, $installed, $locked, 'acme/local-package');
    throw new RuntimeException('Changed package type was accepted');
} catch (RuntimeException $e) {
    fc_assert(str_contains($e->getMessage(), 'version, type or source differs'), 'wrong type refusal: '.$e->getMessage());
}

$changes = new ReflectionMethod(PreservedInstall::class, 'localChanges');
$git = $base.'/local-package';
$sha = fc_init_repo($git, ['name' => 'acme/local-package', 'type' => 'library']);
fc_git($git, 'checkout', '-q', '--detach', $sha);
fc_assert($changes->invoke($preserve, $git) === null, 'clean detached checkout was protected');
file_put_contents($git.'/untracked.txt', 'do not touch');
fc_assert(str_contains((string) $changes->invoke($preserve, $git), 'uncommitted'), 'untracked files not protected');
unlink($git.'/untracked.txt');
fc_git($git, 'switch', '-qc', 'my-unpublished-work');
fc_assert(str_contains((string) $changes->invoke($preserve, $git), 'without upstream'), 'branch without upstream not protected');
fc_assert($changes->invoke($preserve, $git) !== null, 'unpublished work was not protected');

echo "preserve-install-safety: PASS\n";
