/**
 * customers-compliance.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Gestione AJAX della tab "Compliance" nella scheda cliente.
 */

/* global QkCustomerCompliance */

(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.QkCustomerCompliance;
    if (!cfg) return;

    const container = document.getElementById('compliance-status-list');
    if (!container) return;

    // ── Utilities ──────────────────────────────────────────────────────────

    function esc(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
    }

    function fmtDate(str) {
      if (!str) return '—';
      return new Date(str).toLocaleDateString('it-IT');
    }

    /** Restituisce { label, cssModifier, dataStatus } in base allo stato e alla scadenza */
    function resolveStatus(status, expiryDate) {
      const today = new Date().toISOString().split('T')[0];

      if (status === 'approvata') {
        if (expiryDate && expiryDate < today) {
          return { label: 'Scaduta',      mod: 'danger',  dataStatus: 'scaduta'     };
        }
        const expiryTime = new Date(expiryDate).getTime();
        const in30       = Date.now() + 30 * 864e5;
        if (expiryDate && expiryTime <= in30) {
          return { label: 'In scadenza',  mod: 'warning', dataStatus: 'in_scadenza' };
        }
        return   { label: 'Attiva',       mod: 'active',  dataStatus: 'approvata'   };
      }
      if (status === 'in_attesa')    return { label: 'In attesa',    mod: 'info',   dataStatus: 'in_attesa'    };
      if (status === 'rifiutata')    return { label: 'Rifiutata',    mod: 'danger', dataStatus: 'rifiutata'    };
      if (status === 'sospesa')      return { label: 'Sospesa',      mod: 'muted',  dataStatus: 'sospesa'      };
      if (status === 'scaduta')      return { label: 'Scaduta',      mod: 'danger', dataStatus: 'scaduta'      };
      return                                { label: 'Non presente', mod: 'muted',  dataStatus: 'non_presente' };
    }

    function badgeIcon(mod) {
      const icons = {
        active:  'fa-circle-check',
        warning: 'fa-triangle-exclamation',
        danger:  'fa-circle-xmark',
        info:    'fa-clock',
        muted:   'fa-circle-minus',
      };
      return icons[mod] ?? 'fa-circle';
    }

    // ── API helper ─────────────────────────────────────────────────────────

    /**
     * Chiamata API condivisa dai 5 punti di questo file (DRY 27/07/2026,
     * PIANO Punti 1-2-3 Basso Rischio, Punto 3). Centralizza la validazione
     * HTTP/content-type (via window.qkApiFetch, assets/js/app.js) senza
     * imporre un messaggio d'errore unico: ogni chiamante resta libero di
     * scegliere il proprio prefisso — vedi `networkError` sotto — perché i 5
     * messaggi originali erano intenzionalmente diversi tra loro e non un
     * caso di duplicazione da correggere.
     *
     * @param {string} url
     * @param {FormData} [formData] se presente la richiesta è una POST
     * @returns {Promise<{ok:true, data:object}|{ok:false, networkError:true, error:string}>}
     */
    async function apiCall(url, formData) {
      try {
        const data = formData
          ? await window.qkApiFetch(url, { method: 'POST', body: formData })
          : await window.qkApiFetch(url);
        return { ok: true, data };
      } catch (e) {
        return { ok: false, networkError: true, error: e.message };
      }
    }

    // ── Fetch & render ─────────────────────────────────────────────────────

    async function fetchComplianceData() {
      container.innerHTML = `
        <div class="qkc-empty">
          <i class="fa-solid fa-spinner fa-spin fa-lg mb-2"></i><br>Caricamento…
        </div>`;
      const result = await apiCall(`${cfg.apiGet}?customer_id=${cfg.customerId}`);
      if (!result.ok) {
        container.innerHTML = `<div class="alert alert-danger m-3">Errore: ${esc(result.error)}</div>`;
        return;
      }
      const data = result.data;
      if (!data.ok) {
        container.innerHTML = `<div class="alert alert-danger m-3">${esc(data.error)}</div>`;
        return;
      }
      renderCompliance(data.compliance);
    }

    function renderCompliance(list) {
      container.innerHTML = '';

      if (!list.length) {
        container.innerHTML = '<div class="qkc-empty"><i class="fa-solid fa-shield-halved fa-2x mb-2"></i><br>Nessun regime di compliance configurato.</div>';
        return;
      }

      const wrapper = document.createElement('div');
      wrapper.className = 'qkc-list';

      list.forEach(item => {
        wrapper.appendChild(buildCard(item));
      });

      container.appendChild(wrapper);
      attachEvents();
    }

    // ── Build card ─────────────────────────────────────────────────────────

    function buildCard(item) {
      const type   = item.restriction_type;
      const auth   = item.authorization;
      const docs   = item.documents ?? [];
      const rawStatus = auth ? auth.status : 'non_presente';
      const expiry    = auth ? auth.expiry_date : null;
      const { label, mod, dataStatus } = resolveStatus(rawStatus, expiry);

      const card = document.createElement('div');
      card.className = 'qkc-card';
      card.dataset.status = dataStatus;
      card.dataset.typeId = type.id;

      // ── Header ────────────────────────────────────────────────────────────
      const needsRenew = ['non_presente','scaduta','rifiutata','sospesa'].includes(dataStatus);
      const renewLabel = rawStatus === 'non_presente' ? 'Nuova autorizzazione' : 'Rinnova';

      const pillsHtml = parseInt(type.requires_vat) === 1
        ? `<span class="qkc-pill"><i class="fa-solid fa-address-card fa-xs"></i> P.IVA richiesta</span>`
        : '';

      const refHtml = type.regulatory_ref
        ? `<span class="qkc-ref">${esc(type.regulatory_ref)}</span>`
        : '';

      let expiryHtml = '';
      if (auth && expiry) {
        let expiryMod = '';
        if (dataStatus === 'in_scadenza') expiryMod = 'qkc-expiry--warning';
        if (dataStatus === 'scaduta')     expiryMod = 'qkc-expiry--danger';
        const icon = dataStatus === 'approvata' ? 'fa-calendar-check' : 'fa-calendar-xmark';
        expiryHtml = `
          <span class="qkc-expiry ${expiryMod}">
            <i class="fa-solid ${icon} fa-xs"></i>
            ${dataStatus === 'scaduta' ? 'Scaduta il' : 'Scade il'} <strong>${fmtDate(expiry)}</strong>
          </span>`;
      }

      const headerHtml = `
        <div class="qkc-header">
          <div class="qkc-header-icon">
            <i class="fa-solid fa-shield-halved"></i>
          </div>
          <div class="qkc-header-body">
            <div class="qkc-title">${esc(type.name)}</div>
            <div class="qkc-meta">
              <span class="qkc-badge qkc-badge--${mod}">
                <i class="fa-solid ${badgeIcon(mod)} fa-xs"></i> ${label}
              </span>
              ${refHtml}
              ${pillsHtml}
              ${expiryHtml}
            </div>
          </div>
          <div class="qkc-header-actions">
            ${needsRenew
              ? `<button class="btn-qk btn-qk-secondary btn-sm btn-init-auth" data-type-id="${type.id}">
                   <i class="fa-solid fa-plus fa-xs me-1"></i>${renewLabel}
                 </button>`
              : ''}
          </div>
        </div>`;

      // ── Body (solo se esiste un'autorizzazione) ───────────────────────────
      let bodyHtml = '';
      if (auth) {
        // Documento rows
        const docsHtml = docs.map(doc => buildDocRow(doc, auth)).join('');

        // Approval controls
        const isManager = cfg.userRole === 'admin' || cfg.userRole === 'commerciale';
        let footerLeft = '';
        let footerRight = '';

        if (auth.approver_name) {
          footerLeft = `
            <span class="qkc-approver-info">
              <i class="fa-solid fa-user-check fa-xs"></i>
              Gestito da <strong>${esc(auth.approver_name)}</strong>
            </span>`;
        }

        if (isManager) {
          if (rawStatus === 'in_attesa') {
            footerRight = `
              <button class="btn-qk btn-qk-secondary btn-sm btn-approve" data-auth-id="${auth.id}" data-status="rifiutata">
                <i class="fa-solid fa-xmark fa-xs me-1"></i>Rifiuta
              </button>
              <button class="btn-qk btn-qk-primary btn-sm btn-approve" data-auth-id="${auth.id}" data-status="approvata">
                <i class="fa-solid fa-check fa-xs me-1"></i>Approva
              </button>`;
          } else if (rawStatus === 'approvata') {
            footerRight = `
              <button class="btn-qk btn-qk-secondary btn-sm btn-approve" data-auth-id="${auth.id}" data-status="sospesa">
                <i class="fa-solid fa-pause fa-xs me-1"></i>Sospendi
              </button>
              <button class="btn-qk btn-qk-primary btn-sm btn-approve" data-auth-id="${auth.id}" data-status="approvata">
                <i class="fa-solid fa-floppy-disk fa-xs me-1"></i>Salva modifiche
              </button>`;
          } else if (rawStatus === 'sospesa') {
            footerRight = `
              <button class="btn-qk btn-qk-primary btn-sm btn-approve" data-auth-id="${auth.id}" data-status="approvata">
                <i class="fa-solid fa-play fa-xs me-1"></i>Riattiva / Salva
              </button>`;
          }
        } else {
          footerLeft = `<span class="qkc-locked-info"><i class="fa-solid fa-lock fa-xs"></i> Approvazione riservata ad admin o commerciale</span>`;
        }

        bodyHtml = `
          <div class="qkc-body">
            <div class="qkc-docs">
              <div class="qkc-section-label">Documenti</div>
              <div class="qkc-doc-list">${docsHtml}</div>
            </div>
            ${['in_attesa', 'approvata', 'sospesa'].includes(rawStatus) ? `
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label class="qkc-section-label d-block mb-1" style="font-size:0.75rem;">Inizio validità (Emissione)</label>
                <input type="date" class="qk-form-control inp-issued-date" style="padding: 4px 8px; font-size: 0.85rem;" value="${auth.issued_date || new Date().toISOString().split('T')[0]}">
              </div>
              <div class="col-6">
                <label class="qkc-section-label d-block mb-1" style="font-size:0.75rem;">Fine validità (Scadenza)</label>
                <input type="date" class="qk-form-control inp-expiry-date" style="padding: 4px 8px; font-size: 0.85rem;" value="${auth.expiry_date || new Date(Date.now() + parseInt(type.validity_days || 365) * 864e5).toISOString().split('T')[0]}">
              </div>
            </div>
            ` : ''}
            <div class="qkc-notes">
              <div class="qkc-section-label">Note interne</div>
              <textarea class="txt-internal-notes" placeholder="Note sulla verifica…">${esc(auth.internal_notes ?? '')}</textarea>
            </div>
            <div class="qkc-footer">
              ${footerLeft}
              ${footerRight}
            </div>
          </div>`;
      }

      card.innerHTML = headerHtml + bodyHtml;
      return card;
    }

    function buildDocRow(doc, auth) {
      const hasFile = !!doc.file;
      const isMandatory = parseInt(doc.mandatory) === 1;

      const iconClass = hasFile ? 'fa-file-pdf qkc-doc-icon--ok' : 'fa-file-arrow-up qkc-doc-icon--miss';

      let fileSection = '';
      let verifySection = '';
      let uploadBtn = '';

      if (hasFile) {
        const fileUrl = `/Quack/uploads?path=${encodeURIComponent(doc.file.file_path)}`;
        fileSection = `
          <a href="${fileUrl}" target="_blank" class="qkc-doc-filename">
            <i class="fa-solid fa-file-pdf fa-xs text-danger"></i>
            ${esc(doc.file.original_filename)}
          </a>`;

        const verifyChecked = doc.file.verified ? 'checked' : '';
        const verifierHtml  = doc.file.verified
          ? `<span class="qkc-doc-verify"><i class="fa-solid fa-circle-check fa-xs"></i> Verificato da ${esc(doc.file.verifier_name)}</span>`
          : '';
        verifySection = `
          <label class="qkc-verify-label">
            <input class="chk-verify-doc" type="checkbox" data-file-id="${doc.file.id}" ${verifyChecked}>
            Documento valido
          </label>
          ${verifierHtml}`;

        uploadBtn = `
          <label class="qkc-upload-btn">
            <i class="fa-solid fa-arrow-rotate-right fa-xs"></i> Sostituisci
            <input type="file" class="file-upload-input d-none" data-auth-id="${auth.id}" data-doc-id="${doc.id}">
          </label>`;
      } else {
        uploadBtn = `
          <label class="qkc-upload-btn qkc-upload-btn--primary">
            <i class="fa-solid fa-upload fa-xs"></i> Carica
            <input type="file" class="file-upload-input d-none" data-auth-id="${auth.id}" data-doc-id="${doc.id}">
          </label>`;
      }

      return `
        <div class="qkc-doc-row">
          <i class="fa-solid ${iconClass} qkc-doc-icon"></i>
          <div class="qkc-doc-body">
            <div class="qkc-doc-name">
              ${esc(doc.document_name)}${isMandatory ? ' <span class="text-danger" title="Obbligatorio">*</span>' : ''}
            </div>
            ${doc.instructions ? `<div class="qkc-doc-hint">${esc(doc.instructions)}</div>` : ''}
            ${fileSection}
            ${verifySection}
          </div>
          <div class="qkc-doc-actions">
            ${uploadBtn}
          </div>
        </div>`;
    }

    // ── Event listeners ────────────────────────────────────────────────────

    function attachEvents() {

      // 1. Nuova autorizzazione / Rinnova
      container.querySelectorAll('.btn-init-auth').forEach(btn => {
        btn.addEventListener('click', async function () {
          const typeId = this.dataset.typeId;
          btn.disabled = true;
          const fd = new FormData();
          fd.set('customer_id', cfg.customerId);
          fd.set('restriction_type_id', typeId);
          fd.set('csrf_token', cfg.csrfToken);
          const result = await apiCall(cfg.apiSave, fd);
          if (!result.ok) { alert('Errore di rete: ' + result.error); btn.disabled = false; return; }
          const data = result.data;
          if (!data.ok) { alert('Errore: ' + data.error); btn.disabled = false; return; }
          await fetchComplianceData();
        });
      });

      // 2. Upload file
      container.querySelectorAll('.file-upload-input').forEach(input => {
        input.addEventListener('change', async function () {
          if (!this.files?.length) return;
          const fd = new FormData();
          fd.set('authorization_id', this.dataset.authId);
          fd.set('required_doc_id',  this.dataset.docId);
          fd.set('doc_file',         this.files[0]);
          fd.set('csrf_token',       cfg.csrfToken);

          const lbl = this.closest('label');
          const origHtml = lbl.innerHTML;
          lbl.innerHTML = '<i class="fa-solid fa-spinner fa-spin fa-xs"></i>';

          const result = await apiCall(cfg.apiUpload, fd);
          if (!result.ok) { alert('Errore: ' + result.error); lbl.innerHTML = origHtml; return; }
          const data = result.data;
          if (!data.ok) { alert('Errore caricamento: ' + data.error); lbl.innerHTML = origHtml; return; }
          await fetchComplianceData();
        });
      });

      // 3. Verifica documento
      container.querySelectorAll('.chk-verify-doc').forEach(chk => {
        chk.addEventListener('change', async function () {
          const fd = new FormData();
          fd.set('upload_id',   this.dataset.fileId);
          fd.set('verified',    this.checked ? '1' : '0');
          fd.set('csrf_token',  cfg.csrfToken);
          const result = await apiCall(cfg.apiVerify, fd);
          if (!result.ok) { alert('Errore: ' + result.error); this.checked = !this.checked; return; }
          const data = result.data;
          if (!data.ok) { alert('Errore: ' + data.error); this.checked = !this.checked; return; }
          await fetchComplianceData();
        });
      });

      // 4. Approva / Rifiuta / Sospendi / Riattiva
      container.querySelectorAll('.btn-approve').forEach(btn => {
        btn.addEventListener('click', async function () {
          const panel  = this.closest('.qkc-body');
          const notes  = panel?.querySelector('.txt-internal-notes')?.value ?? '';
          const issuedDate = panel?.querySelector('.inp-issued-date')?.value ?? '';
          const expiryDate = panel?.querySelector('.inp-expiry-date')?.value ?? '';
          
          const fd     = new FormData();
          fd.set('authorization_id', this.dataset.authId);
          fd.set('status',           this.dataset.status);
          fd.set('internal_notes',   notes);
          fd.set('issued_date',      issuedDate);
          fd.set('expiry_date',      expiryDate);
          fd.set('csrf_token',       cfg.csrfToken);
          btn.disabled = true;
          const result = await apiCall(cfg.apiApprove, fd);
          if (!result.ok) { alert('Errore: ' + result.error); btn.disabled = false; return; }
          const data = result.data;
          if (!data.ok) { alert('Impossibile aggiornare: ' + data.error); btn.disabled = false; return; }
          await fetchComplianceData();
        });
      });
    }

    // ── Init ───────────────────────────────────────────────────────────────
    fetchComplianceData();
  });
})();
