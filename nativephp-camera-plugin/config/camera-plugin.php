<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Camera Facing Mode
    |--------------------------------------------------------------------------
    | 'user' (front camera) or 'environment' (back camera).
    */
    'facing' => env('CAMERA_FACING', 'environment'),

    /*
    |--------------------------------------------------------------------------
    | Video Resolution
    |--------------------------------------------------------------------------
    | Ideal width and height for the camera stream.
    */
    'width' => (int) env('CAMERA_WIDTH', 1280),
    'height' => (int) env('CAMERA_HEIGHT', 720),

    /*
    |--------------------------------------------------------------------------
    | Audio
    |--------------------------------------------------------------------------
    | Whether to request microphone access alongside the camera.
    */
    'audio' => (bool) env('CAMERA_AUDIO', false),

    /*
    |--------------------------------------------------------------------------
    | Callback URL
    |--------------------------------------------------------------------------
    | Server endpoint to POST permission events to.
    | Leave empty to disable server-side callbacks.
    */
    'callback_url' => env('CAMERA_CALLBACK_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | JavaScript Callbacks
    |--------------------------------------------------------------------------
    | Names of global JS functions to call when permissions are granted/denied.
    */
    'on_granted' => env('CAMERA_ON_GRANTED', ''),
    'on_denied' => env('CAMERA_ON_DENIED', ''),
    'on_permanently_denied' => env('CAMERA_ON_PERMANENTLY_DENIED', ''),
    'on_permission_state' => env('CAMERA_ON_PERMISSION_STATE', ''),

    /*
    |--------------------------------------------------------------------------
    | Container ID
    |--------------------------------------------------------------------------
    | The DOM element ID for the camera preview container.
    */
    'container_id' => env('CAMERA_CONTAINER_ID', 'camera-preview'),

    /*
    |--------------------------------------------------------------------------
    | Auto Start
    |--------------------------------------------------------------------------
    | Automatically start the camera preview once permission is granted.
    */
    'auto_start' => (bool) env('CAMERA_AUTO_START', true),

    /*
    |--------------------------------------------------------------------------
    | Debug Logging
    |--------------------------------------------------------------------------
    | Forward JavaScript console logs to the server log (Android Logcat).
    */
    'debug_logging' => (bool) env('CAMERA_DEBUG_LOGGING', false),
];
