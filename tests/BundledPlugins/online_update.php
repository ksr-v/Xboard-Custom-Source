<?php
declare(strict_types=1);
$GLOBALS['__composer_autoload_files']['a03e85e827f2f53ca6b173cf8d947ac6'] = true;
require __DIR__ . '/bootstrap.php';

use App\Models\Plugin;
use App\Services\Plugin\{OnlinePluginUpdater, PluginManager};
use Illuminate\Support\Facades\{Cache, Http};

function tarMember(string $name, string $data, string $type = '0'): string {
    $header = str_repeat("\0", 512);
    foreach ([[0,100,$name],[100,8,"0000644\0"],[108,8,"0000000\0"],[116,8,"0000000\0"],
        [124,12,sprintf('%011o',strlen($data))."\0"],[136,12,"00000000000\0"],[148,8,'        '],
        [156,1,$type],[257,6,"ustar\0"],[263,2,'00']] as [$offset,$length,$value]) {
        $header = substr_replace($header, str_pad($value, $length, "\0"), $offset, $length);
    }
    $header = substr_replace($header, sprintf('%06o',array_sum(unpack('C*',$header)))."\0 ",148,8);
    return $header . $data . str_repeat("\0",(512-strlen($data)%512)%512);
}
function archiveBytes(array $members): string {
    $tar = '';
    foreach ($members as $name => $data) { $tar .= tarMember($name,$data); }
    return gzencode($tar . str_repeat("\0",1024));
}
function officialReleaseResponses(array $members): array {
    $specs = [
        'node_endpoint'=>['ksr-v/Xboard-Independent-NodeEndpoint','NodeEndpoint-%s.zip','NodeEndpoint','NodeEndpoint','SHA256SUMS'],
        'smart_expiry'=>['ksr-v/xb-SmartExpiry','SmartExpiry-%s-full.zip','SmartExpiry','SmartExpiry',null],
        'qrcodeextend'=>['ksr-v/xb-qrcode-extend','qrcodeextend-%s.zip','Qrcodeextend','Qrcodeextend','SHA256SUMS'],
        'flowscope'=>['ksr-v/xb-flowscope','flowscope-%s.zip','Flowscope','Flowscope','SHA256SUMS-v%s'],
        'subinfo_customizer'=>['ksr-v/xb-SubInfoCustomizer','SubInfoCustomizer-v%s.zip','SubinfoCustomizer','SubInfoCustomizer','SHA256SUMS'],
    ];
    $responses=[];
    foreach($specs as $code=>[$repo,$template,$runtime,$wrapper,$sumTemplate]) {
        $version=json_decode($members['bundled-plugins/'.$runtime.'/config.json'],true)['version'];
        $tag='v'.$version; $name=sprintf($template,$version);
        $temporary=tempnam(sys_get_temp_dir(),'native-release-test-');
        $zip=new ZipArchive(); check($zip->open($temporary,ZipArchive::OVERWRITE)===true,'Cannot make native ZIP fixture');
        $prefix='bundled-plugins/'.$runtime.'/';
        foreach($members as $path=>$bytes) { if(str_starts_with($path,$prefix)) { $zip->addFromString($wrapper.'/'.substr($path,strlen($prefix)),$bytes); } }
        $zip->close(); $body=file_get_contents($temporary); unlink($temporary);
        $sha=hash('sha256',$body);
        $base='https://github.com/'.$repo.'/releases/download/'.$tag.'/';
        $assets=[['id'=>1,'name'=>$name,'state'=>'uploaded','browser_download_url'=>$base.$name,'size'=>strlen($body),'digest'=>'sha256:'.$sha]];
        $responses[$base.$name]=Http::response($body);
        if($sumTemplate!==null) {
            $sumName=sprintf($sumTemplate,$version); $sums=$sha.'  '.$name."\n";
            $assets[]=['id'=>2,'name'=>$sumName,'state'=>'uploaded','browser_download_url'=>$base.$sumName,'size'=>strlen($sums),'digest'=>'sha256:'.hash('sha256',$sums)];
            $responses[$base.$sumName]=Http::response($sums);
        }
        $responses['https://api.github.com/repos/'.$repo.'/releases?per_page=100&page=1']=Http::response([
            ['id'=>100,'tag_name'=>$tag,'draft'=>false,'prerelease'=>false,'html_url'=>'https://github.com/'.$repo.'/releases/tag/'.$tag,'assets'=>$assets],
        ]);
        $responses['https://api.github.com/repos/'.$repo.'/git/ref/tags/'.$tag]=Http::response([
            'ref'=>'refs/tags/'.$tag,'object'=>['sha'=>str_repeat('a',40),'type'=>'commit'],
        ]);
    }
    return $responses;
}
function serveRelease(string $body, string $tag = 'v2026.10.31.1', ?string $hash = null, ?array $nativeMembers = null, array $overrides = [], bool $flushCheckCache = true): OnlinePluginUpdater {
    global $root, $members;
    if($flushCheckCache) { Cache::flush(); }
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    $hash ??= hash('sha256',$body);
    $base = 'https://github.com/ksr-v/Xboard-Custom-Source/releases/download/' . $tag . '/';
    Http::fake(array_merge(officialReleaseResponses($nativeMembers??$members),[
        'https://api.github.com/repos/ksr-v/Xboard-Custom-Source/releases/latest' => Http::response([
            'tag_name'=>$tag,'draft'=>false,'prerelease'=>false,'assets'=>[
                ['name'=>'xboard.tar.gz','browser_download_url'=>$base.'xboard.tar.gz','size'=>strlen($body),'digest'=>'sha256:'.$hash],
                ['name'=>'SHA256SUMS','browser_download_url'=>$base.'SHA256SUMS','size'=>84],
            ],
        ]),
        $base.'SHA256SUMS' => Http::response($hash.'  xboard.tar.gz' . "\n"),
        $base.'xboard.tar.gz' => Http::response($body),
    ],$overrides));
    return new OnlinePluginUpdater($root);
}
function fakeSource(string $version, bool $fail = false): string {
    return '<?php namespace Plugin\\Telegram; class Plugin extends \\App\\Services\\Plugin\\AbstractPlugin {'
        . 'public function cleanup(): void { file_put_contents(base_path("events"),"cleanup-' . $version . '\\n",FILE_APPEND); }'
        . 'public function boot(): void { file_put_contents(base_path("events"),"boot-' . $version . '\\n",FILE_APPEND);' . ($fail ? 'throw new \\RuntimeException("fixture boot failure");' : '') . ' }'
        . 'public function update(string $oldVersion,string $newVersion): void { file_put_contents(base_path("events"),"update-' . $version . '\\n",FILE_APPEND); }'
        . '}';
}

