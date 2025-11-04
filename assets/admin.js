(function () {
  const data = window.UPGEAdminData || {};
  const strings = data.strings || {};
  const ajax = data.ajax || {};
  const app = document.getElementById('up-ge-admin-app');
  const input = document.getElementById('up-ge-config-input');

  if (!app || !input) {
    return;
  }

  if (!document.getElementById('up-ge-admin-styles')) {
    const style = document.createElement('style');
    style.id = 'up-ge-admin-styles';
    style.textContent = `
      #up-ge-admin-app { margin-top: 1.5rem; }
      .up-ge-block { border: 1px solid #dcdcde; background: #fff; padding: 1rem; margin-bottom: 1.5rem; }
      .up-ge-block-header { display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; margin-bottom: 1rem; }
      .up-ge-block-header input[type="text"] { min-width: 220px; }
      .up-ge-controls { margin-left: 0.5rem; }
      .up-ge-panel { border: 1px solid #e2e4e7; background: #fff; padding: 0.75rem; margin-bottom: 0.75rem; }
      .up-ge-panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
      .up-ge-panel-title { font-weight: 600; }
      .up-ge-control { border: 1px solid #e2e4e7; background: #f6f7f7; padding: 0.75rem; margin-bottom: 0.75rem; }
      .up-ge-control h4 { margin: 0 0 0.5rem; font-size: 15px; }
      .up-ge-field { margin-bottom: 0.6rem; }
      .up-ge-field label { display: block; font-weight: 600; margin-bottom: 0.2rem; }
      .up-ge-options { margin-left: 0.5rem; }
      .up-ge-option { border: 1px dashed #c3c4c7; background: #fff; padding: 0.5rem; margin-bottom: 0.5rem; }
      .up-ge-option-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
      .up-ge-flex { display: flex; gap: 0.75rem; flex-wrap: wrap; }
      .up-ge-actions { margin-top: 0.75rem; display: flex; gap: 0.5rem; }
      .up-ge-empty { font-style: italic; color: #646970; margin-bottom: 0.5rem; }
      .up-ge-badge { display: inline-block; padding: 2px 6px; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 3px; font-size: 11px; margin-right: 6px; }
      .up-ge-chip { display: inline-block; padding: 2px 6px; background: #eef6ff; border: 1px solid #a8d1ff; border-radius: 14px; font-size: 11px; margin: 2px 4px 2px 0; }
      .up-ge-help { color: #646970; font-size: 12px; margin: 4px 0 8px; }
      .up-ge-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; align-items: end; }
      .up-ge-col { min-width: 0; }
      @media (max-width: 960px) { .up-ge-row { grid-template-columns: 1fr; } }
    `;
    document.head.appendChild(style);
  }

  function arrayToString(value) {
    if (Array.isArray(value)) {
      return value.join(' ');
    }
    if (typeof value === 'string') {
      return value;
    }
    return '';
  }

  function stringToArray(value) {
    if (Array.isArray(value)) {
      return value.filter(Boolean);
    }
    if (typeof value !== 'string') {
      return [];
    }
    return value
      .replace(/,/g, ' ')
      .split(/\s+/)
      .map((cls) => cls.trim())
      .filter(Boolean);
  }

  function toNumberString(value) {
    if (value === null || value === undefined) {
      return '';
    }
    if (typeof value === 'number') {
      return Number.isFinite(value) ? String(value) : '';
    }
    const str = String(value).trim();
    return str;
  }

  function parseNumber(value) {
    if (value === null || value === undefined) {
      return null;
    }
    if (typeof value === 'string') {
      const trimmed = value.trim();
      if (trimmed === '') {
        return null;
      }
      value = trimmed;
    }
    const num = Number(value);
    return Number.isFinite(num) ? num : null;
  }

  function cloneControl(ctrl) {
    return {
      type: ctrl.type || 'toggle',
      id: ctrl.id || '',
      label: ctrl.label || '',
      panel: ctrl.panel || '',
      description: ctrl.description || '',
      classes: arrayToString(ctrl.classes || ctrl.class || ''),
      class: typeof ctrl.class === 'string' ? ctrl.class : '',
      source: typeof ctrl.source === 'string' ? ctrl.source : '',
      extra: arrayToString(ctrl.extra || ''),
      min: toNumberString(ctrl.min),
      max: toNumberString(ctrl.max),
      step: toNumberString(ctrl.step),
      default: toNumberString(ctrl.default),
      options: (ctrl.options || []).map((opt) => ({
        id: opt.id || '',
        label: opt.label || '',
        classes: arrayToString(opt.classes || opt.class || ''),
      })),
      _collapsed: true,
    };
  }

  let state = Object.keys(data.config || {}).map((blockName) => ({
    name: blockName,
    controls: (data.config[blockName] || []).map((ctrl) => cloneControl(ctrl)),
  }));

  if (!state.length) {
    state.push({ name: '', controls: [] });
  }

  function ensureControlsArray(block) {
    if (!Array.isArray(block.controls)) {
      block.controls = [];
    }
    block.controls.forEach((control) => {
      if (!control || typeof control !== 'object') return;
      control.type = control.type || 'toggle';
      control.id = control.id || '';
      control.label = control.label || '';
      control.panel = control.panel || '';
      control.description = control.description || '';
      control.extra = arrayToString(control.extra || '');
      if (typeof control._collapsed !== 'boolean') {
        control._collapsed = true;
      }
      if (control.type === 'number') {
        control.class = typeof control.class === 'string' ? control.class : '';
        control.min = toNumberString(control.min);
        control.max = toNumberString(control.max);
        control.step = toNumberString(control.step);
        control.default = toNumberString(control.default);
      } else if (control.type === 'palette') {
        control.class = typeof control.class === 'string' ? control.class : '';
        control.source = typeof control.source === 'string' ? control.source : '';
      }
    });
  }

  function sanitizeControl(control) {
    const type = control.type || 'toggle';
    const controlId = (control.id || '').trim();
    const controlLabel = (control.label || '').trim();
    if (!controlId || !controlLabel) {
      return null;
    }

    const result = {
      id: controlId,
      label: controlLabel,
      type,
    };

    if (control.panel && control.panel.trim()) {
      result.panel = control.panel.trim();
    }

    if (control.description && control.description.trim()) {
      result.description = control.description.trim();
    }

    if (type === 'toggle') {
      const classes = stringToArray(control.classes);
      if (!classes.length) {
        return null;
      }
      result.classes = classes;
    } else if (type === 'palette') {
      const classPrefix = (control.class || '').trim();
      const source = (control.source || '').trim();
      if (!classPrefix || !source) {
        return null;
      }
      result.type = 'palette';
      result.class = classPrefix;
      result.source = source;
    } else if (type === 'number') {
      const classPrefix = (control.class || '').trim();
      if (!classPrefix) {
        return null;
      }

      const min = parseNumber(control.min);
      const max = parseNumber(control.max);
      let normalizedMin = min;
      let normalizedMax = max;
      if (normalizedMin !== null && normalizedMax !== null && normalizedMin > normalizedMax) {
        normalizedMin = max;
        normalizedMax = min;
      }

      let step = parseNumber(control.step);
      if (step !== null && step <= 0) {
        step = null;
      }

      let defaultValue = parseNumber(control.default);
      if (defaultValue !== null) {
        if (normalizedMin !== null && defaultValue < normalizedMin) {
          defaultValue = normalizedMin;
        }
        if (normalizedMax !== null && defaultValue > normalizedMax) {
          defaultValue = normalizedMax;
        }
      }

      result.type = 'number';
      result.class = classPrefix;
      if (normalizedMin !== null) {
        result.min = normalizedMin;
      }
      if (normalizedMax !== null) {
        result.max = normalizedMax;
      }
      if (step !== null) {
        result.step = step;
      }
      if (defaultValue !== null) {
        result.default = defaultValue;
      }
    } else {
      const options = [];
      (control.options || []).forEach((opt) => {
        const optId = (opt.id || '').trim();
        const optLabel = (opt.label || '').trim();
        const optClasses = stringToArray(opt.classes);
        if (optId && optLabel && optClasses.length) {
          options.push({ id: optId, label: optLabel, classes: optClasses });
        }
      });
      if (!options.length) {
        return null;
      }
      result.options = options;
    }

    // Extra classes for all types (applied unconditionally in editor)
    const extraArr = stringToArray(control.extra || '');
    if (extraArr.length) {
      result.extra = extraArr;
    }

    return result;
  }

  function collectBlockControls(block, targetPanel) {
    ensureControlsArray(block);
    const controls = [];
    const panelKey = (value) => {
      const name = (value || '').trim();
      return name ? name : 'Options UP';
    };

    block.controls.forEach((control) => {
      const sanitized = sanitizeControl(control);
      if (!sanitized) {
        return;
      }
      const currentPanel = panelKey(control.panel);
      if (targetPanel && currentPanel !== targetPanel) {
        return;
      }
      controls.push(sanitized);
    });

    return controls;
  }

  // Clipboard pour copier/coller un contrôle entre panneaux
  let clipboardControl = null;

  function copyControl(control) {
    const sanitized = sanitizeControl(control);
    if (!sanitized) return false;
    clipboardControl = sanitized;
    return true;
  }

  function toEditableControl(sanitized) {
    // Convertit un contrôle "sanitized" (classes en array) au format éditable (classes en string)
    const base = {
      type: sanitized.type || 'toggle',
      id: sanitized.id || '',
      label: sanitized.label || '',
      panel: sanitized.panel || '',
      description: sanitized.description || '',
      classes: '',
      options: [],
      class: sanitized.class || '',
      extra: arrayToString(sanitized.extra || ''),
      min: toNumberString(sanitized.min),
      max: toNumberString(sanitized.max),
      step: toNumberString(sanitized.step),
      default: toNumberString(sanitized.default),
      _collapsed: false,
    };
    if (base.type === 'toggle') {
      base.classes = arrayToString(sanitized.classes || []);
    } else if (base.type === 'select' || base.type === 'preset') {
      base.options = (sanitized.options || []).map((opt) => ({
        id: opt.id || '',
        label: opt.label || '',
        classes: arrayToString(opt.classes || []),
      }));
    } else if (base.type === 'number') {
      base.class = sanitized.class || '';
    } else if (base.type === 'palette') {
      base.class = sanitized.class || '';
      base.source = sanitized.source || '';
    }
    return base;
  }

  function ensureUniqueId(block, desiredId) {
    const existing = new Set((block.controls || []).map((c) => (c.id || '').trim()).filter(Boolean));
    if (!existing.has(desiredId)) return desiredId;
    let i = 2;
    while (existing.has(`${desiredId}-copy${i}`)) i++;
    return existing.has(`${desiredId}-copy`) ? `${desiredId}-copy${i}` : `${desiredId}-copy`;
  }

  function pasteControlIntoPanel(block, panelName) {
    if (!clipboardControl) return false;
    ensureControlsArray(block);
    const editable = toEditableControl(clipboardControl);
    editable.panel = panelName || editable.panel || '';
    // Assurer un id unique dans ce bloc
    const desiredId = (editable.id || '').trim() || 'control';
    editable.id = ensureUniqueId(block, desiredId);
    block.controls.push(editable);
    return true;
  }

  function updateInput() {
    const output = {};

    state.forEach((block) => {
      const blockName = (block.name || '').trim();
      if (!blockName) {
        return;
      }

      const controls = collectBlockControls(block);

      if (controls.length) {
        output[blockName] = controls;
      }
    });

    input.value = JSON.stringify(output);
  }

  function createField(labelText, inputEl) {
    const wrapper = document.createElement('div');
    wrapper.className = 'up-ge-field';

    const label = document.createElement('label');
    label.textContent = labelText;
    wrapper.appendChild(label);
    wrapper.appendChild(inputEl);

    return wrapper;
  }

  function addControl(block, type) {
    ensureControlsArray(block);
    const control = {
      type: type || 'toggle',
      id: '',
      label: '',
      panel: '',
      description: '',
      classes: '',
      class: '',
      source: '',
      extra: '',
      min: '',
      max: '',
      step: '',
      default: '',
      options: [],
      _collapsed: true,
    };
    block.controls.push(control);
  }

  function addOption(control) {
    if (!Array.isArray(control.options)) {
      control.options = [];
    }
    control.options.push({ id: '', label: '', classes: '' });
  }

  function render() {
    app.innerHTML = '';

    state.forEach((block, blockIndex) => {
      ensureControlsArray(block);
      const blockEl = document.createElement('div');
      blockEl.className = 'up-ge-block';

      const header = document.createElement('div');
      header.className = 'up-ge-block-header';

      const title = document.createElement('strong');
      title.textContent = strings.blocks || 'Bloc';
      header.appendChild(title);

      const nameInput = document.createElement('input');
      nameInput.type = 'text';
      nameInput.className = 'regular-text';
      nameInput.placeholder = strings.blockNamePlaceholder || 'core/paragraph, core/heading';
      nameInput.value = block.name || '';
      nameInput.addEventListener('input', (e) => {
        block.name = e.target.value;
        updateInput();
      });
      header.appendChild(nameInput);

      const removeBlock = document.createElement('button');
      removeBlock.type = 'button';
      removeBlock.className = 'button button-link-delete';
      removeBlock.textContent = strings.remove || 'Supprimer';
      removeBlock.addEventListener('click', () => {
        state.splice(blockIndex, 1);
        if (!state.length) {
          state.push({ name: '', controls: [] });
        }
        render();
        updateInput();
      });
      header.appendChild(removeBlock);

      blockEl.appendChild(header);

      const controlsWrapper = document.createElement('div');
      controlsWrapper.className = 'up-ge-controls';

      if (!block.controls.length) {
        const empty = document.createElement('div');
        empty.className = 'up-ge-empty';
        empty.textContent = strings.addControl || 'Ajouter un contrôle';
        controlsWrapper.appendChild(empty);
      }

      // Grouper par panel
      const panels = {};
      block.controls.forEach((control, controlIndex) => {
        const panelKey = (control.panel || 'Options UP').trim() || 'Options UP';
        if (!panels[panelKey]) {
          panels[panelKey] = [];
        }
        panels[panelKey].push({ control, controlIndex });
      });

      Object.keys(panels).forEach((panelName) => {
        const panelEl = document.createElement('div');
        panelEl.className = 'up-ge-panel';

        const header = document.createElement('div');
        header.className = 'up-ge-panel-header';
        const title = document.createElement('div');
        title.className = 'up-ge-panel-title';
        title.textContent = `${panelName} – Blocs: ${block.name || ''}`;
        header.appendChild(title);

        const actionsWrap = document.createElement('div');
        actionsWrap.className = 'up-ge-actions';

        const savePanelBtn = document.createElement('button');
        savePanelBtn.type = 'button';
        savePanelBtn.className = 'button button-secondary';
        savePanelBtn.textContent = strings.savePanel || 'Enregistrer le panneau comme préconfig';
        savePanelBtn.addEventListener('click', () => {
          if (!ajax.url || !ajax.nonce) {
            window.alert(strings.panelSaveError || 'Erreur AJAX.');
            return;
          }

          const blockName = (block.name || '').trim();
          if (!blockName) {
            window.alert(strings.panelSaveError || 'Erreur AJAX.');
            return;
          }

          const sanitizedControls = collectBlockControls(block, panelName);
          if (!sanitizedControls.length) {
            window.alert(strings.panelSaveError || 'Erreur AJAX.');
            return;
          }

          const formData = new FormData();
          formData.append('action', 'up_ge_save_panel_preset');
          formData.append('nonce', ajax.nonce);
          formData.append('block', block.name || '');
          formData.append('panel', panelName);
          formData.append('controls', JSON.stringify(sanitizedControls));

          const originalText = savePanelBtn.textContent;
          savePanelBtn.disabled = true;
          savePanelBtn.textContent = strings.savingPanel || 'Enregistrement…';

          fetch(ajax.url, { method: 'POST', body: formData })
            .then((response) => response.json())
            .then((json) => {
              if (json && json.success) {
                savePanelBtn.textContent = strings.panelSaved || 'Préconfiguration enregistrée.';
                setTimeout(() => {
                  savePanelBtn.disabled = false;
                  savePanelBtn.textContent = originalText;
                }, 2000);
              } else {
                throw new Error((json && json.data && json.data.message) || 'error');
              }
            })
            .catch(() => {
              savePanelBtn.disabled = false;
              savePanelBtn.textContent = originalText;
              window.alert(strings.panelSaveError || 'Impossible d’enregistrer la préconfiguration.');
            });
        });
        actionsWrap.appendChild(savePanelBtn);

        const pasteBtn = document.createElement('button');
        pasteBtn.type = 'button';
        pasteBtn.className = 'button';
        pasteBtn.textContent = 'Coller dans le panneau';
        pasteBtn.disabled = !clipboardControl;
        pasteBtn.addEventListener('click', () => {
          if (!clipboardControl) return;
          const ok = pasteControlIntoPanel(block, panelName);
          if (ok) {
            render();
            updateInput();
          }
        });
        actionsWrap.appendChild(pasteBtn);

        const editAll = document.createElement('button');
        editAll.type = 'button';
        editAll.className = 'button';
        editAll.textContent = 'Éditer le panneau';
        editAll.addEventListener('click', () => {
          panels[panelName].forEach(({ control }) => control._collapsed = false);
          render();
        });
        actionsWrap.appendChild(editAll);

        header.appendChild(actionsWrap);
        panelEl.appendChild(header);

        panels[panelName].forEach(({ control, controlIndex }) => {
          const controlEl = document.createElement('div');
          controlEl.className = 'up-ge-control';

          const controlTitle = document.createElement('h4');
          controlTitle.textContent = control.label || control.id || (strings.addControl || 'Contrôle');
          controlEl.appendChild(controlTitle);

          if (control._collapsed) {
            // Aperçu
            const summary = document.createElement('div');
            const badge = document.createElement('span');
            badge.className = 'up-ge-badge';
            badge.textContent = control.type || 'toggle';
            summary.appendChild(badge);

            if (control.type === 'toggle') {
              const chips = (control.classes || '').split(/\s+/).filter(Boolean);
              chips.forEach((c) => {
                const chip = document.createElement('span');
                chip.className = 'up-ge-chip';
                chip.textContent = c;
                summary.appendChild(chip);
              });
            } else {
              (control.options || []).forEach((opt) => {
                const optWrap = document.createElement('div');
                const optBadge = document.createElement('span');
                optBadge.className = 'up-ge-badge';
                optBadge.textContent = opt.id || opt.label || 'option';
                optWrap.appendChild(optBadge);
                const chips = (opt.classes || '').split(/\s+/).filter(Boolean);
                chips.forEach((c) => {
                  const chip = document.createElement('span');
                  chip.className = 'up-ge-chip';
                  chip.textContent = c;
                  optWrap.appendChild(chip);
                });
                summary.appendChild(optWrap);
              });
            }

            const actions = document.createElement('div');
            actions.className = 'up-ge-actions';
            const editBtn = document.createElement('button');
            editBtn.type = 'button';
            editBtn.className = 'button';
            editBtn.textContent = 'Éditer';
            editBtn.addEventListener('click', () => {
              control._collapsed = false;
              render();
            });
            actions.appendChild(editBtn);

            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'button';
            copyBtn.textContent = 'Copier';
            copyBtn.addEventListener('click', () => {
              if (copyControl(control)) {
                render(); // met à jour l'état du bouton "Coller"
              }
            });
            actions.appendChild(copyBtn);
            controlEl.appendChild(summary);
            controlEl.appendChild(actions);
          } else {
            // Édition complète (rendu avec 'Panneau' en premier)
            const panelInput = document.createElement('input');
            panelInput.type = 'text';
            panelInput.className = 'regular-text';
            panelInput.value = control.panel || '';
            panelInput.addEventListener('input', (e) => {
              control.panel = e.target.value;
              updateInput();
            });
            const panelField = createField(strings.panel || 'Panneau', panelInput);
            const panelHelp = document.createElement('div');
            panelHelp.className = 'up-ge-help';
            panelHelp.textContent = 'Pour mettre dans le même panneau, écrivez exactement le même nom.';
            panelField.appendChild(panelHelp);
            controlEl.appendChild(panelField);

            // Ligne: Type, Identifiant de l'input, Label de l'input
            const mainRow = document.createElement('div');
            mainRow.className = 'up-ge-row';

            const typeSelect = document.createElement('select');
            typeSelect.innerHTML = [
              { value: 'toggle', label: strings.toggle || 'Toggle' },
              { value: 'select', label: strings.select || 'Select' },
              { value: 'preset', label: strings.preset || 'Preset' },
              { value: 'number', label: strings.number || 'Nombre' },
              { value: 'palette', label: strings.palette || 'Palette' },
            ]
              .map((option) => `<option value=\"${option.value}\">${option.label}</option>`)
              .join('');
            typeSelect.value = control.type || 'toggle';
            typeSelect.addEventListener('change', (e) => {
              control.type = e.target.value;
              if (control.type === 'toggle') {
                control.options = [];
                control.classes = '';
              } else if (control.type === 'number') {
                control.options = [];
              } else if (control.type === 'palette') {
                control.options = [];
              } else if (!Array.isArray(control.options)) {
                control.options = [];
              }
              render();
              updateInput();
            });
            const typeCol = document.createElement('div');
            typeCol.className = 'up-ge-col';
            typeCol.appendChild(createField(strings.type || 'Type', typeSelect));
            mainRow.appendChild(typeCol);

            const idInput = document.createElement('input');
            idInput.type = 'text';
            idInput.className = 'regular-text';
            idInput.value = control.id || '';
            idInput.addEventListener('input', (e) => {
              control.id = e.target.value;
              updateInput();
            });
            const idCol = document.createElement('div');
            idCol.className = 'up-ge-col';
            idCol.appendChild(createField('Identifiant de l\'input', idInput));
            mainRow.appendChild(idCol);

            const labelInput = document.createElement('input');
            labelInput.type = 'text';
            labelInput.className = 'regular-text';
            labelInput.value = control.label || '';
            labelInput.addEventListener('input', (e) => {
              control.label = e.target.value;
              controlTitle.textContent = e.target.value || control.id || (strings.addControl || 'Contrôle');
              updateInput();
            });
            const labelCol = document.createElement('div');
            labelCol.className = 'up-ge-col';
            labelCol.appendChild(createField('Label de l\'input', labelInput));
            mainRow.appendChild(labelCol);

            controlEl.appendChild(mainRow);

            const descInput = document.createElement('textarea');
            descInput.className = 'large-text';
            descInput.rows = 2;
            descInput.value = control.description || '';
            descInput.addEventListener('input', (e) => {
              control.description = e.target.value;
              updateInput();
            });
            controlEl.appendChild(createField(strings.description || 'Description', descInput));

            // Extra classes (applied unconditionally)
            const extraInput = document.createElement('input');
            extraInput.type = 'text';
            extraInput.className = 'regular-text';
            extraInput.placeholder = 'has-color-bg-test';
            extraInput.value = control.extra || '';
            extraInput.addEventListener('input', (e) => {
              control.extra = e.target.value;
              updateInput();
            });
            controlEl.appendChild(createField(strings.extraClasses || 'Classes supplémentaires', extraInput));

            if (control.type === 'toggle') {
              const classesInput = document.createElement('input');
              classesInput.type = 'text';
              classesInput.className = 'regular-text';
              classesInput.placeholder = 'is-large is-bold';
              classesInput.value = control.classes || '';
              classesInput.addEventListener('input', (e) => {
                control.classes = e.target.value;
                updateInput();
              });
              controlEl.appendChild(createField(strings.classes || 'Classes', classesInput));
            } else if (control.type === 'number') {
              const classInput = document.createElement('input');
              classInput.type = 'text';
              classInput.className = 'regular-text';
              classInput.placeholder = 'is-height';
              classInput.value = control.class || '';
              classInput.addEventListener('input', (e) => {
                control.class = e.target.value;
                updateInput();
              });
              controlEl.appendChild(createField(strings.classPrefix || 'Préfixe de classe', classInput));

              const numberRow = document.createElement('div');
              numberRow.className = 'up-ge-row';

              const minInput = document.createElement('input');
              minInput.type = 'number';
              minInput.className = 'regular-text';
              minInput.value = control.min || '';
              minInput.addEventListener('input', (e) => {
                control.min = e.target.value;
                updateInput();
              });
              const minCol = document.createElement('div');
              minCol.className = 'up-ge-col';
              minCol.appendChild(createField(strings.minValue || 'Valeur min', minInput));
              numberRow.appendChild(minCol);

              const maxInput = document.createElement('input');
              maxInput.type = 'number';
              maxInput.className = 'regular-text';
              maxInput.value = control.max || '';
              maxInput.addEventListener('input', (e) => {
                control.max = e.target.value;
                updateInput();
              });
              const maxCol = document.createElement('div');
              maxCol.className = 'up-ge-col';
              maxCol.appendChild(createField(strings.maxValue || 'Valeur max', maxInput));
              numberRow.appendChild(maxCol);

              const stepInput = document.createElement('input');
              stepInput.type = 'number';
              stepInput.className = 'regular-text';
              stepInput.value = control.step || '';
              stepInput.addEventListener('input', (e) => {
                control.step = e.target.value;
                updateInput();
              });
              const stepCol = document.createElement('div');
              stepCol.className = 'up-ge-col';
              stepCol.appendChild(createField(strings.stepValue || 'Pas', stepInput));
              numberRow.appendChild(stepCol);

              controlEl.appendChild(numberRow);

              const defaultInput = document.createElement('input');
              defaultInput.type = 'number';
              defaultInput.className = 'regular-text';
              defaultInput.value = control.default || '';
              defaultInput.addEventListener('input', (e) => {
                control.default = e.target.value;
                updateInput();
              });
              controlEl.appendChild(createField(strings.defaultValue || 'Valeur par défaut', defaultInput));
            } else if (control.type === 'palette') {
              const classInput = document.createElement('input');
              classInput.type = 'text';
              classInput.className = 'regular-text';
              classInput.placeholder = 'has-text';
              classInput.value = control.class || '';
              classInput.addEventListener('input', (e) => {
                control.class = e.target.value;
                updateInput();
              });
              controlEl.appendChild(createField(strings.classPrefix || 'Préfixe de classe', classInput));

              const sourceSelect = document.createElement('select');
              const srcOptions = [
                { value: '', label: '—' },
                { value: 'colors', label: strings.paletteColors || 'Couleurs' },
                { value: 'fontSizes', label: strings.paletteFontSizes || 'Tailles de police' },
                { value: 'spacing', label: 'Espacement' },
              ];
              sourceSelect.innerHTML = srcOptions
                .map((o) => `<option value="${o.value}">${o.label}</option>`)
                .join('');
              sourceSelect.value = control.source || '';
              sourceSelect.addEventListener('change', (e) => {
                control.source = e.target.value;
                updateInput();
              });
              controlEl.appendChild(createField(strings.paletteSource || 'Source', sourceSelect));
            } else {
              const optionsWrapper = document.createElement('div');
              optionsWrapper.className = 'up-ge-options';

              (control.options || []).forEach((opt, optionIndex) => {
                const optionEl = document.createElement('div');
                optionEl.className = 'up-ge-option';

                const heading = document.createElement('div');
                heading.className = 'up-ge-option-title';
                heading.innerHTML = `<strong>${strings.optionLabel || 'Option'} ${optionIndex + 1}</strong>`;

                const removeOption = document.createElement('button');
                removeOption.type = 'button';
                removeOption.className = 'button button-link-delete';
                removeOption.textContent = strings.remove || 'Supprimer';
                removeOption.addEventListener('click', () => {
                  control.options.splice(optionIndex, 1);
                  render();
                  updateInput();
                });
                heading.appendChild(removeOption);
                optionEl.appendChild(heading);

                // Ligne d'option: id, label, classes
                const optRow = document.createElement('div');
                optRow.className = 'up-ge-row';

                const optId = document.createElement('input');
                optId.type = 'text';
                optId.className = 'regular-text';
                optId.value = opt.id || '';
                optId.addEventListener('input', (e) => {
                  opt.id = e.target.value;
                  updateInput();
                });
                const optIdCol = document.createElement('div');
                optIdCol.className = 'up-ge-col';
                optIdCol.appendChild(createField(strings.optionId || 'Identifiant option', optId));
                optRow.appendChild(optIdCol);

                const optLabel = document.createElement('input');
                optLabel.type = 'text';
                optLabel.className = 'regular-text';
                optLabel.value = opt.label || '';
                optLabel.addEventListener('input', (e) => {
                  opt.label = e.target.value;
                  updateInput();
                });
                const optLabelCol = document.createElement('div');
                optLabelCol.className = 'up-ge-col';
                optLabelCol.appendChild(createField(strings.optionLabel || 'Libellé option', optLabel));
                optRow.appendChild(optLabelCol);

                const optClasses = document.createElement('input');
                optClasses.type = 'text';
                optClasses.className = 'regular-text';
                optClasses.placeholder = 'is-large is-bold';
                optClasses.value = opt.classes || '';
                optClasses.addEventListener('input', (e) => {
                  opt.classes = e.target.value;
                  updateInput();
                });
                const optClassesCol = document.createElement('div');
                optClassesCol.className = 'up-ge-col';
                optClassesCol.appendChild(createField(strings.optionClasses || 'Classes option', optClasses));
                optRow.appendChild(optClassesCol);

                optionEl.appendChild(optRow);
                optionsWrapper.appendChild(optionEl);
              });

              const addOptionBtn = document.createElement('button');
              addOptionBtn.type = 'button';
              addOptionBtn.className = 'button';
              addOptionBtn.textContent = strings.optionLabel ? `${strings.optionLabel} +` : 'Ajouter une option';
              addOptionBtn.addEventListener('click', () => {
                addOption(control);
                render();
                updateInput();
              });
              optionsWrapper.appendChild(addOptionBtn);

              controlEl.appendChild(optionsWrapper);
            }

            const controlActions = document.createElement('div');
            controlActions.className = 'up-ge-actions';

            const duplicateBtn = document.createElement('button');
            duplicateBtn.type = 'button';
            duplicateBtn.className = 'button';
            duplicateBtn.textContent = strings.duplicate || 'Dupliquer';
            duplicateBtn.addEventListener('click', () => {
              const cloned = cloneControl(control);
              block.controls.splice(controlIndex + 1, 0, cloned);
              render();
              updateInput();
            });
            controlActions.appendChild(duplicateBtn);

            const copyBtn2 = document.createElement('button');
            copyBtn2.type = 'button';
            copyBtn2.className = 'button';
            copyBtn2.textContent = 'Copier';
            copyBtn2.addEventListener('click', () => {
              copyControl(control);
              render();
            });
            controlActions.appendChild(copyBtn2);

            const collapseBtn = document.createElement('button');
            collapseBtn.type = 'button';
            collapseBtn.className = 'button';
            collapseBtn.textContent = 'Retour aperçu';
            collapseBtn.addEventListener('click', () => {
              control._collapsed = true;
              render();
            });
            controlActions.appendChild(collapseBtn);

            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'button button-link-delete';
            removeBtn.textContent = strings.remove || 'Supprimer';
            removeBtn.addEventListener('click', () => {
              block.controls.splice(controlIndex, 1);
              render();
              updateInput();
            });
            controlActions.appendChild(removeBtn);

            controlEl.appendChild(controlActions);
          }

          panelEl.appendChild(controlEl);
        });

        controlsWrapper.appendChild(panelEl);
      });

      const addControlBtn = document.createElement('button');
      addControlBtn.type = 'button';
      addControlBtn.className = 'button button-secondary';
      addControlBtn.textContent = strings.addControl || 'Ajouter un contrôle';
      addControlBtn.addEventListener('click', () => {
        addControl(block, 'toggle');
        render();
        updateInput();
      });

      const addPresetBtn = document.createElement('button');
      addPresetBtn.type = 'button';
      addPresetBtn.className = 'button button-secondary';
      addPresetBtn.textContent = strings.presetTitle || 'Presets / Bundles';
      addPresetBtn.addEventListener('click', () => {
        const ctrl = {
          type: 'preset',
          id: '',
          label: '',
          panel: '',
          description: '',
          classes: '',
          options: [{ id: '', label: '', classes: '' }],
        };
        block.controls.push(ctrl);
        render();
        updateInput();
      });

      const controlButtons = document.createElement('div');
      controlButtons.className = 'up-ge-actions';
      controlButtons.appendChild(addControlBtn);
      controlButtons.appendChild(addPresetBtn);

      controlsWrapper.appendChild(controlButtons);
      blockEl.appendChild(controlsWrapper);
      app.appendChild(blockEl);
    });

    const addBlockBtn = document.createElement('button');
    addBlockBtn.type = 'button';
    addBlockBtn.className = 'button button-primary';
    addBlockBtn.textContent = strings.addBlock || 'Ajouter un bloc';
    addBlockBtn.addEventListener('click', () => {
      state.push({ name: '', controls: [] });
      render();
      updateInput();
    });

    const footer = document.createElement('div');
    footer.className = 'up-ge-actions';
    footer.appendChild(addBlockBtn);
    app.appendChild(footer);

    updateInput();
  }

  render();
})();
