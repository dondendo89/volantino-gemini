<?php
/*
Plugin Name: Volantino Gemini
Description: Integrazione WordPress con l’API Volantino Gemini su Render per avviare lo scraping e visualizzare/ricercare i prodotti via shortcode.
Version: 1.0.0
Author: Dev
*/

if (!defined('ABSPATH')) { exit; }

$vg_api_base_default = 'https://volantino-gemini.onrender.com';
$vg_api_base = function_exists('get_option') ? get_option('vg_api_base_url', $vg_api_base_default) : $vg_api_base_default;
if (!defined('VG_API_BASE')) { define('VG_API_BASE', $vg_api_base); }

// RIMOSSO: shim di compatibilità per evitare conflitti con le funzioni core di WordPress.

// Opzione per il nome supermercato (usata per avviare scraping)
function vg_register_settings() {
    register_setting('vg_settings_group', 'vg_supermercato_nome', [
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default' => 'Supermercati Deco Arena',
    ]);
    register_setting('vg_settings_group', 'vg_api_base_url', [
        'type' => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default' => 'https://volantino-gemini.onrender.com',
    ]);
}
add_action('admin_init', 'vg_register_settings');

// Menu di amministrazione
function vg_admin_menu() {
    add_menu_page(
        'Volantino Gemini',
        'Volantino Gemini',
        'manage_options',
        'volantino-gemini',
        'vg_render_admin_page',
        'dashicons-media-document',
        81
    );
}
add_action('admin_menu', 'vg_admin_menu');

