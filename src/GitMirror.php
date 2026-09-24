<?php
namespace FastComposer;

/**
 * Persistent shallow Git mirrors of VCS repositories, shared by every project that uses the
 * same repository URL.
 *
 * All network access to VCS repositories goes through here. It also remembers, for the current
 * process only, which repositories and SHAs were obtained from the remote: the lock validation
 * relies on "fetched from the remote during this invocation" as proof of reachability.
 */
final class GitMirror
{
    private const SYNC_MARKER = 'fast-composer-synced';
    private const DEFAULT_BRANCH_FILE = 'fast-composer-default-branch';

    /** @var array<string,?array> composer.json per URL+SHA read during this process (null: none). */
    private array $metadata = [];
    /** @var array<string,true> URL+SHA pairs obtained from the remote during this process. */
    private array $reachable = [];
    /** @var array<string,true> Repositories whose tips were fetched during this process. */
    private array $synced = [];
    /** @var null|callable(string):void */
    private $logger = null;
    /** @var null|callable(string):array<string,string> extra Git environment (credentials) per URL */
    private $credentials = null;

    /**
     * @param string $baseDir cache base directory; mirrors live in $baseDir/mirrors
     * @param string $cwd working directory for Git processes
     */
    public function __construct(private string $baseDir, private string $cwd)
    {
    }

    /** @param callable(string):void $logger receives human-readable progress lines */
    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    /** @param callable(string):array<string,string> $credentials extra Git environment for a remote URL */
    public function setCredentials(callable $credentials): void
    {
        $this->credentials = $credentials;
    }

    /**
     * Synchronize branch/tag tips of each repository into its mirror.
     *
     * One network operation per repository both lists refs and downloads any new tips; the
     * repositories are fetched concurrently. Repositories already synchronized during this
     * invocation are skipped.
     *
     * @param list<string> $urls
     * @return array<string,string> error message per failed URL
     */
    public function sync(array $urls, bool $refreshDefaultBranch = false): array
    {
        $commands = [];
        foreach (array_values(array_unique($urls)) as $url) {
            if ($this->isSynced($url)) {
                continue;
            }
            $dir = $this->ensureMirror($url);
            if ($refreshDefaultBranch || !is_file($dir.'/'.self::DEFAULT_BRANCH_FILE)) {
                // Composer's root identifier is the remote HEAD branch; ask for it in the same
                // parallel batch (it rarely changes, so it is remembered per mirror).
                $commands[$url.'#HEAD'] = $this->remote($url, [['git', 'ls-remote', '--symref', '--', $url, 'HEAD'], $this->cwd]);
            }
            // The first fetch only takes the tips (--depth=1). Later fetches are incremental
            // against those tips; a repeated --depth would force an extra pack round even when
            // nothing changed.
            $depth = is_file($dir.'/'.self::SYNC_MARKER) ? [] : ['--depth=1'];
            $commands[$url] = $this->remote($url, $this->git($dir, array_merge(
                ['fetch', '-q', '--prune', '--no-tags'],
                $depth,
                ['--', $url, '+refs/heads/*:refs/heads/*', '+refs/tags/*:refs/tags/*']
            )));
        }

        $urlsToLock = array_values(array_filter(array_keys($commands), static fn ($key): bool => !str_ends_with((string) $key, '#HEAD')));
        $lookups = count($commands) - count($urlsToLock);
        $what = $lookups === 0
            ? sprintf('fetching %d VCS repositories', count($urlsToLock))
            : sprintf(
                'fetching %d VCS repositories and, once per repository, the default branch of %d (%d Git operations)',
                count($urlsToLock),
                $lookups,
                count($commands)
            );
        $results = $this->runLocked($commands, $urlsToLock, $what, static fn ($key): string => str_ends_with((string) $key, '#HEAD') ? substr((string) $key, 0, -5).' (default branch)' : (string) $key);

        $errors = [];
        foreach ($results as $url => [$code, $out, $err]) {
            if (str_ends_with((string) $url, '#HEAD')) {
                if ($code === 0) {
                    // Remember the answer even when the remote HEAD is dangling (no symref), so
                    // the question is not repeated on every run.
                    $branch = preg_match('{^ref: refs/heads/(\S+)\s+HEAD$}m', $out, $m) ? $m[1] : '';
                    @file_put_contents($this->mirrorDir(substr((string) $url, 0, -5)).'/'.self::DEFAULT_BRANCH_FILE, $branch);
                }
                continue;
            }
            if ($code !== 0) {
                $errors[$url] = $this->gitError($err !== '' ? $err : $out, "Cannot read refs from $url");
                continue;
            }
            @touch($this->mirrorDir($url).'/'.self::SYNC_MARKER);
            $this->synced[GitUrl::normalize($url)] = true;
        }
        return $errors;
    }

