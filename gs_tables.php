<?php
/**
 * Plugin Name: GS Tables
 * Description: Create and manage styled, responsive tables via shortcodes.
 *              Insert tables into any page using [% gs_table id="my-table" %]
 * Version:     2.1.7
 * Author:      GS Tables Plugin
 * Author URI:  https://github.com/GetSimpleCMS-CE/GetSimpleCMS-CE
 */

if (!defined('IN_GS')) { die('You cannot load this page directly.'); }

define('GS_TABLES_VERSION', '2.1.7');

# -----------------------------------------------------------------------
# Bootstrap
# -----------------------------------------------------------------------

$thisfile = basename(__FILE__, '.php');

i18n_merge($thisfile) || i18n_merge($thisfile, 'en_US');

register_plugin(
    $thisfile,
    'GS Tables',
    '2.1.7',
    'Rusty Hill',
    'https://www.getsimple-ce.ovh/',
    'Create and manage styled, responsive tables via shortcodes.',
    'pages',
    'gs_tables_admin_main'
);

# Admin sidebar menu items
add_action('pages-sidebar', 'createSideMenu', array($thisfile, 'GS Tables',        'list'));
add_action('pages-sidebar', 'createSideMenu', array($thisfile, '+ New Table',       'new'));
add_action('pages-sidebar', 'createSideMenu', array($thisfile, 'Import CSV',        'import'));

# Hook: inject shortcode processor into frontend content rendering
add_action('theme-header', 'gs_tables_init_frontend');

# Hook: inject CSS into page footer — fires AFTER all theme stylesheets
# so our rules always win regardless of theme CSS load order
add_action('theme-footer', 'gs_tables_inject_css');

# Hook: load admin CSS/JS in head
add_action('header', 'gs_tables_load_admin_assets');

# -----------------------------------------------------------------------
# Asset registration
# -----------------------------------------------------------------------

function gs_tables_load_admin_assets() {
    if (!isset($_GET['id']) || $_GET['id'] !== 'gs_tables') return;
    # Inline CSS so it beats all other admin stylesheets regardless of specificity
    $css_file = dirname(__FILE__) . '/gs_tables/css/admin.css';
    if (file_exists($css_file)) {
        echo '<style id="gs-tables-admin-css">' . "
" . file_get_contents($css_file) . "
</style>
";
    }
}

function gs_tables_init_frontend() {
    add_filter('content', 'gs_tables_shortcode_filter');
}

function gs_tables_inject_css() {
    $css_file = dirname(__FILE__) . '/gs_tables/css/tables.css';
    if (file_exists($css_file)) {
        $css = file_get_contents($css_file);
        echo '<style id="gs-tables-css">' . "\n" . $css . "\n</style>\n";
    }
}

# -----------------------------------------------------------------------
# Shortcode filter  [% gs_table id="slug" %]
# -----------------------------------------------------------------------

function gs_tables_shortcode_filter($content) {
    return preg_replace_callback(
        '/\[%\s*gs_table\s+id=["\']([^"\']+)["\']\s*%\]/',
        function($matches) {
            $html = gs_tables_render($matches[1]);
            return ($html !== false) ? $html : $matches[0];
        },
        $content
    );
}

# -----------------------------------------------------------------------
# Data helpers
# -----------------------------------------------------------------------

function gs_tables_data_path() {
    $path = GSDATAOTHERPATH . 'gs_tables/';
    if (!file_exists($path)) {
        mkdir($path, 0755, true);
    }
    return $path;
}

function gs_tables_load($id) {
    $file = gs_tables_data_path() . gs_tables_safe_id($id) . '.json';
    if (!file_exists($file)) return false;
    $data = json_decode(file_get_contents($file), true);
    return $data;
}

function gs_tables_save($id, $data) {
    $file = gs_tables_data_path() . gs_tables_safe_id($id) . '.json';
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function gs_tables_delete($id) {
    $file = gs_tables_data_path() . gs_tables_safe_id($id) . '.json';
    if (file_exists($file)) unlink($file);
}

function gs_tables_list_all() {
    $path  = gs_tables_data_path();
    $files = glob($path . '*.json');
    $tables = [];
    if ($files) {
        foreach ($files as $f) {
            $data = json_decode(file_get_contents($f), true);
            if ($data) $tables[] = $data;
        }
    }
    usort($tables, function($a, $b) {
        return strcmp($a['title'] ?? '', $b['title'] ?? '');
    });
    return $tables;
}

function gs_tables_safe_id($id) {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($id)));
}

# -----------------------------------------------------------------------
# HTML renderer  (called both on front-end and for preview)
# -----------------------------------------------------------------------