// Pagina impostazioni
function vg_render_admin_page() {
    if (!current_user_can('manage_options')) { return; }
    $supermercato = get_option('vg_supermercato_nome', 'Supermercati Deco Arena');
    $nonce = wp_create_nonce('vg_avvia_scraping_nonce');
    echo '<div class="wrap">';
    echo '<h1>Volantino Gemini – Impostazioni</h1>';
    echo '<form method="post" action="options.php" style="margin-bottom:24px;">';
    settings_fields('vg_settings_group');
    do_settings_sections('vg_settings_group');
    echo '<table class="form-table">';
    echo '<tr><th scope="row"><label for="vg_supermercato_nome">Nome Supermercato</label></th><td>';
    echo '<input type="text" id="vg_supermercato_nome" name="vg_supermercato_nome" value="' . esc_attr($supermercato) . '" class="regular-text" />';
    echo '</td></tr>';
    echo '<tr><th scope="row"><label for="vg_api_base_url">Endpoint API Base</label></th><td>';
    echo '<input type="url" id="vg_api_base_url" name="vg_api_base_url" value="' . esc_attr(get_option('vg_api_base_url', 'https://volantino-gemini.onrender.com')) . '" class="regular-text" placeholder="https://volantino-gemini.onrender.com" />';
    echo '<p class="description">Imposta l\'URL base dell\'API (es. https://volantino-gemini.onrender.com oppure http://127.0.0.1:8000). Tutte le chiamate useranno questo endpoint.</p>';
    echo '</td></tr>';
    echo '</table>';
    submit_button('Salva impostazioni');
    echo '</form>';

    echo '<hr />';
    echo '<h2>Avvia scraping su Render</h2>';
    echo '<p>Il bottone seguente invia una richiesta al server su Render per avviare lo scraping dei volantini (endpoint /extract_all). La form usa POST verso WordPress, che poi richiama l’API remota (GET) per compatibilità con l’endpoint attuale.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
    echo '<input type="hidden" name="action" value="vg_avvia_scraping" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '" />';
    echo '<label for="vg_limit">Limite volantini (opzionale): </label> <input type="number" min="1" step="1" id="vg_limit" name="limit" style="width:120px;" />';
    echo '<p class="submit">';
    echo '<button type="submit" class="button button-primary">Avvia scraping</button>';
    echo '</p>';
    echo '</form>';

    // Sezione: Estrai prodotti da PDF specifico su Render
    echo '<hr />';
    echo '<h2>Estrai prodotti da PDF specifico su Render</h2>';
    echo '<p>Inserisci l\'URL del PDF e il nome del supermercato; verrà avviata l\'estrazione (endpoint /extract) su Render.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
    echo '<input type="hidden" name="action" value="vg_avvia_estrazione_pdf" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('vg_estrazione_pdf_nonce')) . '" />';
    echo '<p><label for="vg_pdf_url">URL PDF:</label> <input type="url" id="vg_pdf_url" name="pdf_url" class="regular-text" placeholder="https://esempio.com/volantino.pdf" required /></p>';
    echo '<p><label for="vg_pdf_supermercato">Nome Supermercato:</label> <input type="text" id="vg_pdf_supermercato" name="supermercato" class="regular-text" value="' . esc_attr($supermercato) . '" required /></p>';
    echo '<p class="submit"><button type="submit" class="button button-primary">Avvia estrazione PDF</button></p>';
echo '</form>';

    // NUOVO: Importa prodotti da file JSON
    echo '<hr />';
    echo '<h2>Importa prodotti da file JSON</h2>';
    echo '<p>Carica un file JSON esportato (es. gemini_results_*.json) per importare i prodotti nel backend. Il file deve contenere un array di prodotti o un oggetto con la chiave <code>products</code>.</p>';
    echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
    echo '<input type="hidden" name="action" value="vg_import_json" />';
    echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('vg_import_json_nonce')) . '" />';
    echo '<p><label for="vg_import_json_file">File JSON:</label> <input type="file" id="vg_import_json_file" name="json_file" accept=".json,application/json" required /></p>';
    echo '<p><label for="vg_import_supermercato">Nome Supermercato:</label> <input type="text" id="vg_import_supermercato" name="supermercato_nome" class="regular-text" value="' . esc_attr($supermercato) . '" /></p>';
    echo '<p><label for="vg_import_job_id">Job ID (opzionale):</label> <input type="text" id="vg_import_job_id" name="job_id" class="regular-text" /></p>';
    echo '<p><label for="vg_import_vol_url">Volantino URL (opzionale):</label> <input type="url" id="vg_import_vol_url" name="volantino_url" class="regular-text" placeholder="https://esempio.com/volantino.pdf" /></p>';
    echo '<p><label for="vg_import_vol_name">Nome Volantino (opzionale):</label> <input type="text" id="vg_import_vol_name" name="volantino_name" class="regular-text" /></p>';
    echo '<p><label for="vg_import_vol_validita">Validità Volantino (opzionale):</label> <input type="text" id="vg_import_vol_validita" name="volantino_validita" class="regular-text" placeholder="es. 01-15 Ottobre 2024" /></p>';
echo '<p class="submit"><button type="submit" class="button button-primary">Importa JSON</button></p>';
echo '</form>';

// NUOVO: Importa prodotti da URL JSON
echo '<hr />';
echo '<h2>Importa da URL JSON</h2>';
echo '<p>Inserisci l\'URL di un file JSON raggiungibile (es. ' . esc_html(VG_API_BASE . '/results/latest') . ') e importa i prodotti.</p>';
echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;">';
echo '<input type="hidden" name="action" value="vg_import_json_url" />';
echo '<input type="hidden" name="_wpnonce" value="' . esc_attr(wp_create_nonce('vg_import_json_url_nonce')) . '" />';
echo '<p><label for="vg_import_json_url">URL JSON:</label> <input type="url" id="vg_import_json_url" name="json_url" class="regular-text" placeholder="' . esc_attr(VG_API_BASE . '/results/latest') . '" required /></p>';
echo '<p><label for="vg_import_url_supermercato">Nome Supermercato:</label> <input type="text" id="vg_import_url_supermercato" name="supermercato_nome" class="regular-text" value="' . esc_attr($supermercato) . '" /></p>';
echo '<p><label for="vg_import_url_job_id">Job ID (opzionale):</label> <input type="text" id="vg_import_url_job_id" name="job_id" class="regular-text" /></p>';
echo '<p><label for="vg_import_url_vol_url">Volantino URL (opzionale):</label> <input type="url" id="vg_import_url_vol_url" name="volantino_url" class="regular-text" placeholder="https://esempio.com/volantino.pdf" /></p>';
echo '<p><label for="vg_import_url_vol_name">Nome Volantino (opzionale):</label> <input type="text" id="vg_import_url_vol_name" name="volantino_name" class="regular-text" /></p>';
echo '<p><label for="vg_import_url_vol_validita">Validità Volantino (opzionale):</label> <input type="text" id="vg_import_url_vol_validita" name="volantino_validita" class="regular-text" placeholder="es. 01-15 Ottobre 2024" /></p>';
echo '<p class="submit"><button type="submit" class="button button-primary">Importa da URL</button></p>';
echo '</form>';

