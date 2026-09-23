from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"expected exactly one match in {path}, got {count}")
    p.write_text(text.replace(old, new, 1))


# 1) Route targeted updates pinned to an explicit dev branch through ensureBranch(),
# avoiding exhaustive hydration of every branch/tag in that repository.
replace_once(
    "src/Application.php",
    '''                } else {
                    $count = $snapshot->refreshPackages($state, $targets);
                    if ($count > 0) {
                        printf("[fast-composer] refreshed %d targeted VCS repositories\\n", $count);
                    }
                }
''',
    '''                } else {
                    $count = $this->refreshTargetedUpdates($snapshot, $state, $rootCfg, $targets);
                    if ($count > 0) {
                        printf("[fast-composer] refreshed %d targeted VCS repositories\\n", $count);
                    }
                }
'''
)

replace_once(
    "src/Application.php",
    '''    private function refreshExplicitDevRequirements(Snapshot $snapshot, array &$state, array $rootCfg): int
''',
    '''    /** @param list<string> $targets */
    private function refreshTargetedUpdates(Snapshot $snapshot, array &$state, array $rootCfg, array $targets): int
    {
        $count = 0;
        foreach ($targets as $spec) {
            [$name, $temporaryConstraint] = array_pad(explode(':', $spec, 2), 2, null);
            if ($this->isManagedPackage($state, $name)) {
                $constraint = is_string($temporaryConstraint) && $temporaryConstraint !== ''
                    ? $temporaryConstraint
                    : $this->rootConstraintForPackage($rootCfg, $name);
                $branch = is_string($constraint) ? $this->explicitDevBranch($constraint) : null;
                if ($branch !== null) {
                    $snapshot->ensureBranch($state, $name, $branch);
                    $count++;
                    continue;
                }
            }

            // Preserve wildcard and non-dev targeted update behavior.
            $count += $snapshot->refreshPackages($state, [$spec]);
        }
        return $count;
    }

    private function rootConstraintForPackage(array $rootCfg, string $package): ?string
    {
        foreach (['require', 'require-dev'] as $section) {
            $constraint = $rootCfg[$section][$package] ?? null;
            if (is_string($constraint)) {
                return $constraint;
            }
        }
        return null;
    }

    private function refreshExplicitDevRequirements(Snapshot $snapshot, array &$state, array $rootCfg): int
'''
)

# 2) Keep metadata fetched from source during one invocation so the mandatory
# exact-SHA validation can reuse it without a second network fetch. The cache is
# intentionally in-memory only: verify in a new process still checks reachability.
replace_once(
    "src/Snapshot.php",
    '''    private string $root;
    private string $cacheDir;
    private string $workStem;
''',
    '''    private string $root;
    private string $cacheDir;
    private string $workStem;
    /** @var array<string,array> Exact-SHA source metadata fetched during this process only. */
    private array $operationMetadata = [];
'''
)

