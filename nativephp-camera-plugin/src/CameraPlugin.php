<?php

declare(strict_types=1);

namespace CameraPlugin;

/**
 * CameraPlugin — Native PHP Camera Access Plugin
 *
 * Composer package: gitoonga/native-php-camera
 *
 * Usage (after composer require):
 *
 *   use CameraPlugin\CameraPlugin;
 *
 *   $camera = new CameraPlugin([
 *       'facing'      => 'environment',
 *       'callbackUrl' => '/camera-callback.php',
 *       'onGranted'   => 'onCameraGranted',
 *       'onDenied'    => 'onCameraDenied',
 *   ]);
 *
 *   // In your HTML <body>:
 *   echo $camera->renderContainer();
 *
 *   // Before </body>:
 *   $camera->render();
 */
class CameraPlugin
{
    private const VERSION = '1.0.0';

    /** Absolute path to the package resources/ directory. */
    private const RESOURCES_DIR = __DIR__ . '/../resources';

    private array $config;

    /**
     * @param array $options {
     *   @type string  $facing         'user' (front) | 'environment' (back). Default: 'environment'
     *   @type int     $width          Ideal video width px. Default: 1280
     *   @type int     $height         Ideal video height px. Default: 720
     *   @type bool    $audio          Request mic alongside camera. Default: false
     *   @type string  $callbackUrl    Server endpoint to POST permission events. Default: ''
     *   @type string  $onGranted      Global JS function name called on grant. Default: ''
     *   @type string  $onDenied       Global JS function name called on denial. Default: ''
     *   @type string  $onPermanentlyDenied  JS function name for "Don't ask again". Default: ''
     *   @type string  $containerId    DOM element ID for video container. Default: 'camera-preview'
     *   @type bool    $autoStart      Auto-start preview on grant. Default: true
     *   @type bool    $debugLogging   Forward JS logs to Logcat (Android). Default: false
     *   @type string  $nonce          CSRF nonce. Auto-generated if empty.
     * }
     */
    public function __construct(array $options = [])
    {
        $this->config = array_merge([
            'facing'              => 'environment',
            'width'               => 1280,
            'height'              => 720,
            'audio'               => false,
            'callbackUrl'         => '',
            'onGranted'           => '',
            'onDenied'            => '',
            'onPermanentlyDenied' => '',
            'onPermissionState'   => '',
            'containerId'         => 'camera-preview',
            'autoStart'           => true,
            'debugLogging'        => false,
            'nonce'               => $this->generateNonce(),
        ], $options);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns the plugin config as a JSON string.
     */
    public function getConfig(): string
    {
        return json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Returns the CSRF nonce. Store in your session immediately after construction:
     *   $_SESSION['camera_nonce'] = $camera->getNonce();
     */
    public function getNonce(): string
    {
        return $this->config['nonce'];
    }

    /**
     * Renders the HTML video container. Place this where the camera preview should appear.
     */
    public function renderContainer(string $extraClasses = ''): string
    {
        $id      = htmlspecialchars($this->config['containerId'], ENT_QUOTES, 'UTF-8');
        $classes = trim('camera-plugin-container ' . $extraClasses);

        return <<<HTML
<div id="{$id}" class="{$classes}" aria-label="Camera Preview" role="region">
    <video id="{$id}-video"
           class="camera-plugin-video"
           playsinline
           autoplay
           muted
           aria-label="Live camera feed"></video>
    <canvas id="{$id}-canvas" class="camera-plugin-canvas" style="display:none;"></canvas>
    <div id="{$id}-overlay" class="camera-plugin-overlay" aria-live="polite"></div>
</div>
HTML;
    }

    /**
     * Renders a <link> tag for the bundled CSS.
     * Pass a public URL if you publish resources to a web-accessible path.
     *
     * @param string|null $publicUrl  e.g. '/vendor/camera-plugin/camera-plugin.css'
     */
    public function renderStyles(?string $publicUrl = null): string
    {
        if ($publicUrl) {
            $url = htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8');
            return "<link rel=\"stylesheet\" href=\"{$url}\">\n";
        }

        // Inline the CSS (no public URL needed)
        $css = $this->readResource('css/camera-plugin.css');
        return "<style>\n{$css}\n</style>\n";
    }

    /**
     * Renders the JS bridge <script> block. Place before </body>.
     *
     * @param bool $echo  Echo output directly (default) or return as string.
     */
    public function render(bool $echo = true): string
    {
        $config   = htmlspecialchars($this->getConfig(), ENT_QUOTES, 'UTF-8');
        $jsBridge = $this->readResource('js/camera-bridge.js');
        $version  = self::VERSION;

        $output = <<<HTML
<!-- CameraPlugin v{$version} -->
<script>
(function() {
    var _CameraPluginConfig = {$config};
    {$jsBridge}
    window.CameraPlugin = new _CameraPluginBridge(_CameraPluginConfig);
})();
</script>
HTML;

        if ($echo) {
            echo $output;
        }

        return $output;
    }

    /**
     * Handles AJAX POST requests from the JS bridge.
     * Call this in your callback endpoint and it will output JSON + exit.
     *
     * @param string|null $storedNonce  Nonce from your session. Null skips validation.
     */
    public function handleRequest(?string $storedNonce = null): void
    {
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            exit;
        }

        $raw  = file_get_contents('php://input');
        $body = json_decode($raw ?: '{}', true) ?? [];

        if ($storedNonce !== null) {
            $incoming = $body['nonce'] ?? '';
            if (!is_string($incoming) || !hash_equals($storedNonce, $incoming)) {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid or missing nonce']);
                exit;
            }
        }

        $action   = $body['action'] ?? '';
        $response = match ($action) {
            'permission_granted' => $this->onPermissionGranted($body),
            'permission_denied'  => $this->onPermissionDenied($body),
            'permission_check'   => $this->onPermissionCheck($body),
            default              => (function () {
                http_response_code(400);
                return ['error' => 'Unknown action'];
            })(),
        };

        echo json_encode($response);
        exit;
    }

    /**
     * Returns the absolute filesystem path to a bundled resource.
     * Useful if you want to copy assets to your public directory:
     *
     *   copy($camera->getResourcePath('css/camera-plugin.css'), public_path('vendor/camera-plugin.css'));
     */
    public function getResourcePath(string $relative): string
    {
        return rtrim(self::RESOURCES_DIR, '/') . '/' . ltrim($relative, '/');
    }

    // -------------------------------------------------------------------------
    // Server-side event hooks — override in a subclass
    // -------------------------------------------------------------------------

    protected function onPermissionGranted(array $payload): array
    {
        return [
            'status'    => 'ok',
            'message'   => 'Camera permission granted',
            'timestamp' => time(),
        ];
    }

    protected function onPermissionDenied(array $payload): array
    {
        return [
            'status'    => 'denied',
            'message'   => 'Camera permission denied',
            'reason'    => $payload['reason'] ?? 'unknown',
            'timestamp' => time(),
        ];
    }

    protected function onPermissionCheck(array $payload): array
    {
        return [
            'status'    => 'ok',
            'supported' => true,
            'timestamp' => time(),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function readResource(string $relative): string
    {
        $path = $this->getResourcePath($relative);

        if (!file_exists($path)) {
            throw new \RuntimeException(
                "CameraPlugin resource not found: {$path}. " .
                "Run `composer install` to ensure all package files are present."
            );
        }

        return file_get_contents($path);
    }
}
