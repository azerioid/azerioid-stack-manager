<?php

namespace App\Http\Controllers;

use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Validator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

final class VhostFilesController
{
    public function download(string $domain, Request $request, BrokerClient $broker): StreamedResponse
    {
        $domain = Validator::domain($domain);
        $path = (string) $request->query('path', '');
        $res = $broker->call('vhost.files.read', [$domain], [
            'path' => $path,
            'admin_user_id' => (string) auth()->id(),
        ]);
        if (! $res->ok) {
            abort(403, (string) $res->error);
        }
        $bytes = base64_decode((string) ($res->data['content_base64'] ?? ''), true);
        if ($bytes === false) {
            abort(500, 'Invalid file payload from broker.');
        }
        $name = basename((string) ($res->data['path'] ?? 'download'));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'download';
        }

        return response()->streamDownload(static function () use ($bytes): void {
            echo $bytes;
        }, $name, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }

    public function zip(string $domain, Request $request, BrokerClient $broker): StreamedResponse
    {
        $domain = Validator::domain($domain);
        $paths = $request->input('paths', []);
        if (! is_array($paths)) {
            abort(422, 'Invalid path list.');
        }
        $paths = array_values(array_unique(array_map(static fn ($p): string => (string) $p, $paths)));
        if ($paths === []) {
            abort(422, 'No files selected.');
        }
        if (count($paths) > 50) {
            abort(422, 'Too many files (max 50).');
        }
        if (! class_exists(ZipArchive::class)) {
            abort(500, 'Zip support is not available on this panel.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'azfm');
        if ($tmp === false) {
            abort(500, 'Unable to create archive.');
        }
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            @unlink($tmp);
            abort(500, 'Unable to create archive.');
        }

        $added = 0;
        $bytesTotal = 0;
        $maxTotal = 50 * 1024 * 1024;
        $failStatus = null;
        $failMessage = null;
        try {
            foreach ($paths as $path) {
                if ($path === '' || str_contains($path, "\0")) {
                    continue;
                }
                $res = $broker->call('vhost.files.read', [$domain], [
                    'path' => $path,
                    'admin_user_id' => (string) auth()->id(),
                ]);
                if (! $res->ok) {
                    $err = strtolower((string) $res->error);
                    if (str_contains($err, 'read-only') || str_contains($err, 'not available')) {
                        $failStatus = 403;
                        $failMessage = (string) $res->error;
                        break;
                    }

                    continue;
                }
                $bytes = base64_decode((string) ($res->data['content_base64'] ?? ''), true);
                if ($bytes === false) {
                    continue;
                }
                $bytesTotal += strlen($bytes);
                if ($bytesTotal > $maxTotal) {
                    $failStatus = 422;
                    $failMessage = 'Selected files exceed the zip size limit.';
                    break;
                }
                $entry = str_replace('\\', '/', (string) ($res->data['path'] ?? $path));
                if ($entry === '' || str_contains($entry, '..') || str_starts_with($entry, '/')) {
                    continue;
                }
                $zip->addFromString($entry, $bytes);
                $added++;
            }
            $zip->close();
        } catch (\Throwable $e) {
            try {
                $zip->close();
            } catch (\ValueError) {
            }
            @unlink($tmp);
            throw $e;
        }

        if ($failStatus !== null) {
            @unlink($tmp);
            abort($failStatus, (string) $failMessage);
        }

        if ($added === 0) {
            @unlink($tmp);
            abort(422, 'No downloadable files in the selection.');
        }

        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $domain) . '-files.zip';

        return response()->streamDownload(static function () use ($tmp): void {
            try {
                readfile($tmp);
            } finally {
                @unlink($tmp);
            }
        }, $name, [
            'Content-Type' => 'application/zip',
        ]);
    }
}
