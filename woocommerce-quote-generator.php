<?php
/**
 * Plugin Name:  WooCommerce Quote Generator
 * Description:  Devis PDF identique pour le client (téléchargement) et l'admin (email + pièce jointe). Support WAPF, WP Configurator Pro, codes promo, TVA par ligne, images, descriptions IA, ajout manuel de produits (admin).
 * Version:      3.5
 * Author:       Abri Français
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// ============================================================
// MISES À JOUR AUTOMATIQUES VIA GITHUB
// ============================================================
if (file_exists(__DIR__ . '/plugin-update-checker/plugin-update-checker.php')) {
    require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
    $wqg_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/antonymorla/woocommerce-quote-generator/',
        __FILE__,
        'woocommerce-quote-generator'
    );
    // Utiliser les releases GitHub (fichier ZIP en pièce jointe) comme source de mise à jour
    $wqg_updater->getVcsApi()->enableReleaseAssets();
}

// Prompt système IA par défaut — modifiable dans Réglages › Générateur de devis
if (!defined('WQG_DEFAULT_AI_PROMPT')) {
define('WQG_DEFAULT_AI_PROMPT',
    "Tu es un expert technique en produits de construction et d'aménagement extérieur.\n" .
    "Génère une FICHE TECHNIQUE en français, en {max_mots} mots maximum.\n\n" .
    "RÈGLE FONDAMENTALE : utilise UNIQUEMENT les données explicitement présentes dans la description\n" .
    "et les options de configuration fournies. N'ajoute, ne déduis et n'invente JAMAIS d'information\n" .
    "qui n'est pas textuellement présente dans les données source. Si une information n'est pas\n" .
    "mentionnée (certifications, normes, résistances, poids, etc.), NE LA MENTIONNE PAS.\n\n" .
    "FORMAT STRICTEMENT OBLIGATOIRE — liste de points bullet uniquement :\n" .
    "• LIBELLÉ EN MAJUSCULES : valeur(s) précise(s)\n" .
    "Exemple : • DIMENSIONS EXTÉRIEURES : L 300 × l 200 × H 250 cm\n\n" .
    "CONTENU À INCLURE (si et seulement si présent dans les données source) :\n" .
    "• Dimensions (toutes celles fournies : intérieures, extérieures, dalle, hors tout)\n" .
    "• Matériaux principaux mentionnés\n" .
    "• Traitements de surface mentionnés\n" .
    "• Finitions et coloris mentionnés\n" .
    "• Si une configuration est fournie : intégrer les options activées avec leurs valeurs exactes\n" .
    "  (dimensions, ouvertures, extensions, bardage, couverture, menuiserie, accessoires)\n\n" .
    "INTERDICTIONS ABSOLUES :\n" .
    "• Inventer ou déduire des informations non présentes dans les données source\n" .
    "• Ajouter des certifications, normes, résistances ou labels non explicitement mentionnés\n" .
    "• Phrases rédigées, introduction ou conclusion\n" .
    "• Formulations commerciales (livraison rapide, facile à monter, personnalisable, etc.)\n" .
    "• Mentionner « non spécifié » ou « non précisé » — omettre simplement la ligne\n" .
    "• Numérotation ou sous-titres"
);
} // end if (!defined)

// ============================================================
// STYLES
// ============================================================
function wqg_enqueue_styles()
{
    wp_enqueue_style('wqg-styles', plugins_url('/css/wqg-styles.css', __FILE__), [], '3.0');

    // Surcharge dynamique des couleurs du formulaire frontend selon les réglages
    $cp = sanitize_hex_color(get_option('wqg_color_primary', '#67694E')) ?: '#67694E';
    $ca = sanitize_hex_color(get_option('wqg_color_accent',  '#4E5038')) ?: '#4E5038';
    // Dériver une version légère de la couleur principale pour l'ombre de focus
    $cp_hex = ltrim($cp, '#');
    $cp_r   = hexdec(substr($cp_hex, 0, 2));
    $cp_g   = hexdec(substr($cp_hex, 2, 2));
    $cp_b   = hexdec(substr($cp_hex, 4, 2));
    $cp_rgb = "{$cp_r},{$cp_g},{$cp_b}";

    $inline = "
        #quote-form-wrapper h2 {
            color: {$cp};
            border-bottom-color: {$cp};
        }
        #quote-form label {
            color: {$cp};
        }
        #quote-form input[type=\"text\"]:focus,
        #quote-form input[type=\"email\"]:focus,
        #quote-form input[type=\"tel\"]:focus,
        #quote-form textarea:focus {
            border-color: {$cp};
            box-shadow: 0 0 0 3px rgba({$cp_rgb}, 0.15);
        }
        .wqg-submit {
            background-color: {$cp};
        }
        .wqg-submit:hover {
            background-color: {$ca};
        }
        .wqg-admin-badge { background-color: {$cp}; }
        .wqg-row-fields label { color: {$cp}; }
        #wqg-add-item { background-color: {$ca}; }
        #wqg-add-item:hover { background-color: {$cp}; }
        .wqg-admin-header strong { color: {$cp}; }
        .wqg-ttc-display { color: {$cp}; }
    ";
    wp_add_inline_style('wqg-styles', $inline);
}
add_action('wp_enqueue_scripts', 'wqg_enqueue_styles');

/** JS admin (section ajout manuel) — chargé uniquement pour les admins connectés */
function wqg_enqueue_scripts()
{
    if (is_user_logged_in() && (current_user_can('administrator') || current_user_can('manage_woocommerce'))) {
        wp_enqueue_script('wqg-admin', plugins_url('/js/wqg-admin.js', __FILE__), [], '3.0', true);
    }
}
add_action('wp_enqueue_scripts', 'wqg_enqueue_scripts');

// ============================================================
// BOUTON PANIER
// ============================================================
function wqg_add_quote_button()
{
    $quote_page_url = get_option('wqg_quote_page_url', home_url('/generer-un-devis'));
    echo '<a href="' . esc_url($quote_page_url) . '" id="generate-quote" class="button">Générer un devis</a>';
}
add_action('woocommerce_cart_actions', 'wqg_add_quote_button');

// ============================================================
// SHORTCODE FORMULAIRE
// ============================================================
function wqg_quote_form_shortcode()
{
    $is_admin = is_user_logged_in() &&
                (current_user_can('administrator') || current_user_can('manage_woocommerce'));
    ob_start(); ?>
    <div id="quote-form-wrapper">
        <h2>Générer un devis</h2>
        <form id="quote-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('wqg_quote_form_nonce_action', 'wqg_quote_form_nonce'); ?>

            <label for="quote-name">Nom&nbsp;:</label>
            <input type="text" id="quote-name" name="quote-name" required>

            <label for="quote-surname">Prénom&nbsp;:</label>
            <input type="text" id="quote-surname" name="quote-surname" required>

            <label for="quote-email">Email&nbsp;:</label>
            <input type="email" id="quote-email" name="quote-email" required>

            <label for="quote-phone">Téléphone&nbsp;:</label>
            <input type="tel" id="quote-phone" name="quote-phone" required>

            <label for="quote-address">Adresse&nbsp;:</label>
            <textarea id="quote-address" name="quote-address" required></textarea>

            <?php if ($is_admin) : ?>
            <!-- ===== Section admin ===== -->
            <div id="wqg-admin-section">

                <?php
                // --- Sélecteur de commercial (si activé dans les réglages) ---
                $reps_on  = get_option('wqg_enable_sales_reps', '0') === '1';
                $reps_cfg = $reps_on ? get_option('wqg_sales_reps', []) : [];
                if (!is_array($reps_cfg)) $reps_cfg = [];
                $reps_cfg = array_values(array_filter($reps_cfg, function ($r) {
                    return !empty($r['name']);
                }));
                if ($reps_on && !empty($reps_cfg)) : ?>
                <div class="wqg-admin-header" style="margin-bottom:10px;">
                    <span class="wqg-admin-badge">ADMIN</span>
                    <strong>Commercial en charge du devis</strong>
                </div>
                <select name="wqg_sales_rep_id" id="wqg-sales-rep-select" style="width:100%; margin-bottom:18px; padding:8px;">
                    <option value="0">— Aucun commercial —</option>
                    <?php foreach ($reps_cfg as $idx => $rep) : ?>
                    <option value="<?php echo $idx + 1; ?>"><?php echo esc_html($rep['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>

                <div class="wqg-admin-header">
                    <span class="wqg-admin-badge">ADMIN</span>
                    <strong>Produits ajoutés manuellement</strong>
                    <small>— visibles uniquement dans le devis généré</small>
                </div>
                <div id="wqg-manual-items">
                    <p id="wqg-no-items">
                        Aucun produit ajouté. Cliquez sur le bouton ci-dessous pour en ajouter.
                    </p>
                </div>
                <button type="button" id="wqg-add-item">&#43; Ajouter une ligne produit</button>
            </div>
            <?php endif; ?>

            <input type="hidden" name="action" value="wqg_generate_quote">
            <button type="submit" class="wqg-submit">Générer et télécharger le devis</button>
        </form>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('wqg_quote_form', 'wqg_quote_form_shortcode');

// ============================================================
// HELPER : CHEMIN LOCAL D'IMAGE POUR MPDF
// ============================================================
// mPDF ne peut souvent pas charger les images via HTTP (loopback bloqué
// sur de nombreux hébergeurs WordPress). Cette fonction convertit un
// attachment ID en chemin local sur le disque, que mPDF charge sans souci.

/**
 * Résout le chemin local (ou URL en fallback) d'un attachment WordPress
 * pour l'intégration dans le HTML destiné à mPDF.
 *
 * @param int    $attachment_id L'ID de l'attachment WordPress.
 * @param string $size          La taille souhaitée ('woocommerce_thumbnail', 'large', etc.).
 * @return string               Chemin local du fichier, ou URL en dernier recours, ou '' si introuvable.
 */
function wqg_get_image_for_mpdf($attachment_id, $size = 'woocommerce_thumbnail')
{
    if (!$attachment_id) {
        return '';
    }

    $upload_dir = wp_upload_dir();

    // 1. Taille demandée : convertir l'URL en chemin local
    $src = wp_get_attachment_image_src($attachment_id, $size);
    if ($src && !empty($src[0])) {
        $url = $src[0];
        if (strpos($url, $upload_dir['baseurl']) === 0) {
            $local = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
            if (file_exists($local)) {
                return $local;
            }
        }
    }

    // 2. Fichier original (pleine résolution)
    $original = get_attached_file($attachment_id);
    if ($original && file_exists($original)) {
        return $original;
    }

    // 3. Dernier recours : URL (fonctionnera si le serveur autorise le loopback)
    return ($src && !empty($src[0])) ? $src[0] : '';
}

/**
 * Convertit une URL d'image WordPress en chemin local sur le disque.
 * Utile pour les URLs déjà résolues (galeries, etc.).
 *
 * @param string $url L'URL de l'image.
 * @return string     Chemin local ou URL originale si la conversion échoue.
 */
function wqg_url_to_local_path($url)
{
    if (empty($url)) {
        return '';
    }
    $upload_dir = wp_upload_dir();
    if (strpos($url, $upload_dir['baseurl']) === 0) {
        $local = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $url);
        if (file_exists($local)) {
            return $local;
        }
    }
    return $url;
}

// ============================================================
// RÉSUMÉ IA VIA OPENROUTER (ou troncature simple)
// ============================================================

/**
 * Génère un résumé technique de la description produit via OpenRouter.
 *
 * @param string $text         Description brute du produit (HTML accepté).
 * @param array  $cart_options Options produit issues de wqg_get_cart_item_options()
 *                             (WAPF, WP Configurator Pro, etc.).
 * @return string Résumé textuel.
 */
function wqg_summarize_description($text, $cart_options = [], $is_configurator = false, $folder_descriptions = [])
{
    $clean = wp_strip_all_tags($text);
    // Pour un configurateur (WAPF ou WPC Pro) : continuer même sans description texte,
    // l'IA générera la fiche depuis les options seules.
    if (empty($clean) && (empty($cart_options) || !$is_configurator)) {
        return '';
    }

    $api_key   = get_option('wqg_openrouter_api_key', '');
    $use_ai    = get_option('wqg_use_ai_summary', '0') === '1';
    $max_words = max(10, (int) get_option('wqg_summary_max_words', 60));

    // ── Construire la chaîne de configuration AVANT le calcul du budget de tokens ──
    // (on a besoin du nombre d'options pour dimensionner $max_tokens correctement)
    $config_str   = '';
    $option_count = 0;
    if (!empty($cart_options) && is_array($cart_options)) {
        $lines = [];
        foreach ($cart_options as $opt) {
            if (!empty($opt['label']) && !empty($opt['value'])) {
                $lines[] = $opt['label'] . ' : ' . $opt['value'];
                $option_count++;
            }
        }
        $config_str = implode("\n", $lines);
    }

    // ── Budget de tokens dynamique ──
    // Pour un configurateur, chaque option nécessite ~30 tokens dans la réponse
    // (label + valeur + prix + mise en forme bullet). On ajoute 200 tokens pour
    // la description produit résumée. Plafond à 900 pour maîtriser les coûts.
    if ($is_configurator && $option_count > 0) {
        $config_budget = $option_count * 30 + 200;
        $base_budget   = (int) round($max_words * 1.8) + 20;
        $max_tokens    = min(max($base_budget, $config_budget), 900);
        // Aligner max_words sur le vrai budget pour que le prompt soit cohérent
        $max_words_display = (int) round($max_tokens / 1.8);
    } else {
        $max_tokens        = (int) round($max_words * 1.8) + 20;
        $max_words_display = $max_words;
    }

    if (!$use_ai || empty($api_key)) {
        // Pas d'IA : retourner la description texte tronquée, ou vide si aucune description
        return !empty($clean) ? wp_trim_words($clean, $max_words, '...') : '';
    }

    // ── Prompt système ──
    $raw_prompt = get_option('wqg_ai_system_prompt', WQG_DEFAULT_AI_PROMPT);
    $system_msg = str_replace('{max_mots}', (string) $max_words_display, $raw_prompt);

    // Pour les configurateurs : règles renforcées sur l'exhaustivité et les prix
    if ($is_configurator && !empty($config_str)) {
        $system_msg .= "\n\nIMPORTANT : Ce produit est un configurateur. "
                     . "Tu DOIS intégrer la configuration complète dans la fiche technique.\n\n"
                     . "RÈGLES ABSOLUES :\n"
                     . "• TOUTES les {$option_count} options de la configuration doivent figurer dans la fiche — "
                     . "ne supprime AUCUNE option, même si cela dépasse la limite de mots indicative.\n"
                     . "• Les prix entre parenthèses (ex : +150,00 €) doivent être CONSERVÉS TELS QUELS "
                     . "après chaque option — ne les omets pas, ne les arrondis pas.\n"
                     . "• La limite de {$max_words_display} mots est INDICATIVE : si la configuration "
                     . "est longue, dépasse-la pour tout inclure sans rien omettre.\n\n"
                     . "CONSIGNES DE MISE EN FORME :\n"
                     . "• Si des DESCRIPTIONS DE CATÉGORIES sont fournies ci-dessous, utilise-les "
                     . "pour comprendre le vocabulaire métier et regrouper les options logiquement.\n"
                     . "• Regroupe les options par catégorie/emplacement (pas dans l'ordre brut).\n"
                     . "• Si une dimension contient un « + » (ex: 4,2+4,2 m), affiche UNIQUEMENT "
                     . "la dimension totale (ex: 8,4 m) — ne montre jamais les valeurs séparées.\n"
                     . "• Utilise le vocabulaire professionnel issu des descriptions de catégories.\n"
                     . "• Organise en sections logiques (dimensions, structure, options, accessoires…).";
    }

    // ── Message utilisateur ──
    // Si le produit n'a pas de description texte (typique des produits WAPF configurés
    // sans fiche produit rédigée), on le signale à l'IA pour qu'elle génère depuis les options.
    $user_msg = !empty($clean)
        ? "Description produit :\n" . $clean
        : "Ce produit ne possède pas de description textuelle. "
        . "Génère la fiche technique uniquement depuis les options de configuration ci-dessous.";
    if (!empty($config_str)) {
        $user_msg .= "\n\nConfiguration sélectionnée — LES {$option_count} OPTIONS SUIVANTES "
                   . "DOIVENT TOUTES APPARAÎTRE dans la fiche, AVEC LEURS PRIX :\n"
                   . $config_str;
    }
    // Descriptions des dossiers du configurateur (contexte métier dynamique)
    if (!empty($folder_descriptions)) {
        $user_msg .= "\n\nDescriptions des catégories de configuration (contexte métier) :";
        foreach ($folder_descriptions as $folder_name => $folder_desc) {
            $user_msg .= "\n— " . $folder_name . " : " . wp_strip_all_tags($folder_desc);
        }
    }
    if ($is_configurator && !empty($config_str)) {
        $user_msg .= "\n\nGénère la fiche technique complète "
                   . "(les {$option_count} options listées ci-dessus, avec leurs prix) :";
    } else {
        $user_msg .= "\n\nRésumé en " . $max_words_display . " mots maximum :";
    }

    $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
            'HTTP-Referer'  => get_site_url(),
        ],
        'body'    => wp_json_encode([
            'model'    => 'openai/gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system_msg],
                ['role' => 'user',   'content' => $user_msg],
            ],
            'max_tokens' => $max_tokens,
        ]),
        'timeout' => 25,
    ]);

    if (is_wp_error($response)) {
        return wp_trim_words($clean, $max_words, '...');
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (is_array($body) && !empty($body['choices'][0]['message']['content'])) {
        return trim($body['choices'][0]['message']['content']);
    }

    return wp_trim_words($clean, $max_words, '...');
}

