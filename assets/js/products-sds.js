/**
 * products-sds.js
 * ─────────────────────────────────────────────────────────────────────────────
 * Tab "Scheda di sicurezza" (SDS) nel form prodotto — gestione schede di
 * sicurezza per prodotto-fornitore, con versioning, periodo di validità e
 * multi-lingua.
 *
 * Riferimento: docs/appunti adr/PIANO - Refactoring Schede di Sicurezza (SDS)
 * su Prodotto-Fornitore.md, M6. Pattern di caricamento lazy al click sul tab
 * ricalcato da products-suppliers.js (drawer RRP), ma per il dettaglio
 * upload+metadati si usa un modale Bootstrap (`#modal-sds`, `bootstrap.Modal`),
 * non un drawer: qui si compila sempre un solo record alla volta, stesso
 * pattern del modale "Aggiungi restrizione" già nella stessa pagina — deciso
 * il 22/07/2026 al posto del drawer originale perché un modale Bootstrap è
 * già responsive di default (il drawer `.qk-drawer` condiviso con RRP ha
 * larghezza fissa 400px senza breakpoint mobile).
 *
 * Nessun codice di autocomplete qui per il campo lingua: gestito da
 * assets/js/searchable-select.js tramite la classe CSS sul <select> (§3.16),
 * incluso già staticamente nel markup di _form.php.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const cfg = window.QkPF;
    if (!cfg) return;

    const tabBtn = document.getElementById('ptab-btn-sds');
    const tbody = document.getElementById('sds-tbody');
    if (!tabBtn || !tbody) return; // tab non presente (prodotto nuovo, o senza fornitori)

    const CSRF = cfg.csrfToken || (document.querySelector('input[name="csrf_token"]') || {}).value || '';
    const prodId = cfg.prodottoId;
    const fornitori = [...(cfg.fornitori || [])]; // da prodotto_fornitori (già in window.QkPF)

    const sdsEmpty = document.getElementById('sds-empty');
    const sdsTable = document.getElementById('sds-table');
    const filterHideInattivi = document.getElementById('sds-filter-hide-inattivi');
    const btnAddSds = document.getElementById('btn-add-sds');
    const badgeScadute = document.getElementById('badge-sds-scadute');

    const sdsModalEl = document.getElementById('modal-sds');
    const sdsModal = (sdsModalEl && window.bootstrap) ? bootstrap.Modal.getOrCreateInstance(sdsModalEl) : null;
    const modalTitleEl = document.getElementById('sds-modal-title');
    const modalAlertEl = document.getElementById('sds-modal-alert');
    const btnSave = document.getElementById('btn-save-sds');
    const btnDelete = document.getElementById('btn-sds-delete');
    const btnRimuoviFile = document.getElementById('btn-sds-rimuovi-file');

    const fId = document.getElementById('sds-form-id');
    const selForn = document.getElementById('sds-sel-fornitore');
    const selLingua = document.getElementById('sds-sel-lingua');
    const inpVersione = document.getElementById('sds-versione');
    const inpDataEmissione = document.getElementById('sds-data-emissione');
    const inpValidoDal = document.getElementById('sds-valido-dal');
    const inpValidoAl = document.getElementById('sds-valido-al');
    const inpFile = document.getElementById('sds-file-input');
    const fileRequiredMark = document.getElementById('sds-file-required-mark');
    const fileCurrent = document.getElementById('sds-file-current');
    const inpNote = document.getElementById('sds-note');

    let righe = []; // dati dall'ultimo GET /products/sds/list
    let loaded = false;

    function esc(s) {
      if (s === null || s === undefined) return '';
      return s.toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function fmtDateIt(iso) {
      if (!iso) return '—';
      const parts = iso.split('-');
      if (parts.length !== 3) return esc(iso);
      return `${parts[2]}/${parts[1]}/${parts[0]}`;
    }

    function fmtDateTimeIt(sql) {
      if (!sql) return '—';
      const datePart = sql.split(' ')[0];
      return fmtDateIt(datePart);
    }

    function oggiIso() {
      const d = new Date();
      return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    /* ── Caricamento lista ─────────────────────────────────────────────── */
    async function loadSds() {
      try {
        const res = await fetch(`${cfg.apiSdsList}?prodotto_id=${prodId}`);
        const data = await res.json();
        if (!data.ok) {
          console.error('apiSdsList errore:', data.error);
          righe = [];
        } else {
          righe = data.righe || [];
        }
      } catch (e) {
        console.error(e);
        righe = [];
      }
      loaded = true;
      render();
    }

    function statoRiga(r) {
      // Badge "Scaduta" calcolato a runtime, indipendente da e_corrente (§4.13)
      if (r.valido_al && r.valido_al < oggiIso()) {
        return { label: 'Scaduta', cls: 'bg-danger' };
      }
      if (r.e_corrente) {
        return { label: 'Corrente · ' + r.lingua.toUpperCase(), cls: 'bg-success' };
      }
      return { label: 'Storica', cls: 'bg-secondary' };
    }

    function render() {
      if (!loaded) return;

      const hideInattivi = !!(filterHideInattivi && filterHideInattivi.checked);
      const visibili = righe.filter(r => hideInattivi ? !!r.fornitore_attivo : true);

      // Ordinamento leggibile: fornitore → lingua → valido_dal decrescente (§4.15)
      visibili.sort((a, b) => {
        if (a.nome_fornitore !== b.nome_fornitore) return (a.nome_fornitore || '').localeCompare(b.nome_fornitore || '');
        if (a.lingua !== b.lingua) return (a.lingua || '').localeCompare(b.lingua || '');
        return (b.valido_dal || '9999-99-99').localeCompare(a.valido_dal || '9999-99-99');
      });

      if (visibili.length === 0) {
        if (sdsEmpty) sdsEmpty.classList.remove('d-none');
        if (sdsTable) sdsTable.classList.add('d-none');
      } else {
        if (sdsEmpty) sdsEmpty.classList.add('d-none');
        if (sdsTable) sdsTable.classList.remove('d-none');
      }

      if (tbody) {
        tbody.innerHTML = visibili.map(r => {
          const stato = statoRiga(r);
          const fornitoreBadge = r.fornitore_attivo
            ? '<span class="text-success">● Attivo</span>'
            : '<span class="text-danger">● Inattivo</span>';
          const fileCell = r.file_path
            ? `<a href="${cfg.apiUploadsBase}${encodeURIComponent(r.file_path)}" target="_blank" rel="noopener noreferrer" title="${esc(r.file_nome_orig || '')}">
                 <i class="fa-solid fa-file-arrow-down"></i> Scarica
               </a>`
            : `<span class="text-muted small" title="Rimosso il ${esc(fmtDateTimeIt(r.file_rimosso_il))}">File non più disponibile</span>`;

          return `
            <tr data-id="${r.id}">
              <td>
                <div class="fw-semibold">${esc(r.nome_fornitore)}</div>
                <div class="small">${fornitoreBadge}</div>
              </td>
              <td class="text-center text-uppercase">${esc(r.lingua)}</td>
              <td>${esc(r.versione || '—')}</td>
              <td>${fmtDateTimeIt(r.created_at)}</td>
              <td>${fmtDateIt(r.valido_dal)}</td>
              <td>${fmtDateIt(r.valido_al)}</td>
              <td><span class="badge ${stato.cls}">${stato.label}</span></td>
              <td class="text-center">${fileCell}</td>
              <td class="text-center">
                <div class="qk-flex-row qk-gap-xs justify-content-end">
                  <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm btn-sds-edit" title="Modifica">
                    <i class="fa-solid fa-pen"></i>
                  </button>
                  <button type="button" class="btn-qk btn-qk-secondary btn-qk-sm btn-sds-rimuovi-file-row" title="Rimuovi solo il file" ${r.file_path ? '' : 'disabled'}>
                    <i class="fa-solid fa-file-slash"></i>
                  </button>
                  <button type="button" class="btn-qk btn-qk-danger btn-qk-sm btn-sds-delete-row" title="Elimina">
                    <i class="fa-solid fa-trash"></i>
                  </button>
                </div>
              </td>
            </tr>
          `;
        }).join('');

        tbody.querySelectorAll('tr').forEach(tr => {
          const id = parseInt(tr.dataset.id, 10);
          const riga = righe.find(r => r.id === id);
          tr.querySelector('.btn-sds-edit')?.addEventListener('click', () => openSdsModal('edit', riga));
          tr.querySelector('.btn-sds-rimuovi-file-row')?.addEventListener('click', () => rimuoviFile(riga));
          tr.querySelector('.btn-sds-delete-row')?.addEventListener('click', () => eliminaRiga(riga));
        });
      }

      // Badge "!" sulla tab se esiste almeno una scheda scaduta (§4.13/M5)
      const hasScadute = righe.some(r => r.valido_al && r.valido_al < oggiIso());
      if (badgeScadute) badgeScadute.classList.toggle('d-none', !hasScadute);
    }

    /* ── Modale ─────────────────────────────────────────────────────────── */
    function showAlert(msg) {
      if (!modalAlertEl) return;
      modalAlertEl.textContent = msg;
      modalAlertEl.classList.remove('d-none');
    }
    function clearAlert() {
      if (!modalAlertEl) return;
      modalAlertEl.textContent = '';
      modalAlertEl.classList.add('d-none');
    }

    function populateSelFornitore(soloAttivi, selectedId) {
      if (!selForn) return;
      const elenco = soloAttivi ? fornitori.filter(f => !!f.attivo) : fornitori;
      selForn.innerHTML = '<option value="">— Seleziona fornitore —</option>' +
        elenco.map(f => `<option value="${f.id}" ${String(f.id) === String(selectedId) ? 'selected' : ''}>${esc(f.nome_fornitore_rs)}${f.attivo ? '' : ' (disattivato)'}</option>`).join('');
    }

    function openSdsModal(mode, riga) {
      clearAlert();
      if (mode === 'new') {
        modalTitleEl.textContent = 'Nuova scheda di sicurezza';
        fId.value = '0';
        populateSelFornitore(true, '');
        selForn.disabled = false;
        selLingua.value = '';
        selLingua.dispatchEvent(new Event('change'));
        inpVersione.value = '';
        inpDataEmissione.value = '';
        inpValidoDal.value = '';
        inpValidoAl.value = '';
        inpNote.value = '';
        inpFile.value = '';
        inpFile.required = true;
        if (fileRequiredMark) fileRequiredMark.classList.remove('d-none');
        fileCurrent.textContent = '';
        btnDelete.classList.add('d-none');
        btnRimuoviFile.classList.add('d-none');
      } else {
        modalTitleEl.textContent = 'Modifica scheda di sicurezza';
        fId.value = riga.id;
        // In modifica il fornitore non è cambiabile (§3.11 tratta esplicitamente
        // solo il cambio di lingua, non di prodotto_fornitore_id) — mostriamo
        // comunque l'elenco completo (incluso disattivati) perché una scheda
        // storica può essere agganciata a un fornitore oggi disattivato.
        populateSelFornitore(false, riga.prodotto_fornitore_id);
        selForn.disabled = true;
        selLingua.value = riga.lingua || '';
        selLingua.dispatchEvent(new Event('change'));
        inpVersione.value = riga.versione || '';
        inpDataEmissione.value = riga.data_emissione || '';
        inpValidoDal.value = riga.valido_dal || '';
        inpValidoAl.value = riga.valido_al || '';
        inpNote.value = riga.note || '';
        inpFile.value = '';
        inpFile.required = false;
        if (fileRequiredMark) fileRequiredMark.classList.add('d-none');
        fileCurrent.textContent = riga.file_path
          ? `File attuale: ${riga.file_nome_orig || riga.file_path} (lascia vuoto per non sostituirlo)`
          : `Nessun file (rimosso il ${fmtDateTimeIt(riga.file_rimosso_il)}) — carica un nuovo file per ripristinarlo`;
        btnDelete.classList.remove('d-none');
        btnRimuoviFile.classList.toggle('d-none', !riga.file_path);
      }
      btnSave.dataset.mode = mode;
      sdsModal?.show();
    }

    function closeSdsModal() {
      sdsModal?.hide();
      clearAlert();
    }

    btnAddSds?.addEventListener('click', () => openSdsModal('new'));
    // Annulla/X/backdrop/ESC sono gestiti nativamente da Bootstrap
    // (data-bs-dismiss="modal" nel markup) — qui serve solo ripulire
    // l'alert quando il modale si chiude, qualunque sia la via di chiusura.
    sdsModalEl?.addEventListener('hidden.bs.modal', clearAlert);
    filterHideInattivi?.addEventListener('change', render);

    /* ── Salvataggio (insert/update) ──────────────────────────────────── */
    async function saveSds() {
      clearAlert();
      const mode = btnSave.dataset.mode || 'new';
      const prodottoFornitoreId = selForn.value;
      const lingua = selLingua.value;

      if (!prodottoFornitoreId) { showAlert('Seleziona un fornitore.'); return; }
      if (!lingua) { showAlert('Seleziona una lingua.'); return; }
      if (mode === 'new' && !(inpFile.files && inpFile.files[0])) {
        showAlert('Il file è obbligatorio per una nuova scheda.');
        return;
      }
      if (inpValidoDal.value && inpValidoAl.value && inpValidoDal.value > inpValidoAl.value) {
        showAlert('"Valido dal" non può essere successivo a "Valido al".');
        return;
      }

      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id', fId.value || '0');
      fd.append('prodotto_id', prodId);
      fd.append('prodotto_fornitore_id', prodottoFornitoreId);
      fd.append('lingua', lingua);
      fd.append('versione', inpVersione.value || '');
      fd.append('data_emissione', inpDataEmissione.value || '');
      fd.append('valido_dal', inpValidoDal.value || '');
      fd.append('valido_al', inpValidoAl.value || '');
      fd.append('note', inpNote.value || '');
      if (inpFile.files && inpFile.files[0]) {
        fd.append('sds_file', inpFile.files[0]);
      }

      btnSave.disabled = true;
      try {
        const res = await fetch(cfg.apiSdsSave, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
          if (data.error === 'overlap' && data.conflitto) {
            showAlert(
              `Sovrapposizione con una scheda esistente (lingua ${esc(data.conflitto.lingua)}, ` +
              `valida dal ${fmtDateIt(data.conflitto.valido_dal)} al ${fmtDateIt(data.conflitto.valido_al)}). ` +
              `Modifica le date o chiudi prima quella scheda.`
            );
          } else {
            showAlert(data.messaggio || data.error || 'Errore durante il salvataggio.');
          }
          return;
        }
        closeSdsModal();
        await loadSds();
      } catch (e) {
        console.error(e);
        showAlert('Errore di comunicazione con il server.');
      } finally {
        btnSave.disabled = false;
      }
    }
    btnSave?.addEventListener('click', saveSds);

    /* ── Eliminazione riga (§5.4) ─────────────────────────────────────────
       Ritorna true solo se un'azione distruttiva è stata davvero eseguita
       (delete riuscito, oppure rimuovi-file eseguito come alternativa) — i
       i chiamanti dal modale usano questo valore per decidere se chiuderlo,
       senza chiuderlo per errore su un semplice "annulla" della conferma. */
    async function eliminaRiga(riga) {
      if (!riga) return false;
      if (!confirm('Eliminare definitivamente questa scheda di sicurezza (riga + file)?')) return false;
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id', riga.id);
      fd.append('prodotto_id', prodId);
      try {
        const res = await fetch(cfg.apiSdsDelete, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
          if (data.error === 'in_vigore') {
            if (confirm((data.messaggio || 'Impossibile eliminare: scheda in vigore.') + '\n\nVuoi rimuovere solo il file mantenendo la tracciabilità?')) {
              return await rimuoviFile(riga);
            }
          } else {
            alert(data.messaggio || data.error || 'Errore durante l\'eliminazione.');
          }
          return false;
        }
        await loadSds();
        return true;
      } catch (e) {
        console.error(e);
        alert('Errore di comunicazione con il server.');
        return false;
      }
    }

    /* ── Rimuovi solo il file (§5.4bis) ────────────────────────────────────
       Ritorna true solo se il file è stato davvero rimosso (§3.13: azione
       sempre permessa, ma un secondo click su una riga già senza file resta
       un no-op non distruttivo, non un errore — test 26 del piano). */
    async function rimuoviFile(riga) {
      if (!riga) return false;
      if (!riga.file_path) return false; // già rimosso, azione non distruttiva no-op
      const fd = new FormData();
      fd.append('csrf_token', CSRF);
      fd.append('id', riga.id);
      fd.append('prodotto_id', prodId);
      try {
        const res = await fetch(cfg.apiSdsRimuoviFile, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) {
          alert(data.messaggio || data.error || 'Errore durante la rimozione del file.');
          return false;
        }
        await loadSds();
        return true;
      } catch (e) {
        console.error(e);
        alert('Errore di comunicazione con il server.');
        return false;
      }
    }
    btnRimuoviFile?.addEventListener('click', () => {
      const riga = righe.find(r => String(r.id) === String(fId.value));
      if (riga) {
        rimuoviFile(riga).then(ok => { if (ok) closeSdsModal(); });
      }
    });
    btnDelete?.addEventListener('click', () => {
      const riga = righe.find(r => String(r.id) === String(fId.value));
      if (riga) {
        eliminaRiga(riga).then(ok => { if (ok) closeSdsModal(); });
      }
    });

    /* ── Caricamento lazy al primo accesso al tab ─────────────────────── */
    tabBtn.addEventListener('shown.bs.tab', () => {
      if (!loaded) loadSds();
    });
  });
})();
