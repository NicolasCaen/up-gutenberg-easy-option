(function () {
  const { __ } = wp.i18n;
  const { PanelBody, ToggleControl, SelectControl, Spinner, TextControl } = wp.components;
  const { Fragment, useEffect, useState } = wp.element;
  const { InspectorControls } = wp.blockEditor || wp.editor;
  const { addFilter } = wp.hooks;
  const { createHigherOrderComponent } = wp.compose;
  const apiFetch = wp.apiFetch;

  let switchesConfig = null;
  let switchesError = null;
  let switchesLoading = true;

  // Fetch configuration une fois
  function fetchSwitches() {
    switchesLoading = true;
    apiFetch({ path: '/up/v1/switches' })
      .then((res) => {
        switchesConfig = res || {};
        switchesError = null;
      })
      .catch((err) => {
        switchesConfig = {};
        switchesError = err;
      })
      .finally(() => {
        switchesLoading = false;
      });
  }

  fetchSwitches();

  function toClassArray(input, fallback) {
    if (Array.isArray(input)) {
      return input.filter(Boolean);
    }
    if (typeof input === 'string') {
      return input
        .split(/\s+/)
        .map((c) => c.trim())
        .filter(Boolean);
    }
    if (Array.isArray(fallback) || typeof fallback === 'string') {
      return toClassArray(fallback);
    }
    return [];
  }

  function ensureClasses(className, classes, enabled) {
    let list = (className || '').split(/\s+/).filter(Boolean);
    const cls = toClassArray(classes);
    if (!cls.length) {
      return list.join(' ');
    }
    if (enabled) {
      cls.forEach((c) => {
        if (!list.includes(c)) {
          list.push(c);
        }
      });
    } else {
      list = list.filter((c) => !cls.includes(c));
    }
    return list.join(' ');
  }

  // Pour les selects/presets: retirer toutes les classes candidates puis ajouter celles du choix
  function replaceClassesExclusive(className, optionsClasses, chosenClasses) {
    let list = (className || '').split(/\s+/).filter(Boolean);
    const removeSet = new Set();
    optionsClasses.forEach((cls) => toClassArray(cls).forEach((c) => removeSet.add(c)));
    list = list.filter((c) => !removeSet.has(c));
    const chosen = toClassArray(chosenClasses);
    chosen.forEach((c) => {
      if (!list.includes(c)) {
        list.push(c);
      }
    });
    return list.join(' ');
  }

  const withSwitchesInspector = createHigherOrderComponent((BlockEdit) => {
    return (props) => {
      const { name: blockName, attributes, setAttributes, isSelected } = props;
      const [tick, setTick] = useState(0);

      // Re-render when fetch completes
      useEffect(() => {
        if (!switchesLoading) return;
        const interval = setInterval(() => {
          if (!switchesLoading) {
            setTick((t) => t + 1);
            clearInterval(interval);
          }
        }, 100);
        return () => clearInterval(interval);
      }, []);

      const blockSwitches = switchesConfig ? switchesConfig[blockName] : undefined;

      if (!blockSwitches || !isSelected) {
        return wp.element.createElement(BlockEdit, props);
      }

      const className = attributes.className || '';

      // Grouper par panel
      const groups = {};
      (blockSwitches || []).forEach((sw) => {
        const panel = sw.panel || __('Options UP', 'up');
        if (!groups[panel]) groups[panel] = [];
        groups[panel].push(sw);
      });

      return wp.element.createElement(
        Fragment,
        null,
        wp.element.createElement(BlockEdit, props),
        wp.element.createElement(
          InspectorControls,
          null,
          switchesLoading && !blockSwitches
            ? wp.element.createElement(Spinner, null)
            : switchesError
            ? wp.element.createElement('div', { style: { color: 'red' } }, __('Erreur de chargement des options', 'up'))
            : Object.keys(groups).map((panelTitle) => {
                const items = groups[panelTitle];
                return wp.element.createElement(
                  PanelBody,
                  { title: panelTitle, initialOpen: true, key: panelTitle },
                  items.map((sw) => {
                    if ((sw.type === 'select' || sw.type === 'preset') && Array.isArray(sw.options)) {
                      const optionsClasses = sw.options.map((o) => toClassArray(o.classes || o.class));
                      const currentClasses = className.split(/\s+/).filter(Boolean);
                      const activeOption = sw.options.find((option, idx) => {
                        const optionClasses = optionsClasses[idx];
                        if (!optionClasses.length) {
                          return false;
                        }
                        return optionClasses.every((cls) => currentClasses.includes(cls));
                      });
                      const value = activeOption ? activeOption.id : '';
                      const selectOptions = [
                        { label: '—', value: '' },
                        ...sw.options.map((o) => ({ label: o.label || o.id, value: o.id })),
                      ];
                      return wp.element.createElement(SelectControl, {
                        key: sw.id,
                        label: sw.label || sw.id,
                        help: sw.description || undefined,
                        value,
                        options: selectOptions,
                        onChange: (val) => {
                          const index = sw.options.findIndex((o) => o.id === val);
                          const selectedClasses = index > -1 ? optionsClasses[index] : [];
                          const newClassName = replaceClassesExclusive(className, optionsClasses, selectedClasses);
                          setAttributes({ className: newClassName || undefined });
                        },
                      });
                    } else if (sw.type === 'number' && sw.class) {
                      const prefix = String(sw.class);
                      const parts = className.split(/\s+/).filter(Boolean);
                      const currentToken = parts.find((t) => t.indexOf(prefix + '-') === 0);
                      const currentValue = currentToken ? currentToken.slice(prefix.length + 1) : '';

                      const clamp = (val) => {
                        const n = Number(val);
                        if (!Number.isFinite(n)) return '';
                        const min = typeof sw.min === 'number' ? sw.min : null;
                        const max = typeof sw.max === 'number' ? sw.max : null;
                        let v = n;
                        if (min !== null && v < min) v = min;
                        if (max !== null && v > max) v = max;
                        return String(v);
                      };

                      const toNewClassName = (val) => {
                        // remove previous prefix-* classes
                        let list = parts.filter((t) => !(t.indexOf(prefix + '-') === 0));
                        const trimmed = String(val).trim();
                        if (trimmed !== '') {
                          const v = clamp(trimmed);
                          if (v !== '') {
                            list.push(prefix + '-' + v);
                          }
                        }
                        return list.join(' ');
                      };

                      const inputProps = {};
                      if (typeof sw.min === 'number') inputProps.min = sw.min;
                      if (typeof sw.max === 'number') inputProps.max = sw.max;
                      if (typeof sw.step === 'number' && sw.step > 0) inputProps.step = sw.step;

                      return wp.element.createElement(TextControl, {
                        key: sw.id,
                        type: 'number',
                        label: sw.label || sw.id,
                        help: sw.description || undefined,
                        value: currentValue,
                        ...inputProps,
                        onChange: (val) => {
                          const newClassName = toNewClassName(val);
                          setAttributes({ className: newClassName || undefined });
                        },
                      });
                    }

                    const toggleClasses = toClassArray(sw.classes || sw.class);
                    const currentClasses = className.split(/\s+/).filter(Boolean);
                    const enabled = toggleClasses.every((cls) => currentClasses.includes(cls));
                    return wp.element.createElement(ToggleControl, {
                      key: sw.id,
                      label: sw.label || sw.id,
                      help: sw.description || undefined,
                      checked: enabled,
                      onChange: (val) => {
                        const updated = ensureClasses(className, toggleClasses, val);
                        setAttributes({ className: updated || undefined });
                      },
                    });
                  })
                );
              })
        )
      );
    };
  }, 'withSwitchesInspector');

  addFilter('editor.BlockEdit', 'up/with-switches-inspector', withSwitchesInspector);
})();