    public function isSynced(string $url): bool
    {
        return isset($this->synced[GitUrl::normalize($url)]);
    }

    /**
     * Branch and tag tips in the mirror (tags peeled to their commit).
     *
     * @return array{heads:array<string,string>,tags:array<string,string>}
     */
    public function refs(string $url): array
    {
        $out = Process::must($this->git($this->mirrorDir($url), [
            'for-each-ref', '--format=%(objectname) %(*objectname) %(refname)', 'refs/heads', 'refs/tags',
        ])[0], $this->cwd);

        $heads = [];
        $tags = [];
        foreach (preg_split('/\R/', trim($out)) ?: [] as $line) {
            $parts = explode(' ', $line, 3);
            if (count($parts) !== 3) {
                continue;
            }
            [$sha, $peeled, $ref] = $parts;
            if (str_starts_with($ref, 'refs/heads/')) {
                $heads[substr($ref, strlen('refs/heads/'))] = $sha;
            } elseif (str_starts_with($ref, 'refs/tags/')) {
                $tags[substr($ref, strlen('refs/tags/'))] = $peeled !== '' ? $peeled : $sha;
            }
        }

        if ($this->isSynced($url)) {
            // Tips fetched during this invocation are proven reachable on the remote.
            foreach (array_merge(array_values($heads), array_values($tags)) as $sha) {
                $this->reachable[$this->key($url, $sha)] = true;
            }
        }

        return ['heads' => $heads, 'tags' => $tags];
    }

    /**
     * composer.json of commits already in the mirror, read with a single `git cat-file --batch`.
     *
     * @param list<string> $shas
     * @return array<string,?array<string,mixed>> per SHA; null when the commit has no readable composer.json
     */
    public function metadata(string $url, array $shas): array
    {
        $shas = array_values(array_unique(array_filter($shas, static fn (string $sha): bool => $sha !== '')));
        $missing = array_values(array_filter($shas, fn (string $sha): bool => !array_key_exists($this->key($url, $sha), $this->metadata)));

        if ($missing !== []) {
            $objects = $this->catFile($this->mirrorDir($url), array_map(static fn (string $sha): string => $sha.':composer.json', $missing));
            foreach ($missing as $i => $sha) {
                $this->metadata[$this->key($url, $sha)] = $this->decodeComposerJson($objects[$i] ?? null);
            }
        }

        $result = [];
        foreach ($shas as $sha) {
            $result[$sha] = $this->metadata[$this->key($url, $sha)];
        }
        return $result;
    }

    /**
     * Fetch single branches (explicit dev-* requirements) concurrently. Repositories already
     * synchronized during this invocation need no fetch.
     *
     * @param array<array-key,array{0:string,1:string,2:string}> $requests [url, branch, label]
     * @return array<array-key,string> error message per failed request ('' when Git gave none)
     */
    public function fetchBranches(array $requests): array
    {
        $commands = [];
        $urls = [];
        $labels = [];
        foreach ($requests as $key => [$url, $branch, $label]) {
            if ($this->isSynced($url)) {
                continue;
            }
            $ref = 'refs/heads/'.$branch;
            $commands[$key] = $this->remote($url, $this->git($this->ensureMirror($url), [
                'fetch', '-q', '--depth=1', '--no-tags', '--no-write-fetch-head', '--', $url, '+'.$ref.':'.$ref,
            ]));
            $urls[] = $url;
            $labels[$key] = $label;
        }

        $errors = [];
        foreach ($this->runLocked($commands, $urls, 'fetching explicit dev branches', static fn ($key): string => $labels[$key]) as $key => [$code, , $err]) {
            if ($code !== 0) {
                $errors[$key] = $err !== '' ? $this->gitError($err, '') : '';
            }
        }
        return $errors;
    }

    /**
     * Branch tip and its composer.json, read from the mirror after fetchBranches()/sync().
     *
     * @return array{0:string,1:array}
     */
    public function branch(string $url, string $branch, string $notFoundMessage): array
    {
        $ref = 'refs/heads/'.$branch;
        $objects = $this->catFile($this->mirrorDir($url), [$ref.'^{commit}', $ref.':composer.json']);
        $sha = $objects[0]['oid'] ?? null;
        if (!is_string($sha) || ($objects[0]['type'] ?? null) !== 'commit') {
            throw new \RuntimeException($notFoundMessage);
        }

        $key = $this->key($url, $sha);
        $this->reachable[$key] = true;
        $this->metadata[$key] = $this->decodeComposerJson($objects[1] ?? null);
        if ($this->metadata[$key] === null) {
            throw new \RuntimeException('Invalid composer.json at '.$sha);
        }
        return [$sha, $this->metadata[$key]];
    }

