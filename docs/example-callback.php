<?php

/**
 * camera-callback.php — example endpoint for your application.
 *
 * Copy this file into your project's web root (or route to it),
 * then set 'callbackUrl' in your CameraPlugin constructor.
 *
 * Customise the MyApp_CameraPlugin subclass below to add your own
 * server-side logic (DB logging, analytics, user flags, etc.).
 */

use CameraPlugin\CameraPlugin;

require_once __DIR__ . '/vendor/autoload.php';

session_start();

/**
 * Extend the base plugin to add your own server-side event handlers.
 */
class MyApp_CameraPlugin extends CameraPlugin
{
    protected function onPermissionGranted(array $payload): array
    {
        // Example: log to your database
        // $db->insert('camera_events', [
        //     'user_id'   => $_SESSION['user_id'] ?? null,
        //     'action'    => 'granted',
        //     'platform'  => $payload['platform'] ?? 'web',
        //     'facing'    => $payload['facing'] ?? 'unknown',
        //     'created_at' => date('Y-m-d H:i:s'),
        // ]);

        error_log('[CameraPlugin] Permission granted — platform: ' . ($payload['platform'] ?? 'web'));

        return [
            'status'    => 'ok',
            'message'   => 'Permission recorded',
            'timestamp' => time(),
        ];
    }

    protected function onPermissionDenied(array $payload): array
    {
        error_log('[CameraPlugin] Permission denied — reason: ' . ($payload['reason'] ?? 'unknown'));

        return [
            'status'    => 'denied',
            'reason'    => $payload['reason'] ?? 'unknown',
            'timestamp' => time(),
        ];
    }
}

// Validate against the nonce stored when the page was rendered
$storedNonce = $_SESSION['camera_nonce'] ?? null;

$camera = new MyApp_CameraPlugin();
$camera->handleRequest($storedNonce);
