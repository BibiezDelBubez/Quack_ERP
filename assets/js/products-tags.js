document.addEventListener('DOMContentLoaded', () => {
  const modalEl = document.getElementById('modal-tag');
  if (!modalEl) return;

  modalEl.addEventListener('show.bs.modal', (e) => {
    const trigger = e.relatedTarget;
    if (!trigger) return;

    const mode = trigger.getAttribute('data-mode');
    const titleEl = document.getElementById('tag-modal-title');
    const idInput = document.getElementById('tag-id');
    const nomeInput = document.getElementById('tag-nome');

    if (mode === 'create') {
      titleEl.innerText = 'Nuovo Tag';
      idInput.value = '';
      nomeInput.value = '';
    } else {
      titleEl.innerText = 'Modifica Tag';
      const id = trigger.getAttribute('data-id');
      const nome = trigger.getAttribute('data-nome');

      idInput.value = id;
      nomeInput.value = nome;
    }
  });
});