$root = fixtureRoot('online');
$app = bootFixture($root);
$app->instance(PluginManager::class,new PluginManager());
$updater = new OnlinePluginUpdater($root);
$sources = (new ReflectionClass(OnlinePluginUpdater::class))->getConstant('SOURCES');
$members = [];
foreach ($sources as $code => $directory) {
    $members[$directory.'/config.json'] = json_encode(['code'=>$code,'version'=>'1.0.1','name'=>$code,'author'=>'fixture','description'=>'fixture']);
    $members[$directory.'/Plugin.php'] = $code === 'telegram' ? fakeSource('1.0.1') : '<?php // fixture';
    Plugin::create(['code'=>$code,'name'=>$code,'version'=>'1.0.0','is_enabled'=>false,'config'=>'{"secret":"keep"}']);
}
$manifest = ['schema_version'=>1,'bundle_version'=>'2026.10.31.1','plugins'=>[]];
$sourceRepositories=(new ReflectionClass(OnlinePluginUpdater::class))->getConstant('SOURCE_REPOSITORIES');
foreach ($sources as $code => $directory) {
    if (!str_starts_with($directory,'bundled-plugins/')) { continue; }
    $files=[];
    foreach ($members as $name=>$data) { if(str_starts_with($name,$directory.'/')) { $files[substr($name,strlen($directory)+1)]=hash('sha256',$data); } }
    $manifest['plugins'][]=['code'=>$code,'directory'=>basename($directory),'version'=>'1.0.1','files'=>$files,
        'source_commit'=>str_repeat('a',40),'source_repository'=>$sourceRepositories[$code]];
}
$members['bundled-plugins/manifest.json']=json_encode($manifest);
$body=archiveBytes($members);

