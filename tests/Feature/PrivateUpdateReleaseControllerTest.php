<?php

namespace Tests\Feature;

use App\Http\Middleware\InitializePlugins;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PrivateUpdateReleaseControllerTest extends TestCase
{
    private string $releaseDirectory;
    private string $version = '20261005-1111111';
    private string $token = 'test-release-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(InitializePlugins::class);

        $this->releaseDirectory = sys_get_temp_dir() . '/xboard-releases-' . bin2hex(random_bytes(6));
        $archiveDirectory = $this->releaseDirectory . '/releases/' . $this->version;
        File::ensureDirectoryExists($archiveDirectory, 0700);
        File::put($archiveDirectory . '/xboard.tar.gz', 'fixture archive bytes');

        $archivePath = $archiveDirectory . '/xboard.tar.gz';
        File::put($this->releaseDirectory . '/latest.json', json_encode([
            'version' => $this->version,
            'commit' => str_repeat('1', 40),
            'sha256' => hash_file('sha256', $archivePath),
            'size' => filesize($archivePath),
            'published_at' => '2026-10-05T00:00:00Z',
            'author' => 'Private maintainer',
            'message' => 'Orphan release',
        ], JSON_THROW_ON_ERROR));

        config()->set('orphan.xboard_release_dir', $this->releaseDirectory);
        config()->set('orphan.xboard_update_token', $this->token);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->releaseDirectory);
        parent::tearDown();
    }

    public function test_loopback_request_with_bearer_token_can_read_valid_manifest_and_archive(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token];

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/private-xboard-updates/latest.json', $headers)
            ->assertOk()
            ->assertJsonPath('version', $this->version);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->get('/private-xboard-updates/releases/' . $this->version . '/xboard.tar.gz', $headers)
            ->assertDownload('xboard.tar.gz');
    }

    public function test_endpoint_rejects_non_loopback_or_unauthenticated_requests(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.128.20'])
            ->getJson('/private-xboard-updates/latest.json', ['Authorization' => 'Bearer ' . $this->token])
            ->assertNotFound();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/private-xboard-updates/latest.json')
            ->assertNotFound();
    }

    public function test_endpoint_hides_manifest_when_archive_integrity_does_not_match(): void
    {
        $manifestPath = $this->releaseDirectory . '/latest.json';
        $manifest = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['sha256'] = str_repeat('a', 64);
        File::put($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('/private-xboard-updates/latest.json', ['Authorization' => 'Bearer ' . $this->token])
            ->assertNotFound();
    }
}