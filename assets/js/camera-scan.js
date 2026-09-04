/* RSH-LS – Kamera-QR-Scan (jsQR)
 * Wird per <button data-camera-scan-for="inputId"> aktiviert. Öffnet ein
 * Overlay mit Kamerabild, liest QR-/Barcodes und schreibt den Wert ins
 * Zielfeld – anschließend wird das umgebende Formular automatisch
 * abgeschickt (wie bei einem klassischen USB-/BT-Scanner).
 */
(function () {
    'use strict';

    var overlay = null;
    var video = null;
    var canvas = null;
    var ctx = null;
    var stream = null;
    var rafId = null;
    var targetInput = null;

    function ensureJsQR(callback) {
        if (window.jsQR) {
            callback();
            return;
        }
        var script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jsQR/1.4.0/jsQR.js';
        script.onload = callback;
        script.onerror = function () {
            showError('Scanner-Bibliothek konnte nicht geladen werden (keine Internetverbindung?).');
        };
        document.head.appendChild(script);
    }

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'camera-scan-overlay';
        overlay.innerHTML =
            '<div class="camera-scan-box">' +
            '  <video class="camera-scan-video" playsinline autoplay muted></video>' +
            '  <div class="camera-scan-frame"></div>' +
            '  <div class="camera-scan-msg"></div>' +
            '  <button type="button" class="btn btn-ghost camera-scan-close">Abbrechen</button>' +
            '</div>';
        document.body.appendChild(overlay);
        video = overlay.querySelector('.camera-scan-video');
        overlay.querySelector('.camera-scan-close').addEventListener('click', closeScanner);
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeScanner();
        });
    }

    function showError(msg) {
        if (overlay) {
            var m = overlay.querySelector('.camera-scan-msg');
            if (m) m.textContent = msg;
        }
    }

    function openScanner(inputId) {
        targetInput = document.getElementById(inputId);
        if (!targetInput) return;

        buildOverlay();
        canvas = document.createElement('canvas');
        ctx = canvas.getContext('2d', { willReadFrequently: true });

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            showError('Kamerazugriff wird von diesem Browser nicht unterstützt.');
            return;
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } })
            .then(function (s) {
                stream = s;
                video.srcObject = stream;
                ensureJsQR(function () {
                    rafId = requestAnimationFrame(tick);
                });
            })
            .catch(function () {
                showError('Kein Kamerazugriff möglich. Bitte Berechtigung erlauben oder Code manuell eingeben.');
            });
    }

    function tick() {
        if (!stream) return;
        if (video.readyState === video.HAVE_ENOUGH_DATA) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = window.jsQR ? window.jsQR(imageData.data, imageData.width, imageData.height) : null;
            if (code && code.data) {
                onDecoded(code.data);
                return;
            }
        }
        rafId = requestAnimationFrame(tick);
    }

    function onDecoded(value) {
        if (targetInput) {
            targetInput.value = value;
        }
        closeScanner();
        if (targetInput) {
            var form = targetInput.closest('form');
            if (form) {
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }
        }
    }

    function closeScanner() {
        if (rafId) cancelAnimationFrame(rafId);
        rafId = null;
        if (stream) {
            stream.getTracks().forEach(function (t) { t.stop(); });
            stream = null;
        }
        if (overlay && overlay.parentNode) {
            overlay.parentNode.removeChild(overlay);
        }
        overlay = null;
        video = null;
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-camera-scan-for]');
        if (btn) {
            e.preventDefault();
            openScanner(btn.getAttribute('data-camera-scan-for'));
        }
    });
})();
