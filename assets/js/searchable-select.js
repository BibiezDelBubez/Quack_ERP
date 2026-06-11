/**
 * SEARCHABLE-SELECT.JS — Autocomplete / Combobox in Vanilla JS per Quack ERP.
 * Migliora l'usabilità dei select standard rendendoli ricercabili offline.
 */
class SearchableSelect {
  constructor(selectElement) {
    this.select = selectElement;
    if (this.select.dataset.searchableSelectInit) return; // Già inizializzato
    
    this.select.dataset.searchableSelectInit = 'true';
    this.options = Array.from(this.select.options);
    this.wrapper = null;
    this.input = null;
    this.dropdown = null;
    this.highlightedIndex = -1;
    
    this.init();
  }
  
  init() {
    // Nascondi il select originale
    this.select.style.display = 'none';
    
    // Crea il wrapper
    this.wrapper = document.createElement('div');
    this.wrapper.className = 'searchable-select-wrapper';
    
    // Copia classi dal select originale per mantenere lo stile corretto (es. qk-form-control-sm)
    const originalClasses = Array.from(this.select.classList).filter(c => c.startsWith('qk-form-control'));
    
    // Crea l'input di ricerca/visualizzazione
    this.input = document.createElement('input');
    this.input.type = 'text';
    this.input.autocomplete = 'off';
    this.input.className = 'searchable-select-input ' + (originalClasses.join(' ') || 'qk-form-control');
    this.input.placeholder = this.select.options[0]?.text || 'Cerca...';
    
    // Imposta il valore iniziale se c'è un'opzione selezionata
    const selectedOption = this.select.options[this.select.selectedIndex];
    if (selectedOption && selectedOption.value !== '') {
      this.input.value = selectedOption.text;
    } else {
      this.input.value = '';
    }
    
    // Copia l'attributo 'required' per validazione form nativa
    if (this.select.required) {
      this.input.required = true;
    }
    
    // Crea il dropdown delle opzioni
    this.dropdown = document.createElement('div');
    this.dropdown.className = 'searchable-select-dropdown';
    
    // Inserisci gli elementi nel DOM
    this.select.parentNode.insertBefore(this.wrapper, this.select);
    this.wrapper.appendChild(this.input);
    this.wrapper.appendChild(this.dropdown);
    this.wrapper.appendChild(this.select); // Sposta il select originale dentro il wrapper per coerenza
    
    this.renderDropdownItems();
    this.bindEvents();
  }
  
  escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;")
              .replace(/</g, "&lt;")
              .replace(/>/g, "&gt;")
              .replace(/"/g, "&quot;")
              .replace(/'/g, "&#039;");
  }

  renderDropdownItems(filterText = '') {
    this.dropdown.innerHTML = '';
    const filteredOptions = this.options.filter(opt => {
      // Ignora l'opzione segnaposto vuota durante la ricerca
      if (opt.value === '' && filterText !== '') return false;
      return opt.text.toLowerCase().includes(filterText.toLowerCase());
    });
    
    if (filteredOptions.length === 0) {
      const emptyItem = document.createElement('div');
      emptyItem.className = 'searchable-select-item is-empty-placeholder';
      emptyItem.textContent = 'Nessun listino corrispondente';
      this.dropdown.appendChild(emptyItem);
      this.highlightedIndex = -1;
      return;
    }
    
    filteredOptions.forEach((opt, idx) => {
      const item = document.createElement('div');
      item.className = 'searchable-select-item';
      const isPlaceholder = opt.value === '';
      
      if (isPlaceholder) {
        item.className += ' is-placeholder';
      }
      
      // Controlla se è selezionato
      if (this.select.value === opt.value) {
        item.classList.add('is-selected');
      }
      
      // Icona coerente
      const iconHtml = isPlaceholder
        ? '<i class="fa-solid fa-slash me-2" style="opacity: 0.4;"></i>'
        : '<i class="fa-solid fa-tag me-2" style="color: var(--qk-accent);"></i>';
      
      item.innerHTML = iconHtml + `<span>${this.escapeHtml(opt.text)}</span>`;
      item.dataset.value = opt.value;
      
      item.addEventListener('click', (e) => {
        e.stopPropagation();
        this.selectValue(opt.value, opt.text);
        this.close();
      });
      
      this.dropdown.appendChild(item);
    });
    
    this.highlightedIndex = -1;
  }
  
  selectValue(val, text) {
    this.select.value = val;
    this.input.value = val === '' ? '' : text;
    
    // Innesca l'evento change sul select originale per far funzionare script dipendenti
    const event = new Event('change', { bubbles: true });
    this.select.dispatchEvent(event);
    
    // Rendi valido il campo di ricerca per i controlli HTML5
    if (this.input.required) {
      this.input.setCustomValidity('');
    }
  }
  
  open() {
    this.wrapper.classList.add('is-open');
    this.dropdown.style.display = 'block';
    
    // Se l'input ha un valore, svuotalo temporaneamente per mostrare tutte le opzioni,
    // o seleziona tutto il testo per agevolare la sovrascrittura.
    this.input.select();
    
    this.renderDropdownItems(this.input.value);
  }
  
  close() {
    this.wrapper.classList.remove('is-open');
    this.dropdown.style.display = 'none';
    
    // Ripristina il valore testuale corretto basato sulla selezione reale
    const selectedOption = this.select.options[this.select.selectedIndex];
    if (selectedOption && selectedOption.value !== '') {
      this.input.value = selectedOption.text;
    } else {
      this.input.value = '';
    }
  }
  
  bindEvents() {
    // Click sull'input apre il dropdown
    this.input.addEventListener('click', (e) => {
      e.stopPropagation();
      this.open();
    });
    
    this.input.addEventListener('focus', () => {
      this.open();
    });
    
    // Ricerca mentre si digita
    this.input.addEventListener('input', () => {
      this.renderDropdownItems(this.input.value);
    });
    
    // Navigazione da tastiera
    this.input.addEventListener('keydown', (e) => {
      const items = this.dropdown.querySelectorAll('.searchable-select-item:not(.is-empty-placeholder)');
      if (items.length === 0) return;
      
      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          this.highlightedIndex = (this.highlightedIndex + 1) % items.length;
          this.highlightItem(items);
          break;
        case 'ArrowUp':
          e.preventDefault();
          this.highlightedIndex = (this.highlightedIndex - 1 + items.length) % items.length;
          this.highlightItem(items);
          break;
        case 'Enter':
          e.preventDefault();
          if (this.highlightedIndex >= 0 && this.highlightedIndex < items.length) {
            const item = items[this.highlightedIndex];
            this.selectValue(item.dataset.value, item.textContent);
            this.close();
          }
          break;
        case 'Escape':
          this.close();
          this.input.blur();
          break;
      }
    });
    
    // Chiudi al clic esterno
    document.addEventListener('click', (e) => {
      if (!this.wrapper.contains(e.target)) {
        this.close();
      }
    });
  }
  
  highlightItem(items) {
    items.forEach((item, idx) => {
      if (idx === this.highlightedIndex) {
        item.classList.add('is-highlighted');
        // Scorri il dropdown per visualizzare l'elemento evidenziato
        item.scrollIntoView({ block: 'nearest' });
      } else {
        item.classList.remove('is-highlighted');
      }
    });
  }
}

// Inizializzatore globale
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('select.searchable-select').forEach(el => {
    new SearchableSelect(el);
  });
});
