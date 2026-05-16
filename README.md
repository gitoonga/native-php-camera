# gitoonga/native-php-camera

A [NativePHP Mobile](https://nativephp.com/docs/mobile) camera plugin with a JavaScript bridge and Kotlin Android WebView bridge for native camera permission handling.

---

## Installation

```bash
composer require gitoonga/native-php-camera
```

### 2. Register the plugin with NativePHP

```bash
php artisan native:plugin:register gitoonga/native-php-camera
```

This adds the service provider to `app/Providers/NativeServiceProvider.php`:

```php
public function plugins(): array
{
    return [
        \CameraPlugin\CameraPluginServiceProvider::class,
    ];
}
```

### 3. Rebuild the app

```bash
php artisan native:run android
```

### 4. Publish config and assets (optional)

```bash
php artisan vendor:publish --tag=camera-plugin-config
php artisan vendor:publish --tag=camera-plugin-assets
```

Requires **PHP 8.2+**, **Laravel 10/11/12/13**, and **NativePHP Mobile 3.0+**.  
Camera access requires **HTTPS** in production (`http://localhost` works locally).

---

## Laravel Setup

Auto-discovery is supported. After installation, optionally publish the config and assets:

```bash
php artisan vendor:publish --tag=camera-plugin-config
php artisan vendor:publish --tag=camera-plugin-assets
```

The package registers a `CameraPlugin` facade:

```php
use CameraPlugin\Facades\CameraPlugin;

// In a controller:
CameraPlugin::renderStyles();
CameraPlugin::renderContainer();
CameraPlugin::render();
```

To resolve the underlying instance via the container:

```php
$camera = app('camera-plugin');
// or
$camera = app(\CameraPlugin\CameraPlugin::class);
```

> **Tip:** Override the default configuration by publishing the config file (`config/camera-plugin.php`) and editing it, or use the `.env` variables listed in the config.

---

## Quick Start

```php
use CameraPlugin\CameraPlugin;

session_start();

$camera = new CameraPlugin([
    'facing'      => 'environment',           // or 'user' for front cam
    'callbackUrl' => '/camera-callback.php',  // receives permission events
    'onGranted'   => 'onCameraGranted',       // global JS function
    'onDenied'    => 'onCameraDenied',
]);

// Save nonce to session for CSRF validation
$_SESSION['camera_nonce'] = $camera->getNonce();
```

In your HTML `<body>`:

```php
<?= $camera->renderStyles() ?>        <!-- inline CSS, or pass a public URL -->
<?= $camera->renderContainer() ?>     <!-- <div id="camera-preview"> ... </div> -->
```

Before `</body>`:

```php
<?php $camera->render(); ?>           <!-- injects JS bridge with config baked in -->
```

From JavaScript:

```js
window.CameraPlugin.requestPermission();           // opens permission dialog
window.CameraPlugin.capturePhoto().then(dataUrl => ...); // base64 JPEG
window.CameraPlugin.switchCamera();                // toggle front/back
window.CameraPlugin.stopPreview();                 // release hardware
window.CameraPlugin.checkPermissionState();        // 'granted'|'denied'|'prompt'
```

---

## Constructor Options

| Option                | Type    | Default           | Description                                           |
|-----------------------|---------|-------------------|-------------------------------------------------------|
| `facing`              | string  | `'environment'`   | `'user'` (front) or `'environment'` (back)            |
| `width`               | int     | `1280`            | Ideal video width px                                  |
| `height`              | int     | `720`             | Ideal video height px                                 |
| `audio`               | bool    | `false`           | Also request microphone access                        |
| `callbackUrl`         | string  | `''`              | Server endpoint to POST permission events             |
| `onGranted`           | string  | `''`              | Global JS function called when permission is granted  |
| `onDenied`            | string  | `''`              | Global JS function called when permission is denied   |
| `onPermanentlyDenied` | string  | `''`              | JS function for Android "Don't ask again"             |
| `containerId`         | string  | `'camera-preview'`| DOM element ID for the video container               |
| `autoStart`           | bool    | `true`            | Auto-start preview on grant                           |
| `debugLogging`        | bool    | `false`           | Forward JS logs to Android Logcat                     |
| `nonce`               | string  | auto              | CSRF nonce (auto-generated; retrieve via `getNonce()`) |

---

## PHP API

| Method | Description |
|---|---|
| `render(bool $echo = true): string` | Outputs the JS bridge `<script>` block |
| `renderContainer(string $extraClasses = ''): string` | Returns the video container HTML |
| `renderStyles(?string $publicUrl = null): string` | Returns `<style>` block or `<link>` tag |
| `getConfig(): string` | Returns JSON configuration |
| `getNonce(): string` | Returns the CSRF nonce for session storage |
| `getResourcePath(string $relative): string` | Returns absolute path to a bundled resource file |
| `handleRequest(?string $storedNonce): void` | Handles AJAX POST from the JS bridge; outputs JSON + exits |

---

## Server-Side Event Hooks

Extend `CameraPlugin` and override these methods to add your own logic:

```php
use CameraPlugin\CameraPlugin;

class MyCameraPlugin extends CameraPlugin
{
    protected function onPermissionGranted(array $payload): array
    {
        // $payload contains: action, nonce, platform, facing, etc.
        // Log to DB, fire analytics event, update user profile ...
        return ['status' => 'ok', 'timestamp' => time()];
    }

    protected function onPermissionDenied(array $payload): array
    {
        return ['status' => 'denied', 'reason' => $payload['reason'] ?? 'unknown'];
    }
}
```

Then use your subclass everywhere:

```php
$camera = new MyCameraPlugin(['callbackUrl' => '/camera-callback.php']);
```

---

## Callback Endpoint

Create `camera-callback.php` in your web root:

```php
use CameraPlugin\CameraPlugin;
require_once __DIR__ . '/vendor/autoload.php';

session_start();

$camera = new CameraPlugin();
$camera->handleRequest($_SESSION['camera_nonce'] ?? null);
```

---

## Publishing CSS to a Public Path

By default `renderStyles()` inlines the CSS. To serve it as a static file:

```php
// In a setup/install script:
copy(
    $camera->getResourcePath('css/camera-plugin.css'),
    __DIR__ . '/public/vendor/camera-plugin.css'
);

// In your view:
echo $camera->renderStyles('/vendor/camera-plugin.css');
```

---

## Android / Kotlin WebView Bridge

The package ships a Kotlin bridge in `resources/android/`. The bridge exposes native Android camera permission APIs to the JS layer via `window.Android.*`:

### Bridge Components

| File | Description |
|---|---|
| `CameraPluginBridge.kt` | Main bridge — attaches to WebView, handles permission requests, routes JS calls to Android APIs |
| `CameraPluginConfig.kt` | Data class for bridge configuration |
| `CameraPermissionListener.kt` | Optional interface for native permission callbacks |
| `CameraActivity.kt` | Example Activity showing full WebView + bridge setup |

### How it works

When running inside a NativePHP Android WebView, the JS bridge (`camera-bridge.js`) detects the native layer and routes permission requests through `window.Android.*` instead of the browser's `getUserMedia` dialog:

```js
window.Android.requestCameraPermission();   // native dialog
window.Android.checkPermissionState();      // no prompt, returns state
window.Android.openAppSettings();           // "don't ask again" fallback
window.Android.getDeviceInfo();             // camera hardware info
```

The Kotlin bridge calls back into JS via `window.CameraPlugin.onNativePermissionGranted`, `onNativePermissionDenied`, etc.

### Permissions

Declared automatically via `nativephp.json`:
- `android.permission.CAMERA`
- `android.permission.INTERNET`

Audio (`RECORD_AUDIO`) is requested dynamically when `config.audio = true`.

### Verifying the installation

```bash
php artisan native:plugin:list
php artisan native:plugin:validate
```

---

## CSRF Protection

```php
session_start();
$camera = new CameraPlugin([...]);
$_SESSION['camera_nonce'] = $camera->getNonce();  // store on page render

// In camera-callback.php:
$camera->handleRequest($_SESSION['camera_nonce']); // validated automatically
```

---

## Running Tests

```bash
composer install
composer test
```

---

## Requirements

- PHP 8.2+
- Laravel 10 / 11 / 12 / 13
- NativePHP Mobile 3.0+
- HTTPS in production (browsers block `getUserMedia` over HTTP)
- `http://localhost` works for local development without HTTPS