function gs_tables_render($id) {
    $data = gs_tables_load($id);
    if (!$data) return false;

    $style   = $data['style']   ?? [];
    $headers = $data['headers'] ?? [];
    $rows    = $data['rows']    ?? [];
    $caption = isset($data['caption']) ? htmlspecialchars($data['caption']) : '';

    # ---- build inline CSS vars for the table ----
    $border_color   = gs_tables_hex($style['border_color']   ?? '#cccccc');
    $header_bg      = gs_tables_hex($style['header_bg']      ?? '#4a6fa5');
    $header_fg      = gs_tables_hex($style['header_fg']      ?? '#ffffff');
    $stripe_bg      = gs_tables_hex($style['stripe_bg']      ?? '#f0f4f8');
    $cell_padding   = intval($style['cell_padding'] ?? 10) . 'px';
    $font_size      = intval($style['font_size']    ?? 14) . 'px';
    $border_width   = intval($style['border_width'] ?? 1)  . 'px';
    $border_radius  = intval($style['border_radius'] ?? 4) . 'px';

    $table_style = "--gst-border:{$border_color};--gst-header-bg:{$header_bg};"
                 . "--gst-header-fg:{$header_fg};--gst-stripe:{$stripe_bg};"
                 . "--gst-pad:{$cell_padding};--gst-fs:{$font_size};"
                 . "--gst-bw:{$border_width};--gst-br:{$border_radius};";

    $html  = '<div class="gs-table-wrap" style="' . $table_style . '">';
    $html .= '<table class="gs-table" data-id="' . htmlspecialchars($id) . '">';

    if ($caption) {
        $html .= '<caption>' . $caption . '</caption>';
    }

    # ---- header row ----
    if ($headers) {
        $html .= '<thead><tr>';
        foreach ($headers as $h) {
            $cell_style = gs_tables_cell_style($h);
            $html .= '<th style="' . $cell_style . '">' . gs_tables_cell_content($h) . '</th>';
        }
        $html .= '</tr></thead>';
    }

    # ---- build header label lookup for data-label on mobile ----
    $header_labels = [];
    foreach ($headers as $h) {
        $header_labels[] = htmlspecialchars(strip_tags($h['text'] ?? ''));
    }

    # ---- body rows ----
    if ($rows) {
        $html .= '<tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $col_index => $cell) {
                $cell_style = gs_tables_cell_style($cell);
                $label = $header_labels[$col_index] ?? '';
                $attrs = ' data-label="' . $label . '"';
                if (!empty($cell['colspan']) && $cell['colspan'] > 1) {
                    $attrs .= ' colspan="' . intval($cell['colspan']) . '"';
                }
                if (!empty($cell['rowspan']) && $cell['rowspan'] > 1) {
                    $attrs .= ' rowspan="' . intval($cell['rowspan']) . '"';
                }
                $html .= '<td style="' . $cell_style . '"' . $attrs . '>' . gs_tables_cell_content($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>';
    }

    $html .= '</table></div>';
    return $html;
}

function gs_tables_hex($color) {
    # Accepts #rrggbb or #rgb, returns sanitised value
    $color = trim($color);
    if (preg_match('/^#[0-9a-f]{3,6}$/i', $color)) return $color;
    return '#cccccc';
}

function gs_tables_cell_style($cell) {
    $parts = [];
    if (!empty($cell['bg']))     $parts[] = 'background-color:' . gs_tables_hex($cell['bg']);
    if (!empty($cell['fg']))     $parts[] = 'color:'      . gs_tables_hex($cell['fg']);
    if (!empty($cell['align']))  $parts[] = 'text-align:' . htmlspecialchars($cell['align']);
    if (!empty($cell['valign'])) $parts[] = 'vertical-align:' . htmlspecialchars($cell['valign']);
    if (!empty($cell['width']))  $parts[] = 'width:'      . intval($cell['width']) . '%';
    return implode(';', $parts);
}

function gs_tables_cell_content($cell) {
    $text = $cell['text'] ?? '';
    $text = htmlspecialchars($text);
    if (!empty($cell['bold']))   $text = '<strong>' . $text . '</strong>';
    if (!empty($cell['italic'])) $text = '<em>'     . $text . '</em>';
    if (!empty($cell['link']))   $text = '<a href="' . htmlspecialchars($cell['link']) . '">' . $text . '</a>';
    return $text;
}

# -----------------------------------------------------------------------
# Admin: main dispatcher
# -----------------------------------------------------------------------

function gs_tables_admin_main() {
    # createSideMenu passes action as a bare flag (?id=gs_tables&import) not ?action=import
    # So check both styles: bare flag takes priority over ?action=value
    if (isset($_GET['import']))        $action = 'import';
    elseif (isset($_GET['new']))       $action = 'new';
    elseif (isset($_GET['list']))      $action = 'list';
    elseif (isset($_GET['action']))    $action = $_GET['action'];
    else                               $action = 'list';

    # ---- handle POST saves ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gs_tables_save'])) {
        gs_tables_handle_save();
        return;
    }

    # ---- handle DELETE ----
    if ($action === 'delete' && !empty($_GET['id2'])) {
        gs_tables_confirm_delete();
        return;
    }

    if ($action === 'delete_confirm' && !empty($_GET['id2'])) {
        gs_tables_do_delete();
        return;
    }

    # ---- handle CSV import POST ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gs_tables_csv_import'])) {
        gs_tables_handle_csv_import();
        return;
    }

    # ---- handle CSV file upload POST ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gs_tables_csv_upload'])) {
        gs_tables_handle_csv_upload();
        return;
    }

    switch ($action) {
        case 'new':
        case 'edit':
            gs_tables_admin_edit();
            break;
        case 'import':
            gs_tables_admin_import();
            break;
        default:
            gs_tables_admin_list();
    }
}

# -----------------------------------------------------------------------
# Admin: list view
# -----------------------------------------------------------------------

