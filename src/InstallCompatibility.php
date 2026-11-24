<?php

namespace FastComposer;

/** Bound acceleration to stable Composer releases in the verified API families. */
final class InstallCompatibility
{
    public static function supports(string $version): bool
    {
        return preg_match('/^2\.(?:8|9|10)\.\d+$/D', $version) === 1
            && version_compare($version, '2.8.12', '>=');
    }
}
