/* RSH-LS – kleine UI-Helfer (kein Framework) */
(function () {
    'use strict';

    // Bestätigungsdialog für gefährliche Aktionen (Löschen etc.)
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
        }
    });

    // Auto-Fokus auf erstes markiertes Eingabefeld (z.B. Login, Terminal)
    var autofocus = document.querySelector('[data-autofocus]');
    if (autofocus) {
        autofocus.focus();
    }

    // Barcode-/QR-Scanner-Eingabe: liefert per USB/BT meist einen schnellen
    // Tastatur-Stream gefolgt von Enter. Felder mit [data-scan-target]
    // lösen bei Enter automatisch das umgebende Formular aus.
    document.querySelectorAll('[data-scan-target]').forEach(function (input) {
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var form = input.closest('form');
                if (form) form.requestSubmit();
            }
        });
    });

    // Flash-Meldungen nach kurzer Zeit ausblenden
    document.querySelectorAll('.flash').forEach(function (el) {
        setTimeout(function () { el.style.display = 'none'; }, 6000);
    });
})();
