<?php
declare(strict_types=1);
$GLOBALS['__composer_autoload_files']['a03e85e827f2f53ca6b173cf8d947ac6'] = true;
require __DIR__ . '/bootstrap.php';

use App\Models\Plugin;
use App\Services\Plugin\{OnlinePluginUpdater, PluginManager};
use Illuminate\Support\Facades\Http;

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
function serveRelease(string $body, string $tag = 'v2026.10.31.1', ?string $hash = null): void {
    Http::swap(new \Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    $hash ??= hash('sha256',$body);
    $base = 'https://github.com/ksr-v/Xboard-Custom-Source/releases/download/' . $tag . '/';
    Http::fake([
        'https://api.github.com/repos/ksr-v/Xboard-Custom-Source/releases/latest' => Http::response([
            'tag_name'=>$tag,'draft'=>false,'prerelease'=>false,'assets'=>[
                ['name'=>'xboard.tar.gz','browser_download_url'=>$base.'xboard.tar.gz','size'=>strlen($body),'digest'=>'sha256:'.$hash],
                ['name'=>'SHA256SUMS','browser_download_url'=>$base.'SHA256SUMS','size'=>84],
            ],
        ]),
        $base.'SHA256SUMS' => Http::response($hash.'  xboard.tar.gz' . "\n"),
        $base.'xboard.tar.gz' => Http::response($body),
    ]);
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

test('all twelve official plugins discover versions from the same verified stable release', function() use($updater,$sources,$body):void {
    serveRelease($body);
    $result=$updater->check(array_keys($sources));
    check(count($result)===12,'Not all official plugins covered');
    foreach($result as $entry) { check($entry['supported'] && $entry['has_update'] && $entry['latest_version']==='1.0.1' && !isset($entry['error']),'Incorrect online update discovery'); }
    check(!$updater->supports('third_party'),'Untrusted third-party source enabled implicitly');
});
test('checksum failures are explicit errors and never reported as current', function() use($updater,$body):void {
    serveRelease($body,'v2026.10.31.2',str_repeat('b',64));
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
    serveRelease($body);
    $result=$updater->update('telegram');
    $row=Plugin::where('code','telegram')->first();
    check($result['updated'] && $row->version==='1.0.1' && $row->is_enabled && $row->config==='{"secret":"keep"}','Configuration or enabled state changed');
    $events=file_get_contents($root.'/events');
    check(str_contains($events,'cleanup-1.0.0') && str_contains($events,'update-1.0.1') && str_contains($events,'boot-1.0.1'),'Old cleanup or new activation did not execute');
});
test('same-version source fixes update and disabled plugins stay disabled without booting',function() use($root,$updater,$members):void {
    Plugin::where('code','telegram')->update(['is_enabled'=>false]);
    $members['plugins-core/Telegram/Plugin.php']=fakeSource('1.0.1-fixed');
    serveRelease(archiveBytes($members),'v2026.10.31.3');
    check($updater->check(['telegram'])['telegram']['has_update'],'Same-version changed source was missed');
    $before=file_get_contents($root.'/events');
    $updater->update('telegram');
    check(file_get_contents($root.'/events')===$before,'Disabled plugin booted or migrated during source refresh');
    check(!Plugin::where('code','telegram')->first()->is_enabled,'Disabled plugin became enabled');
    check(!$updater->check(['telegram'])['telegram']['has_update'],'Updated same-version source still appears outdated');
    check(!$updater->update('telegram')['updated'],'Unchanged source falsely reported updated');
});
test('failed fresh-process activation restores original source, version, enabled state, and config',function() use($root,$updater,$members):void {
    Plugin::where('code','telegram')->update(['is_enabled'=>true]);
    $old=file_get_contents($root.'/plugins-core/Telegram/Plugin.php');
    $members['plugins-core/Telegram/config.json']=json_encode(['code'=>'telegram','version'=>'1.0.2']);
    $members['plugins-core/Telegram/Plugin.php']=fakeSource('1.0.2',true);
    serveRelease(archiveBytes($members),'v2026.10.31.4');
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
    serveRelease(archiveBytes($members),'v2026.10.31.5');
    $updater->update('smart_expiry');
    $ownership=json_decode(file_get_contents($root.'/storage/app/private/plugin-bundles/ownership.json'),true);
    check($ownership['plugins']['smart_expiry']['bundle_version']==='2026.10.31.1','Online source bundle version not retained');
    check($ownership['plugins']['smart_expiry']['files']['Plugin.php']===hash_file('sha256',$path.'/Plugin.php'),'Ownership differs from installed online source');
    check(!Plugin::where('code','smart_expiry')->first()->is_enabled,'Bundled disabled plugin became enabled');
});
test('late recovery-record failure restores source even after its backup moved to private storage',function() use($root,$updater,$members):void {
    $old=file_get_contents($root.'/plugins-core/Telegram/Plugin.php');
    $members['plugins-core/Telegram/config.json']=json_encode(['code'=>'telegram','version'=>'1.0.2']);
    $candidate=fakeSource('1.0.2');
    $injection='foreach (glob(storage_path("app/private/plugin-online/backup-*/state.json")) as $state) {'
        .'$record=json_decode(file_get_contents($state),true); if (($record["status"]??"")==="staging") { unlink($state); mkdir($state); }}';
    $members['plugins-core/Telegram/Plugin.php']=str_replace('public function boot(): void {','public function boot(): void {'.$injection,$candidate);
    serveRelease(archiveBytes($members),'v2026.10.31.6');
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
    serveRelease($body);
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
