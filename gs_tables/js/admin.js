/**
 * GS Tables – Admin JS
 * Manages the table builder UI and live preview in the GetSimple admin.
 */
(function() {
    'use strict';

    // ── State ────────────────────────────────────────────────────────
    var data; // the live table data object

    // ── Init ─────────────────────────────────────────────────────────
    function gs_tables_init() {
        if (!window.GS_TABLES_DATA) return; // not on edit page
        data = JSON.parse(JSON.stringify(window.GS_TABLES_DATA)); // deep clone

        renderHeaders();
        renderRows();
        renderPreview();
        bindStyleInputs();
        bindMetaInputs();
        bindRowColButtons();
        bindFormSubmit();
        showFlashMessages();
    }

    // Use multiple fallbacks to handle any script loading scenario
    if (window.GS_TABLES_DATA) {
        // Data already available - run immediately
        gs_tables_init();
    } else if (document.readyState === 'loading') {
        // DOM still loading
        document.addEventListener('DOMContentLoaded', gs_tables_init);
    } else {
        // DOM ready but data not yet - wait a tick
        setTimeout(gs_tables_init, 0);
    }

    // ── Flash messages ────────────────────────────────────────────────
    function showFlashMessages() {
        var params = new URLSearchParams(window.location.search);
        if (params.get('saved') === '1') showFlash('Table saved successfully.', 'success');
        if (params.get('deleted') === '1') showFlash('Table deleted.', 'success');
    }

    function showFlash(msg, type) {
        var div = document.createElement('div');
        div.className = 'gs-flash gs-flash-' + type;
        div.textContent = msg;
        var admin = document.querySelector('.gs-tables-admin');
        if (admin) admin.insertBefore(div, admin.children[1]);
        setTimeout(function() { div.remove(); }, 4000);
    }

    // ── Meta inputs ───────────────────────────────────────────────────
    function bindMetaInputs() {
        bindInput('gs-title',   function(v) { data.title   = v; });
        bindInput('gs-caption', function(v) { data.caption = v; });

        var idEl = document.getElementById('gs-id');
        if (idEl && !idEl.readOnly) {
            idEl.addEventListener('input', function() {
                data.id = slugify(idEl.value);
                // Don't rewrite the input while typing; normalise on blur
            });
            idEl.addEventListener('blur', function() {
                idEl.value = slugify(idEl.value);
                data.id = idEl.value;
            });
        } else if (idEl) {
            data.id = idEl.value;
        }
    }

    function slugify(s) {
        return s.toLowerCase().replace(/[^a-z0-9\-_]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
    }

    function bindInput(id, setter) {
        var el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function() { setter(el.value); });
    }

    // ── Style inputs ─────────────────────────────────────────────────
    function bindStyleInputs() {
        // Number / text inputs
        var map = {
            'gs-s-border-width':  'border_width',
            'gs-s-border-radius': 'border_radius',
            'gs-s-cell-pad':      'cell_padding',
            'gs-s-font-size':     'font_size',
        };
        Object.keys(map).forEach(function(elId) {
            var el = document.getElementById(elId);
            if (!el) return;
            el.addEventListener('input', function() {
                data.style[map[elId]] = parseInt(el.value, 10);
                renderPreview();
            });
        });

        // Colour widget pairs: native picker + text input
        var colorMap = {
            'gs-s-header-bg':    'header_bg',
            'gs-s-header-fg':    'header_fg',
            'gs-s-border-color': 'border_color',
            'gs-s-stripe':       'stripe_bg',
        };
        Object.keys(colorMap).forEach(function(elId) {
            var native = document.getElementById(elId);
            var txt    = document.getElementById(elId + '-txt');
            var swatch = native ? native.parentElement : null;
            var key    = colorMap[elId];
            if (!native) return;

            function applyColor(v) {
                data.style[key] = v;
                if (swatch) swatch.style.background = v;
                renderPreview();
            }

            native.addEventListener('input', function() {
                if (txt) txt.value = native.value;
                applyColor(native.value);
            });
            if (txt) {
                txt.addEventListener('input', function() {
                    var v = txt.value.trim();
                    if (/^#[0-9a-fA-F]{6}$/.test(v)) {
                        native.value = v;
                        applyColor(v);
                    }
                });
            }
        });
    }

    // ── Header rendering ─────────────────────────────────────────────
    function renderHeaders() {
        var wrap = document.getElementById('gs-headers-wrap');
        if (!wrap) return;
        wrap.innerHTML = '';
        (data.headers || []).forEach(function(h, i) {
            wrap.appendChild(buildCellEditor(h, i, 'header', function() {
                renderPreview();
            }));
        });
    }

    // ── Row rendering ────────────────────────────────────────────────
    function renderRows() {
        var wrap = document.getElementById('gs-rows-wrap');
        if (!wrap) return;
        wrap.innerHTML = '';
        (data.rows || []).forEach(function(row, ri) {
            var div = document.createElement('div');
            div.className = 'gs-row-wrap';

            var label = document.createElement('div');
            label.className = 'gs-row-label';
            label.textContent = 'Row ' + (ri + 1);
            div.appendChild(label);

            var grid = document.createElement('div');
            grid.className = 'gs-cell-grid';
            row.forEach(function(cell, ci) {
                grid.appendChild(buildCellEditor(cell, ci, 'row-' + ri, function() {
                    renderPreview();
                }));
            });
            div.appendChild(grid);
            wrap.appendChild(div);
        });
    }

    // ── Cell editor widget ────────────────────────────────────────────
    function buildCellEditor(cellObj, index, prefix, onChange) {
        var container = document.createElement('div');
        container.className = 'gs-cell-editor';

        function addLabel(txt) {
            var l = document.createElement('label');
            l.textContent = txt;
            container.appendChild(l);
        }

        function addTextInput(lbl, key, placeholder) {
            addLabel(lbl);
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.placeholder = placeholder || '';
            inp.value = cellObj[key] || '';
            inp.addEventListener('input', function() {
                cellObj[key] = inp.value;
                onChange();
            });
            container.appendChild(inp);
        }

        function addColorInput(lbl, key, def) {
            addLabel(lbl);
            // A cell colour is "set" only if it's a valid hex AND not a legacy default
            // (#ffffff for bg and #000000 for fg were old picker defaults meaning "unset")
            var legacyDefaults = { 'bg': '#ffffff', 'fg': '#000000' };
            var isSet = cellObj[key]
                && /^#[0-9a-fA-F]{6}$/i.test(cellObj[key])
                && cellObj[key].toLowerCase() !== (legacyDefaults[key] || '');
            // Clean up legacy default values so they don't get re-saved
            if (!isSet && cellObj[key] === legacyDefaults[key]) delete cellObj[key];
            var val   = isSet ? cellObj[key] : (def || '#ffffff');

            // Outer row: checkbox + picker
            var row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:4px;';

            // "Use custom" checkbox
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = isSet;
            cb.title = 'Enable custom colour';

            // Wrapper (picker + text)
            var wrap = document.createElement('span');
            wrap.className = 'gs-color-wrap';
            wrap.style.opacity = isSet ? '1' : '0.35';

            var swatch = document.createElement('span');
            swatch.className = 'gs-color-swatch';
            swatch.style.background = val;

            var native = document.createElement('input');
            native.type = 'color';
            native.value = val;
            native.disabled = !isSet;
            swatch.appendChild(native);

            var txt = document.createElement('input');
            txt.type = 'text';
            txt.className = 'gs-color-text';
            txt.value = isSet ? val : '';
            txt.maxLength = 7;
            txt.placeholder = def || '#ffffff';
            txt.disabled = !isSet;

            function setEnabled(on) {
                cb.checked  = on;
                native.disabled = !on;
                txt.disabled    = !on;
                wrap.style.opacity = on ? '1' : '0.35';
                if (on) {
                    cellObj[key] = native.value;
                } else {
                    delete cellObj[key];
                    txt.value = '';
                }
                onChange();
            }

            cb.addEventListener('change', function() { setEnabled(cb.checked); });

            native.addEventListener('input', function() {
                txt.value = native.value;
                swatch.style.background = native.value;
                cellObj[key] = native.value;
                onChange();
            });

            txt.addEventListener('input', function() {
                var v = txt.value.trim();
                if (/^#[0-9a-fA-F]{6}$/.test(v)) {
                    swatch.style.background = v;
                    native.value = v;
                    cellObj[key] = v;
                    onChange();
                }
            });

            wrap.appendChild(swatch);
            wrap.appendChild(txt);
            row.appendChild(cb);
            row.appendChild(wrap);
            container.appendChild(row);
        }

        function addCheckbox(lbl, key) {
            var label = document.createElement('label');
            var cb    = document.createElement('input');
            cb.type    = 'checkbox';
            cb.checked = !!cellObj[key];
            cb.addEventListener('change', function() {
                cellObj[key] = cb.checked;
                onChange();
            });
            label.appendChild(cb);
            label.appendChild(document.createTextNode(' ' + lbl));
            return label;
        }

        function addSelect(lbl, key, options) {
            addLabel(lbl);
            var sel = document.createElement('select');
            options.forEach(function(o) {
                var opt = document.createElement('option');
                opt.value       = o.value;
                opt.textContent = o.label;
                if ((cellObj[key] || '') === o.value) opt.selected = true;
                sel.appendChild(opt);
            });
            sel.addEventListener('change', function() {
                cellObj[key] = sel.value;
                onChange();
            });
            container.appendChild(sel);
        }

        // ─ Text content ─
        addTextInput('Text', 'text', 'Cell text…');

        // ─ Formatting checkboxes ─
        addLabel('Formatting');
        var cbWrap = document.createElement('div');
        cbWrap.className = 'gs-cell-checkboxes';
        cbWrap.appendChild(addCheckbox('Bold',   'bold'));
        cbWrap.appendChild(addCheckbox('Italic', 'italic'));
        container.appendChild(cbWrap);

        // ─ Colours ─
        addColorInput('Background',   'bg',  '#ffffff');
        addColorInput('Text Colour',  'fg',  '#000000');

        // ─ Alignment ─
        addSelect('Align', 'align', [
            {value: '',       label: 'Default'},
            {value: 'left',   label: 'Left'},
            {value: 'center', label: 'Centre'},
            {value: 'right',  label: 'Right'},
        ]);

        // ─ Link ─
        addTextInput('Link URL (optional)', 'link', 'https://…');

        // ─ Width % ─
        addLabel('Width %');
        var wInp = document.createElement('input');
        wInp.type = 'number';
        wInp.min = 0; wInp.max = 100;
        wInp.placeholder = 'auto';
        wInp.value = cellObj['width'] || '';
        wInp.addEventListener('input', function() {
            cellObj['width'] = wInp.value ? parseInt(wInp.value, 10) : '';
            onChange();
        });
        container.appendChild(wInp);

        return container;
    }

    // ── Add / remove columns ─────────────────────────────────────────
    function bindRowColButtons() {
        on('gs-add-col', 'click', function() {
            (data.headers || []).push({text: 'New Column', bold: true});
            (data.rows || []).forEach(function(row) {
                row.push({text: ''});
            });
            renderHeaders();
            renderRows();
            renderPreview();
        });

        on('gs-del-col', 'click', function() {
            if ((data.headers || []).length <= 1) return;
            data.headers.pop();
            (data.rows || []).forEach(function(row) {
                if (row.length > 1) row.pop();
            });
            renderHeaders();
            renderRows();
            renderPreview();
        });

        on('gs-add-row', 'click', function() {
            var cols = (data.headers || []).length || 1;
            var newRow = [];
            for (var i = 0; i < cols; i++) newRow.push({text: ''});
            (data.rows = data.rows || []).push(newRow);
            renderRows();
            renderPreview();
        });

        on('gs-del-row', 'click', function() {
            if ((data.rows || []).length <= 1) return;
            data.rows.pop();
            renderRows();
            renderPreview();
        });

        on('gs-refresh-preview', 'click', renderPreview);
    }

    function on(id, evt, fn) {
        var el = document.getElementById(id);
        if (el) el.addEventListener(evt, fn);
    }

    // ── Live preview ─────────────────────────────────────────────────
    function renderPreview() {
        var preview = document.getElementById('gs-preview');
        if (!preview) return;
        // Deep clone data for preview so we don't mutate the live data object
        var previewData = JSON.parse(JSON.stringify(data));
        preview.innerHTML = buildTableHTML(previewData);
    }

    function buildTableHTML(d) {
        var s = d.style || {};
        var borderColor  = s.border_color  || '#cccccc';
        var headerBg     = s.header_bg     || '#4a6fa5';
        var headerFg     = s.header_fg     || '#ffffff';
        var stripeBg     = s.stripe_bg     || '#f0f4f8';
        var cellPad      = (s.cell_padding || 10) + 'px';
        var fontSize     = (s.font_size    || 14) + 'px';
        var borderWidth  = (s.border_width || 1)  + 'px';
        var borderRadius = (s.border_radius || 4) + 'px';

        var tableStyle = '--gst-border:' + borderColor + ';'
            + '--gst-header-bg:' + headerBg + ';'
            + '--gst-header-fg:' + headerFg + ';'
            + '--gst-stripe:'    + stripeBg + ';'
            + '--gst-pad:'       + cellPad  + ';'
            + '--gst-fs:'        + fontSize + ';'
            + '--gst-bw:'        + borderWidth + ';'
            + '--gst-br:'        + borderRadius + ';';

        var html = '<div class="gs-table-wrap" style="' + tableStyle + '"><table class="gs-table">';

        if (d.caption) {
            html += '<caption>' + esc(d.caption) + '</caption>';
        }

        if (d.headers && d.headers.length) {
            html += '<thead><tr>';
            d.headers.forEach(function(h) {
                // Start with table-level header colours
                var bg = h.bg || headerBg;
                var fg = h.fg || headerFg;
                var thIdx = d.headers.indexOf(h);
                var thStyle = 'background-color:' + bg + ' !important;color:' + fg + ' !important;'
                    + 'padding:' + cellPad + ';text-align:left;font-weight:600;border:none;'
                    + (thIdx > 0 ? 'border-left:' + borderWidth + ' solid rgba(255,255,255,0.2);' : '');
                // Add any remaining per-cell overrides (align, width etc)
                if (h.align)  thStyle += 'text-align:' + h.align + ';';
                if (h.valign) thStyle += 'vertical-align:' + h.valign + ';';
                if (h.width)  thStyle += 'width:' + h.width + '%;';
                html += '<th style="' + thStyle + '">' + cellContent(h) + '</th>';
            });
            html += '</tr></thead>';
        }

        if (d.rows && d.rows.length) {
            html += '<tbody>';
            d.rows.forEach(function(row, rowIndex) {
                html += '<tr>';
                row.forEach(function(cell) {
                    // Base td style with padding and border
                    var isFirstCol = (row.indexOf(cell) === 0);
                    var tdStyle = 'padding:' + cellPad + ';'
                        + 'border:none;'
                        + 'border-top:' + borderWidth + ' solid ' + borderColor + ';'
                        + (!isFirstCol ? 'border-left:' + borderWidth + ' solid ' + borderColor + ';' : '')
                        + 'vertical-align:top;';
                    // Stripe on even rows (0-indexed so odd index = even row visually)
                    if (rowIndex % 2 === 1) tdStyle += 'background-color:' + stripeBg + ';';
                    // Per-cell overrides (bg/fg/align etc) on top
                    var perCell = cellStyleStr(cell);
                    if (perCell) tdStyle += perCell;
                    var attrs = '';
                    if (cell.colspan > 1) attrs += ' colspan="' + cell.colspan + '"';
                    if (cell.rowspan > 1) attrs += ' rowspan="' + cell.rowspan + '"';
                    html += '<td style="' + tdStyle + '"' + attrs + '>' + cellContent(cell) + '</td>';
                });
                html += '</tr>';
            });
            html += '</tbody>';
        }

        html += '</table></div>';
        return html;
    }

    function cellStyleStr(c) {
        var parts = [];
        if (c.bg)     parts.push('background:' + c.bg);
        if (c.fg)     parts.push('color:' + c.fg);
        if (c.align)  parts.push('text-align:' + c.align);
        if (c.valign) parts.push('vertical-align:' + c.valign);
        if (c.width)  parts.push('width:' + c.width + '%');
        return parts.join(';');
    }

    function cellContent(c) {
        var t = esc(c.text || '');
        if (c.bold)   t = '<strong>' + t + '</strong>';
        if (c.italic) t = '<em>'     + t + '</em>';
        if (c.link)   t = '<a href="' + esc(c.link) + '">' + t + '</a>';
        return t;
    }

    function esc(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // ── Form submit: serialise state into hidden JSON field ──────────
    function bindFormSubmit() {
        var form = document.getElementById('gs-tables-form');
        if (!form) return;
        form.addEventListener('submit', function(e) {
            // Sync meta fields one last time
            var titleEl = document.getElementById('gs-title');
            var idEl    = document.getElementById('gs-id');
            var capEl   = document.getElementById('gs-caption');

            if (titleEl) data.title   = titleEl.value;
            if (capEl)   data.caption = capEl.value;
            if (idEl && !idEl.readOnly) data.id = slugify(idEl.value);

            if (!data.id) {
                e.preventDefault();
                alert('Please enter a Table ID / Slug before saving.');
                if (idEl) idEl.focus();
                return;
            }

            document.getElementById('gs_table_json').value = JSON.stringify(data);
        });
    }

})();
