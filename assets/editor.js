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
                    if (sw.type === 'palette' && sw.class && sw.source) {
                      const extra = toClassArray(sw.extra);
                      // If backend provided options, use them directly (most robust)
                      if (Array.isArray(sw.options) && sw.options.length) {
                        const optionsClasses = sw.options.map((o) => toClassArray(o.classes || o.class));
                        const currentClasses = className.split(/\s+/).filter(Boolean);
                        const activeOption = sw.options.find((option, idx) => {
                          const optionClasses = optionsClasses[idx];
                          if (!optionClasses.length) return false;
                          return optionClasses.every((cls) => currentClasses.includes(cls));
                        });
                        const value = activeOption ? activeOption.id : '';
                        const selectOptions = [{ label: '—', value: '' }].concat(
                          sw.options.map((o) => ({ label: o.label || o.id, value: o.id }))
                        );
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
                            const withExtra = val ? ensureClasses(newClassName, extra, true) : ensureClasses(newClassName, extra, false);
                            setAttributes({ className: withExtra || undefined });
                          },
                        });
                      }
                      const beSel = (wp.data && wp.data.select) ? wp.data.select('core/block-editor') : null;
                      const edSel = (wp.data && wp.data.select) ? wp.data.select('core/editor') : null;
                      const settings = (beSel && beSel.getSettings ? beSel.getSettings() : null) || (edSel && edSel.getEditorSettings ? edSel.getEditorSettings() : null) || {};
                      const paletteColors = (settings && (settings.colors || (settings.color && settings.color.palette))) || [];
                      const paletteFontSizes = (settings && (settings.fontSizes || (settings.typography && settings.typography.fontSizes))) || [];
                      const spacingTokensCandidates = [];
                      if (settings) {
                        if (settings.spacing && (settings.spacing.spacingSizes || settings.spacing.sizeScale || settings.spacing.sizes)) {
                          spacingTokensCandidates.push(settings.spacing.spacingSizes || settings.spacing.sizeScale || settings.spacing.sizes);
                        }
                        if (settings.spacingSizes) {
                          spacingTokensCandidates.push(settings.spacingSizes);
                        }
                        if (settings.__experimentalFeatures && settings.__experimentalFeatures.spacing && settings.__experimentalFeatures.spacing.spacingSizes) {
                          spacingTokensCandidates.push(settings.__experimentalFeatures.spacing.spacingSizes);
                        }
                        if (settings.__experimentalFeatures && settings.__experimentalFeatures.spacing && settings.__experimentalFeatures.spacing.sizeScale) {
                          spacingTokensCandidates.push(settings.__experimentalFeatures.spacing.sizeScale);
                        }
                        if (settings.settings && settings.settings.spacing && settings.settings.spacing.spacingSizes) {
                          spacingTokensCandidates.push(settings.settings.spacing.spacingSizes);
                        }
                        if (settings.theme && settings.theme.settings && settings.theme.settings.spacing && settings.theme.settings.spacing.spacingSizes) {
                          spacingTokensCandidates.push(settings.theme.settings.spacing.spacingSizes);
                        }
                      }
                      let spacingTokens = ([]).concat.apply([], spacingTokensCandidates.map((c) => Array.isArray(c) ? c : (c && c.items ? c.items : [])).filter(Boolean));
                      // Some themes might provide an array of groups with `sizes` key
                      if (!spacingTokens.length) {
                        const groupArrays = spacingTokensCandidates
                          .filter((c) => Array.isArray(c))
                          .map((arr) => arr.reduce((acc, item) => acc.concat(item && Array.isArray(item.sizes) ? item.sizes : []), []));
                        spacingTokens = ([]).concat.apply([], groupArrays);
                      }

                      let tokens = [];
                      if (sw.source === 'colors') {
                        tokens = Array.isArray(paletteColors) ? paletteColors : [];
                      } else if (sw.source === 'fontSizes') {
                        tokens = Array.isArray(paletteFontSizes) ? paletteFontSizes : [];
                      } else if (sw.source === 'spacing') {
                        tokens = Array.isArray(spacingTokens) ? spacingTokens : [];
                      }

                      const opts = tokens
                        .map((t) => ({
                          slug: (t && (t.slug || t.name || t.label)) ? (t.slug || t.name || t.label) : (t && t.size ? String(t.size) : ''),
                          label: (t && (t.name || t.label || t.slug)) ? (t.name || t.label || t.slug) : (t && t.size ? String(t.size) : ''),
                        }))
                        .filter((t) => t.slug);

                      const options = [{ label: '—', value: '' }].concat(
                        opts.map((t) => ({ label: t.label, value: t.slug }))
                      );

                      const classesFor = (slug) => (slug ? [String(sw.class) + '-' + String(slug)] : []);
                      const optionsClasses = opts.map((t) => classesFor(t.slug));

                      const currentClasses = className.split(/\s+/).filter(Boolean);
                      const active = opts.find((t) => {
                        const req = classesFor(t.slug);
                        return req.length && req.every((c) => currentClasses.includes(c));
                      });
                      const value = active ? active.slug : '';

                      return wp.element.createElement(SelectControl, {
                        key: sw.id,
                        label: sw.label || sw.id,
                        help: sw.description || undefined,
                        value,
                        options,
                        onChange: (val) => {
                          const idx = opts.findIndex((t) => t.slug === val);
                          const selectedClasses = idx > -1 ? optionsClasses[idx] : [];
                          const newClassName = replaceClassesExclusive(className, optionsClasses, selectedClasses);
                          const withExtra = val ? ensureClasses(newClassName, extra, true) : ensureClasses(newClassName, extra, false);
                          setAttributes({ className: withExtra || undefined });
                        },
                      });
                    }
                    if ((sw.type === 'select' || sw.type === 'preset') && Array.isArray(sw.options)) {
                      const optionsClasses = sw.options.map((o) => toClassArray(o.classes || o.class));
                      const extra = toClassArray(sw.extra);
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
                          const withExtra = val ? ensureClasses(newClassName, extra, true) : ensureClasses(newClassName, extra, false);
                          setAttributes({ className: withExtra || undefined });
                        },
                      });
                    } else if (sw.type === 'number' && sw.class) {
                      const prefix = String(sw.class);
                      const parts = className.split(/\s+/).filter(Boolean);
                      const currentToken = parts.find((t) => t.indexOf(prefix + '-') === 0);
                      const currentValue = currentToken ? currentToken.slice(prefix.length + 1) : '';
                      const extra = toClassArray(sw.extra);

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
                          const has = String(val).trim() !== '';
                          const withExtra = has ? ensureClasses(newClassName, extra, true) : ensureClasses(newClassName, extra, false);
                          setAttributes({ className: withExtra || undefined });
                        },
                      });
                    }

                    const toggleClasses = toClassArray(sw.classes || sw.class);
                    const extra = toClassArray(sw.extra);
                    const currentClasses = className.split(/\s+/).filter(Boolean);
                    const enabled = toggleClasses.every((cls) => currentClasses.includes(cls));
                    return wp.element.createElement(ToggleControl, {
                      key: sw.id,
                      label: sw.label || sw.id,
                      help: sw.description || undefined,
                      checked: enabled,
                      onChange: (val) => {
                        let updated = ensureClasses(className, toggleClasses, val);
                        updated = ensureClasses(updated, extra, val);
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
