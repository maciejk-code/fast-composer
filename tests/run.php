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

    // Parallel runner keeps results keyed and complete.
    $parallel = Process::runMany([
        'b' => [['php', '-r', 'usleep(100000); echo "slow";'], null],
        'a' => [['php', '-r', 'fwrite(STDERR, "err"); exit(3);'], null],
    ], 2);
    if (array_keys($parallel) !== ['b', 'a'] || $parallel['b'] !== [0, 'slow', ''] || $parallel['a'] !== [3, '', 'err']) {
        throw new RuntimeException('Process::runMany returned unexpected results: '.json_encode($parallel));
    }
    echo "parallel-runner: OK\n";

    // A branch without composer.json (e.g. docs/gh-pages) must not break hydration, like Composer.
    Process::must(['git', 'checkout', '-q', '--orphan', 'docs'], $repo);
    Process::must(['git', 'rm', '-q', '-rf', '.'], $repo);
    file_put_contents($repo.'/README.md', "docs\n");
    Process::must(['git', 'add', 'README.md'], $repo);
    Process::must(['git', 'commit', '-q', '-m', 'docs'], $repo);
    Process::must(['git', 'checkout', '-q', '-f', 'main'], $repo);

    $refreshed = $snapshot->refreshPackages($state, ['acme/a']);
    $versions = $state['packages']['acme/a'] ?? [];
    if ($refreshed !== 1 || !isset($versions['1.0.0'], $versions['dev-main'], $versions['dev-unused-a'], $versions['dev-unused-b'])) {
        throw new RuntimeException('mirror hydration missed versions: '.implode(', ', array_keys($versions)));
    }
    if (isset($versions['dev-docs'])) {
        throw new RuntimeException('ref without composer.json produced a version');
    }
    if (($versions['dev-unused-a']['extra']['unused'] ?? null) !== 'a' || ($versions['dev-unused-b']['extra']['unused'] ?? null) !== 'b') {
        throw new RuntimeException('mirror hydration read metadata from the wrong ref');
    }
    $expectedTime = (new DateTimeImmutable('@'.trim(Process::must(['git', 'log', '-1', '--format=%at', 'unused-a'], $repo))))->format(DATE_RFC3339);
    if (($versions['dev-unused-a']['time'] ?? null) !== $expectedTime) {
        throw new RuntimeException('release time differs from Composer (author date): '.json_encode($versions['dev-unused-a']['time'] ?? null));
    }
    echo "mirror-hydration: OK\n";

    // content-hash is patched in place, keeping Composer's empty JSON objects intact.
    $lockPath = $root.'/content-hash.lock';
    file_put_contents($lockPath, "{\n    \"content-hash\": \"old\",\n    \"packages\": [],\n    \"stability-flags\": {},\n    \"platform-dev\": {}\n}\n");
    $snapshot->fixContentHash($lockPath, $config);
    $patched = file_get_contents($lockPath);
    if (str_contains($patched, '"old"') || !str_contains($patched, '"stability-flags": {}') || !str_contains($patched, '"platform-dev": {}')) {
        throw new RuntimeException('content-hash rewrite altered the lock file layout: '.$patched);
    }
    echo "content-hash-in-place: OK\n";
} finally {
    $rrmdir($base);
}
