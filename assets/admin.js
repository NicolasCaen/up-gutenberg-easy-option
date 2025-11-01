(function () {
  const data = window.UPGEAdminData || {};
  const strings = data.strings || {};
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
      .split(/\s+/)
      .map((cls) => cls.trim())
      .filter(Boolean);
  }

  function cloneControl(ctrl) {
    return {
      type: ctrl.type || 'toggle',
      id: ctrl.id || '',
      label: ctrl.label || '',
      panel: ctrl.panel || '',
      description: ctrl.description || '',
      classes: arrayToString(ctrl.classes || ctrl.class || ''),
      options: (ctrl.options || []).map((opt) => ({
        id: opt.id || '',
        label: opt.label || '',
        classes: arrayToString(opt.classes || opt.class || ''),
      })),
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
      if (!Array.isArray(control.options)) {
        control.options = [];
      }
    });
  }

  function updateInput() {
    const output = {};

    state.forEach((block) => {
      const blockName = (block.name || '').trim();
      if (!blockName) {
        return;
      }

      ensureControlsArray(block);
      const controls = [];

      block.controls.forEach((control) => {
        const controlId = (control.id || '').trim();
        const controlLabel = (control.label || '').trim();
        const type = control.type || 'toggle';

        if (!controlId || !controlLabel) {
          return;
        }

        const base = {
          id: controlId,
          label: controlLabel,
          type,
        };

        if (control.panel && control.panel.trim()) {
          base.panel = control.panel.trim();
        }
        if (control.description && control.description.trim()) {
          base.description = control.description.trim();
        }

        if (type === 'toggle') {
          const classes = stringToArray(control.classes);
          if (!classes.length) {
            return;
          }
          base.classes = classes;
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
            return;
          }
          base.options = options;
        }

        controls.push(base);
      });

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
      options: [],
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
      nameInput.placeholder = strings.blockNamePlaceholder || 'core/paragraph';
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

      block.controls.forEach((control, controlIndex) => {
        const controlEl = document.createElement('div');
        controlEl.className = 'up-ge-control';

        const controlTitle = document.createElement('h4');
        controlTitle.textContent = control.label || control.id || (strings.addControl || 'Contrôle');
        controlEl.appendChild(controlTitle);

        const typeSelect = document.createElement('select');
        typeSelect.innerHTML = [
          { value: 'toggle', label: strings.toggle || 'Toggle' },
          { value: 'select', label: strings.select || 'Select' },
          { value: 'preset', label: strings.preset || 'Preset' },
        ]
          .map((option) => `<option value="${option.value}">${option.label}</option>`)
          .join('');
        typeSelect.value = control.type || 'toggle';
        typeSelect.addEventListener('change', (e) => {
          control.type = e.target.value;
          if (control.type === 'toggle') {
            control.options = [];
          } else if (!Array.isArray(control.options)) {
            control.options = [];
          }
          render();
          updateInput();
        });
        controlEl.appendChild(createField(strings.type || 'Type', typeSelect));

        const idInput = document.createElement('input');
        idInput.type = 'text';
        idInput.className = 'regular-text';
        idInput.value = control.id || '';
        idInput.addEventListener('input', (e) => {
          control.id = e.target.value;
          updateInput();
        });
        controlEl.appendChild(createField(strings.id || 'Identifiant', idInput));

        const labelInput = document.createElement('input');
        labelInput.type = 'text';
        labelInput.className = 'regular-text';
        labelInput.value = control.label || '';
        labelInput.addEventListener('input', (e) => {
          control.label = e.target.value;
          controlTitle.textContent = e.target.value || control.id || (strings.addControl || 'Contrôle');
          updateInput();
        });
        controlEl.appendChild(createField(strings.label || 'Libellé', labelInput));

        const panelInput = document.createElement('input');
        panelInput.type = 'text';
        panelInput.className = 'regular-text';
        panelInput.value = control.panel || '';
        panelInput.addEventListener('input', (e) => {
          control.panel = e.target.value;
          updateInput();
        });
        controlEl.appendChild(createField(strings.panel || 'Panneau', panelInput));

        const descInput = document.createElement('textarea');
        descInput.className = 'large-text';
        descInput.rows = 2;
        descInput.value = control.description || '';
        descInput.addEventListener('input', (e) => {
          control.description = e.target.value;
          updateInput();
        });
        controlEl.appendChild(createField(strings.description || 'Description', descInput));

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

            const optId = document.createElement('input');
            optId.type = 'text';
            optId.className = 'regular-text';
            optId.value = opt.id || '';
            optId.addEventListener('input', (e) => {
              opt.id = e.target.value;
              updateInput();
            });
            optionEl.appendChild(createField(strings.optionId || 'Identifiant option', optId));

            const optLabel = document.createElement('input');
            optLabel.type = 'text';
            optLabel.className = 'regular-text';
            optLabel.value = opt.label || '';
            optLabel.addEventListener('input', (e) => {
              opt.label = e.target.value;
              updateInput();
            });
            optionEl.appendChild(createField(strings.optionLabel || 'Libellé option', optLabel));

            const optClasses = document.createElement('input');
            optClasses.type = 'text';
            optClasses.className = 'regular-text';
            optClasses.placeholder = 'is-large is-bold';
            optClasses.value = opt.classes || '';
            optClasses.addEventListener('input', (e) => {
              opt.classes = e.target.value;
              updateInput();
            });
            optionEl.appendChild(createField(strings.optionClasses || 'Classes option', optClasses));

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
        controlsWrapper.appendChild(controlEl);
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
