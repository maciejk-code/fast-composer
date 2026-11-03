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

// Both output streams must be drained concurrently to avoid a full-pipe deadlock.
[$code, $stdout, $stderr] = Process::run([
    'php', '-r',
    'fwrite(STDERR, str_repeat("e", 262144)); fwrite(STDOUT, str_repeat("o", 262144));',
]);
fc_assert(
    $code === 0 && $stdout === str_repeat('o', 262144) && $stderr === str_repeat('e', 262144),
    'Process::run lost output or deadlocked under large stderr/stdout'
);

// Writing a large stdin payload and reading large stderr must not block each other.
$payload = str_repeat('x', 262144);
[$code, $stdout, $stderr] = Process::runWithInput(
    ['php', '-r', 'fwrite(STDERR, str_repeat("e", 262144)); echo hash("sha256", stream_get_contents(STDIN));'],
    $payload
);
fc_assert(
    $code === 0 && $stdout === hash('sha256', $payload) && $stderr === str_repeat('e', 262144),
    'Process::runWithInput lost stdin/stdout/stderr under large streams'
);
