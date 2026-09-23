<?php
namespace FastComposer;

final class GitUrl
{
    /** One spelling per repository: local paths resolved, GitHub URL variants unified. */
    public static function normalize(string $url): string
    {
        $url = trim($url);
        $local = realpath($url);
        if ($local !== false) {
            return rtrim($local, '/\\');
        }

        if (preg_match('~(?:https?://|ssh://git@|git@)?github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?/?$~i', $url, $m)) {
            return 'github.com/'.strtolower($m[1]).'/'.strtolower(preg_replace('/\.git$/i', '', $m[2]));
        }

        return rtrim(preg_replace('/\.git$/i', '', $url), '/\\');
    }
}
