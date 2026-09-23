<?php
// `require` must edit composer.json like Composer does (only the changed lines), not re-encode
// the whole file: tabs, key order and inline arrays stay as they were.
use FastComposer\ComposerJson;

$original = "{\n\t\"name\": \"acme/root\",\n\t\"keywords\": [\"wordpress\", \"cms\"],\n\t\"require\": {\n\t\t\"php\": \">=8.2\"\n\t},\n\t\"require-dev\": {\n\t\t\"acme/tool\": \"^1.0\"\n\t}\n}\n";
$before = json_decode($original, true);
$after = $before;
$after['require']['newsuk/a'] = '0.9.1';
$after['require']['newsuk/b'] = '0.36.0';
$after['require']['acme/tool'] = '^2.0';   // moved from require-dev, like `composer require acme/tool:^2.0`
unset($after['require-dev']);

$expected = "{\n\t\"name\": \"acme/root\",\n\t\"keywords\": [\"wordpress\", \"cms\"],\n\t\"require\": {\n\t\t\"php\": \">=8.2\",\n\t\t\"newsuk/a\": \"0.9.1\",\n\t\t\"newsuk/b\": \"0.36.0\",\n\t\t\"acme/tool\": \"^2.0\"\n\t}\n}\n";
$edited = ComposerJson::applyRequireChanges($original, $before, $after, false);
fc_assert($edited === $expected, "require edit changed more than the links:\n".$edited);

// Without Composer's classes the fallback still keeps the file's indentation.
$fallback = ComposerJson::encode($after, "\t");
fc_assert(str_contains($fallback, "\n\t\"require\": {\n\t\t\"php\""), "fallback lost tab indentation:\n".$fallback);
