<?php
namespace FastComposer;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Repository\Vcs\VcsDriver;

/**
 * A Composer VCS driver that serves a repository from Fast Composer's local Git mirror.
 *
 * Composer's own VcsRepository runs on top of it, so everything about how refs become
 * packages (version names, skipped tags, package name, default-branch, aliases, release time)
 * is Composer's logic, not a re-implementation. The data (refs and composer.json per commit)
 * is registered up front with serve(), read in one batch from the mirror.
 */
final class MirrorDriver extends VcsDriver
{
    /** @var array<string,array{root:string,branches:array<string,string>,tags:array<string,string>,files:array<string,array{composer:?string,date:?int}>}> */
    private static array $sources = [];

    /** @param array{root:string,branches:array<string,string>,tags:array<string,string>,files:array<string,array{composer:?string,date:?int}>} $source */
    public static function serve(string $url, array $source): void
    {
        self::$sources[GitUrl::normalize($url)] = $source;
    }

    public static function release(string $url): void
    {
        unset(self::$sources[GitUrl::normalize($url)]);
    }

    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        return isset(self::$sources[GitUrl::normalize($url)]);
    }

    public function initialize(): void
    {
        if (!isset(self::$sources[GitUrl::normalize($this->url)])) {
            throw new \RuntimeException('No mirror data registered for '.$this->url);
        }
        if (\Composer\Util\Filesystem::isLocalPath($this->url)) {
            // Same as Composer's GitDriver for local repositories.
            $this->url = (string) preg_replace('{[\\\\/]\.git/?$}', '', $this->url);
        }
    }

    public function getRootIdentifier(): string
    {
        return $this->source()['root'];
    }

    public function getBranches(): array
    {
        return $this->source()['branches'];
    }

    public function getTags(): array
    {
        return $this->source()['tags'];
    }

    public function getFileContent(string $file, string $identifier): ?string
    {
        if ($file !== 'composer.json') {
            return null;
        }
        return $this->source()['files'][$this->commit($identifier)]['composer'] ?? null;
    }

    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        $timestamp = $this->source()['files'][$this->commit($identifier)]['date'] ?? null;
        return $timestamp === null ? null : new \DateTimeImmutable('@'.$timestamp, new \DateTimeZone('UTC'));
    }

    public function getDist(string $identifier): ?array
    {
        return null;
    }

    public function getSource(string $identifier): array
    {
        return ['type' => 'git', 'url' => $this->getUrl(), 'reference' => $identifier];
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    /** Composer asks by branch name for the root identifier and by commit SHA otherwise. */
    private function commit(string $identifier): string
    {
        return $this->source()['branches'][$identifier] ?? $identifier;
    }

    private function source(): array
    {
        return self::$sources[GitUrl::normalize($this->url)];
    }
}
