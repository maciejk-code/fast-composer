<?php
require __DIR__.'/../vendor/autoload.php';
use FastComposer\Snapshot;
$dir=sys_get_temp_dir().'/fast-composer-test-'.bin2hex(random_bytes(4));mkdir($dir);file_put_contents($dir.'/composer.lock',json_encode(['packages'=>[['name'=>'acme/a','version'=>'1.2.3','source'=>['type'=>'git','url'=>'https://github.com/acme/a.git','reference'=>str_repeat('a',40)],'require'=>['php'=>'>=8.2']]]],JSON_PRETTY_PRINT));
$s=new Snapshot($dir);$m=$s->buildFromLockAndCache(['repositories'=>[['type'=>'vcs','url'=>'https://github.com/acme/a.git']]]);if(($m['repos']['https://github.com/acme/a.git']['name']??null)!=='acme/a')throw new RuntimeException('repo mapping failed');if(($m['packages']['acme/a']['1.2.3']['source']['reference']??null)!==str_repeat('a',40))throw new RuntimeException('lock package snapshot failed');echo "OK\n";