// Mostra esito se presente
    if (isset($_GET['vg_msg'])) {
        echo '<div class="notice notice-info"><p>' . esc_html($_GET['vg_msg']) . '</p></div>';
    }

    echo '<hr />';
    echo '<h2>Shortcode disponibili</h2>';
    echo '<p>Puoi inserire questi shortcode nelle pagine/articoli o nei template del tema.</p>';
    echo '<ul style="list-style: disc; padding-left: 20px;">';
    echo '<li><strong>Elenco con ricerca e paginazione (AJAX):</strong> <code>[volantino_gemini]</code></li>';
    echo '<li><strong>Elenco completo renderizzato lato server:</strong> <code>[volantino_gemini_all]</code><br />';
    echo 'Attributi opzionali: <code>supermarket</code>, <code>q</code>, <code>page_size</code>, <code>max_pages</code>.<br />';
    echo 'Esempi: <code>[volantino_gemini_all supermarket=\"Supermercati Deco Arena\"]</code>, '; 
    echo '<code>[volantino_gemini_all q=\"pasta\" page_size=\"100\" max_pages=\"30\"]</code>.<br />';
    echo 'Nei template PHP: <code>echo do_shortcode(\'[volantino_gemini_all supermarket=\"Supermercati Deco Arena\"]\');</code>';
    echo '</li>';
    echo '</ul>';

    echo '<hr />';
    echo '<h2>API disponibili su Render</h2>';
    echo '<ul style="list-style: disc; padding-left: 20px;">';
    echo '<li><a href="' . esc_url(VG_API_BASE . '/health') . '" target="_blank">/health</a></li>';
    echo '<li><a href="' . esc_url(VG_API_BASE . '/products') . '" target="_blank">/products</a> (parametri: page, page_size, q, supermarket, marca, categoria, price_min, price_max)</li>';
    echo '<li><a href="' . esc_url(VG_API_BASE . '/results/latest') . '" target="_blank">/results/latest</a></li>';
    echo '<li><a href="' . esc_url(VG_API_BASE . '/images') . '" target="_blank">/images</a> (serve le immagini dei prodotti)</li>';
    echo '<li><a href="' . esc_url(VG_API_BASE . '/compare') . '" target="_blank">/compare</a> (POST JSON: { items: [{ nome, marca, qty }] })</li>';
    echo '</ul>';

    echo '</div>';
}

// Handler POST admin per avviare scraping
function vg_handle_avvia_scraping() {
    if (!current_user_can('manage_options')) { wp_die('Non autorizzato'); }
    check_admin_referer('vg_avvia_scraping_nonce');

    $supermercato = get_option('vg_supermercato_nome', 'Supermercati Deco Arena');
    $limit = isset($_POST['limit']) ? intval($_POST['limit']) : null;

    // Endpoint GET /extract_all su Render
    $url = VG_API_BASE . '/extract_all';
    $args = [ 'supermercato_nome' => $supermercato ];
    if (!empty($limit)) { $args['limit'] = $limit; }
    $url = add_query_arg($args, $url);

    $response = wp_remote_get($url, [
        'timeout' => 60,
        'headers' => [ 'Accept' => 'application/json' ],
    ]);

    $msg = 'Scraping avviato.';
    if (is_wp_error($response)) {
        $msg = 'Errore avvio scraping: ' . $response->get_error_message();
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code >= 200 && $code < 300) {
            $msg = 'Scraping avviato con successo. Risposta: ' . $body;
        } else {
            $msg = 'Errore remoto (' . $code . '): ' . $body;
        }
    }
    wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], admin_url('admin.php?page=volantino-gemini')));
    exit;
}
add_action('admin_post_vg_avvia_scraping', 'vg_handle_avvia_scraping');