# 3) Batch missing ref metadata into shallow fetches (64 SHA tips per fetch)
# instead of creating/fetching one temporary repository per ref.
replace_once(
    "src/Snapshot.php",
    '''    private function hydrateRepository(array &$snapshot, string $url, string $package): void
    {
        $remote = $this->remoteVersions($url);
        $existing = $snapshot['packages'][$package] ?? [];
        $next = [];

        foreach ($remote['versions'] as $version => $ref) {
            $current = $existing[$version] ?? null;
            if (is_array($current) && ($current['source']['reference'] ?? null) === $ref['sha']) {
                $next[$version] = $current;
                continue;
            }

            $meta = $this->metadataAt($url, $ref['sha']);
            if (($meta['name'] ?? null) !== $package) {
                throw new \\RuntimeException("Repository package name mismatch for $url: expected $package");
            }
            $next[$version] = $this->packageFromMetadata($meta, $package, $version, $url, $ref['sha']);
        }

        if ($next === [] && $existing !== []) {
            $next = $existing;
        }

        $snapshot['packages'][$package] = $next;
        $snapshot['repos'][$url]['name'] = $package;
        $snapshot['repos'][$url]['refs'] = $remote['refs'];
        $snapshot['repos'][$url]['checked_at'] = time();
        unset($snapshot['repos'][$url]['last_error']);
    }
''',
    '''    private function hydrateRepository(array &$snapshot, string $url, string $package): void
    {
        $remote = $this->remoteVersions($url);
        $existing = $snapshot['packages'][$package] ?? [];
        $next = [];
        $pending = [];
        $missingShas = [];

        foreach ($remote['versions'] as $version => $ref) {
            $current = $existing[$version] ?? null;
            if (is_array($current) && ($current['source']['reference'] ?? null) === $ref['sha']) {
                $next[$version] = $current;
                continue;
            }

            $cached = $this->composerCacheMetadata($url, $ref['sha']);
            if (is_array($cached)) {
                if (($cached['name'] ?? null) !== $package) {
                    throw new \\RuntimeException("Repository package name mismatch for $url: expected $package");
                }
                $next[$version] = $this->packageFromMetadata($cached, $package, $version, $url, $ref['sha']);
                continue;
            }

            $key = $this->metadataKey($url, $ref['sha']);
            if (isset($this->operationMetadata[$key])) {
                $meta = $this->operationMetadata[$key];
                if (($meta['name'] ?? null) !== $package) {
                    throw new \\RuntimeException("Repository package name mismatch for $url: expected $package");
                }
                $next[$version] = $this->packageFromMetadata($meta, $package, $version, $url, $ref['sha']);
                continue;
            }

            $pending[$version] = $ref;
            $missingShas[$ref['sha']] = true;
        }

        if ($missingShas !== []) {
            $this->composerManyAt($url, array_keys($missingShas));
            foreach ($pending as $version => $ref) {
                $meta = $this->operationMetadata[$this->metadataKey($url, $ref['sha'])] ?? null;
                if (!is_array($meta) || ($meta['name'] ?? null) !== $package) {
                    throw new \\RuntimeException("Repository package name mismatch for $url: expected $package");
                }
                $next[$version] = $this->packageFromMetadata($meta, $package, $version, $url, $ref['sha']);
            }
        }

        if ($next === [] && $existing !== []) {
            $next = $existing;
        }

        $snapshot['packages'][$package] = $next;
        $snapshot['repos'][$url]['name'] = $package;
        $snapshot['repos'][$url]['refs'] = $remote['refs'];
        $snapshot['repos'][$url]['checked_at'] = time();
        unset($snapshot['repos'][$url]['last_error']);
    }
'''
)

replace_once(
    "src/Snapshot.php",
    '''    private function metadataAt(string $url, string $sha): array
    {
        return $this->composerCacheMetadata($url, $sha) ?? $this->composerAt($url, $sha);
    }
''',
    '''    private function metadataAt(string $url, string $sha): array
    {
        $key = $this->metadataKey($url, $sha);
        return $this->operationMetadata[$key]
            ?? $this->composerCacheMetadata($url, $sha)
            ?? $this->composerAt($url, $sha);
    }

    private function metadataKey(string $url, string $sha): string
    {
        return hash('sha256', $this->normalizeGitUrl($url)).':'.$sha;
    }
'''
)

