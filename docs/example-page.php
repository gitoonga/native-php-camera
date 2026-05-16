<?php

/**
 * example-page.php — minimal integration example.
 *
 * Copy and adapt this into your own page.
 * Assumes you have run: composer require gitoonga/native-php-camera
 */

use CameraPlugin\CameraPlugin;

require_once __DIR__ . '/../vendor/autoload.php';

session_start();

$camera = new CameraPlugin([
    'facing'      => 'environment',           // 'user' = selfie cam
    'callbackUrl' => '/camera-callback.php',  // your callback endpoint
    'onGranted'   => 'onCameraGranted',       // JS function on success
    'onDenied'    => 'onCameraDenied',        // JS function on failure
    'autoStart'   => true,
]);

// Store nonce for CSRF validation in the callback
$_SESSION['camera_nonce'] = $camera->getNonce();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Camera Example</title>

    <!-- Option A: Inline styles (no public path needed) -->
    <?= $camera->renderStyles() ?>

    <!-- Option B: Link to a published CSS file -->
    <!-- <?= $camera->renderStyles('/vendor/camera-plugin/camera-plugin.css') ?> -->
</head>
<body>

    <!-- Camera preview container -->
    <?= $camera->renderContainer() ?>

    <button onclick="window.CameraPlugin.requestPermission()">Open Camera</button>
    <button onclick="window.CameraPlugin.capturePhoto().then(showPhoto)">Capture</button>
    <button onclick="window.CameraPlugin.switchCamera()">Switch</button>
    <button onclick="window.CameraPlugin.stopPreview()">Stop</button>

    <img id="snapshot" src="" alt="" style="display:none; max-width:100%; margin-top:1rem;">

    <!-- JS bridge — place before </body> -->
    <?php $camera->render(); ?>

    <script>
        function onCameraGranted(data)  { console.log('Granted', data); }
        function onCameraDenied(data)   { console.warn('Denied', data); }
        function showPhoto(dataUrl) {
            var img = document.getElementById('snapshot');
            img.src = dataUrl;
            img.style.display = 'block';
        }
    </script>

</body>
</html>
