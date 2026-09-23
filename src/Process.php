<?php
namespace FastComposer;

final class Process
{
    /** @return array{0:int,1:string,2:string} */
    public static function run(array $args, ?string $cwd = null, bool $passthru = false): array
    {
        if ($args === []) {
            throw new \InvalidArgumentException('Process command cannot be empty');
        }

        if ($passthru) {
            $spec = [0 => STDIN, 1 => STDOUT, 2 => STDERR];
            $process = proc_open($args, $spec, $pipes, $cwd);
            if (!is_resource($process)) {
                throw new \RuntimeException('Cannot start process: '.self::display($args));
            }

            return [proc_close($process), '', ''];
        }

        $spec = [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($args, $spec, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start process: '.self::display($args));
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout === false ? '' : $stdout, $stderr === false ? '' : $stderr];
    }

    /**
     * Run independent commands concurrently, at most $jobs at a time.
     *
     * Network-bound Git operations spend nearly all their time waiting on the remote, so
     * overlapping them hides latency without changing what each individual command does.
     *
     * @param array<array-key,array{0:list<string>,1:?string}> $commands
     * @return array<array-key,array{0:int,1:string,2:string}> results keyed like $commands
     */
    public static function runMany(array $commands, ?int $jobs = null): array
    {
        $jobs ??= self::defaultJobs();
        $queue = $commands;
        $running = [];
        $results = [];

        while ($queue !== [] || $running !== []) {
            while ($queue !== [] && count($running) < $jobs) {
                $key = array_key_first($queue);
                [$args, $cwd] = $queue[$key];
                unset($queue[$key]);

                $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
                if (!is_resource($process)) {
                    $results[$key] = [127, '', 'Cannot start process: '.self::display($args)];
                    continue;
                }
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $running[$key] = ['process' => $process, 'pipes' => [1 => $pipes[1], 2 => $pipes[2]], 'out' => ['', '', '']];
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
                    unset($running[$key]);
                }
            }
            unset($job);
        }

        $ordered = [];
        foreach (array_keys($commands) as $key) {
            $ordered[$key] = $results[$key];
        }
        return $ordered;
    }

    /** Run a command and feed $input to its stdin. */
    public static function runWithInput(array $args, string $input, ?string $cwd = null): array
    {
        $process = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start process: '.self::display($args));
        }

        // Write stdin while draining stdout/stderr so large outputs cannot deadlock the pipes.
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        $stdin = $input === '' ? null : $pipes[0];
        if ($stdin === null) {
            fclose($pipes[0]);
        }

        while ($open !== [] || $stdin !== null) {
            $read = array_values($open);
            $write = $stdin !== null ? [$stdin] : null;
            $except = null;
            @stream_select($read, $write, $except, 1);

            if ($stdin !== null && $write) {
                $written = fwrite($stdin, $input);
                if ($written === false) {
                    $input = '';
                } else {
                    $input = (string) substr($input, $written);
                }
                if ($input === '') {
                    fclose($stdin);
                    $stdin = null;
                }
            }
            foreach ($open as $fd => $pipe) {
                $chunk = stream_get_contents($pipe);
                if (is_string($chunk) && $chunk !== '') {
                    $out[$fd] .= $chunk;
                }
                if (feof($pipe)) {
                    fclose($pipe);
                    unset($open[$fd]);
                }
            }
        }

        return [proc_close($process), $out[1], $out[2]];
    }

    public static function defaultJobs(): int
    {
        $value = getenv('FAST_COMPOSER_JOBS');
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value)) {
            return (int) $value;
        }
        return 8;
    }

    public static function must(array $args, ?string $cwd = null): string
    {
        [$code, $stdout, $stderr] = self::run($args, $cwd);
        if ($code !== 0) {
            $message = trim($stderr !== '' ? $stderr : $stdout);
            throw new \RuntimeException($message !== '' ? $message : 'Command failed: '.self::display($args));
        }

        return $stdout;
    }

    private static function display(array $args): string
    {
        return implode(' ', array_map(
            static fn (string $arg): string => preg_match('/^[A-Za-z0-9_\-\.\/:=@]+$/', $arg) ? $arg : escapeshellarg($arg),
            $args
        ));
    }
}