function gs_tables_admin_list() {
    global $SITEURL;
    $tables = gs_tables_list_all();
    ?>
    <div class="gs-tables-admin">
        <h3>GS Tables <small style="font-size:0.55em;color:#888;font-weight:normal;">v<?php echo GS_TABLES_VERSION; ?></small></h3>
        <p>
            <a href="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=new"
               class="btn">+ New Table</a>
        </p>

        <?php if (empty($tables)): ?>
            <p class="gs-tables-notice">No tables yet. Click <strong>+ New Table</strong> to get started.</p>
        <?php else: ?>
        <table class="gs-tables-list-tbl">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>ID / Shortcode</th>
                    <th>Rows</th>
                    <th>Cols</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tables as $t): ?>
                <tr>
                    <td><?php echo htmlspecialchars($t['title'] ?? $t['id']); ?></td>
                    <td><code class="gs-sc">[% gs_table id="<?php echo htmlspecialchars($t['id']); ?>" %]</code></td>
                    <td><?php echo count($t['rows'] ?? []); ?></td>
                    <td><?php echo count($t['headers'] ?? []); ?></td>
                    <td class="gs-actions">
                        <a href="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=edit&amp;id2=<?php echo urlencode($t['id']); ?>">Edit</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=delete&amp;id2=<?php echo urlencode($t['id']); ?>"
                           class="gs-delete-link">Delete</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}

# -----------------------------------------------------------------------
# Admin: edit / new
# -----------------------------------------------------------------------

