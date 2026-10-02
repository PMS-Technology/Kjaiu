<?php

namespace App\Services\Admin;

use App\Services\ModuleService;

/**
 * The provisioning module registry.
 *
 * On the original platform a server module is a directory under
 * `public/plugins/servers/<module>/<module>.php`, and the admin console lists
 * whatever is scanned from disk (`get_modules_group`, `servers_add`). This
 * port dispatches through `App\Services\ModuleService`, so the same directory
 * is scanned when it exists and a static list of the modules the drivers
 * support is returned otherwise.
 */
class ModuleRegistry
{
    /**
     * Built-in fallback, matching the modules the original ships in its
     * `plugins/servers` folder. Used when no driver directory is present.
     *
     * @var array<string,string>
     */
    public const BUILT_IN = [
        'bthosts' => 'btHost对接模块',
        'nokvm' => 'NOKVM',
        'proxmoxve' => 'ProxmoxVE LXC',
        'wlkanglepro' => '未来kangle高级对接模块',
        'idcsmartcloud' => '魔方云',
        'dcim' => '魔方DCIM',
        'cpanel' => 'cPanel',
        'directadmin' => 'DirectAdmin',
        'plesk' => 'Plesk',
        'whm' => 'WHM',
        'hyperv' => 'Hyper-V',
        'solusvm' => 'SolusVM',
        'xenserver' => 'XenServer',
        'zjmf_api' => '供应商资源',
        'v10' => 'v10接口',
        'custom' => '自定义接口',
    ];

    /**
     * @return array<int,array{value:string,name:string}>
     */
    public static function modules(): array
    {
        $scanned = self::scan();

        return $scanned !== [] ? $scanned : self::options(self::BUILT_IN);
    }

    /**
     * Module identifiers only.
     *
     * @return array<int,string>
     */
    public static function names(): array
    {
        return array_column(self::modules(), 'value');
    }

    /**
     * Human label for a module identifier.
     */
    public static function label(string $module): string
    {
        foreach (self::modules() as $entry) {
            if ($entry['value'] === $module) {
                return $entry['name'];
            }
        }

        return $module;
    }

    /**
     * Whether a driver file exists for the module.
     */
    public static function exists(string $module): bool
    {
        if ($module === '') {
            return false;
        }

        $service = new ModuleService();

        return $service->hasDriver($module);
    }

    /**
     * Config fields the module declares.
     *
     * The original reads a `config.php` next to the driver; the port derives
     * the same list from the module class's `configOptions()` when present and
     * otherwise returns the conventional connection fields.
     *
     * @return array<int,array{name:string,type:string,title:string,default:mixed}>
     */
    public static function configFields(string $module): array
    {
        $path = base_path('public/plugins/servers/'.basename($module).'/config.php');

        if (is_file($path)) {
            /** @var mixed $config */
            $config = include $path;

            if (is_array($config)) {
                return self::normaliseConfig($config);
            }
        }

        return self::defaultConfigFields($module);
    }

    /**
     * The actions a module exposes, used to enable/disable the per-host power
     * buttons in the business-inner page.
     *
     * @return array<int,string>
     */
    public static function functions(string $module): array
    {
        if ($module === '' || ! self::exists($module)) {
            return [];
        }

        return (new ModuleService())->availableFunctions($module);
    }

    /**
     * @return array<int,array{value:string,name:string}>
     */
    private static function scan(): array
    {
        $root = base_path('public/plugins/servers');
        $out = [];

        if (! is_dir($root)) {
            return [];
        }

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $dir = $root.'/'.$entry;

            if (! is_dir($dir)) {
                continue;
            }

            $info = $dir.'/info.php';
            $name = $entry;

            if (is_file($info)) {
                /** @var mixed $meta */
                $meta = include $info;

                if (is_array($meta) && isset($meta['name'])) {
                    $name = (string) $meta['name'];
                }
            }

            $out[] = ['value' => $entry, 'name' => $name];
        }

        return $out;
    }

    /**
     * @return array<int,array{value:string,name:string}>
     */
    private static function options(array $map): array
    {
        $out = [];

        foreach ($map as $value => $name) {
            $out[] = ['value' => (string) $value, 'name' => $name];
        }

        return $out;
    }

    /**
     * Normalise a `config.php` declaration into the shape the SPA renders.
     */
    private static function normaliseConfig(array $config): array
    {
        $out = [];

        foreach ($config as $key => $field) {
            if (! is_array($field)) {
                continue;
            }

            $out[] = [
                'name' => (string) ($field['name'] ?? $key),
                'type' => (string) ($field['type'] ?? 'text'),
                'title' => (string) ($field['title'] ?? $field['friendlyName'] ?? $key),
                'default' => $field['default'] ?? ($field['value'] ?? ''),
                'options' => $field['options'] ?? [],
                'description' => (string) ($field['description'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The connection fields every driver understands.
     */
    private static function defaultConfigFields(string $module): array
    {
        return [
            ['name' => 'hostname', 'type' => 'text', 'title' => '接口地址', 'default' => '', 'options' => [], 'description' => ''],
            ['name' => 'username', 'type' => 'text', 'title' => '用户名', 'default' => '', 'options' => [], 'description' => ''],
            ['name' => 'password', 'type' => 'password', 'title' => '密码', 'default' => '', 'options' => [], 'description' => ''],
            ['name' => 'port', 'type' => 'text', 'title' => '端口', 'default' => '', 'options' => [], 'description' => ''],
            ['name' => 'secure', 'type' => 'select', 'title' => '启用SSL', 'default' => '0', 'options' => ['0' => '否', '1' => '是'], 'description' => ''],
        ];
    }
}