replace_once(
    "src/Snapshot.php",
    '''    private function composerAt(string $url, string $sha): array
    {
        $this->ensureDir();
        $tmp = $this->dir().'/fetch-'.bin2hex(random_bytes(5));
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \\RuntimeException("Cannot create temporary directory $tmp");
        }

        try {
            Process::must(['git', 'init', '-q'], $tmp);
            Process::must(['git', 'remote', 'add', 'origin', $url], $tmp);
            Process::must(['git', 'fetch', '-q', '--depth=1', 'origin', $sha], $tmp);
            $json = Process::must(['git', 'show', $sha.':composer.json'], $tmp);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \\RuntimeException('Invalid composer.json at '.$sha);
            }
            return $data;
        } finally {
            $this->rrmdir($tmp);
        }
    }
''',
    '''    private function composerAt(string $url, string $sha): array
    {
        $key = $this->metadataKey($url, $sha);
        if (isset($this->operationMetadata[$key])) {
            return $this->operationMetadata[$key];
        }

        $this->composerManyAt($url, [$sha]);
        $data = $this->operationMetadata[$key] ?? null;
        if (!is_array($data)) {
            throw new \\RuntimeException('Invalid composer.json at '.$sha);
        }
        return $data;
    }

    /** @param list<string> $shas */
    private function composerManyAt(string $url, array $shas): void
    {
        $shas = array_values(array_unique(array_filter($shas, static fn ($sha): bool => is_string($sha) && $sha !== '')));
        if ($shas === []) {
            return;
        }

        $missing = [];
        foreach ($shas as $sha) {
            if (!isset($this->operationMetadata[$this->metadataKey($url, $sha)])) {
                $missing[] = $sha;
            }
        }
        if ($missing === []) {
            return;
        }

        $this->ensureDir();
        $tmp = $this->dir().'/fetch-'.bin2hex(random_bytes(5));
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \\RuntimeException("Cannot create temporary directory $tmp");
        }

        try {
            Process::must(['git', 'init', '-q'], $tmp);
            Process::must(['git', 'remote', 'add', 'origin', $url], $tmp);

            // Keep command lines bounded while amortizing SSH/TLS setup across many ref tips.
            foreach (array_chunk($missing, 64) as $chunk) {
                Process::must(array_merge(['git', 'fetch', '-q', '--depth=1', '--no-tags', 'origin'], $chunk), $tmp);
            }

            foreach ($missing as $sha) {
                $json = Process::must(['git', 'show', $sha.':composer.json'], $tmp);
                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($data)) {
                    throw new \\RuntimeException('Invalid composer.json at '.$sha);
                }
                $this->operationMetadata[$this->metadataKey($url, $sha)] = $data;
            }
        } finally {
            $this->rrmdir($tmp);
        }
    }
'''
)

# 4) Add a targeted dev-branch regression after the existing broad dev refresh.
replace_once(
    "tests/runtime-contract.sh",
    '''assert_clean_root "$ROOT_DEV"
echo "mutable-dev-branch: PASS"

if ! find "$FAST_COMPOSER_CACHE_DIR/projects" -name snapshot.json -type f | grep -q .; then
''',
    '''assert_clean_root "$ROOT_DEV"
echo "mutable-dev-branch: PASS"

# Targeting that same explicit dev requirement must use the exact branch path,
# not hydrate every unrelated branch/tag in the repository.
cat > "$FIX/composer.json" <<'JSON'
{
  "name": "acme/runtime-fixture",
  "type": "library",
  "require": {"php": ">=8.2"},
  "autoload": {"psr-4": {"Acme\\\\Runtime\\\\": "src-v3/"}},
  "extra": {"marker": "feature-3"}
}
JSON
git -C "$FIX" add composer.json
git -C "$FIX" commit -q -m 'feature 3'
TARGET_DEV_SHA="$(git -C "$FIX" rev-parse HEAD)"

FAST_COMPOSER_TTL=99999 "${FC[@]}" update acme/runtime-fixture --no-interaction --no-plugins --no-scripts --no-audit -q >/dev/null
php -r '\n$l=json_decode(file_get_contents("composer.lock"),true,512,JSON_THROW_ON_ERROR);\n$p=null;foreach($l["packages"] as $row){if($row["name"]==="acme/runtime-fixture"){$p=$row;break;}}\nif(!$p)throw new RuntimeException("fixture missing");\nif(($p["source"]["reference"]??null)!==$argv[1])throw new RuntimeException("targeted dev branch SHA was not refreshed");\nif(($p["extra"]["marker"]??null)!=="feature-3")throw new RuntimeException("targeted dev branch metadata was not refreshed");\nif(($p["autoload"]["psr-4"]["Acme\\\\Runtime\\\\"]??null)!=="src-v3/")throw new RuntimeException("targeted dev branch autoload metadata was not refreshed");\n' "$TARGET_DEV_SHA"
assert_clean_root "$ROOT_DEV"
echo "targeted-mutable-dev-branch: PASS"

if ! find "$FAST_COMPOSER_CACHE_DIR/projects" -name snapshot.json -type f | grep -q .; then
'''
)

print("patch applied")
