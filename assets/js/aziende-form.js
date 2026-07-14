/**
 * aziende-form.js — logica del form azienda interna (settings/aziende/_form.php)
 *
 * Estratto dal <script> inline nella View (Revisione Incrociata §7.1.2):
 * anteprima immagine (logo/header) al momento della selezione file.
 * Nessuna dipendenza da dati PHP.
 */
document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  function setupPreview(inputId, containerId, previewId, statusId) {
    var input = document.getElementById(inputId);
    var container = document.getElementById(containerId);
    var preview = document.getElementById(previewId);
    var status = document.getElementById(statusId);

    if (!input) return;

    input.addEventListener('change', function () {
      var file = this.files[0];
      if (file) {
        var reader = new FileReader();
        reader.onload = function (e) {
          preview.src = e.target.result;
          container.style.setProperty('display', 'flex', 'important');
          status.innerHTML = '<span class="text-success font-monospace" style="font-weight:600;"><i class="fa-solid fa-circle-check"></i> Anteprima (' + (file.size / 1024).toFixed(1) + ' KB) pronta</span>';
        };
        reader.readAsDataURL(file);
      }
    });
  }

  setupPreview('logo-input', 'logo-preview-container', 'logo-preview', 'logo-status-text');
  setupPreview('header-input', 'header-preview-container', 'header-preview', 'header-status-text');
});