// Shortcode per frontend
function vg_register_assets() {
    $js_path = plugin_dir_path(__FILE__) . 'assets/js/volantino-gemini.js';
    $css_path = plugin_dir_path(__FILE__) . 'assets/css/volantino-gemini.css';
    $js_ver = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
    $css_ver = file_exists($css_path) ? filemtime($css_path) : '1.0.0';
    wp_register_script('vg-frontend', plugins_url('assets/js/volantino-gemini.js', __FILE__), ['jquery'], $js_ver, true);
    // Nessuna localizzazione verso admin-ajax: il JS usa direttamente VG_API_BASE e VG_DEFAULT_SUPERMARKET
    wp_register_style('vg-frontend-style', plugins_url('assets/css/volantino-gemini.css', __FILE__), [], $css_ver);
}
add_action('wp_enqueue_scripts', 'vg_register_assets');

function vg_shortcode_products($atts) {
    wp_enqueue_script('vg-frontend');
    wp_enqueue_style('vg-frontend-style');

    $supermercato_default = get_option('vg_supermercato_nome', 'Supermercati Deco Arena');

    ob_start();
    ?>
    <div id="vg-products-app">
        <div class="vg-controls">
            <label>Supermercato: 
                <select id="vg-supermarket"></select>
            </label>
            <label style="margin-left:12px;">Cerca: 
                <input type="text" id="vg-search" placeholder="Cerca prodotti" />
            </label>
        </div>
        <div id="vg-products-list"></div>
        <div class="vg-pagination">
            <button id="vg-prev" class="button" disabled>Precedente</button>
            <span id="vg-page-info">Pagina 1</span>
            <button id="vg-next" class="button" disabled>Successiva</button>
        </div>
        <div id="vg-shopping">
            <h3>Lista della spesa</h3>
            <div id="vg-shopping-list"></div>
            <div id="vg-shopping-total"></div>
            <div class="vg-shopping-actions">
                <button id="vg-shopping-clear" class="button">Svuota lista</button>
                <button id="vg-shopping-copy" class="button">Copia lista</button>
                <button id="vg-shopping-compare" class="button button-primary">Confronta prezzi</button>
            </div>
            <div id="vg-compare-results"></div>
        </div>
    </div>
    <script>
        window.VG_DEFAULT_SUPERMARKET = <?php echo json_encode($supermercato_default); ?>;
        window.VG_API_BASE = <?php echo json_encode(VG_API_BASE); ?>;
        // Elenco supermercati disponibile nel DB del plugin (opzioni). Al momento si usa solo quello salvato.
        window.VG_SUPERMARKETS = <?php echo json_encode([$supermercato_default]); ?>;
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('volantino_gemini', 'vg_shortcode_products');

// AJAX: lista supermercati
function vg_ajax_list_supermarkets() {
    check_ajax_referer('vg_ajax_nonce', 'nonce');

    $url = add_query_arg([
        'page' => 1,
        'page_size' => 200,
    ], VG_API_BASE . '/products');

    $response = wp_remote_get($url, [ 'timeout' => 30, 'headers' => [ 'Accept' => 'application/json' ] ]);
    if (is_wp_error($response)) {
        wp_send_json_error(['message' => $response->get_error_message()], 500);
    }
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    if ($code >= 200 && $code < 300 && is_array($data)) {
        $supermarkets = [];
        if (!empty($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $p) {
                if (!empty($p['supermercato'])) {
                    $supermarkets[$p['supermercato']] = true;
                }
            }
        }
        $list = array_keys($supermarkets);
        if (empty($list)) { $list = [ get_option('vg_supermercato_nome', 'Supermercati Deco Arena') ]; }
        wp_send_json_success([ 'supermarkets' => $list ]);
    } else {
        wp_send_json_error(['message' => 'Errore remoto', 'status' => $code, 'body' => $body], $code ?: 500);
    }
}
// Hook AJAX rimossi: il frontend usa direttamente gli endpoint API, non admin-ajax
// add_action('wp_ajax_vg_list_supermarkets', 'vg_ajax_list_supermarkets');
// add_action('wp_ajax_nopriv_vg_list_supermarkets', 'vg_ajax_list_supermarkets');
// add_action('wp_ajax_vg_fetch_products', 'vg_ajax_fetch_products');
// add_action('wp_ajax_nopriv_vg_fetch_products', 'vg_ajax_fetch_products');

// Shortcode server-side per mostrare tutti i prodotti nel template
function vg_shortcode_all_products($atts) {
    // attributi con default
    $atts = shortcode_atts([
        'supermarket' => get_option('vg_supermercato_nome', 'Supermercati Deco Arena'),
        'q' => '',
        'page_size' => 100, // numero per pagina richiesto all'API
        'max_pages' => 50    // limite massimo di pagine da iterare
    ], $atts, 'volantino_gemini_all');

    // Carico stile e JS per supportare la lista della spesa
    wp_enqueue_style('vg-frontend-style');
    wp_enqueue_script('vg-frontend');

    $supermarket = sanitize_text_field($atts['supermarket']);
    $q = sanitize_text_field($atts['q']);
    $page_size = max(1, intval($atts['page_size']));
    $max_pages = max(1, intval($atts['max_pages']));

    $all_products = [];
    $total = null;

    for ($page = 1; $page <= $max_pages; $page++) {
        $args = [
            'page' => $page,
            'page_size' => $page_size,
        ];
        if (!empty($q)) { $args['q'] = $q; }
        if (!empty($supermarket)) { $args['supermarket'] = $supermarket; }

        $url = add_query_arg($args, VG_API_BASE . '/products');
        $response = wp_remote_get($url, [ 'timeout' => 30, 'headers' => [ 'Accept' => 'application/json' ] ]);
        if (is_wp_error($response)) {
            break; // interrompe in caso di errore rete
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            break; // interrompe in caso di errore remoto
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) { break; }

        if ($total === null && isset($data['total'])) {
            $total = intval($data['total']);
        }

        $products = isset($data['products']) && is_array($data['products']) ? $data['products'] : [];
        if (empty($products)) {
            break; // nessun prodotto: fine
        }
        $all_products = array_merge($all_products, $products);

        if ($total !== null && count($all_products) >= $total) {
            break; // abbiamo già raccolto tutti i prodotti
        }
    }

    ob_start();
    echo '<div id="vg-products-app">';
    echo '<div class="vg-controls">';
    echo '<strong>Supermercato:</strong> ' . esc_html($supermarket);
    if (!empty($q)) {
        echo ' &nbsp; <strong>Ricerca:</strong> ' . esc_html($q);
    }
    echo '</div>';
    
    echo '<div id="vg-products-list">';
    if (!empty($all_products)) {
        foreach ($all_products as $p) {
            $name = isset($p['nome']) ? $p['nome'] : 'Prodotto';
            $brand = isset($p['marca']) ? $p['marca'] : '';
            $cat = isset($p['categoria']) ? $p['categoria'] : '';
            $super = isset($p['supermercato']) ? $p['supermercato'] : '';
            $price = isset($p['prezzo']) ? $p['prezzo'] : '';
            $imgRel = isset($p['immagine_prodotto_card']) ? ltrim($p['immagine_prodotto_card'], '/') : '';
            $img = $imgRel ? (VG_API_BASE . '/images/' . $imgRel) : '';

            echo '<div class="vg-card">';
            if ($img) {
                echo '<img src="' . esc_url($img) . '" alt="' . esc_attr($name) . '" />';
            }
            echo '<div class="vg-title">' . esc_html($name) . '</div>';
            echo '<div class="vg-meta">';
            if ($brand) { echo '<span>Marca: ' . esc_html($brand) . '</span> '; }
            if ($cat) { echo '<span>Categoria: ' . esc_html($cat) . '</span> '; }
            if ($super) { echo '<span>Supermercato: ' . esc_html($super) . '</span> '; }
            echo '</div>';
            if ($price) { echo '<div class="vg-price">' . esc_html($price) . '</div>'; }
            echo '<div class="vg-actions">';
            echo '<button class="button vg-add" data-item="' . esc_attr(rawurlencode(json_encode($p))) . '">Aggiungi alla lista</button>';
            echo '</div>';
            echo '</div>';
        }
    } else {
        echo '<p>Nessun prodotto trovato.</p>';
    }
    echo '</div>';
    echo '<div id="vg-shopping">';
    echo '<h3>Lista della spesa</h3>';
    echo '<div id="vg-shopping-list"></div>';
    echo '<div id="vg-shopping-total"></div>';
    echo '<div class="vg-shopping-actions">';
    echo '<button id="vg-shopping-clear" class="button">Svuota lista</button>';
    echo '<button id="vg-shopping-copy" class="button">Copia lista</button>';
    echo '<button id="vg-shopping-compare" class="button button-primary">Confronta prezzi</button>';
    echo '</div>';
    echo '<div id="vg-compare-results"></div>';
    echo '</div>';
    echo '</div>';
    echo '<script>window.VG_API_BASE = ' . json_encode(VG_API_BASE) . ';</script>';

    return ob_get_clean();
}
add_shortcode('volantino_gemini_all', 'vg_shortcode_all_products');
function vg_handle_avvia_estrazione_pdf() {
    if (!current_user_can('manage_options')) { wp_die('Non autorizzato'); }
    check_admin_referer('vg_estrazione_pdf_nonce');

    $pdf_url = isset($_POST['pdf_url']) ? esc_url_raw($_POST['pdf_url']) : '';
    $supermercato = isset($_POST['supermercato']) ? sanitize_text_field($_POST['supermercato']) : get_option('vg_supermercato_nome', 'Supermercati Deco Arena');

    if (empty($pdf_url)) {
        $msg = 'URL PDF mancante.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], admin_url('admin.php?page=volantino-gemini')));
        exit;
    }

    $endpoint = VG_API_BASE . '/extract';
    $payload = [ 'url' => $pdf_url, 'supermercato_nome' => $supermercato ];

    $response = wp_remote_post($endpoint, [
        'timeout' => 120,
        'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
        'body' => json_encode($payload),
    ]);

    $msg = 'Estrazione PDF avviata.';
    if (is_wp_error($response)) {
        $msg = 'Errore avvio estrazione: ' . $response->get_error_message();
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code >= 200 && $code < 300) {
            $msg = 'Estrazione avviata con successo. Risposta: ' . $body;
        } else {
            $msg = 'Errore remoto (' . $code . '): ' . $body;
        }
    }

    wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], admin_url('admin.php?page=volantino-gemini')));
    exit;
}
add_action('admin_post_vg_avvia_estrazione_pdf', 'vg_handle_avvia_estrazione_pdf');

