<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use App\Models\Workspace;

/**
 * System packages are CLI tools/libraries installed in the container.
 * Managed by AI via artisan commands; users can view and delete via UI.
 */
class SystemPackage extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = ['name', 'install_script', 'status', 'status_message', 'installed_at'];

    protected $casts = [
        'installed_at' => 'datetime',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_FAILED = 'failed';

    /**
     * Get all package names (for container installation).
     * All packages are installed globally regardless of workspace selection.
     */
    public static function getAllNames(): array
    {
        return static::pluck('name')->unique()->toArray();
    }

    /**
     * Get all packages with their install scripts (for container entrypoint).
     * Returns array of ['id' => uuid, 'name' => name, 'script' => install_script]
     */
    public static function getAllWithScripts(): array
    {
        return static::select('id', 'name', 'install_script')
            ->get()
            ->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'script' => $p->install_script,
            ])
            ->toArray();
    }

    /**
     * Update package installation status by ID.
     */
    public static function updateStatusById(string $id, string $status, ?string $message = null): bool
    {
        $package = static::find($id);
        if (!$package) {
            return false;
        }

        $package->status = $status;
        $package->status_message = $message;
        if ($status === self::STATUS_INSTALLED) {
            $package->installed_at = now();
        }
        return $package->save();
    }

    /**
     * Get package names visible to a workspace.
     * If workspace has selected_packages, only return those.
     * If no selection (null/empty), return all packages.
     *
     * @param Workspace|null $workspace
     * @return array<string>
     */
    public static function getNamesForWorkspace(?Workspace $workspace): array
    {
        $allPackages = static::pluck('name')->toArray();

        if ($workspace === null) {
            return $allPackages;
        }

        $selectedPackages = $workspace->selected_packages;

        // If no selection, show all
        if (empty($selectedPackages)) {
            return $allPackages;
        }

        // Filter to only selected packages that actually exist
        return array_values(array_intersect($selectedPackages, $allPackages));
    }

    /**
     * Check if a package exists.
     */
    public static function packageExists(string $name): bool
    {
        return static::where('name', $name)->exists();
    }

    /**
     * Update package installation status.
     */
    public static function updateStatus(string $name, string $status, ?string $message = null): bool
    {
        $package = static::where('name', $name)->first();
        if (!$package) {
            return false;
        }

        $package->status = $status;
        $package->status_message = $message;
        if ($status === self::STATUS_INSTALLED) {
            $package->installed_at = now();
        }
        return $package->save();
    }

    /**
     * Check if package is installed.
     */
    public function isInstalled(): bool
    {
        return $this->status === self::STATUS_INSTALLED;
    }

    /**
     * Check if package installation failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if package is pending installation.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
