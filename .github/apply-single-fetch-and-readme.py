from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text()
    n = text.count(old)
    if n != 1:
        raise SystemExit(f"expected one match in {path}, got {n}")
    p.write_text(text.replace(old, new, 1))

# Replace ls-remote + conditional SHA fetch with one exact-ref shallow fetch.
replace_once(
    "src/Snapshot.php",
    '''        $ref = 'refs/heads/'.$branch;
        $out = Process::must(['git', 'ls-remote', $url, $ref], $this->root);
        $line = trim($out);
        if ($line === '') {
            throw new \\RuntimeException("Branch $branch not found for $package");
        }

        $sha = preg_split('/\\s+/', $line)[0];
        $version = $this->branchVersion($branch);
        $existing = $snapshot['packages'][$package][$version] ?? null;
        if (is_array($existing) && ($existing['source']['reference'] ?? null) === $sha) {
            $pkg = $existing;
        } else {
            $meta = $this->metadataAt($url, $sha);
            if (($meta['name'] ?? null) !== $package) {
                throw new \\RuntimeException("Repository package name mismatch: expected $package");
            }
            $pkg = $this->packageFromMetadata($meta, $package, $version, $url, $sha);
            $snapshot['packages'][$package][$version] = $pkg;
        }
''',
    '''        $ref = 'refs/heads/'.$branch;
        [$sha, $meta] = $this->composerAtRef($url, $ref, "Branch $branch not found for $package");
        $version = $this->branchVersion($branch);
        $existing = $snapshot['packages'][$package][$version] ?? null;
        if (is_array($existing) && ($existing['source']['reference'] ?? null) === $sha) {
            $pkg = $existing;
        } else {
            if (($meta['name'] ?? null) !== $package) {
                throw new \\RuntimeException("Repository package name mismatch: expected $package");
            }
            $pkg = $this->packageFromMetadata($meta, $package, $version, $url, $sha);
            $snapshot['packages'][$package][$version] = $pkg;
        }
'''
)

replace_once(
    "src/Snapshot.php",
    '''    /** @param list<string> $shas */
    private function composerManyAt(string $url, array $shas): void
''',
    '''    /** @return array{0:string,1:array} */
    private function composerAtRef(string $url, string $ref, string $notFoundMessage): array
    {
        $this->ensureDir();
        $tmp = $this->dir().'/fetch-ref-'.bin2hex(random_bytes(5));
        if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
            throw new \\RuntimeException("Cannot create temporary directory $tmp");
        }

        try {
            Process::must(['git', 'init', '-q'], $tmp);
            Process::must(['git', 'remote', 'add', 'origin', $url], $tmp);
            [$code, $out, $err] = Process::run(['git', 'fetch', '-q', '--depth=1', '--no-tags', 'origin', $ref], $tmp);
            if ($code !== 0) {
                throw new \\RuntimeException($notFoundMessage.($err !== '' ? ': '.trim($err) : ''));
            }

            $sha = trim(Process::must(['git', 'rev-parse', 'FETCH_HEAD'], $tmp));
            if ($sha === '') {
                throw new \\RuntimeException($notFoundMessage);
            }
            $json = Process::must(['git', 'show', $sha.':composer.json'], $tmp);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \\RuntimeException('Invalid composer.json at '.$sha);
            }
            $this->operationMetadata[$this->metadataKey($url, $sha)] = $data;
            return [$sha, $data];
        } finally {
            $this->rrmdir($tmp);
        }
    }

    /** @param list<string> $shas */
    private function composerManyAt(string $url, array $shas): void
'''
)

# Update benchmark section with the latest optimized measurements and the before/after call-count story.
p = Path('README.md')
text = p.read_text()
start = text.index('## Benchmarks (v0.1)')
end = text.index('## What gets refreshed', start)
section = '''## Benchmarks (v0.1)

The benchmark harness is committed with the project. Results below are medians of 3 runs on a GitHub Actions `ubuntu-24.04` runner using PHP 8.4.25, Composer 2.10.3 and Git 2.55.0. All measured update paths use `--no-install --no-plugins --no-scripts --no-audit`.

### Zero-latency local VCS control

This fixture uses 24 local filesystem VCS repositories with 8 additional unused branches per repository, so VCS/network latency is effectively absent.

| Scenario | Composer | Fast Composer | Composer / Fast |
| --- | ---: | ---: | ---: |
| First Fast Composer invocation / cold snapshot | — | 0.77 s | — |
| Warm targeted no-op update | 0.64 s | 0.71 s | 0.90x |
| Targeted discovery of a new tag | 0.64 s | 0.72 s | 0.90x |

### Remote-like VCS latency control

The same shape is served through a local `git daemon`, with Linux `netem` injecting 15 ms of loopback delay. This is a controlled latency simulation, not a claim that it reproduces GitHub/SSH/private-network behavior exactly.

| Scenario | Composer | Fast Composer | Composer / Fast |
| --- | ---: | ---: | ---: |
| First Fast Composer invocation / cold snapshot | — | 6.65 s | — |
| Warm targeted no-op update | 1.19 s | 1.20 s | 0.99x |
| Targeted discovery of a new tag | 1.29 s | 1.45 s | 0.89x |

### Private-VCS-like workload

This fixture contains 40 VCS repositories, 20 root-required packages and 10 additional branches per repository. A Git shim adds 40 ms to network-like Git operations and counts those operations. This models the cost shape of many private VCS repositories without claiming to reproduce a particular GitHub/SSH deployment exactly.

| Scenario | Composer | Fast Composer | Composer Git ops | Fast Git ops |
| --- | ---: | ---: | ---: | ---: |
| Targeted no-op update | 0.87 s | 0.94 s | 1 | 2 |
| Broad no-op update | 5.29 s | **3.48 s** | 20 | 21 |
| Moved explicit `dev-*` branch | 0.92 s | 0.93 s | 1 | 2 |

Both implementations selected the moved development branch correctly in all 3/3 runs.

Before the targeted-refresh optimization, the same workload shape required 14 Git network operations for a Fast Composer targeted no-op and 15 for a moved development branch. The optimized path reduced those counts to 2 while keeping exact-SHA source validation. Timings from separate hosted runners should not be treated as laboratory-grade before/after measurements; the call-count reduction is the stronger deterministic signal.

**Current result:** targeted development-branch updates are now approximately at Composer parity in these synthetic latency controls, while the broad private-VCS-like update is about **1.52x faster** (5.29 s / 3.48 s). Stable new-tag discovery is still modestly slower than standard Composer in these fixtures. A general performance claim should still be validated on the real private-SSH/VCS workload that motivated the project.

Reproduce the measurements with:

```bash
bash benchmarks/run.sh
bash benchmarks/remote-latency.sh
bash benchmarks/private-vcs-like.sh
```

The remote-latency benchmark requires Linux `tc`/`netem` and permission to change the loopback qdisc. The synthetic Git fixtures use isolated temporary Composer homes and local Git daemons only.

'''
p.write_text(text[:start] + section + text[end:])

print('single-fetch optimization and README patch applied')
