package com.cameraplugin.example

import android.os.Bundle
import android.webkit.WebChromeClient
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity
import com.cameraplugin.CameraPermissionListener
import com.cameraplugin.CameraPluginBridge
import com.cameraplugin.CameraPluginConfig

/**
 * CameraActivity — Example host Activity for the CameraPlugin Kotlin bridge.
 *
 * This Activity loads PHP app in a WebView and wires up the native
 * Kotlin bridge so JS can call Android.requestCameraPermission() etc.
 *
 * Add to AndroidManifest.xml:
 *   <uses-permission android:name="android.permission.CAMERA" />
 *   <uses-permission android:name="android.permission.INTERNET" />
 *
 *   <activity android:name=".example.CameraActivity"
 *             android:exported="true" />
 */
class CameraActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var bridge: CameraPluginBridge

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // ── 1. Create and configure WebView ──────────────────────────────────
        webView = WebView(this).apply {
            settings.apply {
                javaScriptEnabled      = true
                domStorageEnabled      = true
                mediaPlaybackRequiresUserGesture = false        // required for camera preview autoplay
                allowFileAccess        = false
                cacheMode              = WebSettings.LOAD_DEFAULT
            }
            webViewClient  = WebViewClient()
            webChromeClient = buildChromeClient()   // handles JS alerts + permission UI hints
        }
        setContentView(webView)

        // ── 2. Create and attach the bridge ───────────────────────────────────
        bridge = CameraPluginBridge(
            activity = this,
            webView  = webView,
            config   = CameraPluginConfig(
                requestAudio       = false,
                phpCallbackUrl     = "",
                preferFrontCamera  = false,
                debugLogging       = true
            ),
            listener = object : CameraPermissionListener {
                override fun onCameraPermissionGranted() {
                    // Optional: handle natively (e.g. start CameraX alongside WebView)
                    android.util.Log.d("CameraActivity", "Permission granted natively")
                }

                override fun onCameraPermissionDenied(permanentlyDenied: Boolean) {
                    if (permanentlyDenied) {
                        // Optionally show a native Snackbar/Dialog directing to Settings
                        android.util.Log.w("CameraActivity", "Permission permanently denied")
                    }
                }
            }
        )
        bridge.attach()

        // ── 3. Load your PHP app ──────────────────────────────────────────────
        webView.loadUrl("https://yourapp.example.com/")
        // For local dev (php -S localhost:8080), use an AVD or forwarded port:
        //   webView.loadUrl("http://10.0.2.2:8080/")   // Android emulator → host machine
    }

    // ── Forward permission results to the bridge ─────────────────────────────
    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        bridge.onRequestPermissionsResult(requestCode, permissions, grantResults)
    }

    override fun onDestroy() {
        bridge.detach()
        webView.destroy()
        super.onDestroy()
    }

    // ── WebChromeClient: needed for camera in WebView on Android ─────────────
    private fun buildChromeClient() = object : WebChromeClient() {

        // Grant camera/mic permission requests that come FROM the WebView itself
        // (needed for getUserMedia on Android 21+)
        override fun onPermissionRequest(request: android.webkit.PermissionRequest) {
            val allowed = request.resources.filter { resource ->
                resource == android.webkit.PermissionRequest.RESOURCE_VIDEO_CAPTURE ||
                resource == android.webkit.PermissionRequest.RESOURCE_AUDIO_CAPTURE
            }.toTypedArray()

            if (allowed.isNotEmpty()) {
                request.grant(allowed)
            } else {
                request.deny()
            }
        }
    }
}
