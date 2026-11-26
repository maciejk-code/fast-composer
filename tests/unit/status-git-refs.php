<?php

use FastComposer\StatusGitRefs;

$head = str_repeat('a', 40);
$other = str_repeat('b', 40);
$refs = "$head HEAD\n$head refs/heads/main\n$head refs/remotes/origin/main\n";
fc_assert(StatusGitRefs::synchronizedHead($refs, $base) === $head, 'identical refs prove synchronized HEAD');
fc_assert(StatusGitRefs::synchronizedHead($refs."$head refs/remotes/upstream/main\n", $base) === $head, 'all matching remotes equal');
fc_assert(StatusGitRefs::synchronizedHead($refs."$other refs/heads/feature\n", $base) === $head, 'unrelated branch can differ');
fc_assert(StatusGitRefs::synchronizedHead($refs."$other refs/tags/1.0.0\n$head refs/tags/1.0.0^{}\n", $base) === $head, 'annotated tag is supported');
fc_assert(StatusGitRefs::synchronizedHead($refs."$other refs/remotes/upstream/main\n", $base) === null, 'divergent remote must fall back');
fc_assert(StatusGitRefs::synchronizedHead($refs."$other refs/tags/main\n", $base) === null, 'ambiguous branch must fall back');
fc_assert(StatusGitRefs::synchronizedHead($refs."$other refs/tags/origin/main\n", $base) === null, 'ambiguous remote must fall back');
fc_assert(StatusGitRefs::synchronizedHead($refs."$other refs/heads/MAIN\n", $base) === null, 'case collisions must fall back');
fc_assert(StatusGitRefs::synchronizedHead("$head HEAD\n$head refs/heads/main\n", $base) === null, 'unpublished branch must fall back');
fc_assert(StatusGitRefs::synchronizedHead("$head HEAD\n$other refs/heads/main\n$other refs/remotes/origin/main\n", $base) === null, 'detached unrelated HEAD must fall back');
fc_assert(StatusGitRefs::synchronizedHead($refs."$head refs/replace/$other\n", $base) === null, 'replacement refs must fall back');
fc_assert(StatusGitRefs::synchronizedHead($refs."garbage\n", $base) === null, 'malformed output must fall back');
mkdir($base.'/.git');
file_put_contents($base.'/.git/main', $other."\n");
fc_assert(StatusGitRefs::synchronizedHead($refs, $base) === null, 'pseudo-ref must fall back');
