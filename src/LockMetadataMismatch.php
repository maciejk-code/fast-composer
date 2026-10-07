<?php

namespace FastComposer;

final class LockMetadataMismatch extends \RuntimeException
{
    public function __construct(
        public readonly string $package,
        public readonly string $sourceUrl,
        public readonly string $reference
    ) {
        parent::__construct(
            "Lock metadata mismatch for {$package} at {$reference}; refusing to write composer.lock"
        );
    }
}
