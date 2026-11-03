<?php
// content-hash is patched in place, keeping Composer's empty JSON objects intact.
use FastComposer\LockFile;

$lockPath = $base.'/content-hash.lock';
file_put_contents($lockPath, "{\n    \"content-hash\": \"old\",\n    \"packages\": [],\n    \"stability-flags\": {},\n    \"platform-dev\": {}\n}\n");
fc_assert(\FastComposer\InProcessComposer::loadClasses(), 'Composer classes are required for lock hash testing');
$composerContents = "{\\n  \\"repositories\\": []\\n}\\n";
LockFile::fixContentHash($lockPath, $composerContents);
$patched = file_get_contents($lockPath);
$expected = \Composer\Package\Locker::getContentHash($composerContents);
fc_assert(
    (json_decode($patched, true, 512, JSON_THROW_ON_ERROR)['content-hash'] ?? '') === $expected,
    'content-hash differs from the current Composer version'
);

$withPolicy = "{\\n  \\"repositories\\": [],\\n  \\"config\\": {\\"policy\\": false}\\n}\\n";
LockFile::fixContentHash($lockPath, $withPolicy);
$policyHash = json_decode(file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR)['content-hash'];
fc_assert(
    $policyHash === \Composer\Package\Locker::getContentHash($withPolicy),
    'config.policy content-hash differs from the current Composer version'
);
if (version_compare(\Composer\Composer::getVersion(), '2.11.0', '>=')) {
    fc_assert($policyHash !== $expected, 'Composer 2.11 must include config.policy in content-hash');
}
$patched = file_get_contents($lockPath);
fc_assert(
    !str_contains($patched, '"old"') && str_contains($patched, '"stability-flags": {}') && str_contains($patched, '"platform-dev": {}'),
    'content-hash rewrite altered the lock file layout: '.$patched
);