test('five maintained plugins use their own stable release while seven core plugins use the panel', function() use($updater,$sources,$body):void {
    $updater=serveRelease($body);
    $result=$updater->check(array_keys($sources));
    check(count($result)===12,'Not all official plugins covered');
    foreach($result as $entry) { check($entry['supported'] && $entry['has_update'] && $entry['latest_version']==='1.0.1' && !isset($entry['error']),'Incorrect online update discovery'); }
    check(!$updater->supports('third_party'),'Untrusted third-party source enabled implicitly');
});
test('QR independent 1.3.1 is discovered even while the panel snapshot carries an older version',function() use($body,$members):void {
    $native=$members;
    $native['bundled-plugins/Qrcodeextend/config.json']=json_encode(['code'=>'qrcodeextend','version'=>'1.3.1']);
    $updater=serveRelease($body,'v2026.10.31.1',null,$native);
    $result=$updater->check(['qrcodeextend','telegram']);
    check($result['qrcodeextend']['latest_version']==='1.3.1','Panel audit version masked the independent latest release');
    check($result['qrcodeextend']['release_url']==='https://github.com/ksr-v/xb-qrcode-extend/releases/tag/v1.3.1','Wrong QR release source');
    check($result['telegram']['latest_version']==='1.0.1' && str_contains($result['telegram']['release_url'],'Xboard-Custom-Source'),'Core plugin stopped using panel source');
});
test('native check failure is isolated from the other native and panel sources and cached as an error',function() use($body):void {
    $url='https://api.github.com/repos/ksr-v/xb-qrcode-extend/releases?per_page=100&page=1';
    $updater=serveRelease($body,'v2026.10.31.1',null,null,[$url=>Http::response([],503)]);
    $result=$updater->check(['qrcodeextend','smart_expiry','telegram']);
    check(isset($result['qrcodeextend']['error']) && $result['qrcodeextend']['latest_version']===null && !$result['qrcodeextend']['has_update'],'Failed source was reported current');
    check($result['smart_expiry']['latest_version']==='1.0.1' && !isset($result['smart_expiry']['error']),'Unrelated native source poisoned');
    check($result['telegram']['latest_version']==='1.0.1' && !isset($result['telegram']['error']),'Panel source poisoned');
    $requests=Http::recorded()->count();
    $updater->check(['qrcodeextend','smart_expiry','telegram']);
    check(Http::recorded()->count()===$requests,'Repeated checks exhausted upstream API instead of using brief metadata/error cache');
});
test('all seven core checks share one panel metadata and archive fetch',function() use($body):void {
    $updater=serveRelease($body);
    $codes=['alipay_f2f','btcpay','coinbase','coin_payments','epay','mgate','telegram'];
    $result=$updater->check($codes);
    $requests=Http::recorded()->count();
    check(count($result)===7 && $requests>=2 && $requests<=3,'Core checks fetched the same panel package repeatedly');
    check(Http::recorded(fn($request)=>str_ends_with($request->url(),'/releases/latest'))->count()===1,'Panel release metadata was requested more than once');
    (new OnlinePluginUpdater(base_path()))->check($codes);
    check(Http::recorded()->count()===$requests,'Verified metadata was not reused across web check requests');
});
test('checksum failures are explicit errors and never reported as current', function() use($updater,$body):void {
    $updater=serveRelease($body,'v2026.10.31.2',str_repeat('b',64));
    $entry=$updater->check(['telegram'])['telegram'];
    check(isset($entry['error']) && !$entry['has_update'] && $entry['latest_version']===null,'Failure incorrectly marked current');
});
test('archive path traversal, links, duplicate members, and truncation are rejected',function() use($updater,$root):void {
    foreach(['../escape','/absolute','C:/drive','a\\b','.env','a/../escape'] as $path) {
        expectFailure(fn()=>OnlinePluginUpdater::assertMember($path),'Unsafe');
    }
    $archive=$root.'/unsafe.tar.gz';
    file_put_contents($archive,gzencode(tarMember('safe','', '2').str_repeat("\0",1024)));
    expectFailure(fn()=>$updater->readArchive($archive,fn()=>null),'Unsupported');
    file_put_contents($archive,gzencode(tarMember('safe','a').tarMember('safe','b').str_repeat("\0",1024)));
    expectFailure(fn()=>$updater->readArchive($archive,fn()=>null),'Duplicate');
    file_put_contents($archive,gzencode(substr(tarMember('safe','a'),0,513)));
    expectFailure(fn()=>$updater->readArchive($archive,fn()=>null),'Truncated');
});