// ============================================================
// EXTRACTION DES OPTIONS PRODUIT (multi-plugins)
// ============================================================
function wqg_get_cart_item_options($cart_item)
{
    $options = [];
    $seen    = []; // pour éviter les doublons

    // --- 1. WAPF – Advanced Product Fields (standard + Extended) ---
    if (!empty($cart_item['wapf']) && is_array($cart_item['wapf'])) {
        foreach ($cart_item['wapf'] as $field) {
            // Respecter le flag "masquer dans le panier"
            if (!empty($field['hide_cart'])) {
                continue;
            }
            $label = isset($field['label']) ? wp_strip_all_tags($field['label']) : '';
            if (empty($label)) {
                continue;
            }
            $values = [];
            if (!empty($field['values']) && is_array($field['values'])) {
                foreach ($field['values'] as $val) {
                    // Libellé : 'label' en priorité, 'value' en fallback (certaines variantes WAPF Extended
                    // (champs texte, nombre) stockent la saisie uniquement dans 'value')
                    $v = !empty($val['label'])
                        ? wp_strip_all_tags($val['label'])
                        : (isset($val['value']) ? wp_strip_all_tags((string) $val['value']) : '');
                    if (empty($v) || strtolower($v) === 'n/a') {
                        continue;
                    }
                    // Prix de l'option :
                    // - WAPF standard  → 'pricing_hint' (ex: "(+99,00 €)")
                    // - WAPF Extended  → parfois 'price' (valeur float brute)
                    $hint = !empty($val['pricing_hint']) ? wp_strip_all_tags($val['pricing_hint']) : '';
                    if (empty($hint) && isset($val['price']) && (float) $val['price'] > 0) {
                        $hint = '(+' . wp_strip_all_tags(wc_price((float) $val['price'])) . ')';
                    }
                    if (!empty($hint)) {
                        $v .= ' ' . $hint;
                    }
                    $values[] = $v;
                }
            }
            if (!empty($values)) {
                $key = strtolower($label);
                if (!in_array($key, $seen, true)) {
                    $options[] = ['label' => $label, 'value' => implode(', ', $values)];
                    $seen[]    = $key;
                }
            }
        }
    }

    // --- 2. WP Configurator Pro – tree_set ---
    if (!empty($cart_item['tree_set']) && is_array($cart_item['tree_set'])) {
        foreach ($cart_item['tree_set'] as $group) {
            $label = isset($group['title']) ? wp_strip_all_tags($group['title']) : '';
            if (empty($label)) {
                continue;
            }
            $selected = [];
            if (!empty($group['active']) && is_array($group['active'])) {
                foreach ($group['active'] as $path) {
                    if (!is_array($path)) {
                        continue;
                    }
                    // Construire le chemin complet : Parent › Enfant › Feuille
                    $path_titles = [];
                    foreach ($path as $node) {
                        if (is_array($node) && !empty($node['title'])) {
                            $path_titles[] = wp_strip_all_tags($node['title']);
                        }
                    }
                    if (empty($path_titles)) {
                        continue;
                    }
                    $item_label = implode(' › ', $path_titles);
                    // Afficher le prix de l'option (sur la feuille = dernier nœud)
                    $leaf  = !empty($path) ? end($path) : false;
                    $price = ($leaf !== false && isset($leaf['sale_price']) && $leaf['sale_price'] !== '')
                        ? floatval($leaf['sale_price'])
                        : (($leaf !== false && isset($leaf['price'])) ? floatval($leaf['price']) : 0);
                    if ($price > 0) {
                        $item_label .= ' (+' . wp_strip_all_tags(wc_price($price)) . ')';
                    }
                    $selected[] = $item_label;
                }
            }
            if (!empty($selected)) {
                $key = strtolower($label);
                if (!in_array($key, $seen, true)) {
                    $options[] = ['label' => $label, 'value' => implode(', ', $selected)];
                    $seen[]    = $key;
                }
            }
        }
    }

    // --- 3. Attributs WooCommerce standards et autres plugins ---
    // Le filtre woocommerce_get_item_data collecte toutes les données des plugins enregistrés
    $item_data = apply_filters('woocommerce_get_item_data', [], $cart_item);
    if (!empty($item_data) && is_array($item_data)) {
        foreach ($item_data as $data) {
            $k = isset($data['key']) ? wp_strip_all_tags($data['key']) : '';
            $v = isset($data['display'])
                ? wp_strip_all_tags($data['display'])
                : (isset($data['value']) ? wp_strip_all_tags($data['value']) : '');
            if (empty($k) || empty($v) || strtolower($v) === 'n/a') {
                continue;
            }
            $key = strtolower($k);
            if (!in_array($key, $seen, true)) {
                $options[] = ['label' => $k, 'value' => $v];
                $seen[]    = $key;
            }
        }
    }

    // --- 4. Dimensions de la variation WooCommerce (WAPF [var_*]) ---
    // Certains configurateurs (ex. WAPF avec formules calc) stockent les dimensions
    // dans les attributs ou les métas de la variation WooCommerce. On les récupère
    // pour enrichir la fiche technique du devis.
    $variation_id = (int) ($cart_item['variation_id'] ?? 0);
    if ($variation_id > 0) {
        $var_product = isset($cart_item['data']) && $cart_item['data'] instanceof WC_Product_Variation
            ? $cart_item['data']
            : wc_get_product($variation_id);

        if ($var_product instanceof WC_Product_Variation) {
            // Clés de dimension connues (issues des formules WAPF [var_*])
            $dimension_keys = [
                'largeur_interieur'          => 'Largeur intérieure',
                'largeur_exterieur_des_murs' => 'Largeur extérieure des murs',
                'largeur_dalle'              => 'Largeur de la dalle',
                'largeur_hors_tout'          => 'Largeur hors tout',
                'profondeur_interieur'       => 'Profondeur intérieure',
                'profondeur_exterieur_des_murs' => 'Profondeur extérieure des murs',
                'profondeur_dalle'           => 'Profondeur de la dalle',
                'profondeur_hors_tout'       => 'Profondeur hors tout',
                'hauteur_interieur'          => 'Hauteur intérieure',
                'surface_de_mur'             => 'Surface de mur',
            ];

            foreach ($dimension_keys as $meta_key => $label) {
                $key_lower = strtolower($label);
                if (in_array($key_lower, $seen, true)) {
                    continue; // déjà capturé par WAPF ou autre
                }

                // Tenter plusieurs formats de stockage (attribut WC, post_meta, _préfixé)
                $value = '';
                $attr_val = $var_product->get_attribute('pa_' . $meta_key);
                if (empty($attr_val)) {
                    $attr_val = $var_product->get_attribute($meta_key);
                }
                if (!empty($attr_val)) {
                    $value = $attr_val;
                } else {
                    // Fallback : post_meta directement sur la variation
                    $meta_val = get_post_meta($variation_id, $meta_key, true);
                    if (empty($meta_val)) {
                        $meta_val = get_post_meta($variation_id, '_' . $meta_key, true);
                    }
                    if (!empty($meta_val)) {
                        $value = $meta_val;
                    }
                }

                if (!empty($value)) {
                    // Ajouter l'unité si absente
                    $v = wp_strip_all_tags((string) $value);
                    if (is_numeric($v) || preg_match('/^\d+([.,]\d+)?$/', $v)) {
                        $v .= ' m';
                    }
                    $options[] = ['label' => $label, 'value' => $v];
                    $seen[]    = $key_lower;
                }
            }
        }
    }

    return $options;
}

// ============================================================
// DESCRIPTIONS DES DOSSIERS WPC PRO (contexte métier pour l'IA)
// ============================================================

/**
 * Récupère les descriptions des dossiers/groupes du configurateur WPC Pro
 * dont au moins un layer enfant est sélectionné.
 *
 * @param array $cart_item Élément du panier WooCommerce.
 * @return array ['Nom du dossier' => 'Description du dossier', ...]
 */
function wqg_get_wpc_folder_descriptions($cart_item)
{
    // tree_set est présent pour tous les produits WPC Pro
    $tree_set = $cart_item['tree_set'] ?? null;
    if (empty($tree_set) || !is_array($tree_set)) {
        return [];
    }

    // ID du configurateur (config_id ou product_id)
    $config_id = !empty($cart_item['config_id'])
        ? (int) $cart_item['config_id']
        : (int) ($cart_item['product_id'] ?? 0);
    if ($config_id <= 0) {
        return [];
    }

    // Charger les composants du configurateur depuis le post meta
    $components = get_post_meta($config_id, '_wpc_components', true);
    // Support JSON (certaines versions de WPC Pro sérialisent en JSON)
    if (!empty($components) && is_string($components)) {
        $components = json_decode($components, true);
    }
    if (empty($components) || !is_array($components)) {
        return [];
    }

    // Construire l'index plat uid → {name, type, description, parent_uid}
    // et une table de correspondance nom → description pour la recherche par titre
    $index    = [];
    $name_map = []; // nom de nœud → description (pour la recherche dans les chemins actifs)
    wqg_flatten_wpc_components($components, $index, '');
    foreach ($index as $node) {
        if (!empty($node['description']) && !empty($node['name'])) {
            $name_map[$node['name']] = $node['description'];
        }
    }

    if (empty($index)) {
        return [];
    }

    $folder_descs = [];

    foreach ($tree_set as $group_key => $group_entry) {
        // ---- Stratégie 1 : la clé du tree_set est un UID (cas WPC Pro standard) ----
        // WPC Pro stocke active_tree_sets avec l'UID du sous-groupe/groupe comme clé.
        if (is_string($group_key) && isset($index[$group_key])) {
            // Collecter la description du nœud lui-même + remonter la hiérarchie
            wqg_collect_desc_upward($index, $group_key, $folder_descs);
        }

        // ---- Stratégie 2 : chercher les descriptions dans les chemins actifs ----
        // Les chemins contiennent les titres des nœuds intermédiaires (sous-groupes).
        // Si ces titres correspondent à des nœuds avec description dans _wpc_components,
        // on récupère ces descriptions (ex: "Porte double Vitrée", "Predecoupe", etc.)
        if (!empty($group_entry['active']) && is_array($group_entry['active'])) {
            foreach ($group_entry['active'] as $path) {
                if (!is_array($path)) {
                    continue;
                }
                foreach ($path as $path_node) {
                    if (!is_array($path_node) || empty($path_node['title'])) {
                        continue;
                    }
                    $node_title = wp_strip_all_tags($path_node['title']);
                    if (!empty($name_map[$node_title]) && !isset($folder_descs[$node_title])) {
                        $folder_descs[$node_title] = $name_map[$node_title];
                    }
                }
            }
        }
    }

    // ---- Stratégie 3 (fallback) : décoder encode_active_key si rien trouvé ----
    if (empty($folder_descs) && !empty($cart_item['encode_active_key'])) {
        $decoded = base64_decode($cart_item['encode_active_key']);
        if (!empty($decoded)) {
            foreach (array_unique(array_filter(explode(',', $decoded))) as $uid) {
                if (isset($index[$uid])) {
                    wqg_collect_desc_upward($index, $uid, $folder_descs);
                }
            }
        }
    }

    return $folder_descs;
}

/**
 * Collecte la description du nœud de départ (si elle existe) puis remonte
 * la hiérarchie pour collecter également les descriptions des ancêtres.
 *
 * @param array  $index       Index plat uid → données.
 * @param string $start_uid   UID de départ.
 * @param array  &$descs      Tableau de descriptions à compléter (par référence).
 */
function wqg_collect_desc_upward($index, $start_uid, &$descs)
{
    $current = $start_uid;
    $visited = [];

    // Description du nœud lui-même
    if (!empty($index[$current]['description']) && !isset($descs[$index[$current]['name']])) {
        $descs[$index[$current]['name']] = $index[$current]['description'];
    }

    // Remonter jusqu'à la racine
    while (isset($index[$current]) && !empty($index[$current]['parent_uid']) && !isset($visited[$current])) {
        $visited[$current] = true;
        $parent_uid = $index[$current]['parent_uid'];
        if (!isset($index[$parent_uid])) {
            break;
        }
        $parent = $index[$parent_uid];
        if (!empty($parent['description']) && !isset($descs[$parent['name']])) {
            $descs[$parent['name']] = $parent['description'];
        }
        $current = $parent_uid;
    }
}

