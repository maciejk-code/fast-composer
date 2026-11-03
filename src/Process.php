<?php

namespace FastComposer;

final class Process
{
    /**
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    public static function run(array $args, ?string $cwd = null, bool $passthru = false): array
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Process command cannot be empty');
        }
        if (!$passthru) {
            return self::capture($args, null, $cwd);
        }

        $process = proc_open($args, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start process: '.self::display($args));
        }
        return [proc_close($process), '', ''];
    }

    /**
     * Run independent commands concurrently, at most $jobs at a time.
     *
     * Network-bound Git operations spend nearly all their time waiting on the remote, so
     * overlapping them hides latency without changing what each individual command does.
     *
     * $progress receives ('done', key, result, seconds, done, total) when a command finishes and
     * ('wait', running keys, null, seconds since start, done, total) every few seconds while
     * commands are still running, so callers can show where time goes.
     *
     * Commands run without a terminal prompt (GIT_TERMINAL_PROMPT=0): several concurrent
     * credential prompts would look like a hang, so a missing credential fails fast instead.
     *
     * @param array<array-key,array{0:list<string>,1:?string,2?:array<string,string>}> $commands [args, cwd, extra environment]
     * @return array<array-key,array{0:int,1:string,2:string}> results keyed like $commands
     */
    public static function runMany(array $commands, ?int $jobs = null, ?callable $progress = null): array
    {
        $jobs ??= self::defaultJobs();
        $queue = $commands;
        $running = [];
        $results = [];
        $total = count($commands);
        $started = microtime(true);
        $lastWait = $started;
        $env = getenv() + ['GIT_TERMINAL_PROMPT' => '0'];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $jobs) {
                $key = array_key_first($queue);
                [$args, $cwd] = $queue[$key];
                $extraEnv = $queue[$key][2] ?? [];
                unset($queue[$key]);

                $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $extraEnv + $env);
                if (!is_resource($process)) {
                    $results[$key] = [127, '', 'Cannot start process: '.self::display($args)];
                    continue;
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[$key] = ['process' => $process, 'pipes' => [1 => $pipes[1], 2 => $pipes[2]], 'out' => ['', '', ''], 'start' => microtime(true), 'args' => $args];
            }

            $read = [];
            foreach ($running as $job) {
                foreach ($job['pipes'] as $pipe) {
                    $read[] = $pipe;
                }
            }
            if ($read === []) {
                continue;
            }
            $write = $except = null;
            @stream_select($read, $write, $except, 1);

            foreach ($running as $key => &$job) {
                foreach ($job['pipes'] as $fd => $pipe) {
                    $chunk = stream_get_contents($pipe);
                    if (is_string($chunk) && $chunk !== '') {
                        $job['out'][$fd] .= $chunk;
                    }
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($job['pipes'][$fd]);
                    }
                }
                if ($job['pipes'] === []) {
                    $results[$key] = [proc_close($job['process']), $job['out'][1], $job['out'][2]];
                    $seconds = microtime(true) - $job['start'];
                    self::debug($job['args'], $results[$key][0], $seconds);
                    unset($running[$key]);
                    if ($progress !== null) {
                        $progress('done', $key, $results[$key], $seconds, count($results), $total);
                    }
                }
            }
            unset($job);

            $now = microtime(true);
            if ($progress !== null && $running !== [] && $now - $lastWait >= self::HEARTBEAT_SECONDS) {
                $lastWait = $now;
                $progress('wait', array_keys($running), null, $now - $started, count($results), $total);
            }
        }

        $ordered = [];
        foreach (array_keys($commands) as $key) {
            $ordered[$key] = $results[$key];
        }
        return $ordered;
    }

    /**
     * Run a command and feed $input to its stdin.
     *
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    public static function runWithInput(array $args, string $input, ?string $cwd = null): array
    {
        return self::capture($args, $input, $cwd);
    }

    /**
     * Drain both output pipes while optionally writing stdin, avoiding pipe deadlocks.
     * Null input inherits the caller's stdin; an empty string closes the child's stdin.
     *
     * @param list<string> $args
     * @return array{0:int,1:string,2:string}
     */
    private static function capture(array $args, ?string $input, ?string $cwd): array
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Process command cannot be empty');
        }

        $spec = [0 => $input === null ? STDIN : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $start = microtime(true);
        $process = proc_open($args, $spec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start process: '.self::display($args));
        }

        $stdin = $input === null ? null : $pipes[0];
        if ($stdin !== null) {
            stream_set_blocking($stdin, false);
            if ($input === '') {
                fclose($stdin);
                $stdin = null;
            }
        }
        $outputs = [1 => $pipes[1], 2 => $pipes[2]];
        $buffer = [1 => '', 2 => ''];
        foreach ($outputs as $pipe) {
            stream_set_blocking($pipe, false);
        }

        while ($outputs !== [] || $stdin !== null) {
            $read = array_values($outputs);
            $write = $stdin !== null ? [$stdin] : [];
            $except = null;
            @stream_select($read, $write, $except, 1);

            if ($stdin !== null && in_array($stdin, $write, true)) {
                $written = @fwrite($stdin, $input);
                $input = $written === false ? '' : (string) substr($input, $written);
                if ($input === '') {
                    fclose($stdin);
                    $stdin = null;
                }
            }
            foreach ($outputs as $fd => $pipe) {
                $chunk = stream_get_contents($pipe);
                if (is_string($chunk) && $chunk !== '') {
                    $buffer[$fd] .= $chunk;
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($outputs[$fd]);
                }
            }
        }

        $code = proc_close($process);
        self::debug($args, $code, microtime(true) - $start);
        return [$code, $buffer[1], $buffer[2]];
    }

    private const HEARTBEAT_SECONDS = 5;

    /** With FAST_COMPOSER_DEBUG=1, print every external command with its exit code and duration. */
    public static function debug(array $args, int $code, float $seconds): void
    {
        if (getenv('FAST_COMPOSER_DEBUG') === '1') {
            fwrite(STDERR, sprintf("[fast-composer debug] %.2fs exit=%d %s\n", $seconds, $code, self::display($args)));
        }
    }

    public static function defaultJobs(): int
    {
        $value = getenv('FAST_COMPOSER_JOBS');
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value)) {
            return (int) $value;
        }
        return 8;
    }

    /** @param list<string> $args */
    public static function must(array $args, ?string $cwd = null): string
    {
        [$code, $stdout, $stderr] = self::run($args, $cwd);
        if ($code !== 0) {
            $message = trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException($message !== '' ? $message : 'Command failed: '.self::display($args));
        }

        return $stdout;
    }

    public static function display(array $args): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => preg_match('/^[A-Za-z0-9_\-\.\/:=@]+$/', $arg) ? $arg : escapeshellarg($arg),
            $args
        ));
    }
}
