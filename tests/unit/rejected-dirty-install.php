<?php
// The removed opt-in install flag must fail explicitly, never pass through to Composer.
use FastComposer\Process;

$root = $base.'/install-project';
mkdir($root);
file_put_contents($root.'/composer.json', "{\n  \"name\": \"acme/project\"\n}\n");
[$code, $stdout, $stderr] = Process::run(
    ['php', __DIR__.'/../../bin/fast-composer', 'install', '--skip-dirty-packages'],
    $root
);
fc_assert($code === 2, 'removed flag must be rejected, not forwarded to composer: '.$stdout.$stderr);
fc_assert(str_contains($stderr, 'has been removed'), 'missing clear refusal message: '.$stderr);
fc_assert(!is_file($root.'/composer.lock'), 'removed flag unexpectedly modified the project');
