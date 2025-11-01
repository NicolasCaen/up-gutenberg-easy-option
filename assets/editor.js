(function () {
  const { __ } = wp.i18n;
  const { PanelBody, ToggleControl, SelectControl, Spinner } = wp.components;
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

  function ensureClass(className, klass, enabled) {
    const list = (className || '').split(/\s+/).filter(Boolean);
    const has = list.includes(klass);
    if (enabled && !has) list.push(klass);
    if (!enabled && has) {
      const idx = list.indexOf(klass);
      if (idx > -1) list.splice(idx, 1);
    }
    return list.join(' ');
  }

  // Pour les selects: retirer toutes les classes candidates et ajouter la choisie
  function replaceClassesExclusive(className, optionClasses, chosenClass) {
    let list = (className || '').split(/\s+/).filter(Boolean);
    const removeSet = new Set(optionClasses);
    list = list.filter((c) => !removeSet.has(c));
    if (chosenClass) list.push(chosenClass);
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
                    if (sw.type === 'select' && Array.isArray(sw.options)) {
                      const optionClasses = sw.options.map((o) => o.class);
                      const current = className
                        .split(/\s+/)
                        .filter(Boolean)
                        .find((c) => optionClasses.includes(c));
                      const value = (sw.options.find((o) => o.class === current) || {}).id || '';
                      const selectOptions = [
                        { label: '—', value: '' },
                        ...sw.options.map((o) => ({ label: o.label || o.id, value: o.id })),
                      ];
                      return wp.element.createElement(SelectControl, {
                        key: sw.id,
                        label: sw.label || sw.id,
                        value,
                        options: selectOptions,
                        onChange: (val) => {
                          const chosen = sw.options.find((o) => o.id === val);
                          const newClassName = replaceClassesExclusive(className, optionClasses, chosen ? chosen.class : '');
                          setAttributes({ className: newClassName || undefined });
                        },
                      });
                    }
                    // toggle par défaut
                    const enabled = className.split(/\s+/).filter(Boolean).includes(sw.class);
                    return wp.element.createElement(ToggleControl, {
                      key: sw.id,
                      label: sw.label || sw.id,
                      checked: enabled,
                      onChange: (val) => {
                        const updated = ensureClass(className, sw.class, val);
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