/**
 * Aplatit récursivement la structure _wpc_components en un index uid → données.
 *
 * @param array  $nodes     Nœuds de l'arbre (tableau de composants).
 * @param array  &$index    Index plat à remplir (par référence).
 * @param string $parent_uid UID du parent (vide pour la racine).
 */
function wqg_flatten_wpc_components($nodes, &$index, $parent_uid)
{
    if (!is_array($nodes)) {
        return;
    }

    foreach ($nodes as $node) {
        if (!is_array($node)) {
            continue;
        }

        $uid = $node['uid'] ?? '';
        if (empty($uid)) {
            continue;
        }

        $type = $node['type'] ?? 'item';
        $name = $node['name'] ?? '';
        $description = '';

        // La description peut être dans settings.description ou directement dans description
        if (!empty($node['settings']['description'])) {
            $description = $node['settings']['description'];
        } elseif (!empty($node['description'])) {
            $description = $node['description'];
        }

        $index[$uid] = [
            'name'        => $name,
            'type'        => $type,
            'description' => $description,
            'parent_uid'  => $parent_uid,
        ];

        // Récurser dans les enfants
        if (!empty($node['children']) && is_array($node['children'])) {
            wqg_flatten_wpc_components($node['children'], $index, $uid);
        }
        // Certaines structures utilisent 'layers' ou 'items' au lieu de 'children'
        if (!empty($node['layers']) && is_array($node['layers'])) {
            wqg_flatten_wpc_components($node['layers'], $index, $uid);
        }
        if (!empty($node['items']) && is_array($node['items'])) {
            wqg_flatten_wpc_components($node['items'], $index, $uid);
        }
    }
}

