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
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $cache = is_string($input['cache'] ?? null) ? FastComposer\StatusFingerprintCache::open($input['cache']) : null;
    $results = [];
    foreach ($input['tasks'] as [$archive, $path]) {
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
