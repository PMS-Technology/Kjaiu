<?php

namespace App\Models;

/**
 * Key/value site settings (`shd_configuration`), read by both the client area
 * and the administrator panel.
 */
class Configuration extends ShdModel
{
    protected $table = 'configuration';

    protected $primaryKey = 'setting';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'create_time' => 'integer',
        'update_time' => 'integer',
    ];

    /**
     * Read one setting value.
     */
    public static function value(string $setting, mixed $default = null): mixed
    {
        $value = static::query()->where('setting', $setting)->value('value');

        return $value === null ? $default : $value;
    }

    /**
     * Read one setting, JSON-decoded when the payload is JSON.
     */
    public static function json(string $setting, mixed $default = null): mixed
    {
        $value = static::value($setting);

        if ($value === null || $value === '') {
            return $default;
        }

        $decoded = json_decode((string) $value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    /**
     * Write one setting, inserting it when absent.
     */
    public static function put(string $setting, mixed $value): void
    {
        $now = time();
        $encoded = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        $existing = static::query()->where('setting', $setting)->first();

        if ($existing !== null) {
            $existing->value = $encoded;
            $existing->update_time = $now;
            $existing->save();

            return;
        }

        static::query()->insert([
            'setting' => $setting,
            'value' => $encoded,
            'create_time' => $now,
            'update_time' => 0,
        ]);
    }

    /**
     * Flush the cached settings map.
     */
    public static function flushCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget('kjaiu.configuration');
    }

    /**
     * All settings as an associative array, cached for the request lifecycle.
     */
    public static function all_map(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('kjaiu.configuration', 300, function () {
            return static::query()->pluck('value', 'setting')->all();
        });
    }
}