function vg_handle_import_json() {
    if (!current_user_can('manage_options')) { wp_die('Non autorizzato'); }
    check_admin_referer('vg_import_json_nonce');

    $redirect = admin_url('admin.php?page=volantino-gemini');
    if (!isset($_FILES['json_file']) || empty($_FILES['json_file']['tmp_name'])) {
        $msg = 'Nessun file JSON caricato.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $file = $_FILES['json_file'];
    if (!empty($file['error'])) {
        $msg = 'Errore upload: ' . intval($file['error']);
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
        $msg = 'Impossibile leggere il file JSON.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $data = json_decode($content, true);
    if (!is_array($data)) {
        $msg = 'File JSON non valido.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $products = [];
    if (isset($data['products']) && is_array($data['products'])) {
        $products = $data['products'];
    } elseif (isset($data['data']['products']) && is_array($data['data']['products'])) {
        $products = $data['data']['products'];
    } elseif (is_array($data) && isset($data[0])) {
        $products = $data; // array puro
    }

    if (empty($products)) {
        $msg = 'Nessun prodotto trovato nel file JSON.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $payload = [
        'job_id' => isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : null,
        'supermercato_nome' => isset($_POST['supermercato_nome']) ? sanitize_text_field($_POST['supermercato_nome']) : get_option('vg_supermercato_nome', 'Supermercati Deco Arena'),
        'volantino_url' => isset($_POST['volantino_url']) ? esc_url_raw($_POST['volantino_url']) : null,
        'volantino_name' => isset($_POST['volantino_name']) ? sanitize_text_field($_POST['volantino_name']) : null,
        'volantino_validita' => isset($_POST['volantino_validita']) ? sanitize_text_field($_POST['volantino_validita']) : null,
        'products' => $products,
    ];
    foreach ($payload as $k => $v) { if ($v === null) { unset($payload[$k]); } }

    $endpoint = VG_API_BASE . '/import';
    $response = wp_remote_post($endpoint, [
        'timeout' => 120,
        'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        $msg = 'Errore import: ' . $response->get_error_message();
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code >= 200 && $code < 300) {
            $msg = 'Import riuscito. Risposta: ' . $body;
        } else {
            $msg = 'Errore remoto (' . $code . '): ' . $body;
        }
    }
    wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
    exit;
}
function vg_handle_import_json_url() {
    if (!current_user_can('manage_options')) { wp_die('Non autorizzato'); }
    check_admin_referer('vg_import_json_url_nonce');

    $redirect = admin_url('admin.php?page=volantino-gemini');
    $json_url = isset($_POST['json_url']) ? esc_url_raw($_POST['json_url']) : '';
    if (empty($json_url)) {
        $msg = 'URL JSON mancante.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $resp = wp_remote_get($json_url, [ 'timeout' => 60 ]);
    if (is_wp_error($resp)) {
        $msg = 'Errore nel recupero URL: ' . $resp->get_error_message();
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }
    $code = wp_remote_retrieve_response_code($resp);
    $body = wp_remote_retrieve_body($resp);
    if ($code < 200 || $code >= 300 || empty($body)) {
        $msg = 'Risposta non valida (' . $code . ') dal URL fornito.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        $msg = 'JSON scaricato non valido.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $products = [];
    if (isset($data['products']) && is_array($data['products'])) {
        $products = $data['products'];
    } elseif (isset($data['data']['products']) && is_array($data['data']['products'])) {
        $products = $data['data']['products'];
    } elseif (is_array($data) && isset($data[0])) {
        $products = $data; // array puro
    }

    if (empty($products)) {
        $msg = 'Nessun prodotto trovato nel JSON.';
        wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
        exit;
    }

    $payload = [
        'job_id' => isset($_POST['job_id']) ? sanitize_text_field($_POST['job_id']) : null,
        'supermercato_nome' => isset($_POST['supermercato_nome']) ? sanitize_text_field($_POST['supermercato_nome']) : get_option('vg_supermercato_nome', 'Supermercati Deco Arena'),
        'volantino_url' => isset($_POST['volantino_url']) ? esc_url_raw($_POST['volantino_url']) : null,
        'volantino_name' => isset($_POST['volantino_name']) ? sanitize_text_field($_POST['volantino_name']) : null,
        'volantino_validita' => isset($_POST['volantino_validita']) ? sanitize_text_field($_POST['volantino_validita']) : null,
        'products' => $products,
    ];
    foreach ($payload as $k => $v) { if ($v === null) { unset($payload[$k]); } }

    $endpoint = VG_API_BASE . '/import';
    $response = wp_remote_post($endpoint, [
        'timeout' => 120,
        'headers' => [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        $msg = 'Errore import: ' . $response->get_error_message();
    } else {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if ($code >= 200 && $code < 300) {
            $msg = 'Import da URL riuscito. Risposta: ' . $body;
        } else {
            $msg = 'Errore remoto (' . $code . '): ' . $body;
        }
    }
    wp_safe_redirect(add_query_arg(['vg_msg' => rawurlencode($msg)], $redirect));
    exit;
}
add_action('admin_post_vg_import_json', 'vg_handle_import_json');
add_action('admin_post_vg_import_json_url', 'vg_handle_import_json_url');