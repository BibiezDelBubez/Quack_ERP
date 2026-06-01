<div id="modal-quick-fornitore"
     style="display:none;position:fixed;inset:0;z-index:1050;
            background:rgba(0,0,0,.45);align-items:center;justify-content:center">
  <div style="background:var(--qk-card-bg);border:1px solid var(--qk-border);
              border-radius:var(--qk-radius-lg);padding:1.5rem;width:100%;max-width:420px">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
      <strong><i class="fa-solid fa-truck-ramp-box me-2"></i>Nuovo fornitore rapido</strong>
      <button type="button" onclick="document.getElementById('modal-quick-fornitore').style.display='none'"
              style="background:none;border:none;cursor:pointer;font-size:1.2rem;color:var(--qk-text-muted)">&times;</button>
    </div>
    <p style="font-size:.82rem;color:var(--qk-text-muted);margin-bottom:1rem">
      <i class="fa-solid fa-circle-info me-1"></i>
      Crea rapidamente il fornitore e completa i dettagli dalla scheda Anagrafica.
    </p>
    <div id="qf-error" class="qk-alert qk-alert-danger mb-3" style="display:none">
      <i class="fa-solid fa-circle-xmark"></i> <span id="qf-error-msg"></span>
    </div>
    <div class="qk-form-group">
      <label class="qk-form-label">Ragione Sociale <span style="color:var(--qk-danger)">*</span></label>
      <input type="text" id="qf-ragione-sociale" class="qk-form-control"
             placeholder="Es. Rossi S.r.l." autocomplete="off">
    </div>
    <div class="qk-form-group">
      <label class="qk-form-label">Partita IVA</label>
      <input type="text" id="qf-partita-iva" class="qk-form-control" placeholder="IT12345678901">
    </div>
    <div style="display:flex;justify-content:flex-end;gap:.5rem;margin-top:1rem">
      <button type="button" class="btn-qk btn-qk-ghost"
              onclick="document.getElementById('modal-quick-fornitore').style.display='none'">
        Annulla
      </button>
      <button type="button" class="btn-qk btn-qk-primary" id="btn-qf-salva">
        <i class="fa-solid fa-floppy-disk"></i> Salva fornitore
      </button>
    </div>
  </div>
</div>
