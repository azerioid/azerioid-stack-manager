<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Projection of a vhost as parsed from the web-server config (A44).
 *
 * Not the source of truth. The config files are, and the broker validates against
 * them; this exists so the panel can query and so later features have somewhere to
 * hang per-vhost records.
 */
class Vhost extends Model
{
    protected $fillable = [
        'domain',
        'engine',
        'type',
        'runtime',
        'php_version',
        'docroot',
        'reverse_proxy',
        'tls_mode',
        'tls_enabled',
        'readonly',
        'enabled',
        'runtime_meta',
        'app_detect',
        'domains',
        'config_path',
        'config_sha256',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'tls_enabled' => 'boolean',
            'readonly' => 'boolean',
            'enabled' => 'boolean',
            'runtime_meta' => 'array',
            'app_detect' => 'array',
            'domains' => 'array',
            'reconciled_at' => 'datetime',
        ];
    }
}
