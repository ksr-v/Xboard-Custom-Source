<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PrivateUpdateReleaseController extends Controller
{
    private const MAX_ARCHIVE_BYTES = 262144000;

    public function manifest(Request $request)
    {
        $this->authorizeLocalRequest($request);

        $directory = $this->releaseDirectory();
        $manifestPath = $directory . '/latest.json';
        abort_unless(is_file($manifestPath) && !is_link($manifestPath), 404);

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(404);
        }

        abort_unless($this->validManifest($manifest), 404);
        $archivePath = $directory . '/releases/' . $manifest['version'] . '/xboard.tar.gz';
        abort_unless($this->validArchive($archivePath, $directory, $manifest), 404);

        return response()->json($manifest, 200, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function archive(Request $request, string $version): BinaryFileResponse
    {
        $this->authorizeLocalRequest($request);
        abort_unless(preg_match('/^[0-9]{8}-[a-fA-F0-9]{7,40}$/', $version) === 1, 404);

        $directory = $this->releaseDirectory();
        $archivePath = $directory . '/releases/' . $version . '/xboard.tar.gz';
        abort_unless(is_file($archivePath) && !is_link($archivePath) && is_readable($archivePath), 404);
        abort_unless($this->isWithinDirectory($archivePath, $directory), 404);

        return response()->download($archivePath, 'xboard.tar.gz', [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeLocalRequest(Request $request): void
    {
        $remoteAddress = (string) $request->server->get('REMOTE_ADDR', '');
        abort_unless(in_array($remoteAddress, ['127.0.0.1', '::1'], true), 404);

        $token = trim((string) config('orphan.xboard_update_token', ''));
        $providedToken = (string) $request->bearerToken();
        abort_unless($token !== '' && $providedToken !== '' && hash_equals($token, $providedToken), 404);
    }

    private function releaseDirectory(): string
    {
        return rtrim((string) config('orphan.xboard_release_dir'), DIRECTORY_SEPARATOR);
    }

    private function validManifest(mixed $manifest): bool
    {
        if (!is_array($manifest)
            || !isset($manifest['version'], $manifest['commit'], $manifest['sha256'], $manifest['size'], $manifest['published_at'], $manifest['author'], $manifest['message'])
            || !preg_match('/^[0-9]{8}-[a-fA-F0-9]{7,40}$/', (string) $manifest['version'])
            || !preg_match('/^[a-fA-F0-9]{40}$/', (string) $manifest['commit'])
            || !preg_match('/^[a-fA-F0-9]{64}$/', (string) $manifest['sha256'])
            || !str_starts_with(strtolower((string) $manifest['commit']), strtolower(substr((string) $manifest['version'], 9)))
            || !is_int($manifest['size']) || $manifest['size'] < 1 || $manifest['size'] > self::MAX_ARCHIVE_BYTES
            || !is_string($manifest['published_at']) || strtotime($manifest['published_at']) === false
            || !is_string($manifest['author']) || strlen($manifest['author']) > 200
            || !is_string($manifest['message']) || strlen($manifest['message']) > 4000) {
            return false;
        }

        return true;
    }

    private function validArchive(string $archivePath, string $directory, array $manifest): bool
    {
        return is_file($archivePath) && !is_link($archivePath) && is_readable($archivePath)
            && $this->isWithinDirectory($archivePath, $directory)
            && filesize($archivePath) === $manifest['size']
            && hash_equals(strtolower($manifest['sha256']), hash_file('sha256', $archivePath));
    }

    private function isWithinDirectory(string $path, string $directory): bool
    {
        $realDirectory = realpath($directory);
        $realPath = realpath($path);
        return $realDirectory !== false && $realPath !== false
            && str_starts_with($realPath, $realDirectory . DIRECTORY_SEPARATOR);
    }
}