<?php

namespace App\Http\Controllers;

use App\Services\Broker\BrokerClient;
use AzerioidPanel\Broker\Validator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // Assembly happens in the broker, as the vhost's own identity (G12). It used to
        // happen here: the panel asked for one file's contents at a time, base64-decoded them
        // into its own memory and built the archive itself. That meant a selected *directory*
        // contributed nothing — the operator got an archive silently missing it — and panel PHP
        // buffered the site's bytes, which is what the per-vhost identity (A25) exists to stop.
        $res = $broker->call('vhost.files.zip', [$domain], [
            'paths' => $paths,
            'admin_user_id' => (string) auth()->id(),
        ], 300);
        if (! $res->ok) {
            $error = (string) $res->error;
            $status = str_contains(strtolower($error), 'read-only') || str_contains(strtolower($error), 'not available')
                ? 403
                : 422;
            abort($status, $error);
        }

        $tmp = (string) ($res->data['path'] ?? '');
        if ($tmp === '' || ! is_readable($tmp)) {
            abort(500, 'The archive could not be read after it was written.');
        }

        $name = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $domain) . '-files.zip';

        // The archive is removed whether or not the client finished reading: an abandoned
        // download must not leave a copy of someone's site on disk. The broker handed it to
        // the panel user in the panel's own temp dir, so the panel can delete it itself;
        // azerioid:maintenance removes any a dead request left behind.
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
