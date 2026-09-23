<?php
// Empty objects in composer.json must stay objects, or Composer rejects the schema.
use FastComposer\ComposerJson;

$decoded = json_decode('{"name":"a/b","require":{},"extra":{},"autoload":{"psr-4":{}},"config":{"allow-plugins":{}},"keywords":[]}', true);
$encoded = ComposerJson::encode($decoded);
foreach (['"require": {}', '"extra": {}', '"psr-4": {}', '"allow-plugins": {}', '"keywords": []'] as $needle) {
    fc_assert(str_contains($encoded, $needle), "composer.json encoding lost $needle: $encoded");
}
