<?php

use FastComposer\SourcePrefetch;

fc_assert(SourcePrefetch::workerCount(0, 8) === 0, 'Warm cache starts no workers');
fc_assert(SourcePrefetch::workerCount(1, 8) === 0, 'Single miss uses native Composer');
fc_assert(SourcePrefetch::workerCount(3, 8) === 3, 'Workers bounded by misses');
fc_assert(SourcePrefetch::workerCount(68, 8) === 8, 'Workers bounded by configured limit');
fc_assert(SourcePrefetch::workerCount(68, 1) === 0, 'Serial limit bypasses prefetch');

$mirror = $base.'/mirror';
$package = ['version' => '1.0.0', 'source' => ['url' => 'git@example.invalid:repo.git', 'reference' => str_repeat('a', 40)]];
fc_assert(SourcePrefetch::cacheRecord($mirror, [$package]) === null, 'Missing mirror is cold');
mkdir($mirror.'/objects', 0700, true);
file_put_contents($mirror.'/config', 'fixture');
$first = SourcePrefetch::cacheRecord($mirror, [$package]);
fc_assert($first !== null && $first === SourcePrefetch::cacheRecord($mirror, [$package]), 'Stable warm record');
$changed = $package;
$changed['source']['reference'] = str_repeat('b', 40);
fc_assert($first !== SourcePrefetch::cacheRecord($mirror, [$changed]), 'Different ref invalidates record');
file_put_contents($mirror.'/config', 'changed');
fc_assert($first !== SourcePrefetch::cacheRecord($mirror, [$package]), 'Changed configuration invalidates record');
rename($mirror, $mirror.'-old');
mkdir($mirror.'/objects', 0700, true);
file_put_contents($mirror.'/config', 'fixture');
fc_assert($first !== SourcePrefetch::cacheRecord($mirror, [$package]), 'Replaced mirror invalidates record');
