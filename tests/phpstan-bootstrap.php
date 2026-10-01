<?php
require __DIR__.'/../vendor/autoload.php';
if (!FastComposer\InProcessComposer::loadClasses()) {
    fwrite(STDERR, "PHPStan: the `composer` phar on PATH is needed to analyse Composer class usage\n");
}