// ============================================================
// CONSTRUCTION DU HTML UNIQUE (PDF et email utilisent la même source)
// ============================================================
function wqg_build_quote_html($client_data)
{
    // --- Variables client ---
    $name         = $client_data['name'];
    $surname      = $client_data['surname'];
    $email        = $client_data['email'];
    $phone        = $client_data['phone'];
    $address      = $client_data['address'];
    $quote_number = $client_data['quote_number'];
    $quote_date   = $client_data['quote_date'];
    $manual_items = $client_data['manual_items'] ?? [];
    $validity     = date('d/m/Y', strtotime('+1 week'));

    // --- Options du plugin ---
    $company_name    = get_option('wqg_company_name', 'SAS Abri Francais');
    $company_address = get_option('wqg_company_address', 'Hameau des Auvillers, 59480 Illies');
    $company_logo    = get_option('wqg_company_logo', '');
    $sales_rep       = $client_data['sales_rep'] ?? [];
    $terms_url       = get_option('wqg_terms_conditions_url', '');
    $custom_footer   = get_option('wqg_custom_footer', '');
    $show_images     = get_option('wqg_show_product_images', '1') === '1';
    $color_primary   = get_option('wqg_color_primary', '#67694E');
    $color_accent    = get_option('wqg_color_accent',  '#4E5038');
    $hide_coupons    = get_option('wqg_hide_coupon_codes', '0') === '1';

    $cart = WC()->cart ? WC()->cart->get_cart() : [];

    // =============================================
    // CSS
    // =============================================
    $cp = $color_primary; // couleur principale
    $ca = $color_accent;  // couleur accentuation

    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html .= '<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        font-size: 12px;
        color: #2d2d2d;
        line-height: 1.5;
        background: #ffffff;
    }
    a { color: ' . $ca . '; text-decoration: none; }
    table { border-collapse: collapse; }

    /* Header */
    .header-table { background-color: ' . $cp . '; width: 100%; }
    .header-logo-cell { padding: 22px 25px; vertical-align: middle; width: 45%; }
    .header-info-cell { padding: 22px 25px; text-align: right; vertical-align: middle; }
    .header-title {
        font-size: 32px;
        font-weight: bold;
        color: #ffffff;
        letter-spacing: 6px;
        line-height: 1;
    }
    .header-sub { font-size: 10px; color: rgba(255,255,255,0.65); margin-top: 8px; line-height: 2; }

    /* Bloc infos société / client */
    .info-table { width: 100%; margin: 18px 0; border: 1px solid #DDE4F0; border-radius: 6px; }
    .info-cell { padding: 16px 20px; vertical-align: top; width: 50%; }
    .info-divider { border-right: 1px solid #DDE4F0; }
    .info-label {
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 1.5px;
        color: ' . $ca . ';
        font-weight: bold;
        margin-bottom: 8px;
    }
    .info-name { font-size: 13px; font-weight: bold; color: ' . $cp . '; margin-bottom: 5px; }
    .info-detail { font-size: 11px; color: #666666; line-height: 1.7; }

    /* Titre de section */
    .section-title {
        font-size: 11px;
        font-weight: bold;
        color: ' . $cp . ';
        border-left: 4px solid ' . $ca . ';
        padding: 5px 0 5px 11px;
        margin: 20px 0 10px;
        text-transform: uppercase;
        letter-spacing: 0.8px;
    }

    /* Tableau produits */
    .products-table { width: 100%; border: 1px solid #DDE4F0; border-radius: 6px; }
    .products-table th {
        background-color: ' . $cp . ';
        color: #ffffff;
        padding: 9px 8px;
        font-size: 10px;
        font-weight: bold;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        border: none;
    }
    .products-table td {
        padding: 10px 8px;
        border: none;
        border-bottom: 1px solid #EEF3FB;
        vertical-align: top;
    }
    .row-odd  td { background-color: #ffffff; }
    .row-even td { background-color: #F4F7FD; }
    .product-name { font-weight: bold; font-size: 12px; color: ' . $cp . '; margin-bottom: 3px; }
    .product-option { font-size: 10px; color: #555555; line-height: 1.7; }
    .product-option strong { color: #2d2d2d; }
    .product-desc {
        font-size: 10px;
        color: #999999;
        font-style: italic;
        margin-top: 6px;
        padding-top: 5px;
        border-top: 1px dashed #DDE4F0;
        line-height: 1.5;
    }
    .product-desc-list {
        font-size: 10px;
        color: #555555;
        margin: 6px 0 0 14px;
        padding: 0;
        border-top: 1px dashed #DDE4F0;
        padding-top: 5px;
        line-height: 1.7;
    }
    .product-desc-list li {
        margin-bottom: 1px;
    }
    .product-desc-list li strong {
        color: #333333;
    }

    /* Totaux */
    .totals-outer { width: 310px; margin-left: auto; margin-top: 22px; }
    .totals-table { width: 100%; border: 1px solid #DDE4F0; border-radius: 6px; }
    .totals-table td { padding: 7px 14px; border: none; border-bottom: 1px solid #EEF3FB; font-size: 12px; }
    .totals-muted { color: #666666; }
    .totals-discount { color: #27AE60; font-weight: bold; }
    .totals-final td {
        background-color: ' . $cp . ';
        color: #ffffff;
        padding: 13px 14px;
        font-size: 14px;
        font-weight: bold;
        border: none;
    }

    /* Coupon */
    .coupon-badge {
        display: inline-block;
        background-color: #FFF8E7;
        border: 1px solid #FFB800;
        color: #7A5200;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 10px;
        font-weight: bold;
    }

    /* Footer document */
    .doc-footer {
        margin-top: 28px;
        padding-top: 12px;
        border-top: 1px solid #DDE4F0;
        font-size: 10px;
        color: #999999;
        width: 100%;
    }
    .doc-footer td { border: none; padding: 2px 0; vertical-align: top; }
    </style>';
    $html .= '</head><body>';

    // =============================================
    // HEADER — bande marine avec logo + "DEVIS"
    // =============================================
    $html .= '<table class="header-table" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td class="header-logo-cell">';

    if (!empty($company_logo)) {
        $logo_src = wqg_url_to_local_path($company_logo);
        $html .= '<img src="' . $logo_src . '" alt="' . esc_attr($company_name) . '" style="max-height:58px; display:block;" />';
    } else {
        $html .= '<span style="font-size:17px; font-weight:bold; color:#ffffff;">' . esc_html($company_name) . '</span>';
    }

    $html .= '            </td>
            <td class="header-info-cell">
                <div class="header-title">DEVIS</div>
                <div class="header-sub">
                    N&#176;&nbsp;' . esc_html($quote_number) . '<br>
                    &#201;mis le ' . esc_html($quote_date) . '<br>
                    Valable jusqu&#8217;au ' . esc_html($validity) . '
                </div>
            </td>
        </tr>
    </table>';

    // =============================================
    // BLOC SOCIÉTÉ / CLIENT
    // =============================================
    $html .= '<table class="info-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="info-cell info-divider">
                <div class="info-label">&#201;metteur</div>
                <div class="info-name">' . esc_html($company_name) . '</div>
                <div class="info-detail">' . nl2br(esc_html($company_address)) . '</div>'
                . (!empty($sales_rep['name'])
                    ? '<div style="margin-top:6px; padding-top:6px; border-top:1px solid rgba(255,255,255,0.3); font-size:10px;">'
                      . '<strong>Votre contact :</strong> ' . esc_html($sales_rep['name'])
                      . (!empty($sales_rep['phone']) ? '<br>' . esc_html($sales_rep['phone']) : '')
                      . (!empty($sales_rep['email']) ? '<br>' . esc_html($sales_rep['email']) : '')
                      . '</div>'
                    : '') . '
            </td>
            <td class="info-cell">
                <div class="info-label">Destinataire</div>
                <div class="info-name">' . esc_html($name) . ' ' . esc_html($surname) . '</div>
                <div class="info-detail">
                    ' . nl2br(esc_html($address)) . '<br>
                    ' . esc_html($email) . '<br>
                    ' . esc_html($phone) . '
                </div>
            </td>
        </tr>
    </table>';

    // =============================================
    // TABLEAU PRODUITS
    // =============================================
    $html .= '<div class="section-title">D&#233;tail des produits</div>';

    $img_th = $show_images
        ? '<th style="width:165px; text-align:center;">Image</th>'
        : '';

    $html .= '<table class="products-table" cellpadding="0" cellspacing="0">
        <thead>
            <tr>
                ' . $img_th . '
                <th style="text-align:left;">Produit</th>
                <th style="width:40px; text-align:center;">Qt&#233;</th>
                <th style="width:88px; text-align:right;">P.U. HT</th>
                <th style="width:78px; text-align:right;">TVA/u</th>
                <th style="width:88px; text-align:right;">P.U. TTC</th>
                <th style="width:92px; text-align:right;">Total TTC</th>
            </tr>
        </thead>
        <tbody>';

    $subtotal_ht  = 0.0;
    $subtotal_tva = 0.0;
    $subtotal_ttc = 0.0;
    $row_idx      = 0;
    $detail_items = []; // Images pour la page détail (WPC Pro vues + galerie variation)

    foreach ($cart as $cart_item_key => $cart_item) {
        $product  = $cart_item['data'];
        $quantity = (int) $cart_item['quantity'];
        $line_ht  = (float) $cart_item['line_subtotal'];      // prix avant remise
        $line_tax = (float) $cart_item['line_subtotal_tax'];  // TVA avant remise
        $line_ttc = $line_ht + $line_tax;
        $unit_ht  = $quantity > 0 ? $line_ht  / $quantity : 0;
        $unit_tva = $quantity > 0 ? $line_tax / $quantity : 0;
        $unit_ttc = $unit_ht + $unit_tva;

        $subtotal_ht  += $line_ht;
        $subtotal_tva += $line_tax;
        $subtotal_ttc += $line_ttc;

        $row_class = ($row_idx % 2 === 0) ? 'row-odd' : 'row-even';
        $row_idx++;

        // Image
        $img_cell = '';
        if ($show_images) {
            $img_tag = '&nbsp;';

            // 1. Image de la configuration WP Configurator Pro
            // base64_cart_image_data est un tableau issu de explode(',', data_uri).
            // La data URI "data:image/png;base64,<data>" devient 2 éléments quand splittée sur ','.
            // On reconstitue exactement comme WPC_Utils::get_encoded_image_src() :
            $wpc_imgs_raw = $cart_item['base64_cart_image_data'] ?? null;
            if (!empty($wpc_imgs_raw)) {
                if (is_array($wpc_imgs_raw)) {
                    $wpc_src = count($wpc_imgs_raw) > 1
                        ? implode(', ', $wpc_imgs_raw)             // ['data:image/png;base64','<data>'] → URI valide
                        : 'data:image/png;base64, ' . reset($wpc_imgs_raw); // 1 élément sans header
                } else {
                    $wpc_src = (strpos($wpc_imgs_raw, 'data:image') !== false)
                        ? $wpc_imgs_raw
                        : 'data:image/png;base64, ' . $wpc_imgs_raw;
                }
                // Valider : la source doit commencer par data:image/ (protection injection HTML)
                if (strpos($wpc_src, 'data:image/') !== 0) {
                    $wpc_src = '';
                }
                $img_tag = !empty($wpc_src)
                    ? '<img src="' . $wpc_src . '" width="150" style="display:block; margin:auto; object-fit:contain;" />'
                    : '&nbsp;';

                // Page détail : priorité aux vues capturées par WPC Performance Booster,
                // sinon fallback sur la galerie WooCommerce du produit configurateur.
                $wpc_detail_imgs = [];

                // 1. Vues de configuration capturées par le plugin WPC Performance Booster
                if (!empty($cart_item['wpc_all_views_images']) && is_array($cart_item['wpc_all_views_images'])) {
                    foreach ($cart_item['wpc_all_views_images'] as $view_url) {
                        if (!empty($view_url)) {
                            $wpc_detail_imgs[] = $view_url; // data-URI JPEG
                        }
                    }
                }

                // 2. Fallback : galerie WooCommerce du produit configurateur
                if (empty($wpc_detail_imgs)) {
                    $wpc_gallery_ids = ($product instanceof WC_Product) ? $product->get_gallery_image_ids() : [];
                    if (empty($wpc_gallery_ids) && !empty($cart_item['product_id'])) {
                        $parent_wpc = wc_get_product((int) $cart_item['product_id']);
                        if ($parent_wpc) {
                            $wpc_gallery_ids = $parent_wpc->get_gallery_image_ids();
                        }
                    }
                    foreach ((array) $wpc_gallery_ids as $gid) {
                        $gpath = wqg_get_image_for_mpdf((int) $gid, 'large');
                        if ($gpath) {
                            $wpc_detail_imgs[] = $gpath;
                        }
                    }
                }

                if (!empty($wpc_detail_imgs)) {
                    $detail_items[] = [
                        'name'   => ($product instanceof WC_Product) ? $product->get_name() : '',
                        'images' => $wpc_detail_imgs,
                        'type'   => 'wpc',
                    ];
                }
            }

            // 2. WAPF Layers — image composite générée par le plugin "Advanced Product Fields: layered images"
            // Le plugin stocke le hash du fichier dans $cart_item['generated_image'].
            // Les fichiers sont sauvegardés dans uploads/wapf-layers/{hash}.png (pleine résolution)
            // et {hash}-thumb.png (vignette WooCommerce). On priorise la pleine résolution pour le PDF.
            if ($img_tag === '&nbsp;' && !empty($cart_item['generated_image'])) {
                $wapf_hash   = sanitize_file_name($cart_item['generated_image']);
                $wapf_upload = wp_upload_dir();
                $wapf_dir    = $wapf_upload['basedir'] . '/wapf-layers/';

                // Priorité à la pleine résolution, fallback sur le thumbnail
                // Utilise le chemin local (pas l'URL) pour que mPDF charge directement le fichier.
                if (file_exists($wapf_dir . $wapf_hash . '.png')) {
                    $wapf_img_path = $wapf_dir . $wapf_hash . '.png';
                } elseif (file_exists($wapf_dir . $wapf_hash . '-thumb.png')) {
                    $wapf_img_path = $wapf_dir . $wapf_hash . '-thumb.png';
                } else {
                    $wapf_img_path = '';
                }

                if (!empty($wapf_img_path)) {
                    $img_tag = '<img src="' . $wapf_img_path . '" width="150"'
                             . ' style="display:block; margin:auto; object-fit:contain;" />';
                }
            }

            // 3. Fallback : image du produit WooCommerce (variation → parent)
            // Certains plugins configurateurs (ex. Wombat) ne génèrent pas d'image
            // pour les variations. On essaie d'abord l'image de la variation, puis
            // celle du produit parent classique WooCommerce.
            if ($img_tag === '&nbsp;') {
                $img_id = $product->get_image_id();

                // Si la variation n'a pas d'image propre, remonter au produit parent
                if (!$img_id && $product instanceof WC_Product_Variation) {
                    $parent_id = $product->get_parent_id();
                    if ($parent_id) {
                        $parent_product = wc_get_product($parent_id);
                        if ($parent_product) {
                            $img_id = $parent_product->get_image_id();
                        }
                    }
                }

                // Dernier recours : product_id du cart item (couvre les cas où $product
                // n'est pas une instance standard de WC_Product_Variation)
                if (!$img_id && !empty($cart_item['product_id'])) {
                    $fallback_product = wc_get_product((int) $cart_item['product_id']);
                    if ($fallback_product) {
                        $img_id = $fallback_product->get_image_id();
                    }
                }

                if ($img_id) {
                    $img_path = wqg_get_image_for_mpdf($img_id, 'woocommerce_thumbnail');
                    if ($img_path) {
                        $img_tag = '<img src="' . $img_path . '" width="150"'
                                 . ' style="display:block; margin:auto; object-fit:contain;" />';
                    }
                }
            }

            $img_cell = '<td style="text-align:center; width:165px;">' . $img_tag . '</td>';
        }

        // Options plugins (WAPF, WP Configurator, autres) — calculées en premier
        // pour être transmises aussi à la fonction de résumé IA
        $cart_opts       = wqg_get_cart_item_options($cart_item);
        $is_wpc          = !empty($cart_item['base64_cart_image_data']); // produit WPC Pro
        // Un produit WAPF avec des options est également traité comme un configurateur :
        // l'IA reformate tout en fiche technique au lieu d'afficher la liste brute.
        $is_configurator = $is_wpc || (!empty($cart_item['wapf']) && !empty($cart_opts));
        $use_ai          = get_option('wqg_use_ai_summary', '0') === '1';

        // Pour les configurateurs + IA : les options seront intégrées dans le résumé IA
        // → ne pas les afficher séparément pour éviter les doublons
        $options_html = '';
        if (!($is_configurator && $use_ai)) {
            foreach ($cart_opts as $opt) {
                $options_html .= '<div class="product-option">'
                               . '<strong>' . esc_html($opt['label']) . '&nbsp;:</strong> '
                               . esc_html($opt['value'])
                               . '</div>';
            }
        }

        // Description résumée — pour les produits variables : description parent + variation
        $desc_html = '';
        $raw_desc  = '';

        if (!empty($cart_item['variation_id'])) {
            // Produit variable : description principale du parent
            $parent_product = wc_get_product((int) $cart_item['product_id']);
            if ($parent_product) {
                $parent_desc = $parent_product->get_short_description();
                if (empty($parent_desc)) {
                    $parent_desc = $parent_product->get_description();
                }
                $raw_desc = wp_strip_all_tags($parent_desc);
            }
            // Description spécifique à la variation (ex. dimensions ou longueurs différentes)
            $var_desc = wp_strip_all_tags($product->get_description());
            if (!empty($var_desc) && $var_desc !== $raw_desc) {
                $raw_desc = trim($raw_desc . "\n\n" . $var_desc);
            }
        } else {
            // Produit simple ou WPC Pro : description courte, sinon description complète
            $raw_desc = $product->get_short_description();
            if (empty($raw_desc)) {
                $raw_desc = $product->get_description();
            }
        }

        // Descriptions des dossiers WPC Pro (contexte métier pour l'IA)
        $folder_descs = $is_wpc ? wqg_get_wpc_folder_descriptions($cart_item) : [];

        // Pour les configurateurs WAPF sans description texte, l'IA est quand même appelée
        // pour générer la fiche depuis les options seules (raw_desc peut être vide).
        $call_ai_config = $is_configurator && $use_ai && !empty($cart_opts);

        if (!empty($raw_desc) || $call_ai_config) {
            $summary_raw = wqg_summarize_description($raw_desc, $cart_opts, $is_configurator, $folder_descs);

            // 1. Découper sur les retours à la ligne existants
            $s_lines  = preg_split('/\r?\n/', trim($summary_raw));

            // 2. Séparer aussi les bullet points qui seraient collés sur une même ligne
            //    Ex: "• DIM : 300 cm • POIDS : 50 kg" → 2 lignes
            $expanded = [];
            foreach ($s_lines as $sl) {
                $sl = trim($sl);
                if (empty($sl)) continue;
                // Éclater sur "• " en milieu de ligne (pas au début)
                $sub = preg_split('/(?<=\.)\s*[\x{2022}\x{2023}\x{25E6}]\s+/u', $sl);
                if (count($sub) > 1) {
                    foreach ($sub as $s) {
                        $s = trim($s);
                        if (!empty($s)) $expanded[] = $s;
                    }
                } else {
                    $expanded[] = $sl;
                }
            }

            // 3. Si on a un seul bloc long (> 120 chars), découper sur les phrases
            if (count($expanded) === 1 && mb_strlen($expanded[0]) > 120) {
                $single = trim(preg_replace('/^[\x{2022}\x{2023}\x{25E6}\-\*]\s*/u', '', $expanded[0]));
                // Découper sur ". " suivi d'une majuscule (limite de phrase)
                $sentences = preg_split('/\.\s+(?=[A-ZÀÂÄÉÈÊËÎÏÔÙÛÜÇŒÆ])/u', $single);
                $expanded  = [];
                foreach ($sentences as $sent) {
                    $sent = trim($sent);
                    if (empty($sent)) continue;
                    // Remettre le point final s'il a été consommé par le split
                    if (!preg_match('/[.\!\?]$/u', $sent)) $sent .= '.';
                    $expanded[] = $sent;
                }
            }

            $li_items = [];
            foreach ($expanded as $sl) {
                $sl = trim($sl);
                if (empty($sl)) continue;
                // Retirer le caractère bullet de tête
                $sl_clean = trim(preg_replace('/^[\x{2022}\x{2023}\x{25E6}\-\*]\s*/u', '', $sl));
                if (empty($sl_clean)) continue;
                // Mettre en gras le libellé en majuscules avant les ":"
                $sl_html = preg_replace_callback(
                    '/^([A-ZÀÂÄÉÈÊËÎÏÔÙÛÜÇŒÆ][A-ZÀÂÄÉÈÊËÎÏÔÙÛÜÇŒÆ0-9\s\/\(\)]+)\s*:\s*(.*)$/u',
                    static function ($m) {
                        return '<strong>' . esc_html(trim($m[1])) . ' :</strong> ' . esc_html(trim($m[2]));
                    },
                    $sl_clean
                );
                // Si le regex n'a pas matché, escape normal
                if ($sl_html === $sl_clean) {
                    $sl_html = esc_html($sl_clean);
                }
                $li_items[] = '<li>' . $sl_html . '</li>';
            }
            if (!empty($li_items)) {
                $desc_html = '<ul class="product-desc-list">' . implode('', $li_items) . '</ul>';
            } else {
                $desc_html = '<div class="product-desc">' . nl2br(esc_html($summary_raw)) . '</div>';
            }
        }

        $html .= '<tr class="' . $row_class . '">
            ' . $img_cell . '
            <td>
                <div class="product-name">' . esc_html($product->get_name()) . '</div>
                ' . $options_html . '
                ' . $desc_html . '
            </td>
            <td style="text-align:center;">' . $quantity . '</td>
            <td style="text-align:right;">' . wc_price($unit_ht) . '</td>
            <td style="text-align:right;">' . wc_price($unit_tva) . '</td>
            <td style="text-align:right;">' . wc_price($unit_ttc) . '</td>
            <td style="text-align:right; font-weight:bold;">' . wc_price($line_ttc) . '</td>
        </tr>';

        // --- Galerie de variation (plugin Woo Variation Gallery) ---
        $variation_id_detail = (int) ($cart_item['variation_id'] ?? 0);
        if ($variation_id_detail > 0) {
            $parent_id_chk = (int) ($cart_item['product_id'] ?? 0);

            // Filtrage par catégories sélectionnées dans les réglages (vide = toutes)
            $allowed_cat_ids = array_filter(
                array_map('intval', preg_split('/[\s,]+/', get_option('wqg_gallery_categories', ''), -1, PREG_SPLIT_NO_EMPTY))
            );
            $show_var_gallery = empty($allowed_cat_ids);
            if (!$show_var_gallery && $parent_id_chk > 0) {
                $product_cat_ids  = wc_get_product_term_ids($parent_id_chk, 'product_cat');
                $show_var_gallery = !empty(array_intersect($product_cat_ids, $allowed_cat_ids));
            }

            if ($show_var_gallery) {
                $var_gal_ids = get_post_meta($variation_id_detail, 'woo_variation_gallery_images', true);
                if (!empty($var_gal_ids) && is_array($var_gal_ids)) {
                    // Indices d'images à afficher (1-indexés, ex. "1,3,5" ; vide = toutes)
                    $idx_raw      = get_option('wqg_gallery_image_indices', '');
                    $allowed_idxs = array_filter(
                        array_map('intval', preg_split('/[\s,]+/', $idx_raw, -1, PREG_SPLIT_NO_EMPTY))
                    );

                    $var_images = [];
                    foreach (array_values($var_gal_ids) as $pos => $att_id) {
                        $pos1 = $pos + 1; // passer en 1-indexé
                        if (!empty($allowed_idxs) && !in_array($pos1, $allowed_idxs, true)) {
                            continue;
                        }
                        $img_path = wqg_get_image_for_mpdf((int) $att_id, 'large');
                        if ($img_path) {
                            $var_images[] = $img_path;
                        }
                    }
                    if (!empty($var_images)) {
                        $detail_items[] = [
                            'name'   => $product->get_name(),
                            'images' => $var_images,
                            'type'   => 'variation',
                        ];
                    }
                }
            }
        }
    }

    // ---- Lignes manuelles (ajoutées par l'admin) ----
    $manual_ttc_total = 0.0;
    $manual_tva_total = 0.0;

    foreach ($manual_items as $item) {
        $qty      = (int)   $item['qty'];
        $ht       = (float) $item['price_ht'];
        $tva_rate = (float) $item['tva_rate'];
        $unit_tva = round($ht * $tva_rate / 100, 4);
        $unit_ttc = $ht + $unit_tva;
        $line_ht  = $ht       * $qty;
        $line_tva = $unit_tva * $qty;
        $line_ttc = $unit_ttc * $qty;

        $subtotal_ht  += $line_ht;
        $subtotal_tva += $line_tva;
        $subtotal_ttc += $line_ttc;

        $manual_ttc_total += $line_ttc;
        $manual_tva_total += $line_tva;

        $row_class = ($row_idx % 2 === 0) ? 'row-odd' : 'row-even';
        $row_idx++;

        $img_cell = $show_images ? '<td style="text-align:center; width:165px;">&nbsp;</td>' : '';

        $html .= '<tr class="' . $row_class . ' product-manual">
            ' . $img_cell . '
            <td>
                <div class="product-name">' . esc_html($item['description']) . '</div>
                <div class="product-option" style="color:#2E5FA3; font-style:italic;">Produit ajouté manuellement</div>
            </td>
            <td style="text-align:center;">' . $qty . '</td>
            <td style="text-align:right;">' . wc_price($ht) . '</td>
            <td style="text-align:right;">' . wc_price($unit_tva) . '
                <span style="font-size:9px; color:#999999;"> (' . $tva_rate . '%)</span>
            </td>
            <td style="text-align:right;">' . wc_price($unit_ttc) . '</td>
            <td style="text-align:right; font-weight:bold;">' . wc_price($line_ttc) . '</td>
        </tr>';
    }

    $html .= '</tbody></table>';

    // =============================================
    // CODES PROMO
    // =============================================
    $applied_coupons  = WC()->cart->get_applied_coupons();
    $coupon_discounts = !empty($applied_coupons) ? WC()->cart->get_coupon_discount_totals() : [];

    if (!$hide_coupons && !empty($applied_coupons)) {
        $html .= '<div class="section-title" style="margin-top:18px;">Codes promotionnels</div>';
        $html .= '<table style="width:100%; border:1px solid #DDE4F0; border-radius:6px;" cellpadding="0" cellspacing="0">
            <thead>
                <tr>
                    <th style="background:#1B3A6B; color:#fff; padding:8px 12px; text-align:left; font-size:10px; text-transform:uppercase; letter-spacing:0.5px; border:none;">Code</th>
                    <th style="background:#1B3A6B; color:#fff; padding:8px 12px; text-align:right; font-size:10px; text-transform:uppercase; letter-spacing:0.5px; border:none;">R&#233;duction</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($applied_coupons as $code) {
            $disc  = isset($coupon_discounts[$code]) ? (float) $coupon_discounts[$code] : 0.0;
            $html .= '<tr>
                <td style="padding:9px 12px; border-bottom:1px solid #EEF3FB; background:#fff;">
                    <span class="coupon-badge">&#127991; ' . esc_html(strtoupper($code)) . '</span>
                </td>
                <td style="padding:9px 12px; text-align:right; color:#27AE60; font-weight:bold; border-bottom:1px solid #EEF3FB; background:#fff;">
                    &minus;' . wc_price($disc) . '
                </td>
            </tr>';
        }
        $html .= '</tbody></table>';
    }

    // =============================================
    // RÉCAPITULATIF TOTAUX
    // =============================================
    $total_discount   = (float) WC()->cart->get_discount_total();
    $shipping_ht      = (float) WC()->cart->get_shipping_total();
    $shipping_tax     = (float) WC()->cart->get_shipping_tax();
    $shipping_ttc     = $shipping_ht + $shipping_tax;
    // Total WC (prix remisés + TVA réelle + livraison) + items manuels
    $wc_grand_total   = (float) WC()->cart->get_total('edit');
    $grand_total      = $wc_grand_total + $manual_ttc_total;
    // TVA totale = cumul par ligne (fiable en contexte admin-post.php, même sans calculate_totals)
    // $subtotal_tva inclut déjà la TVA des items WC + items manuels, cumulée ligne par ligne.
    $actual_tva       = $subtotal_tva + (float) WC()->cart->get_shipping_tax();

    // Libellé livraison — lire le titre depuis les options WP (fiable même en admin-post)
    $shipping_value  = $shipping_ttc > 0 ? wc_price($shipping_ttc) : 'Gratuite';
    $chosen_methods  = (array) WC()->session->get('chosen_shipping_methods', []);
    $shipping_labels = [];

    foreach ($chosen_methods as $method_rate_id) {
        if (empty($method_rate_id)) {
            continue;
        }
        // Format : "method_type:instance_id" (ex. "flat_rate:3", "free_shipping:0")
        $parts       = explode(':', $method_rate_id, 2);
        $method_type = $parts[0];
        $instance_id = isset($parts[1]) ? (int) $parts[1] : 0;

        // 1. Lire le titre de l'instance dans les options WooCommerce (toujours en base)
        $title = '';
        if ($instance_id > 0) {
            $settings = (array) get_option("woocommerce_{$method_type}_{$instance_id}_settings", []);
            $title    = $settings['title'] ?? '';
        }
        // 2. Fallback : titre générique de la classe d'expédition
        if (empty($title)) {
            $wc_methods = WC()->shipping()->get_shipping_methods();
            if (!empty($wc_methods[$method_type])) {
                $title = $wc_methods[$method_type]->get_method_title();
            }
        }
        // 3. Dernier recours : formater l'identifiant brut
        if (empty($title)) {
            $title = ucfirst(str_replace('_', ' ', $method_type));
        }

        if ($shipping_ttc > 0) {
            $shipping_labels[] = esc_html($title) . '&nbsp;: ' . wc_price($shipping_ttc);
        } else {
            $shipping_labels[] = esc_html($title);
        }
    }

    if (!empty($shipping_labels)) {
        $shipping_value = implode('<br>', $shipping_labels);
    }

    $html .= '<div class="totals-outer">
    <table class="totals-table" cellpadding="0" cellspacing="0">
        <tr>
            <td class="totals-muted">Sous-total HT</td>
            <td style="text-align:right;" class="totals-muted">' . wc_price($subtotal_ht) . '</td>
        </tr>';

    // Afficher remise/coupons dans les totaux
    if ($total_discount > 0) {
        if ($hide_coupons) {
            // Masquer les codes : afficher uniquement le montant total de remise
            $html .= '<tr>
                <td class="totals-discount">Remise</td>
                <td style="text-align:right;" class="totals-discount">&minus;' . wc_price($total_discount) . '</td>
            </tr>';
        } elseif (!empty($applied_coupons)) {
            foreach ($applied_coupons as $code) {
                $disc = isset($coupon_discounts[$code]) ? (float) $coupon_discounts[$code] : 0.0;
                if ($disc > 0) {
                    $html .= '<tr>
                        <td class="totals-discount">&#127991; ' . esc_html(strtoupper($code)) . '</td>
                        <td style="text-align:right;" class="totals-discount">&minus;' . wc_price($disc) . '</td>
                    </tr>';
                }
            }
        } else {
            $html .= '<tr>
                <td class="totals-discount">Remise</td>
                <td style="text-align:right;" class="totals-discount">&minus;' . wc_price($total_discount) . '</td>
            </tr>';
        }
    }

    $html .= '        <tr>
            <td class="totals-muted">TVA</td>
            <td style="text-align:right;" class="totals-muted">' . wc_price($actual_tva) . '</td>
        </tr>
        <tr>
            <td class="totals-muted">Livraison</td>
            <td style="text-align:right;" class="totals-muted">' . $shipping_value . '</td>
        </tr>
        <tr class="totals-final">
            <td>TOTAL TTC</td>
            <td style="text-align:right;">' . wc_price($grand_total) . '</td>
        </tr>
    </table>
    </div>';

    // =============================================
    // BLOC PARTAGE DE PANIER (QR code + lien cliquable)
    // =============================================
    // Les produits manuels du devis sont inclus dans le lien de partage
    // afin que le client puisse passer commande de l'ensemble du devis.
    $share_token = wqg_create_shared_cart($manual_items);
    if ($share_token) {
        $share_url = wqg_get_shared_cart_url($share_token);
        $qr_src    = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&format=png&data=' . urlencode($share_url);

        $html .= '<div style="margin-top:20px; padding:14px 18px; border:1px solid #ddd; border-radius:6px; background:#f9fafb;">';
        $html .= '<table cellpadding="0" cellspacing="0" style="width:100%; border:none;">';
        $html .= '<tr>';
        $html .= '<td style="width:130px; vertical-align:middle; border:none; padding-right:14px; text-align:center;">';
        $html .= '<img src="' . esc_url($qr_src) . '" width="110" height="110" style="display:block; margin:auto;" />';
        $html .= '</td>';
        $html .= '<td style="vertical-align:middle; border:none;">';
        $html .= '<div style="font-size:12px; font-weight:bold; color:' . $cp . '; margin-bottom:6px;">Commander en ligne</div>';
        $html .= '<div style="font-size:10px; color:#555; line-height:1.5; margin-bottom:6px;">';
        $html .= 'Scannez le QR code ci-contre ou cliquez sur le lien ci-dessous pour retrouver votre panier et passer commande&nbsp;:';
        $html .= '</div>';
        $html .= '<div style="font-size:10px;"><a href="' . esc_url($share_url) . '" style="color:' . $ca . '; text-decoration:underline; word-break:break-all;">' . esc_html($share_url) . '</a></div>';
        $expiry_days = max(1, (int) get_option('wqg_cart_share_expiry', 30));
        $html .= '<div style="font-size:9px; color:#999; margin-top:4px;">Ce lien est valable ' . $expiry_days . ' jours.</div>';
        $html .= '</td>';
        $html .= '</tr></table>';
        $html .= '</div>';
    }

    // =============================================
    // FOOTER DOCUMENT
    // =============================================
    $footer_left  = !empty($terms_url)
        ? 'CGV&nbsp;: <a href="' . esc_url($terms_url) . '">' . esc_html($terms_url) . '</a>'
        : '';
    $footer_right = !empty($custom_footer)
        ? wp_kses_post($custom_footer)
        : '';

    if (!empty($footer_left) || !empty($footer_right)) {
        $html .= '<table class="doc-footer" cellpadding="0" cellspacing="0">
            <tr>
                <td style="width:60%;">' . $footer_left . '</td>
                <td style="text-align:right; width:40%;">' . $footer_right . '</td>
            </tr>
        </table>';
    }

    // =============================================
    // PAGE DÉTAIL : VUES WPC PRO + GALERIES VARIATIONS
    // =============================================
    if (!empty($detail_items)) {
        // Pas de page-break forcé : les images s'enchaînent directement après les CGV
        $html .= '<div class="section-title" style="margin-top:22px;">D&#233;tail des produits configur&#233;s</div>';

        foreach ($detail_items as $di) {
            $type_label = ($di['type'] === 'wpc')
                ? 'Vues de la configuration'
                : 'Images de la variation s&#233;lectionn&#233;e';

            $html .= '<div style="margin-bottom:22px;">';
            $html .= '<div style="font-size:12px; font-weight:bold; color:' . $cp . '; margin-bottom:8px; padding:4px 8px; background:#f4f4f0; border-left:4px solid ' . $ca . ';">'
                   . esc_html($di['name'])
                   . ' <span style="font-size:10px; font-weight:normal; color:#999;">&mdash; ' . $type_label . '</span>'
                   . '</div>';

            // Dédoublonnage : supprimer les images identiques (même data-URI ou URL)
            $unique_imgs = [];
            $seen_hashes = [];
            foreach ($di['images'] as $img_src) {
                $sig = strlen($img_src) > 500
                    ? md5(substr($img_src, 0, 200) . substr($img_src, -200) . strlen($img_src))
                    : md5($img_src);
                if (!isset($seen_hashes[$sig])) {
                    $seen_hashes[$sig] = true;
                    $unique_imgs[]     = $img_src;
                }
            }

            // Si une seule image unique : pas besoin de la section détail (déjà visible en vignette)
            if (count($unique_imgs) < 2) continue;

            // Images en grille de 2 colonnes — plus grandes et lisibles
            $html .= '<table style="width:100%; border-collapse:collapse;" cellpadding="0" cellspacing="0"><tr>';
            $col = 0;
            foreach ($unique_imgs as $img_src) {
                if ($col > 0 && $col % 2 === 0) {
                    $html .= '</tr><tr>';
                }
                $html .= '<td style="width:50%; padding:5px; text-align:center; vertical-align:top;">';
                $html .= '<div style="border:1px solid #eee; background:#fafafa; padding:6px;">';
                $html .= '<img src="' . $img_src . '" style="max-width:100%; height:auto; display:block; margin:auto;" />';
                $html .= '</div></td>';
                $col++;
            }
            // Remplir la cellule vide si nombre impair
            if ($col % 2 !== 0) {
                $html .= '<td style="width:50%;"></td>';
            }
            $html .= '</tr></table>';
            $html .= '</div>';
        }
    }

    $html .= '</body></html>';
    return $html;
}

// ============================================================
// TRAITEMENT DU FORMULAIRE
// ============================================================
function wqg_generate_quote()
{
    ob_start();

    // Vérification nonce
    if (!isset($_POST['wqg_quote_form_nonce']) || !wp_verify_nonce($_POST['wqg_quote_form_nonce'], 'wqg_quote_form_nonce_action')) {
        wp_die('Vérification de sécurité échouée.');
    }

    // Initialiser le panier si nécessaire
    if (WC()->session === null) {
        wc_load_cart();
    }

    // NE PAS appeler calculate_totals() ici — en contexte admin-post.php,
    // les hooks de WPC Pro ne sont pas chargés, ce qui remet les prix configurateur à 0.
    // La TVA totale est calculée via le cumul par ligne ($subtotal_tva) dans wqg_build_quote_html().

    // Données client
    $name    = sanitize_text_field($_POST['quote-name']);
    $surname = sanitize_text_field($_POST['quote-surname']);
    $address = sanitize_textarea_field($_POST['quote-address']);
    $email   = sanitize_email($_POST['quote-email']);
    $phone   = sanitize_text_field($_POST['quote-phone']);

    $quote_number = 'Devis-' . date('Ymd-His');
    $quote_date   = date('d/m/Y');

    // Produits ajoutés manuellement (admins seulement)
    $manual_items = [];
    if (
        is_user_logged_in() &&
        (current_user_can('administrator') || current_user_can('manage_woocommerce')) &&
        !empty($_POST['wqg_manual']) &&
        is_array($_POST['wqg_manual'])
    ) {
        $allowed_tva = [0.0, 5.5, 10.0, 20.0];
        foreach ($_POST['wqg_manual'] as $raw) {
            $desc     = sanitize_text_field($raw['description'] ?? '');
            $qty      = max(1, (int) ($raw['qty'] ?? 1));
            $price_ht = round((float) str_replace(',', '.', $raw['price_ht'] ?? '0'), 4);
            $tva_rate = round((float) ($raw['tva'] ?? 20), 2);
            if (empty($desc) || $price_ht <= 0) {
                continue;
            }
            if (!in_array($tva_rate, $allowed_tva, true)) {
                $tva_rate = 20.0;
            }
            $manual_items[] = [
                'description' => $desc,
                'qty'         => $qty,
                'price_ht'    => $price_ht,
                'tva_rate'    => $tva_rate,
            ];
        }
    }

    // Pré-calculer le total TTC des items manuels (nécessaire pour l'email)
    $manual_ttc_total = 0.0;
    foreach ($manual_items as $item) {
        $m_qty  = (int)   $item['qty'];
        $m_ht   = (float) $item['price_ht'];
        $m_tva  = (float) $item['tva_rate'];
        $manual_ttc_total += round($m_ht * (1 + $m_tva / 100), 4) * $m_qty;
    }

    // Commercial sélectionné (admins seulement, feature activée)
    $sales_rep = [];
    if (
        is_user_logged_in() &&
        (current_user_can('administrator') || current_user_can('manage_woocommerce')) &&
        get_option('wqg_enable_sales_reps', '0') === '1' &&
        !empty($_POST['wqg_sales_rep_id'])
    ) {
        $rep_idx  = (int) $_POST['wqg_sales_rep_id'] - 1; // 1-indexé dans le formulaire
        $all_reps = get_option('wqg_sales_reps', []);
        if (is_array($all_reps) && isset($all_reps[$rep_idx]) && !empty($all_reps[$rep_idx]['name'])) {
            $sales_rep = $all_reps[$rep_idx];
        }
    }

    $client_data = [
        'name'         => $name,
        'surname'      => $surname,
        'email'        => $email,
        'phone'        => $phone,
        'address'      => $address,
        'quote_number' => $quote_number,
        'quote_date'   => $quote_date,
        'manual_items' => $manual_items,
        'sales_rep'    => $sales_rep, // ['name' => ..., 'email' => ..., 'phone' => ...] ou []
    ];

    require_once plugin_dir_path(__FILE__) . 'vendor/autoload.php';

    // Augmenter les limites PHP pour les gros devis (images WPC en base64, galeries de variation)
    @ini_set('memory_limit', '512M');
    @ini_set('pcre.backtrack_limit', '5000000');  // mPDF parse le HTML avec des regex ; les data-URI WPC dépassent la limite par défaut (1M)
    @set_time_limit(120); // 2 minutes max

    try {
        // ---- HTML commun ----
        $quote_html = wqg_build_quote_html($client_data);

        // Répertoire temporaire mPDF (dans uploads, toujours accessible en écriture)
        $upload_dir  = wp_upload_dir();
        $mpdf_tmp    = $upload_dir['basedir'] . '/wqg-tmp';
        if (!is_dir($mpdf_tmp)) {
            wp_mkdir_p($mpdf_tmp);
        }

        // ---- Génération du PDF ----
        $mpdf = new \Mpdf\Mpdf([
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 0,    // le header HTML occupe toute la largeur, pas de marge
            'margin_bottom' => 20,
            'margin_left'   => 0,
            'margin_right'  => 0,
            'margin_footer' => 6,
            'tempDir'       => $mpdf_tmp,
        ]);
        $pdf_company = get_option('wqg_company_name', '');
        $mpdf->SetTitle('Devis ' . $quote_number);
        $mpdf->SetAuthor($pdf_company);

        // Pied de page : société | numéro de devis | page X/Y
        $mpdf->SetHTMLFooter(
            '<table width="100%" cellpadding="4" cellspacing="0"'
            . ' style="font-size:9px; color:#999999; border-top:1px solid #DDE4F0;">'
            . '<tr>'
            . '<td style="border:none; color:#999999; padding-left:15px;">' . esc_html($pdf_company) . '</td>'
            . '<td style="border:none; text-align:center; color:#1B3A6B; font-weight:bold;">' . esc_html($quote_number) . '</td>'
            . '<td style="border:none; text-align:right; padding-right:15px;">Page {PAGENO}&nbsp;/&nbsp;{nbpg}</td>'
            . '</tr></table>'
        );
        $mpdf->WriteHTML($quote_html);
        $pdf_content  = $mpdf->Output('', 'S');
        $pdf_filename = 'devis-' . time() . '.pdf';

        // ---- Email admin : notification simple + PDF en pièce jointe ----
        // Formater le montant total TTC pour l'objet du mail
        $grand_total_display = number_format((float) WC()->cart->get_total('edit') + $manual_ttc_total, 2, ',', ' ');

        $email_content = '
        <html><head><meta charset="UTF-8"><style>
            body { font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #333; line-height: 1.6; }
            .wrap { max-width: 560px; margin: 30px auto; padding: 30px; border: 1px solid #ddd; border-radius: 8px; }
            .label { font-weight: bold; color: #555; width: 120px; display: inline-block; }
            .amount { font-size: 20px; font-weight: bold; color: #67694E; margin: 16px 0; }
            .footer { margin-top: 24px; font-size: 12px; color: #999; border-top: 1px solid #eee; padding-top: 12px; }
        </style></head><body>
        <div class="wrap">
            <p>Bonjour,</p>
            <p>Un de vos clients a généré un devis d\'un montant de :</p>
            <div class="amount">' . $grand_total_display . ' € TTC</div>
            <p>
                <span class="label">Nom :</span> ' . esc_html($name) . ' ' . esc_html($surname) . '<br>
                <span class="label">Email :</span> ' . esc_html($email) . '<br>
                <span class="label">Téléphone :</span> ' . esc_html($phone) . '<br>
                <span class="label">Adresse :</span> ' . nl2br(esc_html($address)) . '<br>
                <span class="label">Référence :</span> ' . esc_html($quote_number) . '
            </p>
            <p>Le devis complet est joint en pièce jointe (PDF).</p>
            <div class="footer">Ce message a été généré automatiquement depuis le site.</div>
        </div>
        </body></html>';

        // Sauvegarder le PDF temporairement pour l'attacher à l'email
        $upload_dir = wp_upload_dir();
        $temp_pdf   = $upload_dir['path'] . '/' . $pdf_filename;
        file_put_contents($temp_pdf, $pdf_content);

        $to      = get_option('admin_email');
        $subject = 'Un de vos clients a généré un devis de ' . $grand_total_display . ' € — ' . $name . ' ' . $surname;
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Le PDF est passé directement en 5e paramètre de wp_mail (plus fiable que phpmailer_init)
        wp_mail($to, $subject, $email_content, $headers, [$temp_pdf]);

        // Supprimer le fichier temporaire après l'envoi
        if (file_exists($temp_pdf)) {
            unlink($temp_pdf);
        }

        // ---- Téléchargement du PDF par le client ----
        ob_clean();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $pdf_filename . '"');
        header('Content-Length: ' . strlen($pdf_content));
        echo $pdf_content;
        exit;

    } catch (\Mpdf\MpdfException $e) {
        error_log('WQG mPDF error: ' . $e->getMessage());
        wp_die(
            '<h2>Erreur de génération du PDF</h2>'
            . '<p><strong>Erreur mPDF :</strong> ' . esc_html($e->getMessage()) . '</p>'
            . '<p>Cela peut arriver avec des devis contenant beaucoup d\'images (configurateur, galerie de variation).</p>'
            . '<p><strong>Solutions possibles :</strong></p>'
            . '<ul>'
            . '<li>Réduire le nombre d\'images (réglage « Indices des images à afficher »)</li>'
            . '<li>Augmenter la mémoire PHP dans wp-config.php : <code>define(\'WP_MEMORY_LIMIT\', \'512M\');</code></li>'
            . '</ul>'
            . '<p><a href="javascript:history.back()">← Retour au formulaire</a></p>',
            'Erreur PDF — Générateur de devis'
        );
    } catch (\Exception $e) {
        error_log('WQG general error: ' . $e->getMessage());
        wp_die(
            '<h2>Erreur inattendue</h2>'
            . '<p>' . esc_html($e->getMessage()) . '</p>'
            . '<p><a href="javascript:history.back()">← Retour au formulaire</a></p>',
            'Erreur — Générateur de devis'
        );
    }
}
add_action('admin_post_wqg_generate_quote', 'wqg_generate_quote');
add_action('admin_post_nopriv_wqg_generate_quote', 'wqg_generate_quote');

// ============================================================
// PARTAGE DE PANIER — SÉRIALISATION, RESTAURATION, QR CODE
// ============================================================

/**
 * Sérialise le panier WooCommerce + les éventuels produits manuels du devis.
 * Les images multi-vues WPC Pro sont sauvegardées comme fichiers dans uploads
 * (trop volumineuses pour un transient MySQL).
 *
 * @param array $manual_items Produits manuels ajoutés par l'admin dans le devis.
 * @return string|false Token UUID ou false si le panier est vide.
 */
function wqg_create_shared_cart($manual_items = [])
{
    if (!WC()->cart || WC()->cart->is_empty()) {
        // Si le panier est vide mais qu'il y a des items manuels, on continue quand même
        if (empty($manual_items)) {
            return false;
        }
    }

    $cart  = WC()->cart ? WC()->cart->get_cart() : [];
    $token = wp_generate_uuid4();
    $items = [];

    // Clés WPC Pro : vignette incluse, multi-vues sauvegardées en fichiers séparément
    $wpc_keys = [
        'encode_active_key', 'tree_set', 'config_id',
        'base_price', 'wpc_data', 'wpc_timestamp', 'wpc_hash',
        'base64_cart_image_data',
    ];

    foreach ($cart as $cart_item_key => $cart_item) {
        $item = [
            'product_id'     => (int) $cart_item['product_id'],
            'variation_id'   => (int) ($cart_item['variation_id'] ?? 0),
            'quantity'        => (int) $cart_item['quantity'],
            'variation_data' => $cart_item['variation'] ?? [],
        ];

        // WAPF (Advanced Product Fields)
        if (!empty($cart_item['wapf']) && is_array($cart_item['wapf'])) {
            $item['wapf'] = $cart_item['wapf'];
        }
        if (!empty($cart_item['wapf_field_groups'])) {
            $item['wapf_field_groups'] = $cart_item['wapf_field_groups'];
        }

        // WPC Pro — clés de configuration + vignette
        foreach ($wpc_keys as $key) {
            if (isset($cart_item[$key])) {
                $item[$key] = $cart_item[$key];
            }
        }

        // wpc_all_views_images : trop volumineuses pour le transient →
        // on les convertit en fichiers physiques et on stocke les URLs
        if (!empty($cart_item['wpc_all_views_images']) && is_array($cart_item['wpc_all_views_images'])) {
            $saved_urls = [];
            foreach ($cart_item['wpc_all_views_images'] as $idx => $data_uri) {
                if (empty($data_uri)) {
                    continue;
                }
                $url = wqg_save_view_image($data_uri, $token, $cart_item_key, $idx);
                if ($url) {
                    $saved_urls[] = $url;
                }
            }
            if (!empty($saved_urls)) {
                $item['wpc_all_views_images'] = $saved_urls; // URLs de fichiers, plus des data-URIs
            }
        }

        $items[] = $item;
    }

    $shared_cart = [
        'version'      => 2,
        'created_at'   => current_time('mysql'),
        'items'        => $items,
        'coupon_codes' => WC()->cart ? WC()->cart->get_applied_coupons() : [],
        'manual_items' => array_values((array) $manual_items), // produits manuels du devis PDF
    ];

    $expiry = max(1, (int) get_option('wqg_cart_share_expiry', 30)) * DAY_IN_SECONDS;
    set_transient('wqg_shared_cart_' . $token, $shared_cart, $expiry);

    return $token;
}

/**
 * Sauvegarde une image data-URI en fichier physique dans uploads/wqg-views/{token}/.
 * Retourne l'URL publique du fichier, ou null en cas d'échec.
 *
 * @param string $data_uri  Image encodée en base64 (data:image/jpeg;base64,...)
 * @param string $token     Token UUID du panier partagé (sert de dossier).
 * @param string $cart_key  Clé de l'item dans le panier (pour le nom de fichier).
 * @param int    $index     Index de la vue (0, 1, 2...).
 * @return string|null URL publique ou null.
 */
function wqg_save_view_image($data_uri, $token, $cart_key, $index)
{
    // Parser la data-URI : "data:image/jpeg;base64,<data>"
    if (!preg_match('/^data:image\/(\w+);base64,(.+)$/s', $data_uri, $m)) {
        return null;
    }
    $ext  = strtolower($m[1]); // jpeg, png, webp
    $data = base64_decode($m[2]);
    if (empty($data)) {
        return null;
    }

    $upload  = wp_upload_dir();
    $dir     = $upload['basedir'] . '/wqg-views/' . $token;
    if (!is_dir($dir)) {
        wp_mkdir_p($dir);
    }

    // Nom de fichier court et sans caractères spéciaux
    $filename = 'v-' . substr(md5($cart_key), 0, 8) . '-' . (int) $index . '.' . $ext;
    if (file_put_contents($dir . '/' . $filename, $data) === false) {
        return null;
    }

    return $upload['baseurl'] . '/wqg-views/' . $token . '/' . $filename;
}

/**
 * Retourne l'ID du produit WooCommerce virtuel utilisé comme placeholder
 * pour les produits manuels dans le panier restauré.
 * Le produit est créé automatiquement s'il n'existe pas encore.
 *
 * @return int|false ID du produit ou false en cas d'erreur.
 */
function wqg_get_or_create_manual_product()
{
    $pid = (int) get_option('wqg_manual_item_product_id', 0);

    // Vérifier que le produit existe toujours
    if ($pid > 0 && get_post_type($pid) === 'product') {
        return $pid;
    }

    // Créer le produit placeholder une seule fois
    if (!class_exists('WC_Product_Simple')) {
        return false;
    }
    $product = new WC_Product_Simple();
    $product->set_name('Article personnalisé');
    $product->set_status('private');                  // Invisible en boutique
    $product->set_catalog_visibility('hidden');
    $product->set_price(0);
    $product->set_regular_price(0);
    $product->set_virtual(true);
    $product->set_sold_individually(false);
    $product->set_manage_stock(false);
    $pid = $product->save();

    if ($pid) {
        update_option('wqg_manual_item_product_id', $pid);
        return $pid;
    }
    return false;
}

/**
 * Trouve la classe de taxe WooCommerce correspondant à un taux TVA (%).
 * Retourne '' (taux standard) si aucune correspondance exacte n'est trouvée.
 *
 * @param float $rate Taux TVA en pourcentage (ex: 20.0, 10.0, 5.5).
 * @return string Slug de la classe de taxe WooCommerce.
 */
function wqg_find_tax_class_for_rate($rate)
{
    if (!class_exists('WC_Tax')) {
        return '';
    }
    $tax_classes = array_merge([''], WC_Tax::get_tax_classes()); // '' = taux standard
    foreach ($tax_classes as $class) {
        $rates = WC_Tax::get_rates($class);
        foreach ($rates as $tax_rate) {
            if (abs((float) $tax_rate['rate'] - (float) $rate) < 0.1) {
                return $class;
            }
        }
    }
    return ''; // Fallback : taux standard WooCommerce
}

// Surcharge du prix pour les articles manuels WQG dans le panier
add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['wqg_manual_item']) && isset($item['data'])) {
            $price_ht  = (float) $item['wqg_manual_item']['price_ht'];
            $tva_rate  = (float) $item['wqg_manual_item']['tva_rate'];
            $tax_class = wqg_find_tax_class_for_rate($tva_rate);
            $item['data']->set_price($price_ht);
            $item['data']->set_tax_class($tax_class);
        }
    }
}, 20, 1);

// Afficher le nom personnalisé des articles manuels WQG dans le panier
add_filter('woocommerce_cart_item_name', function ($name, $cart_item) {
    if (!empty($cart_item['wqg_manual_item']['description'])) {
        return esc_html($cart_item['wqg_manual_item']['description']);
    }
    return $name;
}, 10, 2);

// Afficher le nom personnalisé dans les emails de commande et le back-office
add_filter('woocommerce_order_item_name', function ($name, $item) {
    $meta = $item->get_meta('_wqg_manual_item');
    if (!empty($meta['description'])) {
        return esc_html($meta['description']);
    }
    return $name;
}, 10, 2);

// Sauvegarder les données de l'article manuel dans les meta de la ligne de commande
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values) {
    if (!empty($values['wqg_manual_item'])) {
        $item->add_meta_data('_wqg_manual_item', $values['wqg_manual_item'], true);
        $item->set_name(esc_html($values['wqg_manual_item']['description']));
    }
}, 10, 3);

/**
 * Restaure un panier partagé depuis un token (paramètre GET).
 * Hook sur template_redirect pour s'assurer que WooCommerce est chargé.
 */
function wqg_restore_shared_cart()
{
    if (!isset($_GET['wqg_restore_cart']) || empty($_GET['wqg_restore_cart'])) {
        return;
    }

    $token = sanitize_text_field($_GET['wqg_restore_cart']);

    // Valider le format UUID
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token)) {
        wc_add_notice(__('Lien de panier invalide.', 'wqg'), 'error');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    $shared_cart = get_transient('wqg_shared_cart_' . $token);
    if (empty($shared_cart) || empty($shared_cart['items'])) {
        wc_add_notice(__('Ce lien de panier a expiré ou est invalide.', 'wqg'), 'error');
        wp_safe_redirect(wc_get_cart_url());
        exit;
    }

    // Vider le panier actuel
    WC()->cart->empty_cart(false);

    // Construire la table d'images AVANT d'ajouter les items.
    // Les images (base64_cart_image_data) sont capturées par le canvas JS du
    // configurateur — elles ne peuvent pas être régénérées côté serveur.
    // Le WPC Performance Booster les écraserait avec une valeur vide lors du
    // add_to_cart(). On les injecte donc directement dans la session après coup.
    $image_map = []; // encode_active_key → base64_cart_image_data
    foreach ($shared_cart['items'] as $shared_item) {
        $eak = $shared_item['encode_active_key'] ?? '';
        if (!empty($eak) && !empty($shared_item['base64_cart_image_data'])) {
            $image_map[$eak] = $shared_item['base64_cart_image_data'];
        }
    }

    $restored = 0;
    foreach ($shared_cart['items'] as $item) {
        $product_id   = (int) $item['product_id'];
        $variation_id = (int) ($item['variation_id'] ?? 0);
        $quantity     = max(1, (int) ($item['quantity'] ?? 1));
        $variation    = $item['variation_data'] ?? [];

        // Reconstruire les données custom du cart item
        // Note : base64_cart_image_data est volontairement absent ici —
        // il sera injecté directement dans la session après add_to_cart().
        $cart_item_data = [];

        // WAPF
        if (!empty($item['wapf'])) {
            $cart_item_data['wapf'] = $item['wapf'];
        }
        if (!empty($item['wapf_field_groups'])) {
            $cart_item_data['wapf_field_groups'] = $item['wapf_field_groups'];
        }

        // WPC Pro (configuration, prix — sans les images)
        $wpc_keys = [
            'encode_active_key', 'tree_set', 'config_id',
            'base_price', 'wpc_data', 'wpc_timestamp', 'wpc_hash',
        ];
        foreach ($wpc_keys as $key) {
            if (isset($item[$key])) {
                $cart_item_data[$key] = $item[$key];
            }
        }

        $added = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation, $cart_item_data);
        if ($added) {
            $restored++;
        }
    }

    // -----------------------------------------------------------------------
    // AJOUT DES PRODUITS MANUELS du devis (placeholder virtuel + surcharge prix)
    // -----------------------------------------------------------------------
    if (!empty($shared_cart['manual_items']) && is_array($shared_cart['manual_items'])) {
        $placeholder_id = wqg_get_or_create_manual_product();
        if ($placeholder_id) {
            foreach ($shared_cart['manual_items'] as $manual) {
                $desc      = sanitize_text_field($manual['description'] ?? '');
                $qty       = max(1, (int) ($manual['qty'] ?? 1));
                $price_ht  = (float) ($manual['price_ht'] ?? 0);
                $tva_rate  = (float) ($manual['tva_rate'] ?? 20);
                if (empty($desc) || $price_ht <= 0) {
                    continue;
                }
                $added = WC()->cart->add_to_cart(
                    $placeholder_id,
                    $qty,
                    0, // pas de variation
                    [],
                    ['wqg_manual_item' => [
                        'description' => $desc,
                        'price_ht'    => $price_ht,
                        'tva_rate'    => $tva_rate,
                    ]]
                );
                if ($added) {
                    $restored++;
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // INJECTION DES IMAGES dans la session WooCommerce.
    //
    // - base64_cart_image_data : vignette de la configuration (déjà sauvegardée)
    // - wpc_all_views_images   : vues multi-angles (URLs de fichiers, pas data-URIs)
    //
    // Ces images ne peuvent pas être régénérées côté serveur (capturées par le
    // canvas JS du configurateur). On les injecte directement dans la session
    // après add_to_cart() pour contourner les hooks du WPC Performance Booster.
    // -----------------------------------------------------------------------
    $views_map = []; // encode_active_key → [urls des vues]
    foreach ($shared_cart['items'] as $shared_item) {
        $eak = $shared_item['encode_active_key'] ?? '';
        if (!empty($eak) && !empty($shared_item['wpc_all_views_images'])) {
            $views_map[$eak] = $shared_item['wpc_all_views_images'];
        }
    }

    if ((!empty($image_map) || !empty($views_map)) && WC()->session) {
        // 1. Mettre à jour cart_contents en mémoire
        foreach (WC()->cart->cart_contents as &$citem) {
            $eak = $citem['encode_active_key'] ?? '';
            if (!empty($eak)) {
                if (isset($image_map[$eak])) {
                    $citem['base64_cart_image_data'] = $image_map[$eak];
                }
                if (isset($views_map[$eak])) {
                    $citem['wpc_all_views_images'] = $views_map[$eak];
                }
            }
        }
        unset($citem);

        // 2. Mettre à jour la session WooCommerce
        $session_cart = WC()->session->get('cart');
        if (is_array($session_cart)) {
            foreach ($session_cart as &$sitem) {
                $eak = $sitem['encode_active_key'] ?? '';
                if (!empty($eak)) {
                    if (isset($image_map[$eak])) {
                        $sitem['base64_cart_image_data'] = $image_map[$eak];
                    }
                    if (isset($views_map[$eak])) {
                        $sitem['wpc_all_views_images'] = $views_map[$eak];
                    }
                }
            }
            unset($sitem);
            WC()->session->set('cart', $session_cart);
        }

        // 3. Persister en base de données
        WC()->cart->set_session();

        // 4. Pour les utilisateurs connectés, mettre à jour le panier persistant
        if (is_user_logged_in() && method_exists(WC()->cart, 'persistent_cart_update')) {
            WC()->cart->persistent_cart_update();
        }
    }

    // Restaurer les coupons
    if (!empty($shared_cart['coupon_codes'])) {
        foreach ($shared_cart['coupon_codes'] as $code) {
            WC()->cart->apply_coupon($code);
        }
    }

    if ($restored > 0) {
        wc_add_notice(
            sprintf(__('Panier restauré avec succès (%d produit(s)).', 'wqg'), $restored),
            'success'
        );
    } else {
        wc_add_notice(__('Impossible de restaurer les produits du panier. Certains produits ne sont peut-être plus disponibles.', 'wqg'), 'error');
    }

    wp_safe_redirect(wc_get_cart_url());
    exit;
}
add_action('template_redirect', 'wqg_restore_shared_cart', 5);

/**
 * Génère l'URL de restauration du panier partagé.
 */
function wqg_get_shared_cart_url($token)
{
    return home_url('/?wqg_restore_cart=' . urlencode($token));
}

/**
 * Bouton "Partager le panier" affiché après le tableau du panier WooCommerce.
 */
function wqg_share_cart_button()
{
    if (WC()->cart->is_empty()) {
        return;
    }
    ?>
    <div id="wqg-share-cart" style="margin: 15px 0; padding: 15px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 6px;">
        <button type="button" id="wqg-share-btn" class="button" style="margin-right:10px;">
            &#128279; Partager ce panier
        </button>
        <span id="wqg-share-status" style="color:#666; font-size:13px;"></span>
        <div id="wqg-share-result" style="display:none; margin-top:10px;">
            <input type="text" id="wqg-share-url" readonly style="width:70%; padding:6px; font-size:13px; border:1px solid #ccc; border-radius:4px;" />
            <button type="button" id="wqg-copy-btn" class="button" style="margin-left:5px;">Copier</button>
            <span id="wqg-copy-status" style="color:green; font-size:12px; margin-left:8px; display:none;">Copié !</span>
        </div>
    </div>
    <script>
    (function() {
        var shareBtn = document.getElementById('wqg-share-btn');
        var copyBtn  = document.getElementById('wqg-copy-btn');
        var urlInput = document.getElementById('wqg-share-url');
        var result   = document.getElementById('wqg-share-result');
        var status   = document.getElementById('wqg-share-status');
        var copyStatus = document.getElementById('wqg-copy-status');

        shareBtn.addEventListener('click', function() {
            shareBtn.disabled = true;
            status.textContent = 'Génération du lien...';

            var formData = new FormData();
            formData.append('action', 'wqg_share_cart');
            formData.append('nonce', '<?php echo wp_create_nonce("wqg_share_cart_nonce"); ?>');

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                shareBtn.disabled = false;
                if (data.success && data.data.url) {
                    urlInput.value = data.data.url;
                    result.style.display = 'block';
                    status.textContent = 'Lien valable ' + data.data.expiry_days + ' jours :';
                } else {
                    status.textContent = 'Erreur : ' + (data.data || 'Impossible de générer le lien.');
                }
            })
            .catch(function() {
                shareBtn.disabled = false;
                status.textContent = 'Erreur réseau.';
            });
        });

        copyBtn.addEventListener('click', function() {
            urlInput.select();
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(urlInput.value).then(function() {
                    copyStatus.style.display = 'inline';
                    setTimeout(function() { copyStatus.style.display = 'none'; }, 2000);
                });
            } else {
                document.execCommand('copy');
                copyStatus.style.display = 'inline';
                setTimeout(function() { copyStatus.style.display = 'none'; }, 2000);
            }
        });
    })();
    </script>
    <?php
}
add_action('woocommerce_after_cart_table', 'wqg_share_cart_button');

/**
 * AJAX : générer un lien de partage du panier.
 */
function wqg_ajax_share_cart()
{
    check_ajax_referer('wqg_share_cart_nonce', 'nonce');

    $token = wqg_create_shared_cart();
    if (!$token) {
        wp_send_json_error('Le panier est vide.');
    }

    $expiry_days = max(1, (int) get_option('wqg_cart_share_expiry', 30));

    wp_send_json_success([
        'url'         => wqg_get_shared_cart_url($token),
        'token'       => $token,
        'expiry_days' => $expiry_days,
    ]);
}
add_action('wp_ajax_wqg_share_cart', 'wqg_ajax_share_cart');
add_action('wp_ajax_nopriv_wqg_share_cart', 'wqg_ajax_share_cart');

// ============================================================
// RÉGLAGES
// ============================================================
function wqg_register_settings()
{
    $defaults = [
        'wqg_company_name'         => get_bloginfo('name'),          // Nom du site WordPress par défaut
        'wqg_company_address'      => '',                            // À renseigner par l'utilisateur
        'wqg_company_logo'         => '',                            // Sélectionnable via la médiathèque
        'wqg_quote_page_url'       => home_url('/generer-un-devis'),
        'wqg_terms_conditions_url' => '',                            // URL CGV à renseigner
        'wqg_custom_footer'        => '',
        'wqg_openrouter_api_key'   => '',
        'wqg_use_ai_summary'       => '0',
        'wqg_show_product_images'  => '1',
        'wqg_color_primary'        => '#1B3A6B',                     // Bleu professionnel par défaut
        'wqg_color_accent'         => '#2E5FA3',                     // Bleu accent par défaut
        'wqg_hide_coupon_codes'    => '0',
        'wqg_summary_max_words'    => '60',
        'wqg_ai_system_prompt'     => WQG_DEFAULT_AI_PROMPT,
        'wqg_gallery_categories'   => '',   // Vide = toutes les catégories
        'wqg_gallery_image_indices'=> '',   // Vide = toutes les images
        'wqg_cart_share_expiry'    => '30', // Durée de validité des liens de partage en jours
    ];
    foreach ($defaults as $key => $value) {
        add_option($key, $value);
        register_setting('wqg_options_group', $key);
    }

    // --- Commerciaux ---
    add_option('wqg_enable_sales_reps', '0');
    register_setting('wqg_options_group', 'wqg_enable_sales_reps');

    add_option('wqg_sales_reps', []);
    register_setting('wqg_options_group', 'wqg_sales_reps', [
        'type'              => 'array',
        'sanitize_callback' => 'wqg_sanitize_sales_reps',
    ]);
}

/**
 * Sanitize le tableau des commerciaux (max 5).
 */
function wqg_sanitize_sales_reps($input)
{
    $clean = [];
    if (!is_array($input)) {
        return $clean;
    }
    $count = 0;
    foreach ($input as $rep) {
        if ($count >= 5) break;
        $name  = isset($rep['name'])  ? sanitize_text_field($rep['name']) : '';
        $email = isset($rep['email']) ? sanitize_email($rep['email']) : '';
        $phone = isset($rep['phone']) ? sanitize_text_field($rep['phone']) : '';
        if (!empty($name)) {
            $clean[] = [
                'name'  => $name,
                'email' => $email,
                'phone' => $phone,
            ];
            $count++;
        }
    }
    return $clean;
}
add_action('admin_init', 'wqg_register_settings');

function wqg_register_options_page()
{
    add_options_page(
        'Paramètres du générateur de devis',
        'Générateur de devis',
        'manage_options',
        'wqg',
        'wqg_options_page'
    );
}
add_action('admin_menu', 'wqg_register_options_page');

/** Charger la médiathèque WordPress sur la page de réglages du plugin */
function wqg_admin_enqueue_media($hook)
{
    if ($hook !== 'settings_page_wqg') {
        return;
    }
    wp_enqueue_media();
}
add_action('admin_enqueue_scripts', 'wqg_admin_enqueue_media');

function wqg_options_page()
{
    ?>
    <div class="wrap">
        <h1>Paramètres du générateur de devis</h1>
        <p style="font-size:13px; color:#666;">Configurez l'identité de votre entreprise, l'apparence du devis PDF, les descriptions IA et la galerie d'images des variations.</p>
        <form method="post" action="options.php">
            <?php settings_fields('wqg_options_group'); ?>
            <table class="form-table">
                <!-- ===== Section Entreprise ===== -->
                <tr><td colspan="2"><h3 style="margin:0 0 5px;">🏢 Informations de l'entreprise</h3></td></tr>

                <tr>
                    <th><label for="wqg_company_name">Nom de l'entreprise</label></th>
                    <td>
                        <input type="text" id="wqg_company_name" name="wqg_company_name" class="regular-text" value="<?php echo esc_attr(get_option('wqg_company_name')); ?>" />
                        <p class="description">Apparaît dans l'en-tête et le pied de page du devis PDF.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_company_address">Adresse de l'entreprise</label></th>
                    <td>
                        <textarea id="wqg_company_address" name="wqg_company_address" rows="3" class="regular-text"><?php echo esc_textarea(get_option('wqg_company_address')); ?></textarea>
                        <p class="description">Adresse complète affichée dans le bloc « Émetteur » du devis (rue, code postal, ville).</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_company_logo">Logo de l'entreprise</label></th>
                    <td>
                        <div style="margin-bottom:10px;">
                            <?php $logo_url = get_option('wqg_company_logo', ''); ?>
                            <div id="wqg_logo_preview" style="margin-bottom:8px; <?php echo empty($logo_url) ? 'display:none;' : ''; ?>">
                                <img src="<?php echo esc_url($logo_url); ?>" style="max-height:80px; max-width:300px; border:1px solid #ddd; padding:4px; background:#fff;" id="wqg_logo_preview_img" />
                            </div>
                            <input type="hidden" id="wqg_company_logo" name="wqg_company_logo" value="<?php echo esc_attr($logo_url); ?>" />
                            <button type="button" class="button" id="wqg_upload_logo_btn">
                                📁 Choisir dans la médiathèque
                            </button>
                            <button type="button" class="button" id="wqg_remove_logo_btn" style="color:#a00; <?php echo empty($logo_url) ? 'display:none;' : ''; ?>">
                                ✕ Supprimer
                            </button>
                        </div>
                        <p class="description">Sélectionnez le logo depuis la médiathèque WordPress. Format PNG recommandé, hauteur max affichée : 58px.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_quote_page_url">URL de la page devis</label></th>
                    <td>
                        <input type="text" id="wqg_quote_page_url" name="wqg_quote_page_url" class="regular-text" value="<?php echo esc_attr(get_option('wqg_quote_page_url')); ?>" />
                        <p class="description">Page contenant le shortcode <code>[wqg_quote_form]</code>. Le bouton « Générer un devis » du panier renvoie vers cette page.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_terms_conditions_url">URL des CGV</label></th>
                    <td>
                        <input type="text" id="wqg_terms_conditions_url" name="wqg_terms_conditions_url" class="regular-text" value="<?php echo esc_attr(get_option('wqg_terms_conditions_url')); ?>" />
                        <p class="description">Lien vers vos Conditions Générales de Vente. Affiché en pied de page du devis PDF. Laissez vide pour masquer.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_custom_footer">Texte de pied de page</label></th>
                    <td>
                        <textarea id="wqg_custom_footer" name="wqg_custom_footer" rows="4" class="regular-text"><?php echo esc_textarea(get_option('wqg_custom_footer')); ?></textarea>
                        <p class="description">Texte libre affiché en bas à droite du devis (ex. : SIRET, TVA intracommunautaire, mentions légales). HTML basique autorisé.</p>
                    </td>
                </tr>
                <!-- ===== Section Apparence ===== -->
                <tr><td colspan="2"><hr style="margin:15px 0 5px;"><h3 style="margin:0;">🎨 Apparence du devis</h3></td></tr>

                <tr>
                    <th><label for="wqg_color_primary">Couleur principale</label></th>
                    <td>
                        <input type="color" id="wqg_color_primary" name="wqg_color_primary" value="<?php echo esc_attr(get_option('wqg_color_primary', '#67694E')); ?>" style="height:36px; padding:2px 4px;" />
                        <p class="description">Couleur dominante du devis : bandeau d'en-tête, en-têtes du tableau produits, noms de produits et ligne TOTAL TTC.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_color_accent">Couleur d'accentuation</label></th>
                    <td>
                        <input type="color" id="wqg_color_accent" name="wqg_color_accent" value="<?php echo esc_attr(get_option('wqg_color_accent', '#4E5038')); ?>" style="height:36px; padding:2px 4px;" />
                        <p class="description">Couleur secondaire : bordures latérales des titres de section, liens cliquables et libellés « Émetteur / Destinataire ».</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_hide_coupon_codes">Masquer les codes promo</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wqg_hide_coupon_codes" name="wqg_hide_coupon_codes" value="1" <?php checked(get_option('wqg_hide_coupon_codes'), '1'); ?> />
                            Ne pas afficher le nom du code promo au client — seul le montant de la remise apparaît dans le récapitulatif.
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_show_product_images">Afficher les images produits</label></th>
                    <td>
                        <select id="wqg_show_product_images" name="wqg_show_product_images">
                            <option value="1" <?php selected(get_option('wqg_show_product_images'), '1'); ?>>Oui — vignette dans le tableau</option>
                            <option value="0" <?php selected(get_option('wqg_show_product_images'), '0'); ?>>Non — masquer les vignettes</option>
                        </select>
                        <p class="description">Affiche une miniature (65×65 px) de chaque produit dans le tableau du devis. Utilise l'image du configurateur WPC si disponible, sinon l'image WooCommerce.</p>
                    </td>
                </tr>

                <!-- ===== Section IA ===== -->
                <tr><td colspan="2"><hr style="margin:15px 0 5px;"><h3 style="margin:0;">🤖 Résumé IA des descriptions produits</h3><p style="color:#666; font-size:12px; margin:4px 0 0;">L'IA génère automatiquement une fiche technique concise à partir de la description WooCommerce de chaque produit. Nécessite une clé API OpenRouter.</p></td></tr>

                <tr>
                    <th><label for="wqg_use_ai_summary">Mode de résumé</label></th>
                    <td>
                        <select id="wqg_use_ai_summary" name="wqg_use_ai_summary">
                            <option value="0" <?php selected(get_option('wqg_use_ai_summary'), '0'); ?>>Troncature simple — coupe le texte au nombre de mots défini</option>
                            <option value="1" <?php selected(get_option('wqg_use_ai_summary'), '1'); ?>>Résumé IA — fiche technique générée par intelligence artificielle</option>
                        </select>
                        <p class="description">
                            <strong>Troncature :</strong> la description est coupée à N mots (gratuit, pas de clé nécessaire).<br>
                            <strong>Résumé IA :</strong> l'IA reformule la description en fiche technique à puces (nécessite clé API + crédits OpenRouter).
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_summary_max_words">Longueur max (mots)</label></th>
                    <td>
                        <input type="number" id="wqg_summary_max_words" name="wqg_summary_max_words"
                               value="<?php echo esc_attr(get_option('wqg_summary_max_words', '60')); ?>"
                               min="10" max="300" step="5" style="width:80px;" />
                        <p class="description">Limite pour le résumé IA (consigne envoyée au modèle) ou la troncature (coupure exacte). 60 mots ≈ 4-5 lignes.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_openrouter_api_key">Clé API OpenRouter</label></th>
                    <td>
                        <input type="password" id="wqg_openrouter_api_key" name="wqg_openrouter_api_key" class="regular-text" value="<?php echo esc_attr(get_option('wqg_openrouter_api_key')); ?>" />
                        <p class="description">
                            Requise uniquement en mode « Résumé IA ». Créez un compte et obtenez votre clé sur
                            <a href="https://openrouter.ai/keys" target="_blank">openrouter.ai/keys</a>.
                            Coût moyen&nbsp;: ~0,001 € par description résumée.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_ai_system_prompt">Instructions IA (prompt)</label></th>
                    <td>
                        <textarea id="wqg_ai_system_prompt" name="wqg_ai_system_prompt"
                                  rows="10" class="large-text"
                                  style="font-family:monospace; font-size:12px;"><?php echo esc_textarea(get_option('wqg_ai_system_prompt', WQG_DEFAULT_AI_PROMPT)); ?></textarea>
                        <p class="description">
                            Instructions envoyées à l'IA pour formater le résumé. Le marqueur <code>{max_mots}</code> sera remplacé par la longueur choisie ci-dessus.<br>
                            Le prompt par défaut génère une fiche technique à puces (dimensions, matériaux, résistances, normes).<br>
                            <a href="#" onclick="document.getElementById('wqg_ai_system_prompt').value=<?php echo wp_json_encode(WQG_DEFAULT_AI_PROMPT); ?>;return false;">
                                ↺ Remettre le prompt par défaut
                            </a>
                        </p>
                    </td>
                </tr>
                <!-- ===== Section Galerie ===== -->
                <tr><td colspan="2"><hr style="margin:15px 0 5px;"><h3 style="margin:0;">🖼️ Galerie d'images des variations</h3><p style="color:#666; font-size:12px; margin:4px 0 0;">Ajoute une page annexe au devis avec les photos de la variation sélectionnée (nécessite le plugin <em>Woo Variation Gallery</em>). Les images s'affichent en grille de 3 colonnes.</p></td></tr>

                <tr>
                    <th><label for="wqg_gallery_categories_select">Catégories concernées</label></th>
                    <td>
                        <?php
                        $all_cats   = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name']);
                        $saved_cats = array_filter(
                            array_map('intval', preg_split('/[\s,]+/', get_option('wqg_gallery_categories', ''), -1, PREG_SPLIT_NO_EMPTY))
                        );
                        if (!is_wp_error($all_cats) && !empty($all_cats)) : ?>
                        <select id="wqg_gallery_categories_select" multiple
                                style="min-width:380px; min-height:140px;">
                            <?php foreach ($all_cats as $cat) : ?>
                            <option value="<?php echo (int) $cat->term_id; ?>"
                                <?php echo in_array((int) $cat->term_id, $saved_cats, true) ? 'selected' : ''; ?>>
                                <?php echo esc_html($cat->name); ?> (<?php echo (int) $cat->count; ?> produits)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <script>
                        document.getElementById('wqg_gallery_categories_select').addEventListener('change', function() {
                            var vals = Array.from(this.selectedOptions).map(o => o.value);
                            document.getElementById('wqg_gallery_categories').value = vals.join(',');
                        });
                        </script>
                        <?php endif; ?>
                        <input type="hidden" id="wqg_gallery_categories" name="wqg_gallery_categories"
                               value="<?php echo esc_attr(get_option('wqg_gallery_categories', '')); ?>" />
                        <p class="description">
                            Seuls les produits de ces catégories afficheront leur galerie de variation dans le devis.<br>
                            Maintenez <kbd>Ctrl</kbd> (ou <kbd>Cmd</kbd> sur Mac) pour sélectionner plusieurs catégories.<br>
                            <strong>Aucune sélection = toutes les catégories</strong> → la galerie s'affiche pour toutes les variations ayant des images.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="wqg_gallery_image_indices">Images à inclure</label></th>
                    <td>
                        <input type="text" id="wqg_gallery_image_indices" name="wqg_gallery_image_indices"
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('wqg_gallery_image_indices', '')); ?>"
                               placeholder="ex. : 1,3,5" />
                        <p class="description">
                            Filtrer les images par position dans la galerie WooCommerce (1 = première image).<br>
                            Exemple : <code>1,2,3</code> → n'affiche que les 3 premières images de chaque variation.<br>
                            <strong>Laissez vide</strong> pour inclure toutes les images de la galerie.
                        </p>
                    </td>
                </tr>

                <!-- ===== Section Partage de panier ===== -->
                <tr><td colspan="2"><h3 style="margin:18px 0 5px;">&#128279; Partage de panier</h3></td></tr>

                <tr>
                    <th><label for="wqg_cart_share_expiry">Durée de validité du lien</label></th>
                    <td>
                        <input type="number" id="wqg_cart_share_expiry" name="wqg_cart_share_expiry"
                               class="small-text" min="1" max="365"
                               value="<?php echo esc_attr(get_option('wqg_cart_share_expiry', '30')); ?>" /> jours
                        <p class="description">
                            Durée pendant laquelle le lien de partage du panier reste actif.<br>
                            Un QR code et un lien cliquable seront automatiquement ajoutés au devis PDF.<br>
                            Un bouton « Partager ce panier » apparaît également sur la page panier WooCommerce.
                        </p>
                    </td>
                </tr>
            </table>

            <!-- ===== Section Commerciaux ===== -->
            <?php
            $reps_enabled = get_option('wqg_enable_sales_reps', '0');
            $sales_reps   = get_option('wqg_sales_reps', []);
            if (!is_array($sales_reps)) $sales_reps = [];
            while (count($sales_reps) < 5) {
                $sales_reps[] = ['name' => '', 'email' => '', 'phone' => ''];
            }
            ?>
            <table class="form-table">
                <tr><td colspan="2"><h3 style="margin:0 0 5px;">👤 Commerciaux</h3></td></tr>
                <tr>
                    <th><label for="wqg_enable_sales_reps">Activer</label></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wqg_enable_sales_reps" name="wqg_enable_sales_reps" value="1" <?php checked($reps_enabled, '1'); ?> />
                            Permettre de sélectionner un commercial lors de la génération du devis (admin uniquement)
                        </label>
                        <p class="description">Les coordonnées du commercial sélectionné seront ajoutées dans le bloc Émetteur du devis PDF.</p>
                    </td>
                </tr>
            </table>

            <div id="wqg-sales-reps-section" style="<?php echo $reps_enabled !== '1' ? 'display:none;' : ''; ?>">
                <?php for ($i = 0; $i < 5; $i++) :
                    $rep = $sales_reps[$i];
                    $num = $i + 1;
                ?>
                <table class="form-table" style="margin-top:0; border-left:4px solid #2271b1; padding-left:12px; margin-bottom:15px; background:#f9f9f9;">
                    <tr><td colspan="2"><strong>Commercial <?php echo $num; ?></strong><?php if ($i === 0) : ?> <span style="color:#999;">(laissez le nom vide pour ignorer un slot)</span><?php endif; ?></td></tr>
                    <tr>
                        <th><label>Nom</label></th>
                        <td><input type="text" name="wqg_sales_reps[<?php echo $i; ?>][name]" class="regular-text" value="<?php echo esc_attr($rep['name']); ?>" placeholder="Ex : Jean Dupont" /></td>
                    </tr>
                    <tr>
                        <th><label>Email</label></th>
                        <td><input type="email" name="wqg_sales_reps[<?php echo $i; ?>][email]" class="regular-text" value="<?php echo esc_attr($rep['email']); ?>" placeholder="jean.dupont@entreprise.fr" /></td>
                    </tr>
                    <tr>
                        <th><label>Téléphone</label></th>
                        <td><input type="tel" name="wqg_sales_reps[<?php echo $i; ?>][phone]" class="regular-text" value="<?php echo esc_attr($rep['phone']); ?>" placeholder="06 12 34 56 78" /></td>
                    </tr>
                </table>
                <?php endfor; ?>
            </div>

            <?php submit_button(); ?>
        </form>

        <script>
        jQuery(document).ready(function($) {
            // ---- Sélecteur de média pour le logo principal ----
            var mediaFrame;
            $('#wqg_upload_logo_btn').on('click', function(e) {
                e.preventDefault();
                if (mediaFrame) { mediaFrame.open(); return; }
                mediaFrame = wp.media({
                    title: 'Sélectionner le logo',
                    button: { text: 'Utiliser cette image' },
                    multiple: false,
                    library: { type: 'image' }
                });
                mediaFrame.on('select', function() {
                    var attachment = mediaFrame.state().get('selection').first().toJSON();
                    $('#wqg_company_logo').val(attachment.url);
                    $('#wqg_logo_preview_img').attr('src', attachment.url);
                    $('#wqg_logo_preview').show();
                    $('#wqg_remove_logo_btn').show();
                });
                mediaFrame.open();
            });
            $('#wqg_remove_logo_btn').on('click', function(e) {
                e.preventDefault();
                $('#wqg_company_logo').val('');
                $('#wqg_logo_preview').hide();
                $(this).hide();
            });

            // ---- Toggle section commerciaux ----
            $('#wqg_enable_sales_reps').on('change', function() {
                $('#wqg-sales-reps-section').toggle(this.checked);
            });
        });
        </script>
    </div>
    <?php
}
?>
