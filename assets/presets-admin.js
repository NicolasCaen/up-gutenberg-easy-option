(function () {
  const data = window.UPEGEPresetsData || {};
  const list = data.presets || [];
  const container = document.getElementById('up-ge-presets-list');

  if (!container) {
    return;
  }

  if (!list.length) {
    container.innerHTML = '<p>Aucune préconfiguration disponible pour le moment.</p>';
    return;
  }

  const table = document.createElement('table');
  table.className = 'widefat striped';
  table.innerHTML = `
    <thead>
      <tr>
        <th>Fichier</th>
        <th>Bloc(s)</th>
        <th>Panneau</th>
        <th>Contrôles</th>
        <th></th>
      </tr>
    </thead>
    <tbody></tbody>
  `;

  const tbody = table.querySelector('tbody');

  list.forEach((preset) => {
    const row = document.createElement('tr');
    const info = document.createElement('td');
    info.innerHTML = `
      <strong>${preset.file}</strong><br>
      <small>${preset.meta}</small>
    `;
    row.appendChild(info);

    const blocksTd = document.createElement('td');
    blocksTd.textContent = preset.blocks || 'Inconnu';
    row.appendChild(blocksTd);

    const panelTd = document.createElement('td');
    panelTd.textContent = preset.panel || 'Inconnu';
    row.appendChild(panelTd);

    const countTd = document.createElement('td');
    countTd.textContent = preset.count || 0;
    row.appendChild(countTd);

    const actionsTd = document.createElement('td');
    const form = document.createElement('form');
    form.method = 'post';
    form.action = data.importUrl;
    form.innerHTML = `
      <input type="hidden" name="action" value="up_ge_import_preset">
      <input type="hidden" name="preset_file" value="${preset.file}">
      <input type="hidden" name="_wpnonce" value="${data.nonce}">
      <input type="submit" class="button" value="Importer">
    `;
    actionsTd.appendChild(form);
    row.appendChild(actionsTd);
    tbody.appendChild(row);

    const detailsRow = document.createElement('tr');
    const detailsCell = document.createElement('td');
    detailsCell.colSpan = 5;
    const details = document.createElement('details');
    const summary = document.createElement('summary');
    summary.textContent = 'Afficher le JSON';
    const pre = document.createElement('pre');
    pre.textContent = preset.raw || '';
    pre.style.maxHeight = '300px';
    pre.style.overflow = 'auto';
    pre.style.background = '#f6f7f7';
    pre.style.padding = '1rem';
    details.appendChild(summary);
    details.appendChild(pre);
    detailsCell.appendChild(details);
    detailsRow.appendChild(detailsCell);
    tbody.appendChild(detailsRow);
  });

  container.appendChild(table);
})();
