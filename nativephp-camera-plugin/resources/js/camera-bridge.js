/**
 * camera-bridge.js — updated with Kotlin/Android native bridge support.
 *
 * When running inside an Android WebView with CameraPluginBridge attached,
 * permission requests are routed through the native Android layer
 * (window.Android.*) instead of the browser's getUserMedia dialog.
 *
 * This gives you:
 *  - Native Android permission dialogs (correct system UI)
 *  - "Don't ask again" / permanently denied detection
 *  - Permission state without triggering a prompt
 *  - Logcat forwarding for JS debug logs
 */

function _CameraPluginBridge(config) {
    var self     = this;
    var stream   = null;
    var isNative = typeof window.Android !== 'undefined';

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    this.requestPermission = function () {
        if (isNative) {
            return _requestNative();
        }
        return _checkBrowserSupport()
            .then(_requestBrowserAccess)
            .then(function (mediaStream) {
                stream = mediaStream;
                _serverNotify('permission_granted', { facing: config.facing });
                _invoke(config.onGranted, { stream: mediaStream });
                if (config.autoStart) self.startPreview(mediaStream);
                return { granted: true, stream: mediaStream };
            })
            .catch(function (err) {
                var reason = _classifyError(err);
                _serverNotify('permission_denied', { reason: reason });
                _invoke(config.onDenied, { reason: reason, error: err });
                _overlay('❌ ' + _errorMsg(reason), 'error');
                return { granted: false, reason: reason };
            });
    };

    this.startPreview = function (mediaStream) {
        _initRefs();
        var s = mediaStream || stream;
        if (!s) return;
        var video = _el('-video');
        if (video) { video.srcObject = s; video.play().catch(function(){}); }
        _overlay('', 'clear');
    };

    this.stopPreview = function () {
        if (stream) { stream.getTracks().forEach(function(t){ t.stop(); }); stream = null; }
        var video = _el('-video');
        if (video) video.srcObject = null;
        _overlay('📷 Camera stopped', 'info');
    };

    this.capturePhoto = function (quality) {
        quality = typeof quality === 'number' ? quality : 0.92;
        return new Promise(function (resolve, reject) {
            var video  = _el('-video');
            var canvas = _el('-canvas');
            if (!video || !video.srcObject) return reject(new Error('No active stream.'));
            if (!canvas) return reject(new Error('Canvas not found.'));
            canvas.width  = video.videoWidth  || config.width;
            canvas.height = video.videoHeight || config.height;
            canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
            resolve(canvas.toDataURL('image/jpeg', quality));
        });
    };

    this.switchCamera = function () {
        config.facing = config.facing === 'user' ? 'environment' : 'user';
        self.stopPreview();
        return self.requestPermission();
    };

    this.checkPermissionState = function () {
        if (isNative) {
            window.Android.checkPermissionState();   // result comes via onNativePermissionState callback
            return Promise.resolve('pending_native');
        }
        if (!navigator.permissions || !navigator.permissions.query) return Promise.resolve('unsupported');
        return navigator.permissions.query({ name: 'camera' })
            .then(function(r){ return r.state; })
            .catch(function(){ return 'unsupported'; });
    };

    this.openSettings = function () {
        if (isNative) { window.Android.openAppSettings(); }
    };

    this.isNative   = function () { return isNative; };
    this.isSupported = function () {
        return isNative || !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    };
    this.getStream  = function () { return stream; };
    this.getConfig  = function () { return Object.assign({}, config); };

    // -------------------------------------------------------------------------
    // Callbacks invoked BY the Kotlin bridge (window.CameraPlugin.on*)
    // -------------------------------------------------------------------------

    this.onNativePermissionGranted = function (payload) {
        _serverNotify('permission_granted', { platform: 'android' });
        _invoke(config.onGranted, payload);
        _overlay('', 'clear');
        // After native grant, start the browser stream for preview
        _requestBrowserAccess()
            .then(function(mediaStream){
                stream = mediaStream;
                self.startPreview(mediaStream);
            })
            .catch(function(){});
    };

    this.onNativePermissionDenied = function (payload) {
        _serverNotify('permission_denied', { reason: 'denied', platform: 'android' });
        _invoke(config.onDenied, payload);
        var msg = payload.permanentlyDenied
            ? '❌ Camera permanently denied. Open Settings to allow.'
            : '❌ Camera access denied.';
        _overlay(msg, 'error');
        if (payload.permanentlyDenied) {
            _invoke(config.onPermanentlyDenied, payload);
        }
    };

    this.onNativePermissionRationale = function (payload) {
        _overlay('ℹ️ ' + (payload.message || 'Camera access is needed.'), 'info');
    };

    this.onNativePermissionState = function (payload) {
        _invoke(config.onPermissionState, payload);
    };

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    function _requestNative() {
        return new Promise(function (resolve) {
            // Kotlin calls back via onNativePermissionGranted / onNativePermissionDenied
            // We store resolve so the callbacks can close the Promise
            window.Android.requestCameraPermission();
            // Resolve with a sentinel; real result arrives in callbacks
            resolve({ granted: null, native: true });
        });
    }

    function _checkBrowserSupport() {
        return new Promise(function(resolve, reject){
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                reject(new Error('getUserMedia not supported'));
            } else { resolve(); }
        });
    }

    function _requestBrowserAccess() {
        return navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: config.facing }, width: { ideal: config.width }, height: { ideal: config.height } },
            audio: !!config.audio
        });
    }

    function _serverNotify(action, extra) {
        if (!config.callbackUrl) return;
        fetch(config.callbackUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ action: action, nonce: config.nonce }, extra || {}))
        }).catch(function(){});
    }

    function _invoke(fnName, data) {
        if (fnName && typeof window[fnName] === 'function') {
            try { window[fnName](data); } catch(e){}
        }
    }

    var _refs = {};
    function _initRefs() {
        if (_refs.done) return;
        var id = config.containerId;
        _refs = { done: true };
    }
    function _el(suffix) {
        return document.getElementById(config.containerId + suffix);
    }

    function _overlay(msg, type) {
        var el = _el('-overlay');
        if (!el) return;
        el.textContent    = msg;
        el.className      = 'camera-plugin-overlay' + (type ? ' camera-plugin-overlay--' + type : '');
        el.style.display  = msg ? 'flex' : 'none';
    }

    function _classifyError(err) {
        var n = (err && err.name) || '';
        if (n === 'NotAllowedError'  || n === 'PermissionDeniedError') return 'denied';
        if (n === 'NotFoundError'    || n === 'DevicesNotFoundError')  return 'no_device';
        if (n === 'NotReadableError' || n === 'TrackStartError')       return 'in_use';
        if (n === 'OverconstrainedError')                               return 'overconstrained';
        if (n === 'SecurityError')                                      return 'insecure_context';
        return 'unknown';
    }

    function _errorMsg(reason) {
        return ({
            denied:           'Camera access denied.',
            no_device:        'No camera found on this device.',
            in_use:           'Camera is already in use.',
            overconstrained:  'Camera does not support requested settings.',
            insecure_context: 'Camera requires HTTPS.',
            unknown:          'An unknown error occurred.',
        })[reason] || 'Camera error.';
    }

    // Route JS console.log to Logcat when running natively
    if (isNative && config.debugLogging) {
        var _origLog = console.log.bind(console);
        console.log = function() {
            _origLog.apply(console, arguments);
            try { window.Android.log(Array.from(arguments).join(' ')); } catch(e){}
        };
    }
}
