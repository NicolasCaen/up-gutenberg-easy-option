(function () {
  const { __ } = wp.i18n;
  const { PanelBody, ToggleControl, Spinner } = wp.components;
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

      return wp.element.createElement(
        Fragment,
        null,
        wp.element.createElement(BlockEdit, props),
        wp.element.createElement(
          InspectorControls,
          null,
          wp.element.createElement(
            PanelBody,
            { title: __('Options UP', 'up'), initialOpen: true },
            switchesLoading && !blockSwitches
              ? wp.element.createElement(Spinner, null)
              : switchesError
              ? wp.element.createElement('div', { style: { color: 'red' } }, __('Erreur de chargement des switches', 'up'))
              : (blockSwitches || []).map((sw) => {
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
          )
        )
      );
    };
  }, 'withSwitchesInspector');

  addFilter('editor.BlockEdit', 'up/with-switches-inspector', withSwitchesInspector);
})();
