<?php
// The parallel runner keeps results keyed like its input and complete.
use FastComposer\Process;

$parallel = Process::runMany([
    'b' => [['php', '-r', 'usleep(100000); echo "slow";'], null],
    'a' => [['php', '-r', 'fwrite(STDERR, "err"); exit(3);'], null],
], 2);
fc_assert(
    array_keys($parallel) === ['b', 'a'] && $parallel['b'] === [0, 'slow', ''] && $parallel['a'] === [3, '', 'err'],
    'Process::runMany returned unexpected results: '.json_encode($parallel)
);
