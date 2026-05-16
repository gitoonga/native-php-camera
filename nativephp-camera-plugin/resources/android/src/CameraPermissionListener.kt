package com.cameraplugin

/**
 * CameraPermissionListener
 *
 * Optional interface to receive permission results natively in your Activity or Fragment,
 * in addition to the JS callbacks sent to the WebView.
 *
 * Usage:
 *   val bridge = CameraPluginBridge(
 *       activity = this,
 *       webView  = webView,
 *       listener = object : CameraPermissionListener {
 *           override fun onCameraPermissionGranted() { /* start native camera if needed */ }
 *           override fun onCameraPermissionDenied(permanentlyDenied: Boolean) { /* show UI */ }
 *       }
 *   )
 */
interface CameraPermissionListener {
    /**
     * Called when the user grants camera permission.
     * Runs on the main thread.
     */
    fun onCameraPermissionGranted()

    /**
     * Called when the user denies camera permission.
     * @param permanentlyDenied  True if the user selected "Don't ask again".
     *                           In this case, guide them to Settings.
     * Runs on the main thread.
     */
    fun onCameraPermissionDenied(permanentlyDenied: Boolean)
}
