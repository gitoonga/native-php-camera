package com.cameraplugin

import android.Manifest
import android.app.Activity
import android.content.Context
import android.content.pm.PackageManager
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.WebChromeClient
import android.webkit.WebView
import androidx.core.app.ActivityCompat
import androidx.core.content.getSystemService
import androidx.core.content.ContextCompat
import org.json.JSONObject

/**
 * CameraPluginBridge
 *
 * Kotlin-native bridge between Android and the PHP/JS CameraPlugin.
 * Inject this into a WebView to allow the JS layer to call native
 * Android camera permission APIs directly.
 *
 * Usage:
 *   val bridge = CameraPluginBridge(activity, webView)
 *   bridge.attach()
 *
 * The JS layer calls:
 *   Android.requestCameraPermission()
 *   Android.checkPermissionState()
 *   Android.getDeviceInfo()
 */
class CameraPluginBridge(
    private val activity: Activity,
    private val webView: WebView,
    private val config: CameraPluginConfig = CameraPluginConfig(),
    private val listener: CameraPermissionListener? = null
) {

    companion object {
        const val JS_INTERFACE_NAME = "Android"
        private const val PREFS_KEY = "camera_plugin_prefs"
        private const val PREF_PERMISSION_ASKED = "permission_asked"
    }

    private val prefs by lazy {
        activity.getSharedPreferences(PREFS_KEY, Context.MODE_PRIVATE)
    }

    // -------------------------------------------------------------------------
    // Setup
    // -------------------------------------------------------------------------

    /**
     * Attach this bridge to the WebView. Call once after WebView is created.
     */
    fun attach() {
        webView.addJavascriptInterface(this, JS_INTERFACE_NAME)
    }

    /**
     * Detach the bridge (call in onDestroy to avoid leaks).
     */
    fun detach() {
        webView.removeJavascriptInterface(JS_INTERFACE_NAME)
    }

    /**
     * Returns a WebChromeClient that auto-grants WebView-level camera/mic
     * permissions (RESOURCE_VIDEO_CAPTURE / RESOURCE_AUDIO_CAPTURE) for
     * getUserMedia calls from JS (e.g. html5-qrcode).
     *
     * Optionally wraps an existing [base] WebChromeClient so that other
     * chrome callbacks (JS alerts, etc.) are preserved.
     *
     * Usage:
     *   webView.webChromeClient = bridge.createCameraAwareChromeClient()
     *
     *   // Or wrap an existing client:
     *   webView.webChromeClient = bridge.createCameraAwareChromeClient(myChromeClient)
     */
    fun createCameraAwareChromeClient(base: WebChromeClient? = null): WebChromeClient {
        return object : WebChromeClient() {
            override fun onPermissionRequest(request: PermissionRequest) {
                val allowed = request.resources.filter {
                    it == PermissionRequest.RESOURCE_VIDEO_CAPTURE ||
                    it == PermissionRequest.RESOURCE_AUDIO_CAPTURE
                }.toTypedArray()
                if (allowed.isNotEmpty()) {
                    request.grant(allowed)
                } else {
                    base?.onPermissionRequest(request) ?: request.deny()
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // JS Interface — callable from the JS layer as Android.*
    // -------------------------------------------------------------------------

    /**
     * Called from JS: Android.requestCameraPermission()
     * Triggers the native Android permission dialog if needed,
     * then resolves back to JS via a callback.
     */
    @JavascriptInterface
    fun requestCameraPermission() {
        activity.runOnUiThread {
            when {
                hasPermission() -> {
                    onPermissionResult(granted = true, fromCache = true)
                }
                shouldShowRationale() -> {
                    // User previously denied — notify JS so it can show explanation UI
                    notifyJS("onNativePermissionRationale", JSONObject().apply {
                        put("message", "Camera access is needed. Please allow it in the next dialog.")
                    })
                    requestNativePermission()
                }
                else -> {
                    requestNativePermission()
                }
            }
        }
    }

    /**
     * Called from JS: Android.checkPermissionState()
     * Returns current state without triggering a dialog.
     * JS receives a callback: onNativePermissionState({ state: "granted"|"denied"|"prompt" })
     */
    @JavascriptInterface
    fun checkPermissionState() {
        val state = when {
            hasPermission() -> "granted"
            wasAlreadyAsked() && !shouldShowRationale() -> "denied_permanently"
            else -> "prompt"
        }
        notifyJS("onNativePermissionState", JSONObject().apply {
            put("state", state)
        })
    }

    /**
     * Called from JS: Android.getDeviceInfo()
     * Returns native device info helpful for camera constraints.
     */
    @JavascriptInterface
    fun getDeviceInfo(): String {
        return JSONObject().apply {
            put("platform",      "android")
            put("sdkVersion",    android.os.Build.VERSION.SDK_INT)
            put("manufacturer",  android.os.Build.MANUFACTURER)
            put("model",         android.os.Build.MODEL)
            put("hasFrontCamera", hasCameraFacing(android.hardware.camera2.CameraCharacteristics.LENS_FACING_FRONT))
            put("hasBackCamera",  hasCameraFacing(android.hardware.camera2.CameraCharacteristics.LENS_FACING_BACK))
        }.toString()
    }

    /**
     * Called from JS: Android.openAppSettings()
     * Opens the app's system settings page (useful when permission is permanently denied).
     */
    @JavascriptInterface
    fun openAppSettings() {
        activity.runOnUiThread {
            val intent = android.content.Intent(
                android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                android.net.Uri.fromParts("package", activity.packageName, null)
            )
            activity.startActivity(intent)
        }
    }

    /**
     * Called from JS: Android.log(message)
     * Routes JS log messages to Android Logcat for debugging.
     */
    @JavascriptInterface
    fun log(message: String) {
        android.util.Log.d("CameraPlugin[JS]", message)
    }

    // -------------------------------------------------------------------------
    // Called by Activity.onRequestPermissionsResult — wire this up in your Activity
    // -------------------------------------------------------------------------

    /**
     * Forward Android permission results to this bridge from your Activity:
     *
     *   override fun onRequestPermissionsResult(requestCode, permissions, grantResults) {
     *       super.onRequestPermissionsResult(requestCode, permissions, grantResults)
     *       bridge.onRequestPermissionsResult(requestCode, permissions, grantResults)
     *   }
     */
    fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        if (requestCode != config.requestCode) return

        val granted = grantResults.isNotEmpty() &&
                grantResults[0] == PackageManager.PERMISSION_GRANTED

        prefs.edit().putBoolean(PREF_PERMISSION_ASKED, true).apply()
        onPermissionResult(granted = granted, fromCache = false)
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private fun requestNativePermission() {
        val permissions = if (config.requestAudio) {
            arrayOf(Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO)
        } else {
            arrayOf(Manifest.permission.CAMERA)
        }
        ActivityCompat.requestPermissions(activity, permissions, config.requestCode)
    }

    private fun onPermissionResult(granted: Boolean, fromCache: Boolean) {
        if (granted) {
            listener?.onCameraPermissionGranted()
            notifyJS("onNativePermissionGranted", JSONObject().apply {
                put("granted",   true)
                put("fromCache", fromCache)
                put("platform",  "android")
            })
        } else {
            val permanentlyDenied = wasAlreadyAsked() && !shouldShowRationale()
            listener?.onCameraPermissionDenied(permanentlyDenied)
            notifyJS("onNativePermissionDenied", JSONObject().apply {
                put("granted",          false)
                put("permanentlyDenied", permanentlyDenied)
                put("platform",          "android")
            })
        }
    }

    private fun notifyJS(callbackName: String, payload: JSONObject) {
        val js = "if(window.CameraPlugin && window.CameraPlugin.$callbackName) { " +
                 "window.CameraPlugin.$callbackName(${payload}); }"
        activity.runOnUiThread {
            webView.evaluateJavascript(js, null)
        }
    }

    private fun hasPermission(): Boolean =
        ContextCompat.checkSelfPermission(activity, Manifest.permission.CAMERA) ==
                PackageManager.PERMISSION_GRANTED

    private fun shouldShowRationale(): Boolean =
        ActivityCompat.shouldShowRequestPermissionRationale(activity, Manifest.permission.CAMERA)

    private fun wasAlreadyAsked(): Boolean =
        prefs.getBoolean(PREF_PERMISSION_ASKED, false)

    private fun hasCameraFacing(facing: Int): Boolean {
        return try {
            val manager = activity.getSystemService<android.hardware.camera2.CameraManager>() ?: return false
            for (cameraId in manager.cameraIdList) {
                val characteristics = manager.getCameraCharacteristics(cameraId)
                if (characteristics.get(android.hardware.camera2.CameraCharacteristics.LENS_FACING) == facing) return true
            }
            false
        } catch (e: Exception) { false }
    }
}
