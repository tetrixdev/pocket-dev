<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServerConnection extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'workspace_id',
        'name',
        'host',
        'ssh_user',
        'ssh_port',
        'notes',
        'has_vps_setup',
        'vps_setup_mode',
        'has_proxy_nginx',
        'proxy_nginx_version',
        'has_tailscale',
        'tailscale_ip',
        'last_checked_at',
        'last_connection_at',
        'last_connection_error',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'ssh_port' => 'integer',
        'has_vps_setup' => 'boolean',
        'has_proxy_nginx' => 'boolean',
        'has_tailscale' => 'boolean',
        'last_checked_at' => 'datetime',
        'last_connection_at' => 'datetime',
    ];

    /**
     * Get the connection status.
     */
    public function getStatusAttribute(): string
    {
        if ($this->last_connection_error) {
            return 'error';
        }

        if (!$this->last_checked_at) {
            return 'unchecked';
        }

        if ($this->has_vps_setup === false) {
            return 'needs_vps_setup';
        }

        if ($this->has_proxy_nginx === false) {
            return 'needs_proxy';
        }

        return 'ready';
    }

    /**
     * Get the status label for display.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'error' => 'Connection Error',
            'unchecked' => 'Not Checked',
            'needs_vps_setup' => 'Needs VPS Setup',
            'needs_proxy' => 'Needs Proxy Nginx',
            'ready' => 'Ready',
            default => 'Unknown',
        };
    }

    /**
     * Get the status color for display.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'error' => 'red',
            'unchecked' => 'gray',
            'needs_vps_setup' => 'yellow',
            'needs_proxy' => 'yellow',
            'ready' => 'green',
            default => 'gray',
        };
    }

    /**
     * Get the SSH connection string.
     */
    public function getSshConnectionStringAttribute(): string
    {
        $port = $this->ssh_port !== 22 ? " -p {$this->ssh_port}" : '';
        return "{$this->ssh_user}@{$this->host}{$port}";
    }

    /**
     * Check if this server is reachable via Tailscale.
     */
    public function hasTailscaleAccess(): bool
    {
        return $this->has_tailscale && !empty($this->tailscale_ip);
    }

    /**
     * Get the best host to connect to (Tailscale IP if available, otherwise public IP).
     */
    public function getConnectionHostAttribute(): string
    {
        // For private mode servers, prefer Tailscale IP
        if ($this->vps_setup_mode === 'private' && $this->hasTailscaleAccess()) {
            return $this->tailscale_ip;
        }

        return $this->host;
    }

    /**
     * Update connection status after a successful connection.
     */
    public function markConnectionSuccess(): void
    {
        $this->update([
            'last_connection_at' => now(),
            'last_connection_error' => null,
        ]);
    }

    /**
     * Update connection status after a failed connection.
     */
    public function markConnectionFailed(string $error): void
    {
        $this->update([
            'last_connection_error' => substr($error, 0, 255),
        ]);
    }

    /**
     * Update detected software status.
     */
    public function updateDetectedStatus(array $status): void
    {
        $this->update(array_merge($status, [
            'last_checked_at' => now(),
        ]));
    }

    /**
     * Get the workspace this server belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the applications deployed on this server.
     */
    public function applications(): HasMany
    {
        return $this->hasMany(ServerApplication::class);
    }

    /**
     * Find by host within a workspace.
     */
    public static function findByHost(string $host, ?string $workspaceId = null): ?self
    {
        $query = static::where('host', $host);
        if ($workspaceId) {
            $query->where('workspace_id', $workspaceId);
        }
        return $query->first();
    }

    /**
     * Scope to a specific workspace.
     */
    public function scopeForWorkspace($query, string $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to servers that need VPS setup.
     */
    public function scopeNeedsVpsSetup($query)
    {
        return $query->where('has_vps_setup', false);
    }

    /**
     * Scope to servers that need proxy nginx.
     */
    public function scopeNeedsProxyNginx($query)
    {
        return $query->where('has_proxy_nginx', false);
    }

    /**
     * Scope to servers that are ready (have both VPS setup and proxy).
     */
    public function scopeReady($query)
    {
        return $query->where('has_vps_setup', true)
                     ->where('has_proxy_nginx', true);
    }
}
