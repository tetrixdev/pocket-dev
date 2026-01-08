<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class Credential extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'slug',
        'env_var',
        'encrypted_value',
        'description',
        'workspace_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected $hidden = [
        'encrypted_value',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Normalize slug to lowercase with underscores only
        static::creating(function (Credential $credential) {
            if (!empty($credential->slug)) {
                $credential->slug = self::normalizeSlug($credential->slug);
            }
        });

        static::updating(function (Credential $credential) {
            if ($credential->isDirty('slug') && !empty($credential->slug)) {
                $credential->slug = self::normalizeSlug($credential->slug);
            }
        });
    }

    /**
     * Normalize a slug to lowercase alphanumeric with underscores.
     */
    public static function normalizeSlug(string $slug): string
    {
        // Convert to lowercase
        $slug = strtolower($slug);

        // Replace any non-alphanumeric characters (except underscores) with underscores
        $slug = preg_replace('/[^a-z0-9_]/', '_', $slug);

        // Remove consecutive underscores
        $slug = preg_replace('/_+/', '_', $slug);

        // Trim underscores from start and end
        return trim($slug, '_');
    }

    /**
     * Validate if a slug is properly formatted.
     */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_]*[a-z0-9]$|^[a-z0-9]$/', $slug) === 1;
    }

    /**
     * Get the decrypted value.
     */
    public function getValue(): ?string
    {
        if (empty($this->encrypted_value)) {
            return null;
        }

        try {
            return Crypt::decryptString($this->encrypted_value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            // Log or handle decryption failure
            return null;
        }
    }

    /**
     * Set the value (encrypts before storing).
     */
    public function setValue(string $value): self
    {
        $this->encrypted_value = Crypt::encryptString($value);
        return $this;
    }

    /**
     * Accessor for virtual 'value' attribute.
     * Allows accessing decrypted value as $credential->value
     */
    public function getValueAttribute(): ?string
    {
        return $this->getValue();
    }

    /**
     * Mutator for virtual 'value' attribute.
     * Allows setting encrypted value as $credential->value = 'secret'
     */
    public function setValueAttribute(string $value): void
    {
        $this->setValue($value);
    }

    /**
     * Find a credential by its slug.
     */
    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    /**
     * Find a credential by its environment variable name.
     */
    public static function findByEnvVar(string $envVar): ?self
    {
        return static::where('env_var', $envVar)->first();
    }

    /**
     * Get all credentials as an associative array of env_var => decrypted_value.
     * Useful for injecting into environment.
     *
     * @return array<string, string>
     */
    public static function getAllAsEnvArray(): array
    {
        $credentials = [];

        foreach (static::all() as $credential) {
            $value = $credential->getValue();
            if ($value !== null) {
                $credentials[$credential->env_var] = $value;
            }
        }

        return $credentials;
    }

    /**
     * Check if a credential with the given slug exists.
     */
    public static function slugExists(string $slug): bool
    {
        return static::where('slug', $slug)->exists();
    }

    /**
     * Get the workspace this credential belongs to (if not global).
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Scope to global credentials only.
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('workspace_id');
    }

    /**
     * Scope to credentials for a specific workspace.
     */
    public function scopeForWorkspace($query, ?string $workspaceId)
    {
        return $query->where('workspace_id', $workspaceId);
    }

    /**
     * Get all credentials available for a workspace (global + workspace-specific).
     * Workspace-specific credentials take precedence over global ones with the same env_var.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function getForWorkspace(?string $workspaceId): \Illuminate\Database\Eloquent\Collection
    {
        return static::where(function ($query) use ($workspaceId) {
            $query->whereNull('workspace_id'); // Global
            if ($workspaceId) {
                $query->orWhere('workspace_id', $workspaceId); // Workspace-specific
            }
        })->orderBy('env_var')->get();
    }

    /**
     * Get all credentials as an associative array of env_var => decrypted_value.
     * Workspace-specific credentials override global ones with the same env_var.
     *
     * @param string|null $workspaceId Filter to this workspace (null = global only)
     * @return array<string, string>
     */
    public static function getEnvArrayForWorkspace(?string $workspaceId): array
    {
        $credentials = [];

        // First, get all global credentials
        foreach (static::whereNull('workspace_id')->get() as $credential) {
            $value = $credential->getValue();
            if ($value !== null) {
                $credentials[$credential->env_var] = $value;
            }
        }

        // Then, overlay workspace-specific credentials (they take precedence)
        if ($workspaceId) {
            foreach (static::where('workspace_id', $workspaceId)->get() as $credential) {
                $value = $credential->getValue();
                if ($value !== null) {
                    $credentials[$credential->env_var] = $value;
                }
            }
        }

        return $credentials;
    }
}
