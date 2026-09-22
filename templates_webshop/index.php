<?php
header('Content-Type: text/html; charset=utf-8');
session_start();
require_once 'db.php';

// 1. Termékek beolvasása az SQLite adatbázisból (csak az aktív termékek)
$products = [];
$stmt_prods = $db->query("SELECT * FROM products WHERE status != 'inactive'");
if ($stmt_prods) {
    while ($row = $stmt_prods->fetch(PDO::FETCH_ASSOC)) {
        $sku = strtolower(trim($row['sku']));
        $row['sku'] = $sku;
        $row['group'] = $row['group_name'];
        $row['category_slug'] = !empty($row['category_slug']) ? $row['category_slug'] : seo_barat_url_php($row['group_name']);
        $row['product_slug'] = !empty($row['product_slug']) ? $row['product_slug'] : seo_barat_url_php($sku . '-' . ($row['name'] ?? ''));
        $row['leírás'] = $row['leiras'];
        $products[$sku] = $row;
    }
}

$all_categories = [];
foreach ($products as $t) {
    $cat = trim($t['group'] ?? '');
    // JAVÍTVA: Teljesen kis- és nagybetű független, tiszta kiszűrés
    $cat_lower = mb_strtolower($cat, 'UTF-8');
    if (empty($cat) || $cat_lower === 'ragasztás' || $cat_lower === 'ragasztas' || $cat_lower === 'egyéb' || $cat_lower === 'egyeb') { 
        continue; 
    }
    if (!in_array($cat, $all_categories)) { // JAVÍTVA: Visszaállítva a gyári in_array() függvényre!
        $all_categories[] = $cat;
    }
}

sort($all_categories);

$discount = isset($_SESSION['discount_percent']) ? (int)$_SESSION['discount_percent'] : 0;
$cart_count = 0;
if (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    $cart_count = array_sum($_SESSION['cart']);
}