    /**
     * The repository's default branch (Composer's "root identifier"): the remote HEAD branch
     * learned during sync(), falling back like Composer when the remote does not advertise one.
     */
    public function defaultBranch(string $url): string
    {
        $file = $this->mirrorDir($url).'/'.self::DEFAULT_BRANCH_FILE;
        $remembered = trim((string) @file_get_contents($file));
        if ($remembered !== '') {
            if (isset($this->refs($url)['heads'][$remembered])) {
                return $remembered;
            }
            // The remote default branch was renamed or deleted: ask again on the next sync.
            @unlink($file);
        }
        // Composer's GitDriver falls back to "master" when the remote HEAD is unknown.
        return 'master';
    }

    /**
     * Package name Composer assigns to each repository: the composer.json name on its default
     * branch.
     *
     * @param list<string> $urls
     * @return array<string,string> per URL
     */
    public function defaultBranchNames(array $urls): array
    {
        // Mirrors that were never synchronized (e.g. only exact SHAs fetched) have no refs yet.
        $errors = $this->sync(array_values(array_filter($urls, fn (string $url): bool => !is_file($this->mirrorDir($url).'/'.self::SYNC_MARKER))));
        $names = [];
        foreach ($urls as $url) {
            if (isset($errors[$url])) {
                throw new \RuntimeException($errors[$url]);
            }
            $sha = $this->refs($url)['heads'][$this->defaultBranch($url)] ?? null;
            $name = $sha === null ? null : ($this->metadata($url, [$sha])[$sha]['name'] ?? null);
            if (!is_string($name) || $name === '') {
                throw new \RuntimeException("composer.json at $url HEAD has no package name");
            }
            $names[$url] = $name;
        }
        return $names;
    }

    /**
     * Raw composer.json text and commit author date of commits in the mirror, for Composer's
     * own VcsRepository (via MirrorDriver).
     *
     * @param list<string> $shas
     * @return array<string,array{composer:?string,date:?int}>
     */
    public function files(string $url, array $shas): array
    {
        $shas = array_values(array_unique($shas));
        if ($shas === []) {
            return [];
        }
        $specs = [];
        foreach ($shas as $sha) {
            $specs[] = $sha.':composer.json';
            $specs[] = $sha.'^{commit}';
        }
        $objects = $this->catFile($this->mirrorDir($url), $specs);
        $result = [];
        foreach ($shas as $i => $sha) {
            $file = $objects[2 * $i] ?? null;
            $commit = $objects[2 * $i + 1] ?? null;
            $result[$sha] = [
                'composer' => $file !== null && $file['type'] === 'blob' ? $file['content'] : null,
                'date' => $commit !== null && preg_match('/^author .* (\d+) [+-]\d{4}$/m', $commit['content'], $m) ? (int) $m[1] : null,
            ];
        }
        return $result;
    }

