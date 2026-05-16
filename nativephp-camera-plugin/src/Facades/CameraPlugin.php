<?php

declare(strict_types=1);

namespace CameraPlugin\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getConfig()
 * @method static string getNonce()
 * @method static string renderContainer(string $extraClasses = '')
 * @method static string renderStyles(?string $publicUrl = null)
 * @method static string render(bool $echo = true)
 * @method static void handleRequest(?string $storedNonce = null)
 * @method static string getResourcePath(string $relative)
 */
class CameraPlugin extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'camera-plugin';
    }
}
