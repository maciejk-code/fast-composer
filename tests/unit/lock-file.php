<?php
// content-hash is patched in place, keeping Composer's empty JSON objects intact.
use FastComposer\LockFile;

$lockPath = $base.'/content-hash.lock';
file_put_contents($lockPath, "{\n    \"content-hash\": \"old\",\n    \"packages\": [],\n    \"stability-flags\": {},\n    \"platform-dev\": {}\n}\n");
LockFile::fixContentHash($lockPath, ['repositories' => []]);
$patched = file_get_contents($lockPath);
fc_assert(
    !str_contains($patched, '"old"') && str_contains($patched, '"stability-flags": {}') && str_contains($patched, '"platform-dev": {}'),
    'content-hash rewrite altered the lock file layout: '.$patched
);
