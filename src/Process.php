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