function gs_tables_admin_edit() {
    global $SITEURL;

    $is_new  = (isset($_GET['new']) || (isset($_GET['action']) && $_GET['action'] === 'new'));
    $edit_id = !$is_new && !empty($_GET['id2']) ? gs_tables_safe_id($_GET['id2']) : '';
    $data    = ($edit_id) ? gs_tables_load($edit_id) : null;

    # defaults for a new table
    if (!$data) {
        $data = [
            'id'      => '',
            'title'   => '',
            'caption' => '',
            'style'   => [
                'border_color'  => '#cccccc',
                'border_width'  => 1,
                'border_radius' => 4,
                'header_bg'     => '#4a6fa5',
                'header_fg'     => '#ffffff',
                'stripe_bg'     => '#f0f4f8',
                'cell_padding'  => 10,
                'font_size'     => 14,
            ],
            'headers' => [
                ['text' => 'Column 1', 'bold' => true],
                ['text' => 'Column 2', 'bold' => true],
                ['text' => 'Column 3', 'bold' => true],
            ],
            'rows' => [
                [
                    ['text' => 'Row 1, Cell 1'],
                    ['text' => 'Row 1, Cell 2'],
                    ['text' => 'Row 1, Cell 3'],
                ],
                [
                    ['text' => 'Row 2, Cell 1'],
                    ['text' => 'Row 2, Cell 2'],
                    ['text' => 'Row 2, Cell 3'],
                ],
            ],
        ];
    }

    $json = json_encode($data, JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
    <div class="gs-tables-admin">
        <h3><?php echo $is_new ? 'New Table' : 'Edit Table: ' . htmlspecialchars($data['title'] ?? $data['id']); ?> <small style="font-size:0.55em;color:#888;font-weight:normal;">v<?php echo GS_TABLES_VERSION; ?></small></h3>
        <p><a href="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=list">&larr; Back to list</a></p>

        <?php if (!empty($_GET['imported'])): ?>
        <div class="gs-flash gs-flash-ok">&#10003; Table imported successfully from CSV! Check the data below and adjust styling as needed, then save.</div>
        <?php endif; ?>
        <?php if (!empty($_GET['warnings'])): ?>
        <div class="gs-flash gs-flash-warn">&#9888; Import warnings:<br><?php echo nl2br(htmlspecialchars(str_replace('|', "\n", $_GET['warnings']))); ?></div>
        <?php endif; ?>
        <?php if (!empty($_GET['saved']) && empty($_GET['imported'])): ?>
        <div class="gs-flash gs-flash-ok">&#10003; Table saved.</div>
        <?php endif; ?>

        <form method="post" action="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=<?php echo $is_new ? 'new' : 'edit'; ?>&amp;id2=<?php echo urlencode($edit_id); ?>" id="gs-tables-form">
            <input type="hidden" name="gs_tables_save" value="1">
            <input type="hidden" name="gs_table_json" id="gs_table_json" value="">
            <input type="hidden" name="gs_table_orig_id" value="<?php echo htmlspecialchars($edit_id); ?>">

            <!-- ===== Meta ===== -->
            <div class="gs-section">
                <h4>Table Details</h4>
                <div class="gs-row">
                    <label>Title (for your reference)</label>
                    <input type="text" id="gs-title" value="<?php echo htmlspecialchars($data['title'] ?? ''); ?>" placeholder="My Products Table">
                </div>
                <div class="gs-row">
                    <label>ID / Slug <small>(letters, numbers, hyphens — used in shortcode)</small></label>
                    <input type="text" id="gs-id" value="<?php echo htmlspecialchars($data['id'] ?? ''); ?>"
                           placeholder="my-products-table" <?php echo !$is_new ? 'readonly' : ''; ?>>
                    <?php if (!$is_new): ?>
                        <small class="gs-muted">ID cannot be changed after creation (would break existing shortcodes).</small>
                    <?php endif; ?>
                </div>
                <div class="gs-row">
                    <label>Caption <small>(optional, displayed above table)</small></label>
                    <input type="text" id="gs-caption" value="<?php echo htmlspecialchars($data['caption'] ?? ''); ?>" placeholder="Table caption">
                </div>
            </div>

            <!-- ===== Table Style ===== -->
            <div class="gs-section">
                <h4>Table Style</h4>
                <div class="gs-style-grid">

                    <div class="gs-style-item">
                        <label>Header Background</label>
                        <span class="gs-color-wrap"><span class="gs-color-swatch" style="background:<?php echo htmlspecialchars($data['style']['header_bg'] ?? '#4a6fa5'); ?>"><input type="color" id="gs-s-header-bg" value="<?php echo htmlspecialchars($data['style']['header_bg'] ?? '#4a6fa5'); ?>"></span><input type="text" class="gs-color-text" id="gs-s-header-bg-txt" value="<?php echo htmlspecialchars($data['style']['header_bg'] ?? '#4a6fa5'); ?>" maxlength="7" placeholder="#4a6fa5"></span>
                    </div>
                    <div class="gs-style-item">
                        <label>Header Text Colour</label>
                        <span class="gs-color-wrap"><span class="gs-color-swatch" style="background:<?php echo htmlspecialchars($data['style']['header_fg'] ?? '#ffffff'); ?>"><input type="color" id="gs-s-header-fg" value="<?php echo htmlspecialchars($data['style']['header_fg'] ?? '#ffffff'); ?>"></span><input type="text" class="gs-color-text" id="gs-s-header-fg-txt" value="<?php echo htmlspecialchars($data['style']['header_fg'] ?? '#ffffff'); ?>" maxlength="7" placeholder="#ffffff"></span>
                    </div>
                    <div class="gs-style-item">
                        <label>Border Colour</label>
                        <span class="gs-color-wrap"><span class="gs-color-swatch" style="background:<?php echo htmlspecialchars($data['style']['border_color'] ?? '#cccccc'); ?>"><input type="color" id="gs-s-border-color" value="<?php echo htmlspecialchars($data['style']['border_color'] ?? '#cccccc'); ?>"></span><input type="text" class="gs-color-text" id="gs-s-border-color-txt" value="<?php echo htmlspecialchars($data['style']['border_color'] ?? '#cccccc'); ?>" maxlength="7" placeholder="#cccccc"></span>
                    </div>
                    <div class="gs-style-item">
                        <label>Stripe / Alt Row Colour</label>
                        <span class="gs-color-wrap"><span class="gs-color-swatch" style="background:<?php echo htmlspecialchars($data['style']['stripe_bg'] ?? '#f0f4f8'); ?>"><input type="color" id="gs-s-stripe" value="<?php echo htmlspecialchars($data['style']['stripe_bg'] ?? '#f0f4f8'); ?>"></span><input type="text" class="gs-color-text" id="gs-s-stripe-txt" value="<?php echo htmlspecialchars($data['style']['stripe_bg'] ?? '#f0f4f8'); ?>" maxlength="7" placeholder="#f0f4f8"></span>
                    </div>
                    <div class="gs-style-item">
                        <label>Border Width (px)</label>
                        <input type="number" id="gs-s-border-width" value="<?php echo intval($data['style']['border_width'] ?? 1); ?>" min="0" max="8">
                    </div>
                    <div class="gs-style-item">
                        <label>Border Radius (px)</label>
                        <input type="number" id="gs-s-border-radius" value="<?php echo intval($data['style']['border_radius'] ?? 4); ?>" min="0" max="24">
                    </div>
                    <div class="gs-style-item">
                        <label>Cell Padding (px)</label>
                        <input type="number" id="gs-s-cell-pad" value="<?php echo intval($data['style']['cell_padding'] ?? 10); ?>" min="2" max="40">
                    </div>
                    <div class="gs-style-item">
                        <label>Font Size (px)</label>
                        <input type="number" id="gs-s-font-size" value="<?php echo intval($data['style']['font_size'] ?? 14); ?>" min="10" max="28">
                    </div>

                </div>
            </div>

            <!-- ===== Column Headers ===== -->
            <div class="gs-section">
                <h4>Column Headers
                    <button type="button" class="gs-btn gs-btn-sm gs-btn-blue" id="gs-add-col">+ Column</button>
                    <button type="button" class="gs-btn gs-btn-sm gs-btn-red"  id="gs-del-col">− Last Column</button>
                </h4>
                <div id="gs-headers-wrap" class="gs-cell-grid"></div>
            </div>

            <!-- ===== Rows ===== -->
            <div class="gs-section">
                <h4>Table Rows
                    <button type="button" class="gs-btn gs-btn-sm gs-btn-blue" id="gs-add-row">+ Row</button>
                    <button type="button" class="gs-btn gs-btn-sm gs-btn-red"  id="gs-del-row">− Last Row</button>
                </h4>
                <div id="gs-rows-wrap"></div>
            </div>

            <!-- ===== Live Preview ===== -->
            <div class="gs-section">
                <h4>Live Preview <button type="button" class="gs-btn gs-btn-sm" id="gs-refresh-preview">Refresh</button></h4>
                <div id="gs-preview"></div>
            </div>

            <!-- ===== Save ===== -->
            <div class="gs-section gs-save-bar">
                <button type="submit" class="gs-btn gs-btn-green gs-btn-lg">Save Table</button>
                &nbsp;
                <a href="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=list" class="gs-btn">Cancel</a>
                <?php if (!$is_new && $data['id']): ?>
                <span class="gs-sc-preview">
                    Shortcode: <code>[% gs_table id="<?php echo htmlspecialchars($data['id']); ?>" %]</code>
                </span>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <script>
    // Pass initial table data to the JS editor
    window.GS_TABLES_DATA  = <?php echo $json; ?>;
    window.GS_TABLES_IS_NEW = <?php echo $is_new ? 'true' : 'false'; ?>;
    window.GS_TABLES_SITE  = <?php echo json_encode($SITEURL); ?>;
    </script>
    <?php
    $js_file = dirname(__FILE__) . '/gs_tables/js/admin.js';
    if (file_exists($js_file)) {
        echo '<script>' . "
" . file_get_contents($js_file) . "
</script>
";
    }
    ?>
    <?php
}

# -----------------------------------------------------------------------
# Admin: delete confirmation + execution
# -----------------------------------------------------------------------

function gs_tables_confirm_delete() {
    global $SITEURL;
    $id = gs_tables_safe_id($_GET['id2']);
    $data = gs_tables_load($id);
    ?>
    <div class="gs-tables-admin">
        <h3>Delete Table</h3>
        <p>Are you sure you want to delete <strong><?php echo htmlspecialchars($data['title'] ?? $id); ?></strong>?</p>
        <p>Any pages using <code>[% gs_table id="<?php echo htmlspecialchars($id); ?>" %]</code> will show a blank space.</p>
        <p>
            <a href="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=delete_confirm&amp;id2=<?php echo urlencode($id); ?>"
               class="gs-btn gs-btn-red">Yes, Delete</a>
            &nbsp;
            <a href="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=list" class="gs-btn">Cancel</a>
        </p>
    </div>
    <?php
}

function gs_tables_do_delete() {
    global $SITEURL;
    $id = gs_tables_safe_id($_GET['id2']);
    gs_tables_delete($id);
    echo '<script>window.location.href="' . $SITEURL . 'admin/load.php?id=gs_tables&action=list&deleted=1";</script>';
}

# -----------------------------------------------------------------------
# Admin: save handler
# -----------------------------------------------------------------------

function gs_tables_handle_save() {
    global $SITEURL;

    if (empty($_POST['gs_table_json'])) {
        echo '<p class="gs-error">Error: no data received.</p>';
        return;
    }

    $incoming = json_decode(stripslashes($_POST['gs_table_json']), true);
    if (!$incoming) {
        echo '<p class="gs-error">Error: could not parse table data.</p>';
        return;
    }

    # Sanitise the ID
    $id = gs_tables_safe_id($incoming['id'] ?? '');
    if (!$id) {
        echo '<p class="gs-error">Error: table ID is empty or invalid.</p>';
        return;
    }

    # If renaming is somehow attempted (shouldn't be, but guard anyway)
    $orig_id = gs_tables_safe_id($_POST['gs_table_orig_id'] ?? '');
    if ($orig_id && $orig_id !== $id) {
        // For safety keep original id
        $incoming['id'] = $orig_id;
        $id = $orig_id;
    }

    $incoming['id'] = $id;

    gs_tables_save($id, $incoming);

    echo '<script>window.location.href="' . $SITEURL . 'admin/load.php?id=gs_tables&action=edit&id2=' . urlencode($id) . '&saved=1";</script>';
}

# -----------------------------------------------------------------------
# CSV Import constants (bounds)
# -----------------------------------------------------------------------

define('GS_TABLES_MAX_COLS',  26);   // Max columns (A-Z feels natural)
define('GS_TABLES_MAX_ROWS', 200);   // Max data rows
define('GS_TABLES_MAX_CELL', 500);   // Max characters per cell
define('GS_TABLES_MAX_FILE', 512);   // Max CSV file size in KB

# -----------------------------------------------------------------------
# Admin: Import page
# -----------------------------------------------------------------------

function gs_tables_admin_import() {
    global $SITEURL;
    $max_kb = GS_TABLES_MAX_FILE;
    $max_cols = GS_TABLES_MAX_COLS;
    $max_rows = GS_TABLES_MAX_ROWS;
    ?>
    <div class="gs-tables-admin">
        <h3>Import CSV Data</h3>
        <p><a href="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=list">&larr; Back to list</a></p>

        <div class="gs-section gs-import-info">
            <h4>Bounds &amp; Limits</h4>
            <ul class="gs-import-limits">
                <li>Maximum <strong><?php echo $max_cols; ?> columns</strong></li>
                <li>Maximum <strong><?php echo $max_rows; ?> data rows</strong> (not counting header row)</li>
                <li>Maximum <strong><?php echo GS_TABLES_MAX_CELL; ?> characters</strong> per cell</li>
                <li>Maximum file size <strong><?php echo $max_kb; ?> KB</strong></li>
                <li>First row of CSV is treated as the <strong>column header row</strong></li>
                <li>Delimiter auto-detected: <strong>comma, semicolon, or tab</strong></li>
            </ul>
            <p class="gs-muted">Tip: Export from Excel or Google Sheets as <em>CSV (comma separated)</em> or <em>TSV (tab separated)</em>.</p>
        </div>

        <!-- ===== Method 1: Paste ===== -->
        <div class="gs-section">
            <h4>Method 1 — Paste CSV Text</h4>
            <p class="gs-muted">Copy cells from Excel / Google Sheets and paste below, or type CSV directly.</p>

            <form method="post" action="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=import"
                  id="gs-csv-paste-form">
                <input type="hidden" name="gs_tables_csv_import" value="1">

                <div class="gs-row">
                    <label>Table Title</label>
                    <input type="text" name="csv_title" placeholder="My Imported Table" style="max-width:320px">
                </div>
                <div class="gs-row">
                    <label>Table ID / Slug <small>(letters, numbers, hyphens)</small></label>
                    <input type="text" name="csv_id" placeholder="my-imported-table" style="max-width:320px">
                </div>

                <div class="gs-row">
                    <label>Paste CSV / TSV data here</label>
                    <textarea name="csv_text" id="gs-csv-paste" rows="10"
                              placeholder="Column 1,Column 2,Column 3&#10;Row 1 Val 1,Row 1 Val 2,Row 1 Val 3&#10;Row 2 Val 1,Row 2 Val 2,Row 2 Val 3"
                              style="width:100%;max-width:700px;font-family:monospace;font-size:0.9em;
                                     border:1px solid #ccc;border-radius:4px;padding:8px;box-sizing:border-box;"></textarea>
                </div>

                <div class="gs-row">
                    <label>Delimiter <small>(leave on Auto to detect automatically)</small></label>
                    <select name="csv_delimiter" style="padding:5px;border-radius:4px;border:1px solid #ccc;">
                        <option value="auto">Auto-detect</option>
                        <option value=",">Comma ( , )</option>
                        <option value=";">Semicolon ( ; )</option>
                        <option value="	">Tab</option>
                    </select>
                </div>

                <div class="gs-row">
                    <label><input type="checkbox" name="csv_has_header" value="1" checked>
                        First row is header row</label>
                </div>

                <!-- Live preview -->
                <div class="gs-row">
                    <button type="button" class="gs-btn gs-btn-blue" id="gs-csv-preview-btn">Preview</button>
                </div>
                <div id="gs-csv-preview-area" style="display:none;margin:10px 0;">
                    <h4>Preview</h4>
                    <div id="gs-csv-preview-table"></div>
                    <div id="gs-csv-bounds-msg" class="gs-flash" style="display:none;margin-top:8px;"></div>
                </div>

                <div class="gs-save-bar" style="margin-top:16px;">
                    <button type="submit" class="gs-btn gs-btn-green gs-btn-lg">Import &amp; Create Table</button>
                </div>
            </form>
        </div>

        <!-- ===== Method 2: Upload ===== -->
        <div class="gs-section">
            <h4>Method 2 — Upload CSV File</h4>
            <p class="gs-muted">Upload a <code>.csv</code> or <code>.tsv</code> file (max <?php echo $max_kb; ?> KB).</p>

            <form method="post" action="<?php echo $SITEURL; ?>admin/load.php?id=gs_tables&amp;action=import"
                  enctype="multipart/form-data">
                <input type="hidden" name="gs_tables_csv_upload" value="1">

                <div class="gs-row">
                    <label>Table Title</label>
                    <input type="text" name="csv_title" placeholder="My Imported Table" style="max-width:320px">
                </div>
                <div class="gs-row">
                    <label>Table ID / Slug</label>
                    <input type="text" name="csv_id" placeholder="my-imported-table" style="max-width:320px">
                </div>

                <div class="gs-row">
                    <label>CSV / TSV File</label>
                    <input type="file" name="csv_file" accept=".csv,.tsv,.txt">
                </div>

                <div class="gs-row">
                    <label>Delimiter</label>
                    <select name="csv_delimiter" style="padding:5px;border-radius:4px;border:1px solid #ccc;">
                        <option value="auto">Auto-detect</option>
                        <option value=",">Comma ( , )</option>
                        <option value=";">Semicolon ( ; )</option>
                        <option value="	">Tab</option>
                    </select>
                </div>

                <div class="gs-row">
                    <label><input type="checkbox" name="csv_has_header" value="1" checked>
                        First row is header row</label>
                </div>

                <div class="gs-save-bar" style="margin-top:16px;">
                    <button type="submit" class="gs-btn gs-btn-green gs-btn-lg">Upload &amp; Create Table</button>
                </div>
            </form>
        </div>

    </div>

    <script>
    // ── Live CSV preview (paste method only) ─────────────────────────
    document.getElementById('gs-csv-preview-btn').addEventListener('click', function() {
        var raw       = document.getElementById('gs-csv-paste').value.trim();
        var delimSel  = document.querySelector('select[name="csv_delimiter"]').value;
        var hasHeader = document.querySelector('input[name="csv_has_header"]').checked;
        var area      = document.getElementById('gs-csv-preview-area');
        var tblWrap   = document.getElementById('gs-csv-preview-table');
        var msgEl     = document.getElementById('gs-csv-bounds-msg');

        if (!raw) { alert('Please paste some CSV data first.'); return; }

        var delim = detectDelimiter(raw, delimSel);
        var rows  = parseCSV(raw, delim);
        var warnings = [];

        // Bounds check
        var maxCols = <?php echo GS_TABLES_MAX_COLS; ?>;
        var maxRows = <?php echo GS_TABLES_MAX_ROWS; ?>;
        var maxCell = <?php echo GS_TABLES_MAX_CELL; ?>;

        var dataRows = hasHeader ? rows.slice(1) : rows;
        var headers  = hasHeader && rows.length ? rows[0] : [];

        if (headers.length > maxCols || (rows[0] && rows[0].length > maxCols)) {
            warnings.push('⚠ Too many columns (' + (rows[0] ? rows[0].length : 0) + '). Maximum is ' + maxCols + '. Extra columns will be dropped.');
            rows = rows.map(function(r) { return r.slice(0, maxCols); });
            headers = headers.slice(0, maxCols);
            dataRows = dataRows.map(function(r) { return r.slice(0, maxCols); });
        }
        if (dataRows.length > maxRows) {
            warnings.push('⚠ Too many rows (' + dataRows.length + '). Maximum is ' + maxRows + '. Extra rows will be dropped.');
            dataRows = dataRows.slice(0, maxRows);
        }
        var cellTrunc = false;
        dataRows = dataRows.map(function(row) {
            return row.map(function(cell) {
                if (cell.length > maxCell) { cellTrunc = true; return cell.substring(0, maxCell); }
                return cell;
            });
        });
        if (cellTrunc) warnings.push('⚠ Some cells exceeded ' + maxCell + ' characters and were truncated.');

        // Build preview table
        var html = '<table style="border-collapse:collapse;width:100%;font-size:0.9em;">';
        if (headers.length) {
            html += '<thead><tr>';
            headers.forEach(function(h) {
                html += '<th style="background:#4a6fa5 !important;color:#fff;padding:6px 10px;border:1px solid #ccc;text-align:left;">' + esc(h) + '</th>';
            });
            html += '</tr></thead>';
        }
        html += '<tbody>';
        dataRows.forEach(function(row, ri) {
            var bg = ri % 2 === 1 ? '#f0f4f8' : '#fff';
            html += '<tr>';
            row.forEach(function(cell) {
                html += '<td style="padding:5px 10px;border:1px solid #ddd;background:' + bg + ';">' + esc(cell) + '</td>';
            });
            html += '</tr>';
        });
        html += '</tbody></table>';
        html += '<p style="margin-top:6px;font-size:0.85em;color:#666;">' +
                (headers.length ? headers.length : (rows[0] ? rows[0].length : 0)) + ' columns &times; ' +
                dataRows.length + ' data rows</p>';

        tblWrap.innerHTML = html;
        area.style.display = 'block';

        if (warnings.length) {
            msgEl.className = 'gs-flash gs-flash-error';
            msgEl.innerHTML = warnings.join('<br>');
            msgEl.style.display = 'block';
        } else {
            msgEl.style.display = 'none';
        }
    });

    function detectDelimiter(text, sel) {
        if (sel !== 'auto') return sel;
        var first = text.split('\n')[0];
        var tabs   = (first.match(/\t/g)   || []).length;
        var commas = (first.match(/,/g)    || []).length;
        var semis  = (first.match(/;/g)    || []).length;
        if (tabs >= commas && tabs >= semis) return '\t';
        if (semis > commas) return ';';
        return ',';
    }

    function parseCSV(text, delim) {
        var rows = [];
        var lines = text.split(/\r?\n/);
        lines.forEach(function(line) {
            if (line.trim() === '') return;
            if (delim === ',') {
                // Handle quoted fields
                var row = [], cur = '', inQ = false;
                for (var i = 0; i < line.length; i++) {
                    var c = line[i];
                    if (c === '"' && !inQ) { inQ = true; }
                    else if (c === '"' && inQ && line[i+1] === '"') { cur += '"'; i++; }
                    else if (c === '"' && inQ) { inQ = false; }
                    else if (c === ',' && !inQ) { row.push(cur.trim()); cur = ''; }
                    else { cur += c; }
                }
                row.push(cur.trim());
                rows.push(row);
            } else {
                rows.push(line.split(delim).map(function(c) { return c.trim(); }));
            }
        });
        return rows;
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    </script>
    <?php
}

# -----------------------------------------------------------------------
# CSV parsing helper (server-side)
# -----------------------------------------------------------------------

function gs_tables_parse_csv_text($text, $delimiter = 'auto') {
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);
    $lines = explode("\n", trim($text));
    $lines = array_filter($lines, function($l) { return trim($l) !== ''; });
    $lines = array_values($lines);

    if (empty($lines)) return [];

    // Auto-detect delimiter
    if ($delimiter === 'auto') {
        $first = $lines[0];
        $tabs   = substr_count($first, "\t");
        $commas = substr_count($first, ',');
        $semis  = substr_count($first, ';');
        if ($tabs >= $commas && $tabs >= $semis) $delimiter = "\t";
        elseif ($semis > $commas)                $delimiter = ';';
        else                                     $delimiter = ',';
    }

    $rows = [];
    foreach ($lines as $line) {
        if ($delimiter === ',') {
            // Use str_getcsv for proper quoted-field handling
            $rows[] = array_map('trim', str_getcsv($line, ','));
        } else {
            $rows[] = array_map('trim', explode($delimiter, $line));
        }
    }
    return $rows;
}

function gs_tables_apply_bounds($rows, &$warnings) {
    if (empty($rows)) return $rows;

    $max_cols = GS_TABLES_MAX_COLS;
    $max_rows = GS_TABLES_MAX_ROWS;
    $max_cell = GS_TABLES_MAX_CELL;

    $col_count = max(array_map('count', $rows));

    if ($col_count > $max_cols) {
        $warnings[] = "Too many columns ({$col_count}). Trimmed to {$max_cols}.";
        $rows = array_map(function($r) use ($max_cols) {
            return array_slice($r, 0, $max_cols);
        }, $rows);
    }

    // Account for header row — first row is headers
    $data_rows = count($rows) - 1;
    if ($data_rows > $max_rows) {
        $warnings[] = "Too many data rows ({$data_rows}). Trimmed to {$max_rows}.";
        $rows = array_slice($rows, 0, $max_rows + 1);
    }

    $cell_truncated = false;
    foreach ($rows as &$row) {
        foreach ($row as &$cell) {
            if (mb_strlen($cell) > $max_cell) {
                $cell = mb_substr($cell, 0, $max_cell);
                $cell_truncated = true;
            }
        }
    }
    if ($cell_truncated) {
        $warnings[] = "Some cells exceeded {$max_cell} characters and were truncated.";
    }

    return $rows;
}

function gs_tables_rows_to_tabledata($rows, $has_header) {
    if (empty($rows)) return ['headers' => [], 'rows' => []];

    $headers = [];
    $data_rows = $rows;

    if ($has_header && count($rows) > 0) {
        $header_row = array_shift($data_rows);
        foreach ($header_row as $h) {
            $headers[] = ['text' => $h, 'bold' => true];
        }
    }

    $col_count = $headers ? count($headers) : (isset($data_rows[0]) ? count($data_rows[0]) : 0);

    // Normalise row lengths
    $body = [];
    foreach ($data_rows as $row) {
        $normalised = [];
        for ($c = 0; $c < $col_count; $c++) {
            $normalised[] = ['text' => isset($row[$c]) ? $row[$c] : ''];
        }
        $body[] = $normalised;
    }

    return ['headers' => $headers, 'rows' => $body];
}

# -----------------------------------------------------------------------
# Admin: handle CSV paste import
# -----------------------------------------------------------------------

function gs_tables_handle_csv_import() {
    global $SITEURL;

    $csv_text   = $_POST['csv_text']      ?? '';
    $delimiter  = $_POST['csv_delimiter'] ?? 'auto';
    $has_header = !empty($_POST['csv_has_header']);
    $title      = trim($_POST['csv_title'] ?? '');
    $id         = gs_tables_safe_id($_POST['csv_id'] ?? '');

    if (!$id)         { gs_tables_import_error('Please provide a Table ID / Slug.'); return; }
    if (!$csv_text)   { gs_tables_import_error('No CSV data received.'); return; }

    $warnings = [];
    $rows = gs_tables_parse_csv_text($csv_text, $delimiter);
    if (empty($rows)) { gs_tables_import_error('Could not parse any data from the CSV.'); return; }

    $rows = gs_tables_apply_bounds($rows, $warnings);
    $table_data = gs_tables_rows_to_tabledata($rows, $has_header);

    $data = [
        'id'      => $id,
        'title'   => $title ?: $id,
        'caption' => '',
        'style'   => [
            'border_color'  => '#cccccc',
            'border_width'  => 1,
            'border_radius' => 4,
            'header_bg'     => '#4a6fa5',
            'header_fg'     => '#ffffff',
            'stripe_bg'     => '#f0f4f8',
            'cell_padding'  => 10,
            'font_size'     => 14,
        ],
        'headers' => $table_data['headers'],
        'rows'    => $table_data['rows'],
    ];

    gs_tables_save($id, $data);

    $warn_param = $warnings ? '&warnings=' . urlencode(implode('|', $warnings)) : '';
    echo '<script>window.location.href="' . $SITEURL . 'admin/load.php?id=gs_tables&action=edit&id2='
        . urlencode($id) . '&saved=1&imported=1' . $warn_param . '";</script>';
}

# -----------------------------------------------------------------------
# Admin: handle CSV file upload
# -----------------------------------------------------------------------

function gs_tables_handle_csv_upload() {
    global $SITEURL;

    $title     = trim($_POST['csv_title']     ?? '');
    $id        = gs_tables_safe_id($_POST['csv_id'] ?? '');
    $delimiter = $_POST['csv_delimiter']      ?? 'auto';
    $has_header = !empty($_POST['csv_has_header']);

    if (!$id) { gs_tables_import_error('Please provide a Table ID / Slug.'); return; }

    if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        gs_tables_import_error('File upload failed. Error code: ' . ($_FILES['csv_file']['error'] ?? 'unknown'));
        return;
    }

    $max_bytes = GS_TABLES_MAX_FILE * 1024;
    if ($_FILES['csv_file']['size'] > $max_bytes) {
        gs_tables_import_error('File too large. Maximum size is ' . GS_TABLES_MAX_FILE . ' KB.');
        return;
    }

    // Validate file extension
    $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'tsv', 'txt'])) {
        gs_tables_import_error('Invalid file type. Please upload a .csv, .tsv, or .txt file.');
        return;
    }

    $csv_text = file_get_contents($_FILES['csv_file']['tmp_name']);
    if ($csv_text === false || trim($csv_text) === '') {
        gs_tables_import_error('Could not read the uploaded file, or it is empty.');
        return;
    }

    $warnings = [];
    $rows = gs_tables_parse_csv_text($csv_text, $delimiter);
    if (empty($rows)) { gs_tables_import_error('Could not parse any data from the file.'); return; }

    $rows = gs_tables_apply_bounds($rows, $warnings);
    $table_data = gs_tables_rows_to_tabledata($rows, $has_header);

    $data = [
        'id'      => $id,
        'title'   => $title ?: $id,
        'caption' => '',
        'style'   => [
            'border_color'  => '#cccccc',
            'border_width'  => 1,
            'border_radius' => 4,
            'header_bg'     => '#4a6fa5',
            'header_fg'     => '#ffffff',
            'stripe_bg'     => '#f0f4f8',
            'cell_padding'  => 10,
            'font_size'     => 14,
        ],
        'headers' => $table_data['headers'],
        'rows'    => $table_data['rows'],
    ];

    gs_tables_save($id, $data);

    $warn_param = $warnings ? '&warnings=' . urlencode(implode('|', $warnings)) : '';
    echo '<script>window.location.href="' . $SITEURL . 'admin/load.php?id=gs_tables&action=edit&id2='
        . urlencode($id) . '&saved=1&imported=1' . $warn_param . '";</script>';
}

# -----------------------------------------------------------------------
# Import error display
# -----------------------------------------------------------------------

function gs_tables_import_error($msg) {
    global $SITEURL;
    echo '<div class="gs-tables-admin">';
    echo '<div class="gs-flash gs-flash-error">' . htmlspecialchars($msg) . '</div>';
    echo '<p><a href="' . $SITEURL . 'admin/load.php?id=gs_tables&amp;action=import" class="gs-btn">&larr; Back to Import</a></p>';
    echo '</div>';
}
