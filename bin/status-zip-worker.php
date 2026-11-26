<?php
// Internal CLI worker: compare only the explicit archive/path pairs sent by the parent.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'FastComposer\\')) {
        $file = __DIR__.'/../src/'.str_replace('\\', '/', substr($class, strlen('FastComposer\\'))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
try {
    $raw = stream_get_contents(STDIN, 8 * 1024 * 1024 + 1);
    if ($raw === false || strlen($raw) > 8 * 1024 * 1024) {
        exit(1);
    }
    $input = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input) || !is_array($input['tasks'] ?? null)) {
        exit(1);
    }
    $cache = is_string($input['cache'] ?? null) ? FastComposer\StatusFingerprintCache::open($input['cache']) : null;
    $results = [];
    foreach ($input['tasks'] as $task) {
        if (!is_array($task) || !is_string($task[0] ?? null) || !is_string($task[1] ?? null)) {
            exit(1);
        }
        [$archive, $path] = $task;
        try {
            $results[] = FastComposer\StatusZipComparison::identical($archive, $path, $cache);
        } catch (Throwable) {
            $results[] = false;
        }
    }
    echo json_encode($results, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    exit(1);
}
