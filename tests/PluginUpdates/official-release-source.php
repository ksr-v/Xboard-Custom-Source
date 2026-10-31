<?php
declare(strict_types=1);
// Pure adapter tests. Synthetic transport only; no Laravel, database, network, or installed source.
require dirname(__DIR__,2).'/app/Services/Plugin/OnlinePluginUpdater.php';
require dirname(__DIR__,2).'/app/Services/Plugin/OfficialPluginReleaseSource.php';
use App\Services\Plugin\OfficialPluginReleaseSource;

$work=sys_get_temp_dir().'/xboard-native-release-'.bin2hex(random_bytes(6));
mkdir($work,0700);
$passed=0; $sequence=0;
function verify(bool $condition,string $message='Assertion failed'):void { if(!$condition) { throw new RuntimeException($message); } }
function rejects(callable $call,string $message=''):void {
    try { $call(); } catch(Throwable $error) { verify($message==='' || str_contains($error->getMessage(),$message),'Unexpected error: '.$error->getMessage()); return; }
    throw new RuntimeException('Unsafe release was accepted');
}
function scenario(string $name,callable $test):void { global $passed; $test(); $passed++; echo "PASS $name\n"; }

final class NativeReleaseFixture {
    public const SPECS=[
        'node_endpoint'=>['ksr-v/Xboard-Independent-NodeEndpoint','NodeEndpoint-%s.zip','NodeEndpoint','NodeEndpoint','SHA256SUMS'],
        'smart_expiry'=>['ksr-v/xb-SmartExpiry','SmartExpiry-%s-full.zip','SmartExpiry','SmartExpiry',null],
        'qrcodeextend'=>['ksr-v/xb-qrcode-extend','qrcodeextend-%s.zip','Qrcodeextend','Qrcodeextend','SHA256SUMS'],
        'flowscope'=>['ksr-v/xb-flowscope','flowscope-%s.zip','Flowscope','Flowscope','SHA256SUMS-v%s'],
        'subinfo_customizer'=>['ksr-v/xb-SubInfoCustomizer','SubInfoCustomizer-v%s.zip','SubInfoCustomizer','SubinfoCustomizer','SHA256SUMS'],
    ];
    public array $release; public array $releases; public array $ref;
    public array $requests=[]; public array $bodies=[];
    public bool $moveTag=false; public bool $annotated=false;
    public int $refReads=0;
    public string $repository; public string $wrapper;
    private string $filename; private ?string $sumName; private string $base;

