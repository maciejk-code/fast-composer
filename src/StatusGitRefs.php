<?php

namespace FastComposer;

/** A conservative one-sided proof for the native Git status unpushed-change check. */
final class StatusGitRefs
{
    public static function synchronizedHead(string $output, string $path): ?string
    {
        $refs = [];
        foreach (explode("\n", trim($output)) as $line) {
            if (!preg_match('{^([a-f0-9]{40}) (HEAD|refs/[A-Za-z0-9_./-]+(?:\^\{\})?)$}D', $line, $match)) {
                return null;
            }
            if (isset($refs[$match[2]])) {
                return null;
            }
            $refs[$match[2]] = $match[1];
        }
        foreach (array_keys($refs) as $name) {
            if ($name !== 'HEAD' && !preg_match('{^refs/(?:heads|remotes|tags)/}D', $name)) {
                return null;
            }
        }
        $head = $refs['HEAD'] ?? null;
        if ($head === null) {
            return null;
        }
        // Reject case-folded collisions: Composer matches refs case-insensitively.
        $folded = array_change_key_case($refs, CASE_LOWER);
        if (count($folded) !== count($refs)) {
            return null;
        }
        foreach ($refs as $name => $sha) {
            if (!str_starts_with($name, 'refs/heads/') || $sha !== $head) {
                continue;
            }
            $branch = substr($name, strlen('refs/heads/'));
            // Native git diff uses abbreviated names. Only optimize ordinary, unambiguous
            // branch names, and require every selected remote to be at the exact HEAD SHA.
            if (!preg_match('{^[a-z][a-z0-9._/-]*$}D', $branch) || !self::unambiguous($refs, $branch, $name, $path)) {
                return null;
            }
            $matched = false;
            foreach ($refs as $remote => $remoteSha) {
                if (!preg_match('{^refs/remotes/([^/]+)/'.preg_quote($branch, '{').'$}iD', $remote, $match)) {
                    continue;
                }
                $short = substr($remote, strlen('refs/remotes/'));
                if (
                    $remoteSha !== $head || !preg_match('{^[a-z][a-z0-9._-]*$}D', $match[1])
                    || !self::unambiguous($refs, $short, $remote, $path)
                ) {
                    return null;
                }
                $matched = true;
            }
            if ($matched) {
                return $head;
            }
        }
        // Missing remote or detached HEAD: leave native behavior in charge.
        return null;
    }

    /** @param array<string, string> $refs */
    private static function unambiguous(array $refs, string $short, string $expected, string $path): bool
    {
        if (file_exists($path.'/.git/'.$short) || is_link($path.'/.git/'.$short)) {
            return false;
        }
        foreach (['refs/'.$short, 'refs/tags/'.$short, 'refs/heads/'.$short, 'refs/remotes/'.$short, 'refs/remotes/'.$short.'/HEAD'] as $candidate) {
            if ($candidate !== $expected && isset($refs[$candidate])) {
                return false;
            }
        }
        return true;
    }
}