// A tiny Artisan host runs the real command and real PluginManager in fresh PHP
// processes against the fixture SQLite database, without a live application.
$child = <<<'PHP'
<?php
$GLOBALS['__composer_autoload_files']['a03e85e827f2f53ca6b173cf8d947ac6']=true;
require getenv('XBOARD_ONLINE_BOOTSTRAP');
$app=bootFixture(__DIR__,true);
$app->instance(\App\Services\Plugin\PluginManager::class,new \App\Services\Plugin\PluginManager());
$command=new \App\Console\Commands\PluginOnlineUpdate();
$command->setLaravel($app);
array_splice($_SERVER['argv'],1,1);
exit($command->run(new \Symfony\Component\Console\Input\ArgvInput($_SERVER['argv']),new \Symfony\Component\Console\Output\ConsoleOutput()));
PHP;
file_put_contents($root.'/artisan',$child);
putenv('XBOARD_ONLINE_BOOTSTRAP='.__DIR__.'/bootstrap.php');
$_ENV['XBOARD_ONLINE_BOOTSTRAP']=__DIR__.'/bootstrap.php';
putenv('XBOARD_TEST_VENDOR='.getenv('XBOARD_TEST_VENDOR'));
// Carry the explicit extension configuration to fresh fixture processes.
$ini=$root.'/php.ini';
$extension=dirname(PHP_BINARY).'/ext';
file_put_contents($ini,'extension_dir="'.str_replace('\\','/',$extension).'"' . "\n"
    . "extension=pdo_sqlite\nextension=sqlite3\nextension=mbstring\nextension=openssl\nextension=curl\nextension=fileinfo\n");
putenv('PHPRC='.$ini);
$_ENV['PHPRC']=$ini;

