<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A container on a worker server that the controller's proxy routes to. The worker publishes the
 * container ports on its internal IP (`ports` maps container port => host port), and the controller
 * runs a relay with the container's proxy `labels` that forwards to those host ports.
 *
 * A route is inactive until its container is live, so a rolling update keeps traffic on the old
 * container until the new one passed its health check.
 */
class ControllerIngressRoute extends Model
{
    protected $fillable = [
        'server_id',
        'resource_key',
        'service_name',
        'container_name',
        'resource_type',
        'resource_id',
        'ports',
        'labels',
        'is_active',
    ];

    protected $casts = [
        'ports' => 'array',
        'labels' => 'array',
        'is_active' => 'boolean',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function resource(): MorphTo
    {
        return $this->morphTo();
    }
}
