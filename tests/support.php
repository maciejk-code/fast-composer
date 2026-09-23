<?php
// Helpers shared by the unit tests in tests/unit/. Each test file runs with a fresh temporary
// $base directory and FAST_COMPOSER_CACHE_DIR pointing inside it (see tests/run.php).

use FastComposer\Process;

function fc_git(string $repo, string ...$args): string
{
    return Process::must(array_merge(['git'], $args), $repo);
}

/** Create a Git repository whose first commit contains $composer as composer.json. */
function fc_init_repo(string $repo, array $composer): string
{
    mkdir($repo, 0700, true);
    fc_git($repo, 'init', '-q', '-b', 'main');
    fc_git($repo, 'config', 'user.email', 'fast-composer-test@example.invalid');
    fc_git($repo, 'config', 'user.name', 'fast-composer-test');
    fc_git($repo, 'config', 'commit.gpgsign', 'false');
    fc_git($repo, 'config', 'tag.gpgsign', 'false');
    return fc_commit_composer($repo, $composer, 'initial');
}

/** Commit $composer as composer.json on the current branch; returns the commit SHA. */
function fc_commit_composer(string $repo, array $composer, string $message): string
{
    file_put_contents($repo.'/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fc_git($repo, 'add', 'composer.json');
    fc_git($repo, 'commit', '-q', '-m', $message);
    return trim(fc_git($repo, 'rev-parse', 'HEAD'));
}

/**
 * Package acme/a: tag 1.0.0 on main plus branches unused-a / unused-b with their own metadata,
 * and a root project whose lock pins 1.0.0.
 *
 * @return array{repo:string,root:string,config:array,lockedSha:string}
 */
function fc_fixture_acme_a(string $base): array
{
    $repo = $base.'/repo';
    $lockedSha = fc_init_repo($repo, ['name' => 'acme/a', 'type' => 'library', 'require' => ['php' => '>=8.2']]);
    fc_git($repo, 'tag', '1.0.0');
    foreach (['a', 'b'] as $suffix) {
        fc_git($repo, 'checkout', '-q', '-b', 'unused-'.$suffix, 'main');
        fc_commit_composer($repo, ['name' => 'acme/a', 'type' => 'library', 'require' => ['php' => '>=8.2'], 'extra' => ['unused' => $suffix]], 'unused '.$suffix);
    }
    fc_git($repo, 'checkout', '-q', 'main');

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

    return ['repo' => $repo, 'root' => $root, 'config' => $config, 'lockedSha' => $lockedSha];
}

function fc_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fc_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        is_dir($path) && !is_link($path) ? fc_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}