test('real fresh-process update cleans old source, migrates new source, and preserves enabled configuration',function() use($root,$updater,$body):void {
    mkdir($root.'/plugins-core/Telegram');
    file_put_contents($root.'/plugins-core/Telegram/config.json',json_encode(['code'=>'telegram','version'=>'1.0.0']));
    file_put_contents($root.'/plugins-core/Telegram/Plugin.php',fakeSource('1.0.0'));
    Plugin::where('code','telegram')->update(['is_enabled'=>true]);
    $updater=serveRelease($body);
    $result=$updater->update('telegram');
    $row=Plugin::where('code','telegram')->first();
    check($result['updated'] && $row->version==='1.0.1' && $row->is_enabled && $row->config==='{"secret":"keep"}','Configuration or enabled state changed');
    $events=file_get_contents($root.'/events');
    check(str_contains($events,'cleanup-1.0.0') && str_contains($events,'update-1.0.1') && str_contains($events,'boot-1.0.1'),'Old cleanup or new activation did not execute');
});
test('same-version source fixes update and disabled plugins stay disabled without booting',function() use($root,$updater,$members):void {
    Plugin::where('code','telegram')->update(['is_enabled'=>false]);
    $members['plugins-core/Telegram/Plugin.php']=fakeSource('1.0.1-fixed');
    $updater=serveRelease(archiveBytes($members),'v2026.10.31.3');
    check($updater->check(['telegram'])['telegram']['has_update'],'Same-version changed source was missed');
    $before=file_get_contents($root.'/events');
    $updater->update('telegram');
    $after=file_get_contents($root.'/events'); $newEvents=substr($after,strlen($before));
    check(str_starts_with($after,$before) && !str_contains($newEvents,'boot-') && !str_contains($newEvents,'update-'),'Disabled plugin booted or migrated during source refresh');
    check(str_contains($newEvents,'cleanup-1.0.1-fixed'),'Disabled replacement retained an active integration');
    check(!Plugin::where('code','telegram')->first()->is_enabled,'Disabled plugin became enabled');
    check(!$updater->check(['telegram'])['telegram']['has_update'],'Updated same-version source still appears outdated');
    check(!$updater->update('telegram')['updated'],'Unchanged source falsely reported updated');
});
test('failed fresh-process activation restores original source, version, enabled state, and config',function() use($root,$updater,$members):void {
    Plugin::where('code','telegram')->update(['is_enabled'=>true]);
    $old=file_get_contents($root.'/plugins-core/Telegram/Plugin.php');
    $members['plugins-core/Telegram/config.json']=json_encode(['code'=>'telegram','version'=>'1.0.2']);
    $members['plugins-core/Telegram/Plugin.php']=fakeSource('1.0.2',true);
    $updater=serveRelease(archiveBytes($members),'v2026.10.31.4');
    expectFailure(fn()=>$updater->update('telegram'),'fixture boot failure');
    $row=Plugin::where('code','telegram')->first();
    check(file_get_contents($root.'/plugins-core/Telegram/Plugin.php')===$old,'Old plugin source not restored');
    check($row->version==='1.0.1' && $row->is_enabled && $row->config==='{"secret":"keep"}','Recovery lost original database state');
});
test('bundled online updates retain the verified source ownership for later panel synchronization',function() use($root,$updater,$members,$manifest):void {
    $path=$root.'/plugins/SmartExpiry';
    mkdir($path);
    file_put_contents($path.'/config.json',json_encode(['code'=>'smart_expiry','version'=>'1.0.0']));
    file_put_contents($path.'/Plugin.php',str_replace('Plugin\\Telegram','Plugin\\SmartExpiry',fakeSource('1.0.0')));
    $members['bundled-plugins/SmartExpiry/Plugin.php']=str_replace('Plugin\\Telegram','Plugin\\SmartExpiry',fakeSource('1.0.1'));
    foreach($manifest['plugins'] as &$item) {
        if($item['code']==='smart_expiry') { $item['files']['Plugin.php']=hash('sha256',$members['bundled-plugins/SmartExpiry/Plugin.php']); }
    }
    unset($item);
    $members['bundled-plugins/manifest.json']=json_encode($manifest);
    $updater=serveRelease(archiveBytes($members),'v2026.10.31.5',null,$members);
    $updater->update('smart_expiry');
    $ownership=json_decode(file_get_contents($root.'/storage/app/private/plugin-bundles/ownership.json'),true);
    check(!isset($ownership['plugins']['smart_expiry']['bundle_version']),'Independent plugin version was confused with panel bundle_version');
    check($ownership['plugins']['smart_expiry']['source_provenance']['kind']==='github-release-asset','Native release provenance not retained');
    check($ownership['plugins']['smart_expiry']['source_repository']==='https://github.com/ksr-v/xb-SmartExpiry','Native repository was not retained');
    check($ownership['plugins']['smart_expiry']['source_commit']===str_repeat('a',40),'Real resolved tag commit not retained');
    check($ownership['plugins']['smart_expiry']['files']['Plugin.php']===hash_file('sha256',$path.'/Plugin.php'),'Ownership differs from installed online source');
    check(!Plugin::where('code','smart_expiry')->first()->is_enabled,'Bundled disabled plugin became enabled');
});
test('native update ignores check cache and preserves disabled state config and true release provenance',function() use($root,$body,$members):void {
    $path=$root.'/plugins/Flowscope'; mkdir($path);
    file_put_contents($path.'/config.json',json_encode(['code'=>'flowscope','version'=>'1.0.0']));
    file_put_contents($path.'/Plugin.php',str_replace('Plugin\\Telegram','Plugin\\Flowscope',fakeSource('1.0.0')));
    $updater=serveRelease($body);
    check($updater->check(['flowscope'])['flowscope']['latest_version']==='1.0.1','Check snapshot setup failed');
    $native=$members;
    $native['bundled-plugins/Flowscope/config.json']=json_encode(['code'=>'flowscope','version'=>'1.0.2']);
    $native['bundled-plugins/Flowscope/Plugin.php']=str_replace('Plugin\\Telegram','Plugin\\Flowscope',fakeSource('1.0.2'));
    $updater=serveRelease($body,'v2026.10.31.1',null,$native,[],false);
    check($updater->check(['flowscope'])['flowscope']['latest_version']==='1.0.1','Brief cached check metadata was not reused');
    $result=$updater->update('flowscope'); $row=Plugin::where('code','flowscope')->first();
    check($result['version']==='1.0.2' && $result['updated'] && !$row->is_enabled && $row->config==='{"secret":"keep"}','Fresh update used stale metadata or changed original state');
    check($result['source']['source_repository']==='https://github.com/ksr-v/xb-flowscope' && $result['source']['tag']==='v1.0.2','Selected native source was not reported');
    $owned=json_decode(file_get_contents($root.'/storage/app/private/plugin-bundles/ownership.json'),true)['plugins']['flowscope'];
    check(!isset($owned['bundle_version']) && $owned['source_provenance']['checksum_source']==='SHA256SUMS-v1.0.2','Native version mixed with panel bundle or wrong checksum origin');
    check($owned['source_provenance']['archive_sha256']===$result['source']['archive_sha256'],'Reported source differs from owned installed artifact');
    check($updater->check(['flowscope'])['flowscope']['latest_version']==='1.0.2','Successful update did not invalidate stale check metadata');
});
test('native activation failure restores original source state config and ownership',function() use($root,$body,$members):void {
    Plugin::where('code','smart_expiry')->update(['is_enabled'=>true]);
    $path=$root.'/plugins/SmartExpiry/Plugin.php'; $before=file_get_contents($path);
    $ownership=file_get_contents($root.'/storage/app/private/plugin-bundles/ownership.json');
    $native=$members;
    $native['bundled-plugins/SmartExpiry/config.json']=json_encode(['code'=>'smart_expiry','version'=>'1.0.2']);
    $native['bundled-plugins/SmartExpiry/Plugin.php']=str_replace('Plugin\\Telegram','Plugin\\SmartExpiry',fakeSource('1.0.2',true));
    $updater=serveRelease($body,'v2026.10.31.1',null,$native);
    expectFailure(fn()=>$updater->update('smart_expiry'),'fixture boot failure');
    $row=Plugin::where('code','smart_expiry')->first();
    check(file_get_contents($path)===$before && $row->version==='1.0.1' && $row->is_enabled && $row->config==='{"secret":"keep"}','Native rollback lost original source or state');
    check(file_get_contents($root.'/storage/app/private/plugin-bundles/ownership.json')===$ownership,'Native failure changed original ownership evidence');
});
test('malformed ownership JSON or schema is refused before staging cleanup source or database mutation',function() use($root,$body,$members):void {
    $path=$root.'/storage/app/private/plugin-bundles/ownership.json'; $saved=file_get_contents($path);
    $source=$root.'/plugins/SmartExpiry/Plugin.php'; $sourceBefore=file_get_contents($source);
    $rowBefore=Plugin::where('code','smart_expiry')->first()->toArray(); $events=file_get_contents($root.'/events');
    $stages=glob($root.'/plugins/.online-*'); $backups=glob($root.'/storage/app/private/plugin-online/backup-*');
    $native=$members;
    $native['bundled-plugins/SmartExpiry/config.json']=json_encode(['code'=>'smart_expiry','version'=>'1.0.2']);
    $native['bundled-plugins/SmartExpiry/Plugin.php']=str_replace('Plugin\\Telegram','Plugin\\SmartExpiry',fakeSource('1.0.2'));
    try {
        foreach(['{','null','{"schema_version":2,"plugins":[]}','{"schema_version":1,"plugins":"invalid"}'] as $invalid) {
            file_put_contents($path,$invalid);
            $updater=serveRelease($body,'v2026.10.31.1',null,$native);
            expectFailure(fn()=>$updater->update('smart_expiry'),'Invalid plugin ownership record');
            check(file_get_contents($source)===$sourceBefore && Plugin::where('code','smart_expiry')->first()->toArray()===$rowBefore,'Invalid ownership changed source or row');
            check(file_get_contents($root.'/events')===$events && file_get_contents($path)===$invalid,'Invalid ownership ran lifecycle or changed evidence');
            check(glob($root.'/plugins/.online-*')===$stages && glob($root.'/storage/app/private/plugin-online/backup-*')===$backups,'Invalid ownership created staging or operation directories');
        }
    } finally { file_put_contents($path,$saved); }
});
test('ownership restoration write failure cannot bypass original source database and boot recovery',function() use($root,$body,$members):void {
    $path=$root.'/storage/app/private/plugin-bundles/ownership.json'; $saved=file_get_contents($path);
    $source=$root.'/plugins/SmartExpiry/Plugin.php'; $before=file_get_contents($source);
    $native=$members;
    $native['bundled-plugins/SmartExpiry/config.json']=json_encode(['code'=>'smart_expiry','version'=>'1.0.2']);
    $injection='$ownership=storage_path("app/private/plugin-bundles/ownership.json"); unlink($ownership); mkdir($ownership);';
    $candidate=str_replace('public function boot(): void {','public function boot(): void {'.$injection,fakeSource('1.0.2'));
    $native['bundled-plugins/SmartExpiry/Plugin.php']=str_replace('Plugin\\Telegram','Plugin\\SmartExpiry',$candidate);
    $updater=serveRelease($body,'v2026.10.31.1',null,$native);
    set_error_handler(fn()=>true);
    try {
        expectFailure(fn()=>$updater->update('smart_expiry'),'recovery record');
        $row=Plugin::where('code','smart_expiry')->first();
        check(file_get_contents($source)===$before && $row->version==='1.0.1' && $row->is_enabled && $row->config==='{"secret":"keep"}','Ownership write exception prevented source/row/boot recovery');
        $found=false;
        foreach(glob($root.'/storage/app/private/plugin-online/backup-*/state.json') as $state) {
            if(!is_file($state)) { continue; }
            $record=json_decode(file_get_contents($state),true);
            if(($record['code']??null)==='smart_expiry' && ($record['status']??null)==='failed' && ($record['ownership_restored']??null)===false) {
                check(($record['source_restored']??null)===true,'Failed ownership recovery lost source evidence');
                check(json_decode(file_get_contents(dirname($state).'/ownership-before.json'),true)===json_decode($saved,true),'Original ownership graph was not retained for repair');
                $found=true;
            }
        }
        check($found,'Ownership restoration failure was not recorded');
    } finally {
        restore_error_handler();
        if(is_dir($path)) { rmdir($path); }
        file_put_contents($path,$saved);
    }
});
test('a bulk updater instance freezes one fresh panel snapshot across core targets',function() use($body):void {
    Plugin::whereIn('code',['btcpay','coinbase'])->update(['version'=>'9.0.0']);
    $bulk=serveRelease($body,'v2026.10.31.10');
    $first=$bulk->update('btcpay');
    serveRelease($body,'v2026.10.31.11');
    $second=$bulk->update('coinbase');
    check($first['source']['tag']==='v2026.10.31.10' && $second['source']['tag']==='v2026.10.31.10','Core target switched its selected release halfway through the operation');
    check(Http::recorded()->count()===0,'Bulk operation refetched panel latest for a later target');
    Plugin::whereIn('code',['btcpay','coinbase'])->update(['version'=>'1.0.0']);
});
test('late recovery-record failure restores source even after its backup moved to private storage',function() use($root,$updater,$members):void {
    $old=file_get_contents($root.'/plugins-core/Telegram/Plugin.php');
    $members['plugins-core/Telegram/config.json']=json_encode(['code'=>'telegram','version'=>'1.0.2']);
    $candidate=fakeSource('1.0.2');
    $injection='foreach (glob(storage_path("app/private/plugin-online/backup-*/state.json")) as $state) {'
        .'$record=json_decode(file_get_contents($state),true); if (($record["status"]??"")==="staging") { unlink($state); mkdir($state); }}';
    $members['plugins-core/Telegram/Plugin.php']=str_replace('public function boot(): void {','public function boot(): void {'.$injection,$candidate);
    $updater=serveRelease(archiveBytes($members),'v2026.10.31.6');
    // Force only the final state-record rename to fail after activation succeeds.
    set_error_handler(fn()=>true);
    try { expectFailure(fn()=>$updater->update('telegram'),'recovery record'); }
    finally { restore_error_handler(); }
    check(file_get_contents($root.'/plugins-core/Telegram/Plugin.php')===$old,'Late record failure lost moved source backup');
    $row=Plugin::where('code','telegram')->first();
    check($row->version==='1.0.1' && $row->is_enabled && $row->config==='{"secret":"keep"}','Late failure did not restore original plugin state');
});
test('newer installed versions are identified and never downgraded',function() use($root,$updater,$body):void {
    Plugin::where('code','telegram')->update(['version'=>'9.0.0']);
    $before=hash_file('sha256',$root.'/plugins-core/Telegram/Plugin.php');
    $updater=serveRelease($body);
    $entry=$updater->check(['telegram'])['telegram'];
    check($entry['is_ahead'] && !$entry['has_update'],'Ahead-of-release version not distinguished');
    check(!$updater->update('telegram')['updated'] && Plugin::where('code','telegram')->value('version')==='9.0.0','Newer plugin was downgraded');
    check(hash_file('sha256',$root.'/plugins-core/Telegram/Plugin.php')===$before,'Newer source changed');
});
test('ordinary plugin mutations reject a busy shared lock and release it after success or failure',function() use($root,$app):void {
    $app->register(\Illuminate\Routing\RoutingServiceProvider::class);
    $middleware=new \App\Http\Middleware\PluginMutationLock();
    $request=\Illuminate\Http\Request::create('/plugin/config','POST');
    $path=$root.'/storage/app/private/plugin-bundles/sync.lock';
    $owner=fopen($path,'c+b');
    check(flock($owner,LOCK_EX|LOCK_NB),'Cannot acquire fixture update lock');
    $called=false;
    $result=$middleware->handle($request,function() use(&$called) { $called=true; return 'unexpected'; });
    check($result->getStatusCode()===409 && !$called,'Busy plugin mutation was allowed');
    flock($owner,LOCK_UN); fclose($owner);
    check($middleware->handle($request,fn()=>'completed')==='completed','Unlocked plugin mutation failed');
    expectFailure(fn()=>$middleware->handle($request,fn()=>throw new RuntimeException('fixture mutation failed')),'fixture mutation failed');
    $probe=fopen($path,'c+b');
    check(flock($probe,LOCK_EX|LOCK_NB),'Plugin mutation left lock held after exception');
    flock($probe,LOCK_UN); fclose($probe);
});
echo "Completed {$passed} online plugin update tests\n";
