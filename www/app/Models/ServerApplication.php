<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class ServerApplication extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'workspace_id',
        'server_connection_id',
        'name',
        'slug',
        'description',
        'deploy_path',
        'compose_content',
        'env_content',
        'domains',
        'upstream_container',
        'ssl_enabled',
        'ssl_expires_at',
        'status',
        'last_error',
        'last_deployed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'domains' => 'array',
        'ssl_enabled' => 'boolean',
        'ssl_expires_at' => 'datetime',
        'last_deployed_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'env_content',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate slug from name if not provided
        static::creating(function (ServerApplication $app) {
            if (empty($app->slug)) {
                $app->slug = Str::slug($app->name);
            }
        });
    }

    /**
     * Get the workspace this application belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Get the server this application is deployed on.
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(ServerConnection::class, 'server_connection_id');
    }

    /**
     * Get the decrypted .env content.
     */
    public function getEnvDecrypted(): ?string
    {
        if (empty($this->env_content)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->env_content);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            \Log::warning('ServerApplication env decryption failed', [
                'app_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Set the .env content (encrypts before storing).
     */
    public function setEnvContent(string $content): self
    {
        $this->env_content = Crypt::encryptString($content);
        return $this;
    }

    /**
     * Get the primary domain (first in the list).
     */
    public function getPrimaryDomainAttribute(): ?string
    {
        $domains = $this->domains;
        return $domains[0] ?? null;
    }

    /**
     * Check if SSL is expiring soon (within 14 days).
     */
    public function isSslExpiringSoon(): bool
    {
        if (!$this->ssl_enabled || !$this->ssl_expires_at) {
            return false;
        }

        return $this->ssl_expires_at->isBefore(now()->addDays(14));
    }

    /**
     * Check if SSL is expired.
     */
    public function isSslExpired(): bool
    {
        if (!$this->ssl_enabled || !$this->ssl_expires_at) {
            return false;
        }

        return $this->ssl_expires_at->isPast();
    }

    /**
     * Get the status label for display.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'stopped' => 'Stopped',
            'running' => 'Running',
            'error' => 'Error',
            'deploying' => 'Deploying...',
            default => 'Unknown',
        };
    }

    /**
     * Get the status color for display.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'stopped' => 'gray',
            'running' => 'green',
            'error' => 'red',
            'deploying' => 'yellow',
            default => 'gray',
        };
    }

    /**
     * Get full deploy path on the server.
     */
    public function getFullDeployPathAttribute(): string
    {
        return $this->deploy_path ?: "/home/admin/docker-apps/{$this->slug}";
    }

    /**
     * Mark as deploying.
     */
    public function markDeploying(): void
    {
        $this->update([
            'status' => 'deploying',
            'last_error' => null,
        ]);
    }

    /**
     * Mark deployment as successful.
     */
    public function markDeployed(): void
    {
        $this->update([
            'status' => 'running',
            'last_error' => null,
            'last_deployed_at' => now(),
        ]);
    }

    /**
     * Mark deployment as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'error',
            'last_error' => $error,
        ]);
    }

    /**
     * Scope to a specific workspace.
     */
    public function scopeForWorkspace($query, string $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Scope to a specific server.
     */
    public function scopeOnServer($query, string $serverId)
    {
        return $query->where('server_connection_id', $serverId);
    }

    /**
     * Scope to running applications.
     */
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope to applications with SSL issues.
     */
    public function scopeWithSslIssues($query)
    {
        return $query->where('ssl_enabled', true)
            ->where(function ($q) {
                $q->whereNull('ssl_expires_at')
                  ->orWhere('ssl_expires_at', '<', now()->addDays(14));
            });
    }
}
