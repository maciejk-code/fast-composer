<?php
require __DIR__.'/../vendor/autoload.php';

use FastComposer\Primer;
use FastComposer\Process;
use FastComposer\Snapshot;

$base = sys_get_temp_dir().'/fast-composer-test-'.bin2hex(random_bytes(4));
mkdir($base, 0700, true);

$rrmdir = static function (string $dir) use (&$rrmdir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) ? $rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
};

try {
    $repo = $base.'/repo';
    mkdir($repo);
    Process::must(['git', 'init', '-q', '-b', 'main'], $repo);
    Process::must(['git', 'config', 'user.email', 'fast-composer-test@example.invalid'], $repo);
    Process::must(['git', 'config', 'user.name', 'fast-composer-test'], $repo);

    file_put_contents($repo.'/composer.json', json_encode([
        'name' => 'acme/a',
        'type' => 'library',
        'require' => ['php' => '>=8.2'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    Process::must(['git', 'add', 'composer.json'], $repo);
    Process::must(['git', 'commit', '-q', '-m', '1.0'], $repo);
    $lockedSha = trim(Process::must(['git', 'rev-parse', 'HEAD'], $repo));
    Process::must(['git', 'tag', '1.0.0'], $repo);

    Process::must(['git', 'checkout', '-q', '-b', 'unused-a'], $repo);
    file_put_contents($repo.'/composer.json', json_encode([
        'name' => 'acme/a',
        'type' => 'library',
        'require' => ['php' => '>=8.2'],
        'extra' => ['unused' => 'a'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    Process::must(['git', 'add', 'composer.json'], $repo);
    Process::must(['git', 'commit', '-q', '-m', 'unused a'], $repo);

    Process::must(['git', 'checkout', '-q', 'main'], $repo);
    Process::must(['git', 'checkout', '-q', '-b', 'unused-b'], $repo);
    file_put_contents($repo.'/composer.json', json_encode([
        'name' => 'acme/a',
        'type' => 'library',
        'require' => ['php' => '>=8.2'],
        'extra' => ['unused' => 'b'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    Process::must(['git', 'add', 'composer.json'], $repo);
    Process::must(['git', 'commit', '-q', '-m', 'unused b'], $repo);
    Process::must(['git', 'checkout', '-q', 'main'], $repo);

    $root = $base.'/root';
    mkdir($root);
    $config = ['repositories' => [['type' => 'vcs', 'url' => $repo]]];
    file_put_contents($root.'/composer.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    file_put_contents($root.'/composer.lock', json_encode([
        'packages' => [[
            'name' => 'acme/a',
            'version' => '1.0.0',
            'source' => ['type' => 'git', 'url' => $repo, 'reference' => $lockedSha],
            'require' => ['php' => '>=8.2'],
        ]],
        'packages-dev' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $snapshot = new Snapshot($root);
    $state = (new Primer($root, $snapshot))->build($config);

    if (($state['repos'][$repo]['name'] ?? null) !== 'acme/a') {
        throw new RuntimeException('repo mapping failed');
    }
    if (($state['packages']['acme/a']['1.0.0']['source']['reference'] ?? null) !== $lockedSha) {
        throw new RuntimeException('lock package snapshot failed');
    }
    if (count($state['packages']['acme/a'] ?? []) !== 1) {
        throw new RuntimeException('priming hydrated unused branch/tag metadata instead of staying lazy');
    }
    if (!isset($state['repos'][$repo]['refs']['heads']['main'], $state['repos'][$repo]['refs']['heads']['unused-a'], $state['repos'][$repo]['refs']['heads']['unused-b'])) {
        throw new RuntimeException('priming did not index remote refs');
    }
    if (!$snapshot->isCompatible($state, $config)) {
        throw new RuntimeException('primer produced an incompatible snapshot hash');
    }
    if (glob($snapshot->dir().'/prime-fetch-*')) {
        throw new RuntimeException('primer left temporary fetch directories behind');
    }

    echo "lazy-primer: OK\n";
} finally {
    $rrmdir($base);
}
