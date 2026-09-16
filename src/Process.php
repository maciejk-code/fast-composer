<?php
namespace FastComposer;
final class Process {
    public static function run(array $args, ?string $cwd=null, bool $passthru=false): array {
        $cmd=implode(' ',array_map('escapeshellarg',$args));
        if($passthru){
            $spec=[0=>STDIN,1=>STDOUT,2=>STDERR];
            $p=proc_open($cmd,$spec,$pipes,$cwd);
            if(!is_resource($p)) throw new \RuntimeException('Cannot start process');
            $code=proc_close($p);
            return [$code,'',''];
        }
        $spec=[0=>STDIN,1=>['pipe','w'],2=>['pipe','w']];
        $p=proc_open($cmd,$spec,$pipes,$cwd);
        if(!is_resource($p)) throw new \RuntimeException('Cannot start process');
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($p);
        return [$code,$out,$err];
    }
    public static function must(array $args, ?string $cwd=null): string {
        [$c,$o,$e]=self::run($args,$cwd); if($c!==0) throw new \RuntimeException(trim($e?:$o)); return $o;
    }
}
