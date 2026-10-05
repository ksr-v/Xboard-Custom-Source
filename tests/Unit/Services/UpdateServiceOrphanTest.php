<?php

namespace Tests\Unit\Services;

use App\Services\UpdateService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateServiceOrphanTest extends TestCase
{
    private ?string $previousGitConfig = null;
    private ?string $testGitConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        $previous = getenv('GIT_CONFIG_GLOBAL');
        $this->previousGitConfig = $previous === false ? null : $previous;
        $this->testGitConfig = tempnam(sys_get_temp_dir(), 'xboard-git-config-') ?: null;
        if ($this->testGitConfig !== null) {
            putenv('GIT_CONFIG_GLOBAL=' . $this->testGitConfig);
        }
    }

    protected function tearDown(): void
    {
        if ($this->previousGitConfig === null) {
            putenv('GIT_CONFIG_GLOBAL');
        } else {
            putenv('GIT_CONFIG_GLOBAL=' . $this->previousGitConfig);
        }
        if ($this->testGitConfig !== null && is_file($this->testGitConfig)) {
            unlink($this->testGitConfig);
        }

        parent::tearDown();
    }

    public function test_update_check_queries_only_the_configured_private_repository(): void
    {
        config()->set('orphan.xboard_repository', 'ksr-v/Xboard-Custom-Source');
        config()->set('orphan.xboard_ref', 'master');
        config()->set('orphan.xboard_update_token', '');

        $currentCommit = trim((string) shell_exec('git rev-parse HEAD'));
        Http::fake([
            'api.github.com/repos/ksr-v/Xboard-Custom-Source/commits*' => Http::response([[
                'sha' => $currentCommit,
                'html_url' => 'https://github.com/ksr-v/Xboard-Custom-Source/commit/' . $currentCommit,
                'commit' => [
                    'message' => 'Current private release',
                    'author' => [
                        'name' => 'Private maintainer',
                        'date' => '2026-10-05T00:00:00Z',
                    ],
                ],
            ]], 200),
        ]);

        $result = app(UpdateService::class)->checkForUpdates();

        $this->assertFalse($result['has_update']);
        Http::assertSent(fn(Request $request) => str_contains(
            $request->url(),
            '/repos/ksr-v/Xboard-Custom-Source/commits?'
        ) && str_contains($request->url(), 'sha=master'));
        Http::assertNotSent(fn(Request $request) => str_contains($request->url(), 'cedar2025'));
    }
}