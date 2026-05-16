package com.cameraplugin

/**
 * Configuration for CameraPluginBridge.
 *
 * @param requestAudio       Also request RECORD_AUDIO permission alongside camera.
 * @param phpCallbackUrl     URL of the PHP callback endpoint (forwarded to JS config).
 * @param preferFrontCamera  Start with the front-facing camera (selfie mode).
 * @param debugLogging       Enable verbose Logcat output from the bridge.
 */
data class CameraPluginConfig(
    val requestAudio: Boolean = false,
    val phpCallbackUrl: String = "",
    val preferFrontCamera: Boolean = false,
    val debugLogging: Boolean = false
)
