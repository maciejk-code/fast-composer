<?php
namespace FastComposer;
final class Application {
    public function run(array $args): int {
        $root=getcwd(); if(!is_file($root.'/composer.json')){fwrite(STDERR,"composer.json not found\n");return 2;}
        $cmd=$args[0]??'help'; if(in_array($cmd,['help','--help','-h'],true)){$this->help();return 0;}
        $rootCfg=json_decode(file_get_contents($root.'/composer.json'),true); if(!is_array($rootCfg)){fwrite(STDERR,"Invalid composer.json\n");return 2;} $snap=new Snapshot($root);
        try {
            if($cmd==='verify'){ $bad=0; foreach($snap->verifyLock() as $name=>$r){printf("%s %s %s\n",$r['reachable']?'OK':'MISSING',$name,substr($r['sha'],0,12)); if(!$r['reachable'])$bad++;} return $bad?1:0; }
            if($cmd==='refresh'){ $s=$snap->buildFromLockAndCache($rootCfg); printf("snapshot repos=%d packages=%d\n",count($s['repos']),array_sum(array_map('count',$s['packages']))); return 0; }
            if(!in_array($cmd,['update','require','install'],true)){fwrite(STDERR,"Unsupported command: $cmd\n");return 2;}
            $s=$snap->load(); if(!$s){fwrite(STDOUT,"[fast-composer] no snapshot; priming with regular Composer\n"); [$code]=Process::run(array_merge(['composer'],$args),$root,true); if($code!==0)return $code; $snap->buildFromLockAndCache(json_decode(file_get_contents($root.'/composer.json'),true)); return 0;}
            if($cmd==='require') foreach(array_slice($args,1) as $spec){if(str_starts_with($spec,'-')||!str_contains($spec,':'))continue; [$name,$constraint]=explode(':',$spec,2); if(str_starts_with($constraint,'dev-'))$snap->ensureBranch($s,$name,substr($constraint,4));}
            $fast=$snap->writeFastComposer($rootCfg,$s); $fastLock=$root.'/.fast-composer.lock'; if(is_file($root.'/composer.lock'))copy($root.'/composer.lock',$fastLock); elseif(is_file($fastLock))unlink($fastLock);
            $env=getenv('COMPOSER'); putenv('COMPOSER='.basename($fast)); [$code]=Process::run(array_merge(['composer'],$args),$root,true); $env===false?putenv('COMPOSER'):putenv('COMPOSER='.$env); if($code!==0)return $code;
            if(is_file($fastLock))copy($fastLock,$root.'/composer.lock');
            if($cmd==='require'){ $new=json_decode(file_get_contents($fast),true); foreach(['require','require-dev'] as $k)if(isset($new[$k]))$rootCfg[$k]=$new[$k]; file_put_contents($root.'/composer.json',json_encode($rootCfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n"); }
            $snap->buildFromLockAndCache($rootCfg); return 0;
        } catch(\Throwable $e){fwrite(STDERR,"[fast-composer] ".$e->getMessage()."\n"); return 1;}
    }
    private function help(): void {echo "fast-composer MVP\n\nCommands:\n  fast-composer update [args...]\n  fast-composer require vendor/package:constraint [args...]\n  fast-composer install [args...]\n  fast-composer refresh\n  fast-composer verify\n\nThe first run may delegate to Composer to prime its cache. Later runs replace VCS discovery with a local snapshot. Explicit dev-* requirements refresh only the mapped repository.\n";}
}
