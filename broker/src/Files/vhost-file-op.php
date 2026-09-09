#!/usr/bin/env php
<?php
/**
 * Privileged helper: broker (root) invokes this, then we drop to the vhost
 * user before any file I/O. Do not invoke this from the panel FPM process.
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use AzerioidPanel\Broker\Files\VhostFileException;
use AzerioidPanel\Broker\Files\VhostFileOp;
use AzerioidPanel\Broker\Files\VhostPath;

// PREFIX/src is root-only (chmod go-rwx). Load every class we need before
// setuid — autoload cannot open these files after the drop.
class_exists(VhostPath::class);
class_exists(VhostFileException::class);
class_exists(VhostFileOp::class);

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'Empty request.', 'code' => 2]) . "\n");
    exit(2);
}
$req = json_decode($raw, true);
if (!is_array($req)) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'Request must be JSON.', 'code' => 2]) . "\n");
    exit(2);
}

try {
    if (!function_exists('posix_getpwnam') || !function_exists('posix_setuid')) {
        throw new VhostFileException('PHP posix extension is required (install php-process on EL).', 1);
    }
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        throw new VhostFileException('File manager helper must start as root.', 3);
    }
    $user = trim((string) ($req['drop_user'] ?? ''));
    if ($user === '') {
        throw new VhostFileException('drop_user is required.', 2);
    }
    dropToUser($user);
    $data = VhostFileOp::execute($req);
    fwrite(STDOUT, json_encode(['ok' => true, 'data' => $data, 'error' => null, 'code' => 0], JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
} catch (VhostFileException $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => $e->getMessage(), 'code' => $e->errorCode], JSON_UNESCAPED_SLASHES) . "\n");
    exit($e->errorCode > 0 ? $e->errorCode : 1);
} catch (Throwable $e) {
    fwrite(STDOUT, json_encode(['ok' => false, 'error' => 'File operation failed.', 'code' => 1], JSON_UNESCAPED_SLASHES) . "\n");
    exit(1);
}

function dropToUser(string $username): void
{
    if ($username === 'root' || $username === '' || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $username)) {
        throw new VhostFileException('Invalid drop user.', 2);
    }
    $pw = posix_getpwnam($username);
    if ($pw === false) {
        throw new VhostFileException('Vhost user does not exist.', 3);
    }
    $uid = (int) $pw['uid'];
    $gid = (int) $pw['gid'];
    if ($uid === 0) {
        throw new VhostFileException('Refusing to drop to root.', 3);
    }
    if (function_exists('posix_initgroups')) {
        @posix_initgroups($username, $gid);
    }
    if (!posix_setgid($gid) || !posix_setuid($uid)) {
        throw new VhostFileException('Unable to drop privileges to the vhost user.', 1);
    }
    if (posix_geteuid() !== $uid || posix_getegid() !== $gid) {
        throw new VhostFileException('Privilege drop did not take effect.', 1);
    }
    // Clear root-only env; keep PATH for nothing — we do not exec further.
    putenv('HOME=' . ($pw['dir'] ?? '/'));
}
