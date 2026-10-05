<?php

namespace Tests\Unit\Services;

use App\Services\PrivateArchiveUpdateService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrivateArchiveUpdateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('orphan.xboard_update_base_url', 'https://updates.example.test/xboard');
        config()->set('orphan.xboard_update_token', 'test-token');
        config()->set('orphan.xboard_build_commit', '1111111111111111111111111111111111111111');
    }

    public function test_archive_update_check_uses_authenticated_private_manifest_and_fixed_asset_path(): void
    {
        Http::fake([
            'updates.example.test/xboard/latest.json' => Http::response([
                'version' => '20261005-2222222',
                'commit' => str_repeat('2', 40),
                'sha256' => str_repeat('a', 64),
                'size' => 120,
                'published_at' => '2026-10-05T00:00:00Z',
                'author' => 'Private maintainer',
                'message' => 'Orphan release',
            ]),
        ]);

        $result = app(PrivateArchiveUpdateService::class)->checkForUpdates();

        $this->assertTrue($result['has_update']);
        $this->assertSame('2222222', $result['latest_version']);
        $this->assertSame(
            'https://updates.example.test/xboard/releases/20261005-2222222/xboard.tar.gz',
            $result['download_url']
        );
        Http::assertSent(fn(Request $request) => str_ends_with($request->url(), '/latest.json')
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_archive_mode_rejects_non_https_update_base_without_network_access(): void
    {
        config()->set('orphan.xboard_update_base_url', 'http://updates.example.test/xboard');
        Http::fake();

        $result = app(PrivateArchiveUpdateService::class)->checkForUpdates();

        $this->assertFalse($result['has_update']);
        Http::assertNothingSent();
    }

    public function test_archive_mode_allows_loopback_http_for_a_same_host_release_endpoint(): void
    {
        config()->set('orphan.xboard_update_base_url', 'http://127.0.0.1/private-xboard-updates');
        Http::fake([
            '127.0.0.1/private-xboard-updates/latest.json' => Http::response([
                'version' => '20261005-2222222',
                'commit' => str_repeat('2', 40),
                'sha256' => str_repeat('a', 64),
                'size' => 120,
                'published_at' => '2026-10-05T00:00:00Z',
                'author' => 'Private maintainer',
                'message' => 'Orphan release',
            ]),
        ]);

        $result = app(PrivateArchiveUpdateService::class)->checkForUpdates();

        $this->assertTrue($result['has_update']);
        Http::assertSent(fn(Request $request) => $request->url() === 'http://127.0.0.1/private-xboard-updates/latest.json'
            && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_archive_manifest_rejects_invalid_release_names_and_hashes(): void
    {
        Http::fake([
            'updates.example.test/xboard/latest.json' => Http::response([
                'version' => '../latest',
                'commit' => 'not-a-commit',
                'sha256' => 'bad',
                'size' => 1,
                'published_at' => '2026-10-05T00:00:00Z',
                'author' => 'Private maintainer',
                'message' => 'Invalid release',
            ]),
        ]);

        $result = app(PrivateArchiveUpdateService::class)->checkForUpdates();

        $this->assertFalse($result['has_update']);
        $this->assertSame('', $result['download_url']);
    }

    public function test_archive_application_rejects_a_modified_download_url_before_network_access(): void
    {
        Http::fake();

        try {
            app(PrivateArchiveUpdateService::class)->applyUpdate([
                'latest_version' => '2222222',
                'archive_version' => '20261005-2222222',
                'archive_commit' => str_repeat('2', 40),
                'archive_sha256' => str_repeat('a', 64),
                'archive_size' => 120,
                'download_url' => 'https://attacker.example.test/release.tar.gz',
            ]);
            $this->fail('An unexpected archive URL must be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Private release metadata is invalid.', $exception->getMessage());
        }

        Http::assertNothingSent();
    }
}