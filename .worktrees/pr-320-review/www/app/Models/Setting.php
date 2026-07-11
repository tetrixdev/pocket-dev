<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['key', 'value'];

    /**
     * Get a setting value by key.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        if (!$setting) {
            return $default;
        }

        // Try to decode JSON, otherwise return raw value
        $decoded = json_decode($setting->value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $setting->value;
    }

    /**
     * Set a setting value.
     */
    public static function set(string $key, mixed $value): void
    {
        $storedValue = is_array($value) || is_object($value)
            ? json_encode($value)
            : (string) $value;

        static::updateOrCreate(
            ['key' => $key],
            ['value' => $storedValue]
        );
    }

    /**
     * Get multiple settings at once.
     */
    public static function getMany(array $keys, array $defaults = []): array
    {
        $settings = static::whereIn('key', $keys)->pluck('value', 'key');

        $result = [];
        foreach ($keys as $key) {
            if ($settings->has($key)) {
                $value = $settings->get($key);
                $decoded = json_decode($value, true);
                $result[$key] = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
            } else {
                $result[$key] = $defaults[$key] ?? null;
            }
        }

        return $result;
    }

    /**
     * Set multiple settings at once.
     */
    public static function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            static::set($key, $value);
        }
    }
}