$settings = [];
$stmt_set = $db->query("SELECT setting_key, setting_value FROM global_settings");
if ($stmt_set) {
    while ($row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}
$is_showcase = (($settings['showcase_mode'] ?? '0') === '1') || B2B_ONLY;

$desktop_contact = !empty($settings['webshop_desktop_contact_text']) ? $settings['webshop_desktop_contact_text'] : ($settings['contact_text'] ?? '');
$mobile_contact = !empty($settings['webshop_mobile_contact_text']) ? $settings['webshop_mobile_contact_text'] : ($settings['contact_text'] ?? '');
$email_text = !empty($settings['webshop_desktop_email_text']) ? $settings['webshop_desktop_email_text'] : 'info@variox.hu';

$double_row = ($settings['webshop_double_row_header'] ?? '0') === '1';

$top_align = $settings['webshop_header_top_align_desktop'] ?? 'justify';
$top_justify = ($top_align === 'left') ? 'flex-start' : (($top_align === 'center') ? 'center' : (($top_align === 'right') ? 'flex-end' : 'space-between'));

$bottom_align = $settings['webshop_header_bottom_align_desktop'] ?? 'justify';
$bottom_justify = ($bottom_align === 'left') ? 'flex-start' : (($bottom_align === 'center') ? 'center' : (($bottom_align === 'right') ? 'flex-end' : 'space-between'));

$align_desktop = $settings['webshop_desktop_header_align'] ?? 'left';
$justify_desktop = ($align_desktop === 'center') ? 'center' : (($align_desktop === 'right') ? 'flex-end' : (($align_desktop === 'justify') ? 'space-between' : 'flex-start'));

$align_mobile = $settings['webshop_mobile_header_align'] ?? 'justify';
$justify_mobile = ($align_mobile === 'center') ? 'center' : (($align_mobile === 'right') ? 'flex-end' : (($align_mobile === 'justify') ? 'space-between' : 'flex-start'));

// Szerkezeti láthatóságok állapotgépének előkészítése
$show_title_desktop = ($settings['webshop_desktop_show_title'] ?? '1') === '1';
$show_title_mobile = ($settings['webshop_mobile_show_title'] ?? '1') === '1';

$show_sub_desktop = ($settings['webshop_desktop_show_subtitle'] ?? '1') === '1';
$show_sub_mobile = ($settings['webshop_mobile_show_subtitle'] ?? '1') === '1';

$show_logo_desktop = ($settings['webshop_desktop_show_logo'] ?? '1') === '1';
$show_logo_mobile = ($settings['webshop_mobile_show_logo'] ?? '1') === '1';

$show_contact_desktop = ($settings['webshop_desktop_show_contact'] ?? '1') === '1';
$show_contact_mobile = ($settings['webshop_mobile_show_contact'] ?? '1') === '1';

$show_email_desktop = ($settings['webshop_desktop_show_email'] ?? '1') === '1';
$show_email_mobile = ($settings['webshop_mobile_show_email'] ?? '1') === '1';

$show_search_toggle_desktop = ($settings['webshop_desktop_show_search_toggle'] ?? '1') === '1';
$show_search_toggle_mobile = ($settings['webshop_mobile_show_search_toggle'] ?? '1') === '1';

$show_search_navbar_desktop = ($settings['webshop_desktop_show_search_navbar'] ?? '1') === '1';
$show_search_navbar_mobile = ($settings['webshop_mobile_show_search_navbar'] ?? '1') === '1';

// Asztali és mobil kereső beállítások betöltése (Biztonságos kisbetűsítéssel)
$dt_search_mode = strtolower(trim($settings['webshop_desktop_search_mode'] ?? 'default'));
$dt_search_pos = strtolower(trim($settings['webshop_desktop_search_position'] ?? 'navbar'));
$mb_search_mode = strtolower(trim($settings['webshop_mobile_search_mode'] ?? 'icon'));
$mb_search_pos = strtolower(trim($settings['webshop_mobile_search_position'] ?? 'navbar'));

// Ha a keresősáv fixen a fejléc alatt van, elrejtjük a gombot
if ($dt_search_mode === 'fixed' && $dt_search_pos === 'below') {
    $show_search_toggle_desktop = false;
}
if ($mb_search_mode === 'fixed' && $mb_search_pos === 'below') {
    $show_search_toggle_mobile = false;
}

// Fejléc alatti fix keresősávok állapotai
$show_search_below_desktop = $dt_search_mode === 'fixed' && $dt_search_pos === 'below';
$show_search_below_mobile = $mb_search_mode === 'fixed' && $mb_search_pos === 'below';

$below_class = '';
if (!$show_search_below_desktop) { $below_class .= ' ai-desktop-hidden'; }
if (!$show_search_below_mobile) { $below_class .= ' ai-mobile-hidden'; }
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    
<!-- JAVÍTVA: Dinamikus bázis URL a SEO-barát linkek relatív elérésének javítására -->

    <?php 
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $base_path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
    $base_url = $protocol . $_SERVER['HTTP_HOST'] . $base_path . "/";
    ?>
    <base href="<?= $base_url ?>">

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['webshop_title'] ?? 'Szalay Barkács') ?></title>
    
    <link rel="stylesheet" href="static/base_layout.css?v=1">
    
    <!-- CSS Téma betöltése -->
    <?php 
    $active_theme = $settings['active_theme'] ?? 'custom';
    if ($active_theme === 'custom' && file_exists(__DIR__ . "/static/custom.css")): ?>
        <link rel="stylesheet" href="static/custom.css?v=<?= time() ?>">
    <?php elseif (file_exists(__DIR__ . "/static/themes/{$active_theme}.css")): ?>
        <link rel="stylesheet" href="static/themes/<?= $active_theme ?>.css?v=<?= time() ?>">
    <?php else: ?>
        [[CSS_REFERENCE]]
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <?php     
    /* elszinezes a fejlec felett, csak mobil nezetben*/
    // Alapértelmezett szín 
     $theme_color = '#ffffff'; 
    if ($active_theme == 'dark_thinheader') {
        $theme_color = '#38bdf8';
    } 
    ?>
    <meta name="theme-color" content="<?php echo htmlspecialchars($theme_color); ?>">
    
    
    <?php 
    $h_font = $settings['header_font'] ?? 'Arial';
    if (!in_array(strtolower($h_font), ["arial", "georgia", "impact", "times new roman", "trebuchet ms", "verdana", "serif", "sans-serif", "monospace"])) {
        $font_query = str_replace(' ', '+', $h_font);
        echo '<link rel="preconnect" href="https://fonts.googleapis.com">';
        echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        echo '<link href="https://fonts.googleapis.com/css2?family=' . $font_query . ':ital,wght@0,300;0,400;0,700;0,900;1,300;1,400;1,700;1,900&display=swap" rel="stylesheet">';
    }
    ?>

    <style>
        :root {
            --header-bg: <?= !empty($settings['header_bg']) ? $settings['header_bg'] : 'linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%)' ?>;
            --header-text-color: <?= $settings['header_text_color'] ?? '#ffffff' ?>;
            --header-accent-color: <?= $settings['header_accent_color'] ?? '#3b82f6' ?>;
            --webshop-title-size: <?= ($settings['webshop_desktop_title_size'] ?? 24) . 'px' ?>;
            --webshop-title-color: <?= $settings['webshop_desktop_title_color'] ?? '#ffffff' ?>;
            --webshop-subtitle-size: <?= ($settings['webshop_desktop_subtitle_size'] ?? 12) . 'px' ?>;
            --webshop-subtitle-color: <?= $settings['webshop_desktop_subtitle_color'] ?? '#cbd5e1' ?>;
            --webshop-contact-color: <?= $settings['webshop_desktop_contact_color'] ?? '#94a3b8' ?>;
            
            --menu-size-desktop: <?= ($settings['webshop_desktop_menu_size'] ?? 12) . 'px' ?>;
            --menu-size-mobile: <?= ($settings['webshop_mobile_menu_size'] ?? 11) . 'px' ?>;
            
            --card-bg: <?= $settings['webshop_card_bg'] ?? '#ffffff' ?>;
            --card-title-color: <?= $settings['webshop_card_title_color'] ?? '#1e293b' ?>;

            --logo-scale: <?= (int)($settings['logo_scale'] ?? 100) / 100 ?>;
            --user-badge-size: <?= (int)($settings['webshop_user_badge_size'] ?? 28) ?>px;
            --user-badge-mobile-size: <?= (int)($settings['webshop_user_badge_mobile_size'] ?? 22) ?>px;
            
            --justify-top-desktop: <?= $top_justify ?>;
            --justify-bottom-desktop: <?= $bottom_justify ?>;
            --brand-text-margin-right: <?= ($top_align === 'justify') ? 'auto' : 'normal' ?>;
            --webshop-contact-size: <?= ($settings['webshop_desktop_contact_size'] ?? 12) . 'px' ?>;

                   /* Felugró számláló kosár ablak*/
            --sheet-bg-color: #ffffff;
            --sheet-text-color: #1e293b;
            --sheet-border-color: #cbd5e1;
            --sheet-btn-bg: #f8fafc;
            --sheet-cancel-bg: #f1f5f9;
    }

        .hidden-cell { display: none !important; }
        .hidden-section { display: none !important; }
        .navbar-search-fixed { display: none !important; }
        .search-toggle-btn { display: none !important; }

        
        /* Valamint ellenőrizd, hogy a .promo-cell szabályok megkapták-e a változót: */
        .promo-cell {
            background-color: var(--card-bg, #ffffff);
        }
        .promo-cell .title {
            color: var(--card-title-color, #1e293b);
        }
       
       
        .webshop-navbar {
            position: relative !important; 
            background: var(--header-bg) !important;
            color: var(--header-text-color) !important;
            font-family: '<?= htmlspecialchars($h_font) ?>', sans-serif !important;
            min-height: <?= (int)($settings['webshop_header_height_px'] ?? 64) ?>px !important;
            gap: <?= (int)($settings['webshop_header_gap'] ?? 15) ?>px !important;
            padding: 12px <?= (int)($settings['webshop_header_padding_side'] ?? 20) ?>px !important;
            border-radius: 8px !important;
            margin-bottom: 20px !important;
            
            <?php if (($settings['webshop_sticky_navbar'] ?? '0') === '1'): ?>
                position: sticky !important;
                top: 0 !important;
                z-index: 1000 !important;
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.08) !important;
            <?php endif; ?>
        }

        .webshop-title {
            font-family: '<?= htmlspecialchars($h_font) ?>', sans-serif !important;
            font-style: <?= ($settings['webshop_header_font_italic'] ?? '0') === '1' ? 'italic' : 'normal' ?> !important;
            text-transform: <?= ($settings['webshop_header_font_uppercase'] ?? '0') === '1' ? 'uppercase' : 'none' ?> !important;
            font-weight: <?= htmlspecialchars($settings['webshop_header_font_weight'] ?? '700') ?> !important;
        }
        
        .webshop-nav-links a {
            font-family: '<?= htmlspecialchars($h_font) ?>', sans-serif !important;
            font-style: <?= ($settings['webshop_header_font_italic'] ?? '0') === '1' ? 'italic' : 'normal' ?> !important;
            text-transform: <?= ($settings['webshop_header_font_uppercase'] ?? '0') === '1' ? 'uppercase' : 'none' ?> !important;
            font-weight: <?= htmlspecialchars($settings['webshop_header_font_weight'] ?? '700') ?> !important;
        }

        .navbar-row-top { 
            display: flex; 
            align-items: center; 
            padding: 4px <?= (int)($settings['webshop_header_top_padding'] ?? 20) ?>px !important; 
        }
        .navbar-row-bottom { 
            display: flex; 
            align-items: center; 
            padding: 4px <?= (int)($settings['webshop_header_bottom_padding'] ?? 20) ?>px !important; 
        }

        /* =============================================================
           1. ASZTALI NÉZET REZSPONZÍV SZABÁLYAI (100% ELKÜLÖNÍTVE)
           ============================================================= */
        @media screen and (min-width: 1025px) {
            body .webshop-navbar .logo-wrapper { display: <?= $show_logo_desktop ? 'flex' : 'none' ?> !important; }
            body .webshop-navbar .brand-text .webshop-title { display: <?= $show_title_desktop ? 'inline-block' : 'none' ?> !important; }
            body .webshop-navbar .brand-text .webshop-subtitle { display: <?= $show_sub_desktop ? 'block' : 'none' ?> !important; }
            body .webshop-navbar .desktop-only-contact .webshop-contact-phone { display: <?= $show_contact_desktop ? 'inline-block' : 'none' ?> !important; }
            body .webshop-navbar .desktop-only-contact .webshop-contact-email { display: <?= $show_email_desktop ? 'inline-block' : 'none' ?> !important; }

            body .webshop-navbar .search-toggle-btn { 
                display: <?= $show_search_toggle_desktop ? 'inline-flex' : 'none' ?> !important; 
                background: none !important;
                border: none !important;
                box-shadow: none !important;
                padding: 4px 8px !important;
                margin: 0 !important;
                font-size: var(--menu-size-desktop, 12px) !important;
                font-family: '<?= htmlspecialchars($h_font) ?>', sans-serif !important;
                font-style: <?= ($settings['webshop_header_font_italic'] ?? '0') === '1' ? 'italic' : 'normal' ?> !important;
                text-transform: <?= ($settings['webshop_header_font_uppercase'] ?? '0') === '1' ? 'uppercase' : 'none' ?> !important;
                font-weight: <?= htmlspecialchars($settings['webshop_header_font_weight'] ?? '700') ?> !important;
                color: var(--header-text-color, #ffffff) !important;
                cursor: pointer !important;
                align-items: center !important;
                gap: 6px !important;
            }
            body .webshop-navbar .search-toggle-btn:hover {
                color: var(--header-accent-color, #3b82f6) !important;
            }

            body .webshop-navbar .mobile-only-contact { display: none !important; }
            
            body .webshop-navbar .desktop-only-contact { 
                display: flex !important; 
                flex-direction: column !important;
                align-items: flex-start !important; 
                gap: 4px !important;
            }

            body .webshop-navbar .dropdown-content {
                display: grid !important;
                padding: 15px !important;
                box-sizing: border-box !important;
                gap: 12px 20px !important;
            }
            body .webshop-navbar .dropdown-content.three-columns {
                grid-template-columns: repeat(3, minmax(170px, 1fr)) !important;
                width: 580px !important;
                max-width: 650px !important;
            }
            body .webshop-navbar .dropdown-content.two-columns {
                grid-template-columns: repeat(2, minmax(160px, 1fr)) !important;
                width: 380px !important;
            }
            body .webshop-navbar .dropdown-content a {
                word-break: keep-all !important;
                word-wrap: normal !important;
                display: block !important;
                padding: 8px 8px !important; /* 12px helyett 8px: több hely a szövegnek */
                font-size: 12px !important;  /* 13px helyett 12px: elegánsabb megjelenés */
                font-weight: bold !important;
                text-align: center !important;
                white-space: nowrap !important; /* Ne törje ketté a szót */
                overflow: hidden !important;
                text-overflow: ellipsis !important; /* Ha mégis túl hosszú lenne, nem lóg ki */
                box-sizing: border-box !important;
            }

            .ai-desktop-hidden { display: none !important; }

            <?php if ($dt_search_pos === 'below' && $dt_search_mode === 'fixed'): ?>
                body .webshop-search-below-container { display: block !important; }
            <?php elseif ($dt_search_pos === 'below' && $dt_search_mode !== 'fixed'): ?>
                body .webshop-search-below-container { display: none !important; }
                body .webshop-search-below-container.search-active { display: block !important; }
            <?php else: ?>
                body .webshop-search-below-container { display: none !important; }
            <?php endif; ?>

            <?php if ($dt_search_pos === 'navbar' && $dt_search_mode === 'fixed'): ?>
                body .webshop-navbar .navbar-search-fixed { display: block !important; }
            <?php elseif ($dt_search_pos === 'navbar' && $dt_search_mode !== 'fixed'): ?>
                body .webshop-navbar .navbar-search-fixed { display: none !important; }
                body .webshop-navbar .navbar-search-fixed.search-active {
                    display: block !important;
                    position: absolute !important;
                    left: 50% !important;
                    transform: translateX(-50%) !important;
                    top: calc(100% + 5px) !important;
                    z-index: 10000 !important;
                    background: var(--header-bg) !important;
                    padding: 8px !important;
                    border-radius: 8px !important;
                    box-shadow: 0 10px 25px rgba(0,0,0,0.2) !important;
                    border: 1px solid rgba(255,255,255,0.1) !important;
                }
                body .webshop-navbar .navbar-search-fixed.search-active input {
                    width: 240px !important;
                    padding: 8px 12px !important;
                    border-radius: 20px !important;
                    border: 1px solid #cbd5e1 !important;
                    font-size: 14px !important;
                    outline: none !important;
                    color: #333 !important;
                    background: #fff !important;
                    box-sizing: border-box !important;
                }
            <?php else: ?>
                body .webshop-navbar .navbar-search-fixed { display: none !important; }
            <?php endif; ?>

            .navbar-row-top { 
                justify-content: var(--justify-top-desktop) !important; 
            }
            .navbar-row-bottom { 
                justify-content: var(--justify-bottom-desktop) !important; 
            }
            
            .brand-text {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                justify-content: center !important;
                flex-grow: <?= ($top_align === 'justify') ? '1' : '0' ?> !important;
            }
            
            .webshop-nav-links {
                display: flex !important;
                flex-wrap: wrap !important;
                align-items: center !important; 
                justify-content: var(--justify-bottom-desktop) !important;
                width: 100% !important;
                gap: 15px !important;
            }
        }

        /* =============================================================
           2. MOBIL NÉZET REZSPONZÍV SZABÁLYAI (100% ELKÜLÖNÍTVE)
           ============================================================= */
        @media screen and (max-width: 1024px) {
            body .webshop-navbar .logo-wrapper { display: <?= $show_logo_mobile ? 'flex' : 'none' ?> !important; }
            body .webshop-navbar .brand-text .webshop-title { display: <?= $show_title_mobile ? 'inline-block' : 'none' ?> !important; }
            body .webshop-navbar .brand-text .webshop-subtitle { display: <?= $show_sub_mobile ? 'block' : 'none' ?> !important; }
            body .webshop-navbar .mobile-only-contact .webshop-contact-phone { display: <?= $show_contact_mobile ? 'inline-block' : 'none' ?> !important; }
            body .webshop-navbar .mobile-only-contact .webshop-contact-email { display: <?= $show_email_mobile ? 'inline-block' : 'none' ?> !important; }
            body .webshop-navbar .search-toggle-btn { display: <?= $show_search_toggle_mobile ? 'inline-flex' : 'none' ?> !important; }

            body .webshop-navbar .desktop-only-contact { display: none !important; }
            body .webshop-navbar .mobile-only-contact { display: inline-block !important; }

            <?php if ($mb_search_mode === 'icon'): ?>
                body .webshop-navbar .search-toggle-btn .search-text { display: none !important; }
            <?php endif; ?>

            body .category-nav a,
            body .category-nav-wrapper a,
            body .category-nav .nav-item,
            body .category-nav-wrapper .nav-item,
            body .nav-item {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 6px 14px !important;
                border-radius: 20px !important; 
                background-color: rgba(255, 255, 255, 0.08) !important;
                color: var(--header-text-color, #ffffff) !important;
                font-size: 12px !important;
                font-weight: bold !important;
                text-decoration: none !important;
                border: 1px solid rgba(255, 255, 255, 0.15) !important;
                transition: all 0.2s ease !important;
                margin-right: 6px !important;
                box-sizing: border-box !important;
            }
            body .category-nav a.active,
            body .category-nav-wrapper a.active,
            body .category-nav .nav-item.active,
            body .category-nav-wrapper .nav-item.active,
            body .nav-item.active {
                background-color: var(--header-accent-color, #3b82f6) !important;
                color: #ffffff !important;
                border-color: var(--header-accent-color, #3b82f6) !important;
                box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2) !important;
            }

            .ai-mobile-hidden { display: none !important; }

            body .webshop-search-below-container {
                margin-top: 25px !important;
                margin-bottom: 25px !important;
            }

            <?php if ($mb_search_pos === 'below' && $mb_search_mode === 'fixed'): ?>
                body .webshop-search-below-container { display: block !important; }
            <?php elseif ($mb_search_pos === 'below' && $mb_search_mode !== 'fixed'): ?>
                body .webshop-search-below-container { display: none !important; }
                body .webshop-search-below-container.search-active { display: block !important; }
            <?php else: ?>
                body .webshop-search-below-container { display: none !important; }
            <?php endif; ?>

            <?php if ($mb_search_pos === 'navbar' && $mb_search_mode === 'fixed'): ?>
                body .webshop-navbar .navbar-search-fixed { display: block !important; }
            <?php elseif ($mb_search_pos === 'navbar' && $mb_search_mode !== 'fixed'): ?>
                body .webshop-navbar .navbar-search-fixed { display: none !important; }
                body .webshop-navbar .navbar-search-fixed.search-active {
                    display: block !important;
                    position: absolute !important;
                    left: 50% !important;
                    transform: translateX(-50%) !important;
                    top: calc(100% + 5px) !important;
                    z-index: 10000 !important;
                    background: var(--header-bg) !important;
                    padding: 8px !important;
                    border-radius: 8px !important;
                    box-shadow: 0 10px 25px rgba(0,0,0,0.2) !important;
                    border: 1px solid rgba(255,255,255,0.1) !important;
                }
                body .webshop-navbar .navbar-search-fixed.search-active input {
                    width: 240px !important;
                    padding: 8px 12px !important;
                    border-radius: 20px !important;
                    border: 1px solid #cbd5e1 !important;
                    color: #333 !important;
                    background: #fff !important;
                    box-sizing: border-box !important;
                }
            <?php else: ?>
                body .webshop-navbar .navbar-search-fixed { display: none !important; }
            <?php endif; ?>

            body .webshop-navbar .navbar-row-top { 
                justify-content: space-between !important; 
                flex-wrap: nowrap !important;
                padding: 2px 0 !important;
                width: 100% !important;
            }
            .navbar-row-bottom { 
                justify-content: var(--justify-mobile) !important; 
                flex-wrap: wrap !important;
                padding: 2px 0 !important;
                border-top: none !important;
            }
            
            .brand-text {
                display: flex !important;
                flex-direction: column !important;
                align-items: flex-start !important;
                flex-grow: <?= ($align_mobile === 'justify') ? '1' : '0' ?> !important;
            }
            
            .webshop-nav-links {
                display: flex !important;
                flex-wrap: wrap !important;
                align-items: center !important;
                justify-content: var(--justify-mobile) !important;
                width: 100% !important;
                gap: 10px !important;
            }
            
            .logo-wrapper {
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                max-height: 35px !important;
                margin-bottom: 2px !important;
            }
            .logo-wrapper img {
                height: 30px !important;
                width: auto !important;
                max-width: 110px !important;
                transform: none !important;
                object-fit: contain !important;
            }
            
            .webshop-title { font-size: <?= (int)($settings['webshop_mobile_title_size'] ?? 16) ?>px !important; }
            .brand-text .webshop-subtitle { font-size: <?= (int)($settings['webshop_mobile_subtitle_size'] ?? 10) ?>px !important; }
            .mobile-only-contact { font-size: <?= (int)($settings['webshop_mobile_contact_size'] ?? 11) ?>px !important; }
            .webshop-nav-links { gap: <?= (int)($settings['webshop_mobile_menu_gap'] ?? 10) ?>px !important; }
        }

        /* =============================================================
           3. UNIVERZÁLIS SZERKEZETI (LAYOUT) CSS SZABÁLYOK (MINDEN TÉMÁHOZ)
           ============================================================= */
        body .webshop-product-grid-container .promo-cell {
            display: flex !important;
            flex-direction: column !important;
            overflow: hidden !important;
            position: relative !important;
            height: 100% !important;
        }
        body .webshop-product-grid-container .image-container {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            position: relative !important;
            height: 120px !important;
        }
        body .webshop-product-grid-container .content {
            display: flex !important;
            flex-direction: column !important;
            gap: 6px !important;
            padding: 12px !important;
            flex-grow: 1 !important;
        }
        body .webshop-product-grid-container .csoport {
            display: block !important;
            font-size: 8px !important;
            text-transform: uppercase !important;
            font-weight: bold !important;
            letter-spacing: 0.5px !important;
        }
        body .webshop-product-grid-container .sku {
            display: block !important;
            font-size: 8px !important;
            color: #94a3b8 !important;
            margin-bottom: 4px !important;
        }
        body .webshop-product-grid-container .title {
            display: block !important;
            font-size: 11px !important;
            font-weight: 600 !important;
            line-height: 1.3 !important;
            height: 32px !important;
            overflow: hidden !important;
        }
        body .webshop-product-grid-container .price-section {
            display: flex !important;
            justify-content: flex-end !important;
            align-items: baseline !important;
            gap: 5px !important;
            margin-top: auto !important;
            padding: 4px !important;
            border-radius: 4px !important;
        }
        body .webshop-product-grid-container .btn-cart {
            display: block !important;
            width: 100% !important;
            border: none !important;
            cursor: pointer !important;
            font-weight: bold !important;
            padding: 8px !important;
            font-size: 11px !important;
            margin-top: 10px !important;
            border-radius: 6px !important;
        }
    </style>
</head>
<body>
    <div class="container">
       
        <input type="checkbox" id="search-toggle-checkbox" style="position: absolute !important; opacity: 0 !important; width: 0 !important; height: 0 !important; overflow: hidden !important; pointer-events: none !important; z-index: -1 !important;">
        
        <div class="webshop-navbar">
            <div class="navbar-row-top">
            <!-- ÚJ (OKOS, BÁRMILYEN FORMÁTUMÚ LOGÓT FELISMERŐ KÓD): -->
                <?php 
                $logo_file = '';
                $possible_logos = ['logo.webp', 'logo.png', 'logo.svg', 'logo.jpg', 'logo.jpeg'];
                foreach ($possible_logos as $pl) {
                    if (file_exists(__DIR__ . '/static/images/' . $pl)) {
                        $logo_file = $pl;
                        break;
                    }
                }
                ?>
                <?php if (!empty($logo_file)): ?>
                    <a href="index.php" class="logo-wrapper"><img src="static/images/<?= $logo_file ?>" alt="Logo"></a>
                <?php endif; ?>
                
                <div class="brand-text">
                    <?php if (($settings['webshop_desktop_use_title_image'] ?? '0') === '1' && !empty($settings['webshop_desktop_title_image']) && file_exists('static/images/' . $settings['webshop_desktop_title_image'])): ?>
                        <img src="static/images/<?= htmlspecialchars($settings['webshop_desktop_title_image']) ?>" class="webshop-title-image" alt="Főcím kép">
                        <span class="webshop-title desktop-hidden"><?= htmlspecialchars($settings['webshop_title'] ?? 'Szalay Barkács') ?></span>
                    <?php else: ?>
                        <span class="webshop-title"><?= htmlspecialchars($settings['webshop_title'] ?? 'Szalay Barkács') ?></span>
                    <?php endif; ?>
                    
                    <span class="webshop-subtitle">
                        <?= htmlspecialchars($settings['webshop_subtitle'] ?? 'Minőségi szerszámok és kiegészítők') ?>
                    </span>
                </div>
                
                <!-- ASZTALI ÉS MOBIL KAPCSOLATOK -->
                <div class="desktop-only-contact">
                    <span class="webshop-contact webshop-contact-phone" style="margin-bottom: 2px;"><i class="fa fa-phone" style="margin-right:5px;"></i><?= htmlspecialchars($desktop_contact) ?></span>
                    <span class="webshop-contact webshop-contact-email"><i class="fa fa-envelope" style="margin-right:5px;"></i><?= htmlspecialchars($email_text) ?></span>
                </div>
                
                <span class="webshop-contact mobile-only-contact">
                    <span class="webshop-contact-phone"><i class="fa fa-phone"></i> <?= htmlspecialchars($mobile_contact) ?></span>
                    <span class="webshop-contact-email" style="margin-left: 8px;"><i class="fa fa-envelope"></i> <?= htmlspecialchars($email_text) ?></span>
                </span>
            </div>
            
           
            
            <div class="navbar-row-bottom">
                <div class="navbar-search-fixed">
                    <input type="text" id="webshop-search-input-navbar" onkeyup="filterProducts()" placeholder="Keresés...">
                </div>

                <div class="webshop-nav-links">
             
                <!-- JAVÍTVA: Blog link kiemelése ikonnal és egyedi osztállyal -->
                <?php 
                $enable_blog = ($settings['enable_blog'] ?? '0') === '1';
                if ($enable_blog && file_exists("blog.php")): 
                ?>
                    <a href="blog.php" class="products-menu-link blog-menu-link"><i class="fa fa-blog"></i> <?= htmlspecialchars($settings['blog_menu_name'] ?? 'Blog') ?></a>
                <?php endif; ?> 
                 
                <label class="accent-link search-toggle-btn" onclick="toggleSearchOverlay(event)" style="cursor: pointer; display: inline-flex; align-items: center;">
                        <span class="search-icon">&#128269;</span>
                        <span class="search-text">Keresés</span>
                    </label>
                    
<!-- JAVÍTVA: "Termékek" link - Teljesen különálló, függetlenül ki- és bekapcsolható -->
                    <?php if (($settings['webshop_show_products_menu'] ?? '1') === '1'): ?>
                        <a href="index.php" class="products-menu-link"><i class="fa fa-boxes"></i> Termékek</a>
                    <?php endif; ?>

                    <!-- JAVÍTVA: "Dropdown" kategória menü (☰ Kategóriák) - Teljesen különálló, függetlenül ki- és bekapcsolható, mobilon eleve nem jelenik meg -->
                    <?php if (($settings['webshop_show_categories_dropdown'] ?? '0') === '1'): ?>
                        <?php 
                        $h_style = $settings['webshop_hamburger_style'] ?? 'text_icon';
                        $trigger_type = $settings['webshop_hamburger_trigger'] ?? 'hover';
                        ?>
                        <div class="dropdown-category <?= $trigger_type === 'click' ? 'click-trigger' : 'hover-trigger' ?>">
                            <a href="index.php" class="dropdown-trigger <?= $h_style === 'pill' ? 'hamburger-pill' : '' ?>" onclick="toggleHamburgerMenu(event, '<?= $trigger_type ?>')">
                                <span>☰ Kategóriák</span>
                            </a>
                            <?php
                            $dropdown_class = '';
                            if (count($all_categories) > 6) { $dropdown_class = 'three-columns'; } 
                            elseif (count($all_categories) > 3) { $dropdown_class = 'two-columns'; }
                            ?>

                            <div class="dropdown-content <?= $dropdown_class ?>">
                                <a href="index.php" onclick="selectCategoryFromMenu('all', event)">Összes termék</a>
                                <?php foreach ($all_categories as $cat): ?>
                                    <a href="kategoria/<?= htmlspecialchars(seo_barat_url_php($cat)) ?>" onclick="selectCategoryFromMenu('<?= htmlspecialchars(seo_barat_url_php($cat), ENT_QUOTES) ?>', event)"><?= htmlspecialchars($cat) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- JAVÍTVA: Kosár ikon és struktúra a tökéletesen kerek jelvényért -->
                    <?php if (!$is_showcase): ?>
                        <a href="cart.php" class="navbar-cart-link">
                            <i class="fa fa-shopping-cart"></i> 
                            <span class="cart-text">Kosár</span> 
                            <?php if ($cart_count > 0): ?>
                                <span class="cart-badge"><?= $cart_count ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="profile.php" class="user-profile-badge">
                            <span class="user-avatar-circle">
                                <svg xmlns="http://w3.org" viewBox="20 15 60 60" width="100%" height="100%">
                                    <path d="M45 62h10v10H45z" fill="#D49973" />
                                    <path d="M22 75c8-5 16-6 28-6s20 1 28 6c0 10-4 18-28 18S22 85 22 75z" fill="#2D3748" />
                                    <path d="M45 69l5 6 5-6z" fill="#E2A983" />
                                    <circle cx="50" cy="45" r="21" fill="#F3BA95" />
                                    <path d="M29 42c0-12 10-21 21-21s21 9 21 21c0 2-3-2-5-4s-9-4-16-4-12 2-14 4-7 4-7 4z" fill="#1A202C" />
                                 </svg>
                            </span>
                            <div class="user-badge-text">
                                <span class="badge-label">Fiókom</span>
                                <span class="badge-name"><?= htmlspecialchars($_SESSION['username'] ?? '') ?></span>
                            </div>
                        </a>
                        <?php if ($_SESSION['is_admin']): ?>
                                <?php 
                                // JAVÍTVA: Intelligens útvonal-választó az Admin gombhoz
                                $admin_url = "admin.php";
                                if (has_permission('orders')) { $admin_url = "admin.php"; }
                                elseif (has_permission('products')) { $admin_url = "admin_products.php"; }
                                elseif (has_permission('users')) { $admin_url = "admin_users.php"; }
                                elseif (has_permission('blog')) { $admin_url = "blog.php"; }
                                elseif (has_permission('files')) { $admin_url = "admin_files.php"; }
                                elseif (has_permission('theme')) { $admin_theme_url = "admin_theme.php"; $admin_url = $admin_theme_url; }
                                elseif (has_permission('ai')) { $admin_url = "admin_ai.php"; }
                                elseif (has_permission('settings')) { $admin_url = "admin_settings.php"; }
                                ?>
                                <a href="<?= $admin_url ?>" class="accent-link" style="font-weight: bold;"><i class="fa fa-user-shield"></i> Admin</a>
                            <?php endif; ?>
                            <!-- JAVÍTVA: Kijelentkezés helyett rövidebb "Kilépés" felirat, ikonnal -->
                            <a href="logout.php"><i class="fa fa-sign-out-alt"></i> Kilépés</a>
                        <?php else: ?>
                        <!-- JAVÍTVA: Belépés és Regisztráció ikonokkal -->
                        <a href="login.php"><i class="fa fa-sign-in-alt"></i> Belépés</a>
                        <a href="register.php"><i class="fa fa-user-plus"></i> Regisztráció</a>
                    <?php endif; ?>
                </div>
            </div>
   
        </div>

        [[PHP_FLASH_HTML]]
        [[CATEGORY_NAV_HTML]]

        <!-- DINAMIKUS FEJLÉC ALATTI KERESŐSÁV -->
        <div class="webshop-search-below-container<?= $below_class ?>">
            <div style="display: flex; gap: 8px; max-width: 500px; margin: 0 auto; width: 100%; position: relative;">
                <input type="text" id="webshop-search-input-below" onkeyup="filterProducts()" placeholder="Keresés a termékek között..." style="flex: 1; padding: 10px 15px; border: 1px solid #cbd5e1; border-radius: 25px; outline: none; font-size: 14px; box-sizing: border-box;">
                <button type="button" id="search-clear-btn" onclick="clearSearch()" style="display: none; background: none; border: none; font-size: 18px; cursor: pointer; color: #94a3b8; position: absolute; right: 15px; top: 50%; transform: translateY(-50%);">&times;</button>
            </div>
        </div>
  
        
            <!-- index.php -> Landing Page betöltő blokk: -->
        <?php 
        $enable_landing = ($settings['webshop_enable_landing_page'] ?? '0') === '1';
        $active_theme = $settings['active_theme'] ?? 'custom';
        $theme_landing_file = "static/themes/landings/landing_{$active_theme}.php";

        // JAVÍTVA: Nem töltjük be a főoldalt, ha előnézet módban vagyunk (!isset($_GET['preview']))
        if ($enable_landing && file_exists($theme_landing_file) && !isset($_GET['preview'])) {
            include $theme_landing_file;
        }
        ?>

        <!-- DINAMIKUS TERMÉKRÁCS GENERÁLÁS -->
        <div class="webshop-product-grid-container" style="margin-top: 20px;">
            <?php
     
            // Csoportosítsuk a termékeket kategóriák szerint, mint a katalógusban
            $grouped_products = [];
            foreach ($products as $sku => $p) {
                $cat = trim($p['group'] ?? '');
                $cat_lower = mb_strtolower($cat, 'UTF-8');
                if (empty($cat) || $cat_lower === 'ragasztás' || $cat_lower === 'ragasztas' || $cat_lower === 'egyéb' || $cat_lower === 'egyeb') {
                    continue;
                }
                $grouped_products[$cat][$sku] = $p;
            }

            ksort($grouped_products);
            
            // JAVÍTVA: Dinamikus, adminisztrációs panelről szabályozható termékrendezés!
            $sort_method = $settings['webshop_product_sorting'] ?? 'name_asc';
            foreach ($grouped_products as $cat => &$items) {
                if ($sort_method === 'name_asc') {
                    uasort($items, function($a, $b) {
                        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
                    });
                } elseif ($sort_method === 'sku_asc') {
                    uasort($items, function($a, $b) {
                        return strcasecmp($a['sku'] ?? '', $b['sku'] ?? '');
                    });
                } elseif ($sort_method === 'price_asc') {
                    uasort($items, function($a, $b) {
                        return (int)($a['price'] ?? 0) - (int)($b['price'] ?? 0);
                    });
                } elseif ($sort_method === 'price_desc') {
                    uasort($items, function($a, $b) {
                        return (int)($b['price'] ?? 0) - (int)($a['price'] ?? 0);
                    });
                }
            }
            unset($items); // Referencia törlése

            foreach ($grouped_products as $category_name => $cat_products):
                     $first_p = reset($cat_products);
                     $cat_slug = $first_p['category_slug'] ?? seo_barat_url_php($category_name);
            
            ?>
                <div class="group-section" data-category="<?= htmlspecialchars(strtolower($category_name)) ?>" data-slug="<?= htmlspecialchars($cat_slug) ?>" style="margin-bottom: 30px;">
                    <h3 class="group-title" style="font-size: 1.3em; color: var(--header-bg); border-bottom: 2px solid var(--header-bg); padding-bottom: 5px; margin-bottom: 15px; text-transform: uppercase; font-weight: bold;"><?= htmlspecialchars($category_name) ?></h3>
                    
                    <div class="promo-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
                        
                        <?php 
                foreach ($cat_products as $sku => $p): 
                            $status = $p['status'] ?? 'standard';
                            $has_desc = !empty($p['leírás']) || !empty($p['leiras']) || !empty($p['description']);

                            $show_no_image = ($settings['webshop_show_no_image_products'] ?? '1') === '1';
                            $img_file = $p['image'] ?? '';
                            $has_image = !empty($img_file) && file_exists(__DIR__ . '/static/images/' . $img_file);
                            
                            if (!$show_no_image && !$has_image) {
                                continue;
                            }

                            $final_price = get_product_price_for_user($p, $_SESSION['user_id'] ?? 0, $discount);
                            
                            // ÚJ: Szép kulcsszavas SEO-link előállítása
                            $product_link = 'termek/' . htmlspecialchars($p['product_slug'] ?? $sku);
                        ?>
                           <div class="promo-cell" data-name="<?= htmlspecialchars(strtolower($p['name'] ?? '')) ?>" data-sku="<?= htmlspecialchars(strtolower($sku)) ?>" data-category="<?= htmlspecialchars(strtolower($category_name)) ?>" data-slug="<?= htmlspecialchars($cat_slug) ?>" data-type="<?= htmlspecialchars(strtolower($p['type'] ?? '')) ?>" data-description="<?= htmlspecialchars(strtolower($p['leírás'] ?? $p['leiras'] ?? '')) ?>">
                                
                                <!-- Termékkép -->
                                <div class="image-container">
                                    <?php if ($status === 'no_stock'): ?>
                                        <div class="card-badge badge-no-stock">HIÁNY</div>
                                    <?php elseif ($status === 'low_stock'): ?>
                                        <div class="card-badge" style="background:#f59e0b; color:white; font-size:8px; padding:2px 4px; border-radius:4px; font-weight:bold; position:absolute; top:5px; left:5px; z-index:10;">KEVÉS</div>
                                    <?php elseif ($status === 'promo'): ?>
                                        <div class="card-badge" style="background:#e11d48; color:white; font-size:8px; padding:2px 4px; border-radius:4px; font-weight:bold; position:absolute; top:5px; left:5px; z-index:10;">AKCIÓ</div>
                                    <?php endif; ?>

                                    <?php 
                                    $img_file = $p['image'] ?? '';
                                    $img_src = '';
                                    if (!empty($img_file) && file_exists(__DIR__ . '/static/images/' . $img_file)) {
                                        $img_src = 'static/images/' . $img_file;
                                    }
                                    ?>
                                  
                                    <?php if ($has_desc): ?>
                                        <!-- 1. JAVÍTOTT LINK: A termékkép a szép SEO URL-re mutat -->
                                        <a href="<?= $product_link ?>" style="display:contents;">
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($img_src)): ?>
                                        <img src="<?= htmlspecialchars($img_src) ?>" alt="">
                                    <?php else: ?>
                                        <i class="fa fa-paint-brush no-img"></i>
                                    <?php endif; ?>

                                    <?php if ($has_desc): ?>
                                        </a>
                                    <?php endif; ?>
                                </div>

                                <!-- Termék adatok -->
                                <div class="content">
                                    <span class="csoport"><?= htmlspecialchars($category_name) ?></span>
                                    <span class="sku">Cikkszám: <?= htmlspecialchars($sku) ?></span>
                                    
                                    <?php if ($has_desc): ?>
                                        <!-- 2. JAVÍTOTT LINK: A terméknév linkje is a szép SEO URL-re mutat -->
                                        <a href="<?= $product_link ?>" style="text-decoration:none; color:inherit;" class="product-title-link">
                                    <?php endif; ?>

                                    <span class="title"><?= htmlspecialchars($p['name'] ?? '') ?></span>
                                    <?php if ($has_desc): ?>
                                        </a>
                                    <?php endif; ?>

<!-- ÁR SZEKCIÓ -->
                                    <?php 
                                    $hide_prices_anon = ($settings['hide_prices_anon'] ?? '0') === '1';
                                    $is_showcase_active = ($settings['showcase_mode'] ?? '0') === '1';
                                    
                                    // Az árat csak akkor mutatjuk, ha nincs bemutató mód ÉS (vagy nem kell elrejteni az árakat, vagy be van lépve a felhasználó)
                                    $show_price = !$is_showcase_active && (!$hide_prices_anon || isset($_SESSION['user_id']));
                                    
                                    if ($show_price): 
                                        $base_price = (int)($p['price'] ?? 0);
                                        $old_price = (isset($p['old_price']) && $p['old_price'] !== null) ? (int)$p['old_price'] : 0;
                                        
                                        // Meghatározzuk, melyik árat kell áthúzottként megjeleníteni
                                        $crossed_out_price = 0;
                                        if ($old_price > 0) {
                                            if ($old_price < $base_price) {
                                                // Ha az old_price kisebb, akkor a nagyobb (base_price) az áthúzandó eredeti ár!
                                                $crossed_out_price = $base_price;
                                            } else {
                                                // Standard eset
                                                $crossed_out_price = $old_price;
                                            }
                                        }
                                    ?>
                                        <div class="price-section">
                                            <?php if ($crossed_out_price > 0 && ($settings['webshop_show_old_price_on_cards'] ?? '0') === '1'): ?>
                                                <span class="old-price" style="text-decoration: line-through; color: #94a3b8; margin-right: 8px; font-size: 0.9em;"><?= number_format($crossed_out_price, 0, '', ' ') ?> Ft</span>
                                            <?php endif; ?>
                                            <span class="new-price"><?= number_format($final_price, 0, '', ' ') ?> Ft</span>
                                        </div>

                                    <?php elseif (!$is_showcase_active && $hide_prices_anon && !isset($_SESSION['user_id'])): ?>
                                        <div class="price-section" style="justify-content: center; background: #fee2e2; border-radius: 4px; padding: 4px;">
                                            <a href="login.php" style="color: #b91c1c; font-size: 0.8em; font-weight: bold; text-decoration: underline;">Belépés az árakhoz</a>
                                        </div>
                                    <?php endif; ?>
                                    
                                    
                                    <?php if (!$is_showcase && $status !== 'no_stock'): ?>
                                        <button type="button" class="btn-cart" onclick="addCardToCartWithQty('<?= htmlspecialchars($sku, ENT_QUOTES, 'UTF-8') ?>', this)">Kosárba</button>
                                    <?php elseif ($status === 'no_stock'): ?>
                                        <button type="button" class="btn" style="width: 100%; margin-top: 10px;" disabled>Nem rendelhető</button>
                                    <?php endif; ?>
                             
                                </div>
                              
                               <!-- Felugró ablak a kártyában-számláló kosár -->

                               <?php if (($settings['webshop_enable_b2b_qty_input'] ?? '0') === '1'): ?>
                                    <div class="card-qty-overlay-sheet">
                                        <!-- Kis bezáró X a sarokban -->
                                        <span class="close-card-overlay" onclick="closeCardQtyOverlay(this)">&times;</span>
                                        
                                        <!-- ÚJ: Diszkrét terméknév és cikkszám kiírás, hogy a vevő tudja, mit rendel -->
                                        <div style="text-align: center; margin-bottom: 8px; width: 100%; box-sizing: border-box; line-height: 1.2;">
                                            <strong style="font-size: 11px; display: block; white-space: normal; color: var(--theme-text-color);"><?= htmlspecialchars($p['name']) ?></strong>
                                            <span style="font-size: 9px; color: #94a3b8; display: block; margin-top: 2px;">Cikkszám: <?= htmlspecialchars($sku) ?></span>
                                        </div>
                                        
                                        <div class="qty-adjust-container" style="margin-bottom: 12px !important;">
                                            <button type="button" onclick="adjustCardQty(this, -1)" class="qty-adjust-btn">-</button>
                                            <input type="number" class="qty-input-field card-qty-val" value="1" min="1" max="9999">
                                            <button type="button" onclick="adjustCardQty(this, 1)" class="qty-adjust-btn">+</button>
                                        </div>
                                        
                                        <button type="button" onclick="submitCardQty(this, '<?= htmlspecialchars($sku) ?>')" class="action-btn-submit btn-cart" style="width: 100% !important; margin: 0 !important;"><i class="fa fa-shopping-basket"></i> Kosárba</button>
                                    </div>
                                <?php endif; ?>


                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        [[QUICK_ORDER_HTML]]
        [[PAST_ORDERS_HTML]]
        
        [[FOOTER_HTML]]
    </div>
    
    [[MINI_CART_HTML]]
    [[COOKIE_BANNER_HTML]]

    <span id="initial-cart-count-value" style="display:none;"><?= (isset($_SESSION['cart']) && is_array($_SESSION['cart'])) ? (int)array_sum($_SESSION['cart']) : 0 ?></span>
    
    <script>
    function toggleSearchOverlay(event) {
        if (event) {
            event.preventDefault();
            event.stopPropagation();
        }
        var navbar = document.querySelector('.webshop-navbar');
        var searchBelow = document.querySelector('.webshop-search-below-container');
        var searchFixed = document.querySelector('.navbar-search-fixed');
        
        if (navbar) navbar.classList.toggle('search-active');
        if (searchBelow) searchBelow.classList.toggle('search-active');
        if (searchFixed) searchFixed.classList.toggle('search-active');
    }


    </script>

[[WEBSHOP_SCRIPT]]


        <!-- B2B MOBIL MENNYISÉG VÁLASZTÓ FIÓK (BOTTOM SHEET) -->
    
        
<script>
    var webshopB2BQtyInputEnabled = <?= ($settings['webshop_enable_b2b_qty_input'] ?? '0') === '1' ? 'true' : 'false' ?>;
</script>

<!-- B2B MOBIL MENNYISÉG VÁLASZTÓ FIÓK (BOTTOM SHEET) -->
<div id="mobile-qty-sheet" class="mobile-qty-sheet">
    <div class="drag-handle"></div>
    
    <h4 class="sheet-title">Hány darabot szeretne rendelni?</h4>
    <p id="sheet-product-title" class="sheet-product-title">-</p>
    
    <!-- Tapintható nagy gombok -->
    <div class="qty-adjust-container">
        <button type="button" onclick="adjustSheetQty(-1)" class="qty-adjust-btn">-</button>
        <input type="number" id="sheet-qty-input" class="qty-input-field" value="1" min="1" max="9999">
        <button type="button" onclick="adjustSheetQty(1)" class="qty-adjust-btn">+</button>
    </div>
    
    <!-- Akciógombok (a Kosárba gomb megkapta a btn-cart osztályt is a natív színekért!) -->
    <div class="action-btn-container">
        <button type="button" onclick="closeMobileQtyPicker()" class="action-btn-cancel">Mégse</button>
        <button type="button" onclick="submitMobileQtyPicker()" class="action-btn-submit btn-cart"><i class="fa fa-shopping-basket"></i> Kosárba tesz</button>
    </div>
</div>

<!-- Sötétítő háttér -->
<div id="mobile-qty-overlay" class="mobile-qty-overlay" onclick="closeMobileQtyPicker()"></div>


</body>
</html>