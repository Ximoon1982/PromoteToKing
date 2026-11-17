/* Release Control batch selectors. External script required by the strict CSP. */
(() => {
  'use strict';
  for (const kind of ['release', 'filesystem']) {
    const form = document.getElementById(kind === 'release' ? 'rcReleaseBatchForm' : 'rcFilesystemBatchForm');
    if (!form) continue;
    const items = [...form.querySelectorAll('[data-batch-item="' + kind + '"]')];
    const all = form.querySelector('[data-batch-all="' + kind + '"]');
    const count = form.querySelector('[data-batch-count="' + kind + '"]');
    const button = form.querySelector('button[type="submit"]');
    const update = () => {
      const selected = items.filter(input => input.checked).length;
      if (count) count.textContent = String(selected);
      if (button) button.disabled = selected === 0;
      if (all) {
        all.checked = items.length > 0 && selected === items.length;
        all.indeterminate = selected > 0 && selected < items.length;
      }
    };
    for (const input of items) input.addEventListener('change', update);
    if (all) all.addEventListener('change', () => {
      for (const input of items) input.checked = all.checked;
      update();
    });
    form.addEventListener('submit', event => {
      const selected = items.filter(input => input.checked).length;
      if (selected === 0) {
        event.preventDefault();
        return;
      }
      if (!window.confirm('Permanently delete ' + selected + ' selected ' +
          (kind === 'release' ? 'obsolete release(s)' : 'maintenance artifact(s)') +
          '? Protection rules will be checked again.')) event.preventDefault();
    });
    update();
  }
})();