    /** composer.json at an exact SHA obtained from the remote during this invocation. */
    public function composerAt(string $url, string $sha): array
    {
        if (!isset($this->reachable[$this->key($url, $sha)])) {
            $this->fetchExact([$url => [$sha]]);
        }
        $data = $this->metadata($url, [$sha])[$sha];
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid composer.json at '.$sha);
        }
        return $data;
    }

    /**
     * Fetch exact commits from the remote (one fetch per repository, concurrently), skipping
     * those already obtained during this invocation.
     *
     * @param array<string,list<string>> $shasByUrl
     * @return array<string,string> error message per failed URL
     */
    public function fetchExact(array $shasByUrl, bool $throw = true): array
    {
        $commands = [];
        $chunks = [];
        foreach ($shasByUrl as $url => $shas) {
            $shas = array_values(array_unique(array_filter(
                $shas,
                fn (string $sha): bool => $sha !== '' && !isset($this->reachable[$this->key($url, $sha)])
            )));
            if ($shas === []) {
                continue;
            }
            $dir = $this->ensureMirror($url);
            // Keep command lines bounded while amortizing SSH/TLS setup across many SHAs.
            foreach (array_chunk($shas, 64) as $i => $chunk) {
                $commands[$url.'#'.$i] = $this->remote($url, $this->git($dir, array_merge(
                    ['fetch', '-q', '--depth=1', '--no-tags', '--no-write-fetch-head', '--', $url],
                    $chunk
                )));
                $chunks[$url.'#'.$i] = [$url, $chunk];
            }
        }

        $results = $this->runLocked(
            $commands,
            array_column($chunks, 0),
            'fetching exact locked commits',
            static fn ($id): string => $chunks[$id][0].' ('.count($chunks[$id][1]).' SHA)'
        );

        $errors = [];
        foreach ($results as $id => [$code, $out, $err]) {
            [$url, $chunk] = $chunks[$id];
            if ($code !== 0) {
                $errors[$url] = $this->gitError($err !== '' ? $err : $out, 'Cannot fetch '.implode(', ', $chunk)." from $url");
                continue;
            }
            foreach ($chunk as $sha) {
                $this->reachable[$this->key($url, $sha)] = true;
            }
        }

        if ($throw && $errors !== []) {
            throw new \RuntimeException(reset($errors));
        }
        return $errors;
    }

    private function key(string $url, string $sha): string
    {
        return hash('sha256', GitUrl::normalize($url)).':'.$sha;
    }

    private function mirrorDir(string $url): string
    {
        return $this->baseDir.'/mirrors/'.substr(hash('sha256', GitUrl::normalize($url)), 0, 24).'.git';
    }

    private function ensureMirror(string $url): string
    {
        $dir = $this->mirrorDir($url);
        if (!is_file($dir.'/HEAD')) {
            $parent = dirname($dir);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new \RuntimeException("Cannot create mirror directory $parent");
            }
            Process::must(['git', 'init', '-q', '--bare', $dir], $this->cwd);
        }
        return $dir;
    }

    /**
     * A Git command against a mirror. Mirrors are private metadata caches: never trigger
     * background maintenance or gc.
     *
     * @param list<string> $args
     * @return array{0:list<string>,1:string}
     */
    private function git(string $dir, array $args): array
    {
        return [array_merge(
            ['git', '-c', 'maintenance.auto=false', '-c', 'gc.auto=0', '-c', 'fetch.writeCommitGraph=false', '--git-dir='.$dir],
            $args
        ), $this->cwd];
    }

    /**
     * Attach the credentials Composer would use for $url to a network Git command.
     *
     * @param array{0:list<string>,1:string} $command
     * @return array{0:list<string>,1:string,2?:array<string,string>}
     */
    private function remote(string $url, array $command): array
    {
        $env = $this->credentials !== null ? ($this->credentials)($url) : [];
        if ($env !== []) {
            $command[2] = $env;
        }
        return $command;
    }

    /**
     * Run Git commands concurrently while holding the locks of the mirrors they write to.
     *
     * @param array<array-key,array{0:list<string>,1:?string,2?:array<string,string>}> $commands [args, cwd, credentials environment]
     * @param list<string> $urls repositories whose mirrors the commands write
     * @param callable(array-key):string $labelOf
     */
    private function runLocked(array $commands, array $urls, string $what, callable $labelOf): array
    {
        if ($commands === []) {
            return [];
        }
        $locks = $this->lock($urls);
        try {
            return $this->run($commands, $what, $labelOf);
        } finally {
            foreach ($locks as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /**
     * Serialize writes to a shared mirror across processes (other projects may use it). Locks
     * are taken in a stable order so concurrent processes cannot deadlock.
     *
     * @param list<string> $urls
     * @return list<resource>
     */
    private function lock(array $urls): array
    {
        $dirs = array_values(array_unique(array_map(fn (string $url): string => $this->mirrorDir($url), $urls)));
        sort($dirs);
        $handles = [];
        foreach ($dirs as $dir) {
            $handle = @fopen($dir.'.lock', 'c');
            if ($handle === false) {
                continue;
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                $this->log('waiting for another fast-composer process that is fetching into '.basename($dir).' ...');
                flock($handle, LOCK_EX);
            }
            $handles[] = $handle;
        }
        return $handles;
    }

    /**
     * Run Git commands concurrently and report per-repository progress plus a heartbeat naming
     * what is still running, so a slow or stuck remote is visible instead of silent.
     *
     * @param array<array-key,array{0:list<string>,1:?string,2?:array<string,string>}> $commands [args, cwd, credentials environment]
     * @param callable(array-key):string $labelOf
     */
    private function run(array $commands, string $what, callable $labelOf): array
    {
        $total = count($commands);
        // $what either ends with its own count ("...: N" is appended otherwise).
        $this->log(preg_match('/\d/', $what)
            ? sprintf('%s, up to %d in parallel', $what, min($total, Process::defaultJobs()))
            : sprintf('%s: %d (up to %d in parallel)', $what, $total, min($total, Process::defaultJobs())));

        $results = $this->runWithProgress($commands, $labelOf);

        // Composer credentials were tried first; like Composer (which tries both), fall back to
        // Git's own authentication (SSH agent, credential helper) when they were not accepted.
        $retry = [];
        foreach ($results as $key => [$code]) {
            if ($code !== 0 && isset($commands[$key][2])) {
                $retry[$key] = [$commands[$key][0], $commands[$key][1]];
            }
        }
        if ($retry !== []) {
            $this->log(sprintf('retrying %d without Composer credentials', count($retry)));
            foreach ($this->runWithProgress($retry, $labelOf) as $key => $result) {
                if ($result[0] === 0) {
                    $results[$key] = $result;
                }
            }
        }
        return $results;
    }

    /**
     * @param array<array-key,array{0:list<string>,1:?string,2?:array<string,string>}> $commands
     * @return array<array-key,array{0:int,1:string,2:string}>
     */
    private function runWithProgress(array $commands, callable $labelOf): array
    {
        return Process::runMany($commands, null, function (string $event, $subject, ?array $result, float $seconds, int $done, int $total) use ($labelOf): void {
            if ($event === 'done') {
                [$code, , $err] = $result;
                $status = $code === 0 ? 'ok' : 'FAILED: '.strtok(trim($err) ?: 'exit '.$code, "\n");
                $this->log(sprintf('  [%d/%d] %s %s (%.1fs)', $done, $total, $labelOf($subject), $status, $seconds));
                return;
            }
            $labels = array_map($labelOf, $subject);
            $shown = implode(', ', array_slice($labels, 0, 3)).(count($labels) > 3 ? sprintf(' (+%d more)', count($labels) - 3) : '');
            $this->log(sprintf('  ... %d/%d done after %.0fs, still waiting on: %s', $done, $total, $seconds, $shown));
            if ($seconds >= 15) {
                $this->log('      no progress for a long time usually means Git/SSH is waiting for an unreachable host, a VPN, or an SSH passphrase/host-key confirmation (run `ssh -T <host>` once, or load the key into ssh-agent)');
            }
        });
    }

    /** Add a hint to Git errors caused by missing credentials. */
    private function gitError(string $err, string $fallback): string
    {
        $message = trim($err) ?: $fallback;
        if (preg_match('/terminal prompts disabled|could not read (Username|Password)|Permission denied \(publickey|Host key verification failed/i', $message)) {
            $message .= "\nHint: Fast Composer runs Git non-interactively. Make sure `git ls-remote <url>` works without prompting (SSH key in ssh-agent, known host accepted, or an HTTPS credential helper).";
        }
        return $message;
    }

    /**
     * Read many objects with one `git cat-file --batch` process.
     *
     * @param list<string> $specs
     * @return list<?array{oid:string,type:string,content:string}>
     */
    private function catFile(string $dir, array $specs): array
    {
        [$args, $cwd] = $this->git($dir, ['cat-file', '--batch']);
        [$code, $out, $err] = Process::runWithInput($args, implode("\n", $specs)."\n", $cwd);
        if ($code !== 0) {
            throw new \RuntimeException(trim($err) ?: 'git cat-file failed in '.$dir);
        }

        $result = [];
        $offset = 0;
        $length = strlen($out);
        foreach ($specs as $i => $_) {
            $eol = strpos($out, "\n", $offset);
            if ($eol === false) {
                $result[$i] = null;
                continue;
            }
            $header = substr($out, $offset, $eol - $offset);
            $offset = $eol + 1;
            if (!preg_match('/^([0-9a-f]{40,64}) (\S+) (\d+)$/', $header, $m)) {
                // "<spec> missing" / "<spec> ambiguous": no object for this spec.
                $result[$i] = null;
                continue;
            }
            $size = (int) $m[3];
            $result[$i] = ['oid' => $m[1], 'type' => $m[2], 'content' => (string) substr($out, $offset, $size)];
            $offset = min($length, $offset + $size + 1);
        }
        return $result;
    }

    private function decodeComposerJson(?array $object): ?array
    {
        if ($object === null || $object['type'] !== 'blob') {
            return null;
        }
        try {
            $data = json_decode($object['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
