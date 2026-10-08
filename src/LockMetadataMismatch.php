<?php

namespace FastComposer;

final class LockMetadataMismatch extends \RuntimeException
{
    /** @param list<string> $fields */
    public function __construct(
        public readonly string $package,
        public readonly string $sourceUrl,
        public readonly string $reference,
        public readonly array $fields = []
    ) {
        $details = $fields === [] ? '' : '; different metadata fields: '.implode(', ', array_slice($fields, 0, 12));
        if (count($fields) > 12) {
            $details .= sprintf(' (+%d more)', count($fields) - 12);
        }
        parent::__construct(
            "Lock metadata mismatch for {$package} at {$reference}{$details}; refusing to write composer.lock"
        );
    }
}