    public function __construct(public string $code='smart_expiry',array $entries=[]) {
        [$this->repository,$pattern,$this->wrapper,$directory,$sum]=self::SPECS[$code];
        $this->filename=sprintf($pattern,'1.2.3');
        $this->sumName=$sum===null?null:sprintf($sum,'1.2.3');
        $this->base='https://github.com/'.$this->repository.'/releases/download/v1.2.3/';
        $this->release=['id'=>9,'tag_name'=>'v1.2.3','draft'=>false,'prerelease'=>false,
            'html_url'=>'https://github.com/'.$this->repository.'/releases/tag/v1.2.3','assets'=>[]];
        $all=array_merge([
            [$this->wrapper.'/config.json',json_encode(['code'=>$code,'version'=>'1.2.3']),0100644],
            [$this->wrapper.'/Plugin.php','<?php /* synthetic plugin */',0100644],
        ],$entries);
        global $work;
        $path=tempnam($work,'zip-'); $zip=new ZipArchive();
        verify($zip->open($path,ZipArchive::OVERWRITE)===true);
        foreach($all as [$name,$bytes,$mode]) { $zip->addFromString($name,$bytes); $zip->setExternalAttributesName($name,ZipArchive::OPSYS_UNIX,$mode<<16); }
        $zip->close(); $archive=file_get_contents($path); unlink($path);
        $sha=hash('sha256',$archive);
        $this->release['assets'][]=$this->asset($this->filename,$archive,1);
        $this->bodies[$this->base.$this->filename]=$archive;
        if($this->sumName!==null) {
            $sums=$sha.'  '.$this->filename."\n";
            $this->release['assets'][]=$this->asset($this->sumName,$sums,2);
            $this->bodies[$this->base.$this->sumName]=$sums;
        }
        $this->releases=[$this->release];
        $this->ref=['ref'=>'refs/tags/v1.2.3','object'=>['type'=>'commit','sha'=>str_repeat('a',40)]];
    }
    private function asset(string $name,string $body,int $id):array {
        return ['id'=>$id,'name'=>$name,'size'=>strlen($body),'digest'=>'sha256:'.hash('sha256',$body),
            'state'=>'uploaded','browser_download_url'=>$this->base.$name];
    }
    public function sync():void { $this->releases=[$this->release]; }
    public function __invoke(string $url,int $limit,?string $destination=null):string {
        $this->requests[]=$url;
        if($url==='https://api.github.com/repos/'.$this->repository.'/releases?per_page=100&page=1') { $body=json_encode($this->releases); }
        elseif($url==='https://api.github.com/repos/'.$this->repository.'/git/ref/tags/v1.2.3') {
            $ref=$this->ref; $this->refReads++;
            if($this->annotated) { $ref['object']=['type'=>'tag','sha'=>str_repeat('b',40)]; }
            if($this->moveTag && $this->refReads>1) { $ref['object']=['type'=>'commit','sha'=>str_repeat('c',40)]; }
            $body=json_encode($ref);
        } elseif($url==='https://api.github.com/repos/'.$this->repository.'/git/tags/'.str_repeat('b',40)) {
            $body=json_encode(['sha'=>str_repeat('b',40),'object'=>['type'=>'commit','sha'=>str_repeat('a',40)]]);
        } else { $body=$this->bodies[$url]??throw new RuntimeException('Unexpected transport URL: '.$url); }
        verify(strlen($body)<=$limit,'Transport size cap');
        if($destination!==null) { file_put_contents($destination,$body); return ''; }
        return $body;
    }
}
function adapter(?NativeReleaseFixture $fixture=null):array {
    global $work,$sequence;
    $fixture??=new NativeReleaseFixture(); $cache=$work.'/cache-'.++$sequence; mkdir($cache,0700);
    return [new OfficialPluginReleaseSource($fixture,$cache),$fixture,$cache];
}
try {
    foreach(NativeReleaseFixture::SPECS as $code=>$spec) {
        scenario('fixed official source and root '.$code,function()use($code,$spec):void {
            [$source,$fixture]=adapter(new NativeReleaseFixture($code)); $package=$source->package($code);
            verify($package['directory']===$spec[3] && $package['configs'][$code]['version']==='1.2.3');
            $owned=$package['manifest'][$code];
            verify($owned['source_repository']==='https://github.com/'.$spec[0] && $owned['source_commit']===str_repeat('a',40));
            verify($owned['source_provenance']['kind']==='github-release-asset' && !isset($package['bundle_version']));
            $files=[]; $source->readArchive($package,static function($name,$bytes)use(&$files):void { $files[$name]=hash('sha256',$bytes); }); ksort($files);
            verify($files===$package['files'][$code] && count($files)===2);
            verify(!array_filter($fixture->requests,fn($url)=>str_contains($url,'Xboard-Custom-Source')));
        });
    }
    scenario('core seven are not independent sources',function():void {
        [$source,$fixture]=adapter();
        foreach(['alipay_f2f','btcpay','coinbase','coin_payments','epay','mgate','telegram'] as $code) { verify(!$source->supports($code)); rejects(fn()=>$source->package($code),'panel archive'); }
        verify($fixture->requests===[]);
    });
    scenario('annotated tag resolves to genuine commit not tag object or fake digest',function():void {
        $fixture=new NativeReleaseFixture('qrcodeextend'); $fixture->annotated=true;
        [$source]=adapter($fixture); $item=$source->package('qrcodeextend')['manifest']['qrcodeextend'];
        verify($item['source_commit']===str_repeat('a',40) && $item['source_provenance']['tag_object']===str_repeat('b',40));
    });
    scenario('tag mutation during verification is rejected',function():void { $f=new NativeReleaseFixture(); $f->moveTag=true; [$s]=adapter($f); rejects(fn()=>$s->package('smart_expiry'),'tag changed'); });
    scenario('tools draft and prerelease do not replace Node plugin stable',function():void {
        $f=new NativeReleaseFixture('node_endpoint'); $noise=[];
        foreach(['tools-v99.0.0','root-installer-v99','web-manager-v99','v99.0.0-beta.1'] as $tag) { $r=$f->release; $r['tag_name']=$tag; $noise[]=$r; }
        $r=$f->release; $r['tag_name']='v99.0.0'; $r['prerelease']=true; $noise[]=$r;
        $r['prerelease']=false; $r['draft']=true; $noise[]=$r; $f->releases=array_merge($noise,[$f->release]);
        [$s]=adapter($f); verify($s->package('node_endpoint')['version']==='1.2.3');
    });
    scenario('broken highest stable never falls back',function():void {
        $f=new NativeReleaseFixture(); $new=$f->release; $new['tag_name']='v1.2.4'; $new['html_url']='https://github.com/'.$f->repository.'/releases/tag/v1.2.4'; $new['assets']=[];
        $f->releases=[$new,$f->release]; [$s]=adapter($f); rejects(fn()=>$s->package('smart_expiry'),'unique required asset');
    });
    scenario('selected artifact stays pinned until staged',function():void {
        [$s,$f]=adapter(); $p=$s->package('smart_expiry'); $requests=count($f->requests); $f->releases=[];
        verify($s->package('smart_expiry')===$p); $s->readArchive($p,fn()=>null); verify(count($f->requests)===$requests);
    });
    foreach(['size'=>33554433,'browser_download_url'=>'https://attacker.invalid/package.zip','digest'=>'md5:invalid','state'=>'new'] as $key=>$bad) {
        scenario('asset rejects '.$key,function()use($key,$bad):void { $f=new NativeReleaseFixture(); $f->release['assets'][0][$key]=$bad; $f->sync(); [$s]=adapter($f); rejects(fn()=>$s->package('smart_expiry')); });
    }
    scenario('asset without any SHA256 source is rejected',function():void { $f=new NativeReleaseFixture(); $f->release['assets'][0]['digest']=null; $f->sync(); [$s]=adapter($f); rejects(fn()=>$s->package('smart_expiry'),'no SHA256'); });
    scenario('duplicate assets rejected',function():void { $f=new NativeReleaseFixture(); $f->release['assets'][]=$f->release['assets'][0]; $f->sync(); [$s]=adapter($f); rejects(fn()=>$s->package('smart_expiry'),'unique'); });
    scenario('checksum and API digest disagreement rejected',function():void { $f=new NativeReleaseFixture('qrcodeextend'); $f->release['assets'][0]['digest']='sha256:'.str_repeat('0',64); $f->sync(); [$s]=adapter($f); rejects(fn()=>$s->package('qrcodeextend'),'disagree'); });
    scenario('checksum sidecar tampering rejected',function():void {
        $f=new NativeReleaseFixture('qrcodeextend'); $asset=$f->release['assets'][1]; $f->bodies[$asset['browser_download_url']]=str_repeat('x',$asset['size']);
        [$s]=adapter($f); rejects(fn()=>$s->package('qrcodeextend'),'checksum file');
    });
    scenario('failed download leaves no temporary artifact',function():void {
        $f=new NativeReleaseFixture(); $a=$f->release['assets'][0]; $f->bodies[$a['browser_download_url']]=str_repeat('x',$a['size']);
        [$s,,$cache]=adapter($f); rejects(fn()=>$s->package('smart_expiry'),'SHA256'); verify(count(scandir($cache))===2);
    });
    scenario('cached bytes reverified before stage',function():void { [$s]=adapter(); $p=$s->package('smart_expiry'); file_put_contents($p['archive'],'corrupt'); rejects(fn()=>$s->readArchive($p,fn()=>null),'SHA256'); });
    foreach([
        'traversal'=>['SmartExpiry/../escape','x',0100644], 'absolute'=>['/SmartExpiry/escape','x',0100644],
        'backslash'=>['SmartExpiry\\escape','x',0100644], 'other root'=>['Other/escape','x',0100644],
        'symlink'=>['SmartExpiry/link','/etc/passwd',0120777], 'fifo'=>['SmartExpiry/pipe','',0010644],
        'env'=>['SmartExpiry/.ENV','x',0100644], 'trailing dot'=>['SmartExpiry/file.','x',0100644],
        'reserved path'=>['SmartExpiry/CON.txt','x',0100644], 'file as dir'=>['SmartExpiry/dir/','',0100644],
        'dir as file'=>['SmartExpiry/dir','',0040755],
        'config code'=>['SmartExpiry/config.json','{"code":"wrong","version":"1.2.3"}',0100644],
        'config version'=>['SmartExpiry/config.json','{"code":"smart_expiry","version":"1.2.2"}',0100644],
        'config JSON'=>['SmartExpiry/config.json','{',0100644],
    ] as $name=>$entry) { scenario('ZIP rejects '.$name,function()use($entry):void { [$s]=adapter(new NativeReleaseFixture('smart_expiry',[$entry])); rejects(fn()=>$s->package('smart_expiry')); }); }
    scenario('ZIP case collision rejected',function():void { [$s]=adapter(new NativeReleaseFixture('smart_expiry',[['SmartExpiry/A','x',0100644],['SmartExpiry/a','x',0100644]])); rejects(fn()=>$s->package('smart_expiry'),'case-colliding'); });
    scenario('ZIP inconsistent parent case rejected',function():void { [$s]=adapter(new NativeReleaseFixture('smart_expiry',[['SmartExpiry/Dir/a','x',0100644],['SmartExpiry/dir/b','x',0100644]])); rejects(fn()=>$s->package('smart_expiry'),'parent-directory casing'); });
    scenario('ZIP file-directory conflict rejected',function():void { [$s]=adapter(new NativeReleaseFixture('smart_expiry',[['SmartExpiry/a','x',0100644],['SmartExpiry/a/b','x',0100644]])); rejects(fn()=>$s->package('smart_expiry'),'conflicting file'); });
    scenario('ZIP expanded file limit enforced',function():void { [$s]=adapter(new NativeReleaseFixture('smart_expiry',[['SmartExpiry/big',str_repeat('x',16777217),0100644]])); rejects(fn()=>$s->package('smart_expiry'),'expanded size'); });
    echo "Completed {$passed} official plugin release source tests\n";
} finally {
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($iterator as $entry) { $entry->isDir()&&!$entry->isLink()?rmdir($entry->getPathname()):unlink($entry->getPathname()); }
    rmdir($work);
}
