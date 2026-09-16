<?php
namespace FastComposer;
final class Snapshot {
    public const DIR='.fast-composer';
    private const KEEP=['name','description','type','keywords','homepage','license','authors','support','funding','require','require-dev','conflict','replace','provide','suggest','autoload','include-path','target-dir','bin','extra'];
    public function __construct(private string $root){}
    public function dir(): string {return $this->root.'/'.self::DIR;}
    public function load(): array { $p=$this->dir().'/snapshot.json'; return is_file($p)?(json_decode(file_get_contents($p),true)?:[]):[]; }
    public function save(array $s): void {if(!is_dir($this->dir())) mkdir($this->dir(),0777,true); file_put_contents($this->dir().'/snapshot.json',json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");}
    public function buildFromLockAndCache(array $rootConfig): array {
        $snapshot=['generated_at'=>time(),'repos'=>[],'packages'=>[]];
        $lock=$this->readJson($this->root.'/composer.lock',[]);
        foreach(($rootConfig['repositories']??[]) as $r){if(is_array($r)&&($r['type']??null)==='vcs'&&is_string($r['url']??null)){$snapshot['repos'][$r['url']]=['url'=>$r['url']];}}
        foreach(array_merge($lock['packages']??[],$lock['packages-dev']??[]) as $p){$src=$p['source']??[]; if(($src['type']??null)!=='git'||empty($src['url'])||empty($p['name'])) continue; $snapshot['repos'][$src['url']]['name']=$p['name']; $snapshot['packages'][$p['name']][$p['version']]=$this->cleanPackage($p);}
        foreach(array_keys($snapshot['repos']) as $url){$this->addCachedTags($snapshot,$url);} $this->save($snapshot); return $snapshot;
    }
    public function ensureBranch(array &$snapshot,string $package,string $branch): array {
        $url=$this->urlForPackage($snapshot,$package); if(!$url) throw new \RuntimeException("No VCS repository mapping for $package. Run a normal Composer update once, then fast-composer refresh.");
        $ref='refs/heads/'.$branch; $out=Process::must(['git','ls-remote',$url,$ref],$this->root); $line=trim($out); if($line==='') throw new \RuntimeException("Branch $branch not found for $package"); $sha=preg_split('/\s+/',$line)[0];
        $meta=$this->composerAt($url,$sha); if(($meta['name']??null)!==$package) throw new \RuntimeException("Repository package name mismatch: expected $package");
        $pkg=$this->cleanPackage($meta); $pkg['name']=$package; $pkg['version']='dev-'.$branch; $pkg['source']=['type'=>'git','url'=>$url,'reference'=>$sha];
        $snapshot['packages'][$package][$pkg['version']]=$pkg; $snapshot['repos'][$url]['name']=$package; $snapshot['repos'][$url]['branches'][$branch]=$sha; $this->save($snapshot); return $pkg;
    }
    public function writeFastComposer(array $rootConfig,array $snapshot): string {
        if(!is_dir($this->dir())) mkdir($this->dir(),0777,true); $packages=[]; foreach($snapshot['packages']??[] as $versions) foreach($versions as $p) $packages[]=$p;
        file_put_contents($this->dir().'/packages.json',json_encode(['packages'=>$this->groupPackages($packages)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        $cfg=$rootConfig; $other=[]; foreach(($cfg['repositories']??[]) as $r){if(!(is_array($r)&&($r['type']??null)==='vcs'))$other[]=$r;} $cfg['repositories']=array_merge([['type'=>'composer','url'=>$this->dir()]],$other);
        $path=$this->root.'/.fast-composer.json'; file_put_contents($path,json_encode($cfg,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n"); return $path;
    }
    public function verifyLock(): array {
        $lock=$this->readJson($this->root.'/composer.lock',[]); $results=[];
        foreach(array_merge($lock['packages']??[],$lock['packages-dev']??[]) as $p){$s=$p['source']??[]; if(($s['type']??null)!=='git'||empty($s['url'])||empty($s['reference'])) continue; $tmp=$this->dir().'/verify-'.bin2hex(random_bytes(4)); if(!is_dir($this->dir())) mkdir($this->dir(),0777,true); mkdir($tmp); Process::run(['git','init','-q'],$tmp); Process::run(['git','remote','add','origin',$s['url']],$tmp); [$c]=Process::run(['git','fetch','-q','--depth=1','origin',$s['reference']],$tmp); $results[$p['name']]=['sha'=>$s['reference'],'reachable'=>$c===0]; $this->rrmdir($tmp); }
        return $results;
    }
    private function addCachedTags(array &$snapshot,string $url): void {
        if(!preg_match('~github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?$~',$url,$m)) return; $owner=$m[1];$repo=preg_replace('/\.git$/','',$m[2]); [$c,$out]=Process::run(['git','ls-remote','--tags',$url],$this->root); if($c!==0)return;
        $tags=[];$peeled=[]; foreach(explode("\n",trim($out)) as $line){if(!$line)continue;[$sha,$ref]=preg_split('/\s+/',$line,2);$tag=substr($ref,10);if(str_ends_with($tag,'^{}'))$peeled[substr($tag,0,-3)]=$sha;else$tags[$tag]=$sha;} $tags=array_replace($tags,$peeled);
        $cache=$this->composerCacheRepoDir(); foreach($tags as $tag=>$sha){$version=preg_replace('/^v/','',$tag); if(!preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/',$version))continue; $paths=["$cache/github.com/".strtolower($owner)."/$repo/$sha","$cache/github.com/$owner/$repo/$sha"]; $meta=null; foreach($paths as $p)if(is_file($p)){ $meta=json_decode(file_get_contents($p),true); if(is_array($meta))break;} if(!$meta)continue; $name=$snapshot['repos'][$url]['name']??($meta['name']??null); if(!$name)continue; $pkg=$this->cleanPackage($meta);$pkg['name']=$name;$pkg['version']=$version;$pkg['source']=['type'=>'git','url'=>$url,'reference'=>$sha];$snapshot['packages'][$name][$version]=$pkg; }
    }
    private function composerCacheRepoDir(): string {[$c,$o]=Process::run(['composer','config','cache-repo-dir','--absolute'],$this->root); return $c===0?trim($o):(getenv('COMPOSER_CACHE_DIR')?:getenv('HOME').'/.cache/composer').'/repo';}
    private function composerAt(string $url,string $sha): array {$tmp=$this->dir().'/fetch-'.bin2hex(random_bytes(4)); if(!is_dir($this->dir()))mkdir($this->dir(),0777,true);mkdir($tmp); Process::must(['git','init','-q'],$tmp);Process::must(['git','remote','add','origin',$url],$tmp);Process::must(['git','fetch','-q','--depth=1','origin',$sha],$tmp);$json=Process::must(['git','show',$sha.':composer.json'],$tmp);$this->rrmdir($tmp);$d=json_decode($json,true);if(!is_array($d))throw new \RuntimeException('Invalid composer.json at '.$sha);return $d;}
    private function urlForPackage(array $s,string $name): ?string {foreach($s['repos']??[] as $url=>$r)if(($r['name']??null)===$name)return $url;return null;}
    private function cleanPackage(array $p): array {$r=[];foreach(self::KEEP as $k)if(array_key_exists($k,$p))$r[$k]=$p[$k];foreach(['name','version','source','dist'] as $k)if(array_key_exists($k,$p))$r[$k]=$p[$k];return $r;}
    private function groupPackages(array $ps): array {$g=[];foreach($ps as $p)if(isset($p['name'],$p['version']))$g[$p['name']][$p['version']]=$p;return $g;}
    private function readJson(string $p,array $default): array {if(!is_file($p))return $default;$d=json_decode(file_get_contents($p),true);if(!is_array($d))throw new \RuntimeException("Invalid JSON: $p");return $d;}
    private function rrmdir(string $d): void {if(!is_dir($d))return;foreach(scandir($d) as $f){if($f==='.'||$f==='..')continue;$p="$d/$f";is_dir($p)?$this->rrmdir($p):@unlink($p);}@rmdir($d);}
}
