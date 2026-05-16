<?php

declare(strict_types=1);

namespace CameraPlugin;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class CameraPluginServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/camera-plugin.php' => config_path('camera-plugin.php'),
        ], 'camera-plugin-config');

        $this->publishes([
            __DIR__ . '/../resources' => public_path('vendor/camera-plugin'),
        ], 'camera-plugin-assets');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/camera-plugin.php',
            'camera-plugin'
        );

        $this->app->singleton(CameraPlugin::class, function () {
            $camelConfig = $this->mapConfig(config('camera-plugin', []));
            return new CameraPlugin($camelConfig);
        });

        $this->app->alias(CameraPlugin::class, 'camera-plugin');
    }

    private function mapConfig(array $config): array
    {
        $mapped = [];
        foreach ($config as $key => $value) {
            $mapped[Str::camel($key)] = $value;
        }
        return $mapped;
    }
}
