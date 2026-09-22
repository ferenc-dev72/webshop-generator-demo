<?php
header("Connection: close"); // Az állandó olvasás és fagyás javítására
require_once 'config.php';

define('DEBUG_MODE', false);
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// GLOBÁLIS SEO-SLUG GENERÁLÓ FÜGGVÉNY
function seo_barat_url_php($szoveg) {
    if ($szoveg === null || trim($szoveg) === '' || strtolower(trim($szoveg)) === 'egyeb') {
        return "egyeb";
    }
    $ekezetek = ["á"=>"a", "é"=>"e", "í"=>"i", "ó"=>"o", "ö"=>"o", "ő"=>"o", "ú"=>"u", "ü"=>"u", "ű"=>"u", "Á"=>"a", "É"=>"e", "Í"=>"i", "Ó"=>"o", "Ö"=>"o", "Ő"=>"o", "Ú"=>"u", "Ü"=>"u", "Ű"=>"u"];
    $szoveg = strtr(mb_strtolower(trim($szoveg), "UTF-8"), $ekezetek);
    $szoveg = preg_replace('/[^a-z0-9\s-]/', '', $szoveg);
    $szoveg = trim(preg_replace('/[\s-]+/', '-', $szoveg), '-');
    return ($szoveg !== '') ? $szoveg : "egyeb";
}

// INTELLIGENS MUNKAMENET-KEZELŐ:
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $is_cart_ajax = isset($_GET['action']) && (strpos($_GET['action'], 'ajax') !== false);
    $exclude_scripts = ['cart.php', 'login.php', 'register.php', 'logout.php', 'profile.php'];
    
    if (!in_array($current_script, $exclude_scripts) && !$is_cart_ajax) {
        session_write_close(); // Csak a tiszta olvasási oldalaknál zárjuk le azonnal!
    }
}

if (isset($_GET['clear_sim_emails']) && $_GET['clear_sim_emails'] == '1') {
    unset($_SESSION['simulated_emails']);
    $url = strtok($_SERVER["REQUEST_URI"], '?');
    header("Location: $url");
    exit;
}

try {
    $db = new PDO('sqlite:' . __DIR__ . '/webshop.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. TÁBLÁK LÉTREHOZÁSA ÉS STRUKTÚRA FELÉPÍTÉSE
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        email TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        discount_percent INTEGER DEFAULT 0,
        is_admin INTEGER DEFAULT 0,
        company_name TEXT NULL,
        vat_number TEXT NULL,
        billing_address TEXT NULL,
        shipping_address TEXT NULL,
        credit_limit INTEGER DEFAULT 0,
        is_approved INTEGER DEFAULT 1,
        allow_transfer INTEGER DEFAULT 0,
        payment_terms INTEGER DEFAULT 8,
        phone TEXT NULL
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        items_json TEXT NOT NULL,
        total_price INTEGER NOT NULL,
        status TEXT DEFAULT 'Függőben',
        payment_method TEXT DEFAULT 'Utánvét',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS global_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS coupons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        discount_type TEXT NOT NULL,
        discount_value INTEGER NOT NULL,
        is_active INTEGER DEFAULT 1
    )");
    
    $db->exec("CREATE TABLE IF NOT EXISTS user_custom_prices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        product_sku TEXT NOT NULL,
        custom_price INTEGER NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id),
        UNIQUE(user_id, product_sku)
    )");

  
    // SQLITE TERMÉKTÁBLA LÉTREHOZÁSA (Bővítve a category_slug és product_slug oszlopokkal)
    $db->exec("CREATE TABLE IF NOT EXISTS products (
        sku TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        type TEXT NULL,
        price INTEGER NOT NULL,
        purchase_price INTEGER NULL,
        old_price INTEGER NULL,
        custom_price INTEGER NULL,
        group_name TEXT NOT NULL,
        category_slug TEXT NULL,
        product_slug TEXT NULL,
        image TEXT NULL,
        galeria TEXT NULL,
        kep_statusz TEXT NULL,
        status TEXT DEFAULT 'standard',
        shipping_type TEXT DEFAULT 'global',
        shipping_fee INTEGER DEFAULT 0,
        leiras TEXT NULL,
        te TEXT NULL
    )");

    // Gyorsító indexek elhelyezése
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_group ON products(group_name)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_cat_slug ON products(category_slug)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_prod_slug ON products(product_slug)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_products_status ON products(status)");

    // OSZLOPOK DINAMIKUS HOZZÁADÁSA
    try { $db->exec("ALTER TABLE users ADD COLUMN shipping_type TEXT DEFAULT 'global'"); } catch(PDOException $e){}
    try { $db->exec("ALTER TABLE users ADD COLUMN shipping_fee INTEGER DEFAULT 0"); } catch(PDOException $e){}
    try { $db->exec("ALTER TABLE users ADD COLUMN is_super_admin INTEGER DEFAULT 0"); } catch(PDOException $e){}
    try { $db->exec("ALTER TABLE users ADD COLUMN admin_permissions TEXT DEFAULT '[]'"); } catch(PDOException $e){}
    try { $db->exec("ALTER TABLE users ADD COLUMN phone TEXT NULL"); } catch(PDOException $e){}
    try { $db->exec("ALTER TABLE products ADD COLUMN category_slug TEXT"); } catch(PDOException $e){}
    try { $db->exec("ALTER TABLE products ADD COLUMN product_slug TEXT"); } catch(PDOException $e){}
    
    // ALAPÉRTELMEZETT GLOBÁLIS BEÁLLÍTÁSOK FELTÖLTÉSE
    $default_settings = [
        'auto_email' => '1', 
        'auto_invoice' => '1',
        'admin_notify' => '1',
        'seller_name' => 'Az Ön Cége Kft.',
        'seller_address' => '1011 Budapest, Fő utca 1.',
        'seller_vat' => '12345678-2-42',
        'seller_bank' => 'HU00 1111 2222 3333 4444 5555',
        'seller_reg_number' => '01-09-123456',
        'hosting_provider' => 'Tárhelyszolgáltató Kft. (Székhely: 1132 Budapest, Victor Hugo u. 18-22., Email: info@tarhely.hu)',
        'legal_aszf' => 'ÁSZF szöveg...',
        'legal_adatkezeles' => 'Adatkezelési Tájékoztató...',
        'strict_b2b' => '0',
        'min_order_value' => '0',
        'hide_prices_anon' => '0',
        'contact_text' => 'Kapcsolat: +36 1 234 5678',
        'enable_coupons' => '0',
        'showcase_mode' => '0',
        'shipping_type' => 'free',
        'shipping_fee' => '0',
        'webshop_title' => 'Szalay Barkács',
        'webshop_subtitle' => 'Minőségi szerszámok és kiegészítők',
        'webshop_show_categories_dropdown' => '0',
        'webshop_desktop_title_image' => '',
        'webshop_desktop_use_title_image' => '0',
        'webshop_desktop_title_image_height' => '80',
        'webshop_hamburger_style' => 'text_icon',
        'webshop_hamburger_trigger' => 'hover',
        'webshop_header_font_italic' => '0',
        'webshop_header_font_uppercase' => '0',
        'webshop_header_font_weight' => '400',
        'header_bg_start' => '#1e3a8a',
        'header_bg_end' => '#0f172a',
        'enable_shipping_fee' => '0',
        'footer_text' => 'VARIOX Hungary Kft - Minden jog fenntartva.', 
        'webshop_desktop_email_text' => 'info@variox.hu',
        'webshop_desktop_contact_text' => '+36 70 234 5678',
        'webshop_mobile_contact_text' => '+36 70 234 5678',
        'webshop_desktop_show_email' => '1',
        'webshop_mobile_show_email' => '1',
        'webshop_mobile_show_categories_dropdown' => '0',
        'webshop_desktop_menu_size' => '12',
        'webshop_mobile_menu_size' => '11',
        'webshop_desktop_contact_size' => '12', 
        'webshop_header_top_align_desktop' => 'justify',
        'webshop_header_bottom_align_desktop' => 'justify',
        'active_theme' => 'custom',
        'header_bg' => 'linear-gradient(135deg, #1e3a8a 0%, #0f172a 100%)',
        'header_text_color' => '#ffffff',
        'header_accent_color' => '#f59e0b',
        'enable_blog' => '0', 
        'blog_menu_name' => 'Blog',
        'email_test_mode' => '1',
        'webshop_featured_auto_promo' => '0', 
        'webshop_landing_blog_auto' => '1',   
        'webshop_show_featured_products' => '1',
        'webshop_featured_skus' => '',
        'webshop_featured_products_limit' => '5',
        'webshop_landing_blog_limit' => '3',
        'webshop_featured_blog_id' => '0',
        'webshop_featured_blog_ids' => '',
        'webshop_enable_b2b_qty_input' => '0',
        'webshop_profile_show_custom_prices' => '0',
        'webshop_profile_show_order_history' => '1', 
        'webshop_product_sorting' => 'name_asc', 
        'webshop_global_b2c_markup' => '0', 
        'webshop_show_old_price_on_cards' => '0', 
        'webshop_show_no_image_products' => '1', 
    ];

    foreach ($default_settings as $key => $val) {
        $stmt_check = $db->prepare("SELECT COUNT(*) FROM global_settings WHERE setting_key = ?");
        $stmt_check->execute([$key]);
        if ((int)$stmt_check->fetchColumn() === 0) {
            $stmt_ins = $db->prepare("INSERT INTO global_settings (setting_key, setting_value) VALUES (?, ?)");
            $stmt_ins->execute([$key, $val]);
        }
    }

    // EGYSZERI JOGOSULTSÁG-MIGRÁCIÓ
    try {
        $stmt_mig = $db->query("SELECT COUNT(*) FROM global_settings WHERE setting_key = 'mig_super_admin_v2'");
        if ($stmt_mig && (int)$stmt_mig->fetchColumn() === 0) {
            $db->exec("UPDATE users SET is_super_admin = 1 WHERE is_admin = 1");
            $all_p = json_encode(["orders", "products", "users", "files", "theme", "ai", "settings"], JSON_UNESCAPED_UNICODE);
            $db->exec("UPDATE users SET admin_permissions = '{$all_p}' WHERE is_admin = 1");
            $db->exec("INSERT OR IGNORE INTO global_settings (setting_key, setting_value) VALUES ('mig_super_admin_v2', '1')");
        }
    } catch(Exception $e){}

    // ADMIN JOGOSULTSÁGOK ELLENŐRZÉSE ÉS LÉTREHOZÁSA
    $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->execute([ADMIN_USER]);
    if (!$stmt->fetch()) {
        $hash = password_hash(ADMIN_PASS, PASSWORD_DEFAULT);
        $all_p = json_encode(["orders", "products", "users", "files", "theme", "ai", "settings"], JSON_UNESCAPED_UNICODE);
        $stmt_insert = $db->prepare("INSERT INTO users (username, email, password_hash, is_admin, is_super_admin, admin_permissions) VALUES (?, ?, ?, 1, 1, ?)");
        $stmt_insert->execute([ADMIN_USER, ADMIN_EMAIL, $hash, $all_p]);
    }

    if (isset($_SESSION['user_id']) && (int)($_SESSION['is_admin'] ?? 0) === 1) {
        try {
            $stmt_sync = $db->prepare("SELECT is_admin, is_super_admin, admin_permissions FROM users WHERE id = ?");
            $stmt_sync->execute([$_SESSION['user_id']]);
            $sync_u = $stmt_sync->fetch(PDO::FETCH_ASSOC);
            if ($sync_u) {
                $_SESSION['is_admin'] = (int)$sync_u['is_admin'];
                $_SESSION['is_super_admin'] = (int)$sync_u['is_super_admin'];
                $_SESSION['admin_permissions'] = $sync_u['admin_permissions'] ?? '[]';
            }
        } catch(Exception $e){}
    }

} catch (PDOException $e) {
    die("Adatbázis hiba: " . $e->getMessage());
}

// INTELLIGENS MUNKAMENET CACHE AZ UTALÁSOKHOZ
$GLOBALS['user_custom_prices_cache'] = [];
$GLOBALS['user_is_b2b'] = false; 
if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] > 0) {
    try {
        $stmt_u_details = $db->prepare("SELECT company_name FROM users WHERE id = ?");
        $stmt_u_details->execute([$_SESSION['user_id']]);
        $comp_name = $stmt_u_details->fetchColumn();
        if (!empty($comp_name)) {
            $GLOBALS['user_is_b2b'] = true; 
        }

        $stmt_cache = $db->prepare("SELECT product_sku, custom_price FROM user_custom_prices WHERE user_id = ?");
        $stmt_cache->execute([$_SESSION['user_id']]);
        $GLOBALS['user_custom_prices_cache'] = $stmt_cache->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {
        $GLOBALS['user_custom_prices_cache'] = [];
    }
}



// AZ ADATBÁZIS-SZINTŰ KÉP- ÉS GALÉRIAPROCESSZOR (db.php - FIZIKAI LEMEZ-ELLENŐRZŐVEL)
function process_product_images($sku, &$p, $existing_disk_files = null) {
    $sku_lower = strtolower($sku);
    

    // Automatikus SEO URL generálás, ha hiányozna
    $group_name = $p['group'] ?? $p['group_name'] ?? 'Egyéb';
    $name_val = $p['name'] ?? '';
    $p['category_slug'] = !empty($p['category_slug']) ? $p['category_slug'] : seo_barat_url_php($group_name);
    $p['product_slug'] = !empty($p['product_slug']) ? $p['product_slug'] : seo_barat_url_php($sku_lower . '-' . $name_val);


    // Kategória név tisztítása
    $clean_group = strtolower($p['group'] ?? $p['group_name'] ?? 'egyeb');
    $unwanted = array('á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ö'=>'o', 'ő'=>'o', 'ú'=>'u', 'ü'=>'u', 'ű'=>'u', 'Á'=>'a', 'É'=>'e', 'Í'=>'i', 'Ó'=>'o', 'Ö'=>'o', 'Ő'=>'o', 'Ú'=>'u', 'Ü'=>'u', 'Ű'=>'u');
    $clean_group = strtr($clean_group, $unwanted);
    $clean_group = preg_replace('/[^a-z0-9_-]/', '', $clean_group);

    $p['category_slug'] = !empty($p['category_slug']) ? $p['category_slug'] : seo_barat_url_php($p['group'] ?? $p['group_name'] ?? 'Egyéb');
    
    $img_dir = __DIR__ . '/static/images/';

    // 1. BEOLVASSUK A LEMEZ TARTALMÁT A RAM-BA (Ha még nem kaptunk indexet)
    if ($existing_disk_files === null) {
        $existing_disk_files = [];
        if (is_dir($img_dir)) {
            $files = scandir($img_dir);
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..') {
                    $existing_disk_files[strtolower($f)] = $f;
                }
            }
        }
    }

    // FŐKÉP DETEKTÁLÁS (A fizikai fájlok között keresünk)
  //  FŐKÉP-DETEKTÁLÁS (A BEÁLLÍTÁSOKBAN MEGADOTT ALAPÉRTELMEZETT KITERJESZTÉSSEL)
    $settings = get_global_settings();
    $def_ext = strtolower($settings['webshop_default_image_extension'] ?? 'webp');

    $img_file = $p['image'] ?? '';
    if (empty($img_file) || strtolower($img_file) === 'nincs kép' || strtolower($img_file) === 'none') {
        $possible_names = [
            $sku_lower . "_" . $clean_group . "." . $def_ext, // <--- AZ ADMINBAN BEÁLLÍTOTT FORMÁTUM AZ ELSŐ
            $sku_lower . "_" . $clean_group . ".webp",
            $sku_lower . "_" . $clean_group . ".png",
            $sku_lower . "_" . $clean_group . ".jpg",
            $sku_lower . "_" . $clean_group . ".jpeg",
            $sku_lower . "." . $def_ext,
            $sku_lower . ".webp",
            $sku_lower . ".png",
            $sku_lower . ".jpg"
        ];
        
        foreach ($possible_names as $p_name) {
            if (isset($existing_disk_files[strtolower($p_name)])) {
                $img_file = $existing_disk_files[strtolower($p_name)];
                $p['image'] = $img_file;
                break;
            }
        }
        
        // Ha nem létezik a lemezen, a kiválasztott kiterjesztéssel hozza létre a nevet:
        if (empty($img_file) || strtolower($img_file) === 'nincs kép' || strtolower($img_file) === 'none') {
            $img_file = $sku_lower . "_" . $clean_group . "." . $def_ext;
            $p['image'] = $img_file;
        }
    }

    $main_image_base = strtolower(pathinfo($img_file, PATHINFO_FILENAME));

    // 3. FŐKÉP FIZIKAI LÉTEZÉSÉNEK ELLENŐRZÉSE
    $file_missing = !isset($existing_disk_files[strtolower($img_file)]);

    // 4. GALÉRIA FELOLDÁS: Megkeressük a lemezen a cikkszámmal kezdődő képeket
    $found_on_disk = [];
    if (!empty($sku_lower)) {
        foreach ($existing_disk_files as $f_lower => $f_orig) {
            if (strpos($f_lower, $sku_lower . '_') === 0) {
                $f_base = strtolower(pathinfo($f_orig, PATHINFO_FILENAME));
                if ($f_base !== $main_image_base) {
                    $found_on_disk[] = $f_orig;
                }
            }
        }
    }

    // Kézi/Excel galéria bejegyzések kiolvasása
    $manual_gal = array_filter(array_map('trim', explode(',', $p['galeria'] ?? $p['galéria'] ?? '')));

    // Egyesítjük a kézi és a lemezen talált galériát
    $combined_gal = array_unique(array_merge($manual_gal, $found_on_disk));

    // --- SZIGORÚ FIZIKAI SZŰRÉS ---
    // Csak az a kép maradhat a galériában, ami TÉNYLEG ott van a lemezen!
    // Ha kilett törölve a mappából, itt automatikusan kiesik!
    $valid_gallery = [];
    foreach ($combined_gal as $g_item) {
        if (isset($existing_disk_files[strtolower($g_item)])) {
            $valid_gallery[] = $existing_disk_files[strtolower($g_item)];
        }
    }

    $p['galeria'] = implode(', ', $valid_gallery);

    // 5. KÉPSTÁTUSZ BEÁLLÍTÁSA ("Hiányzó képfájl" kezelése)
    $raw_status = $p['kep_statusz'] ?? '';
    if (strtolower($raw_status) === 'új felvitel' || strtolower($raw_status) === 'uj felvitel' || strpos(strtolower($raw_status), 'hiányzó képfájl') !== false) {
        $raw_status = '';
    }

    $p['kep_statusz'] = $file_missing ? ("Hiányzó képfájl" . (!empty($raw_status) ? ": " . $raw_status : "")) : $raw_status;
}

// AUTOMATIKUS PYTHON-JSON SZINKRONIZÁLÓ MOTOR
$json_import_file = __DIR__ . '/products.json';
if (file_exists($json_import_file)) {
    try {
        $imported_raw = json_decode(file_get_contents($json_import_file), true);
        if (is_array($imported_raw) && !empty($imported_raw)) {
            $db->beginTransaction();
            $db->exec("DELETE FROM products");
            
   $stmt_ins_prod = $db->prepare("INSERT INTO products 
                (sku, name, type, price, purchase_price, old_price, custom_price, group_name, category_slug, product_slug, image, galeria, kep_statusz, status, shipping_type, shipping_fee, leiras, te) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($imported_raw as $p_sku => $p) {
                $sku_val = strtolower(trim($p_sku));
                $p['sku'] = $sku_val;
                $p['status'] = $p['status'] ?? 'standard';
                $p['shipping_type'] = $p['shipping_type'] ?? 'global';
                $p['shipping_fee'] = (int)($p['shipping_fee'] ?? 0);
                $p['leírás'] = $p['leírás'] ?? $p['leiras'] ?? '';
                $p['te'] = $p['te'] ?? '';
                $p['galeria'] = $p['galeria'] ?? $p['galéria'] ?? '';
                $p['kep_statusz'] = $p['kep_statusz'] ?? '';
                $p['category_slug'] = $p['category_slug'] ?? seo_barat_url_php($p['group'] ?? $p['group_name'] ?? 'Egyéb');
                $p['product_slug'] = $p['product_slug'] ?? seo_barat_url_php($sku_val . '-' . ($p['name'] ?? ''));
                
                process_product_images($sku_val, $p);
                
                $stmt_ins_prod->execute([
                    $sku_val,
                    $p['name'] ?? '',
                    $p['type'] ?? null,
                    (int)($p['price'] ?? 0),
                    (isset($p['purchase_price']) && $p['purchase_price'] !== '') ? (int)$p['purchase_price'] : null,
                    (isset($p['old_price']) && $p['old_price'] !== '') ? (int)$p['old_price'] : null,
                    (isset($p['custom_price']) && $p['custom_price'] !== '') ? (int)$p['custom_price'] : null,
                    $p['group'] ?? $p['group_name'] ?? 'Egyéb',
                    $p['category_slug'],
                    $p['product_slug'],
                    $p['image'] ?? null,
                    $p['galeria'] ?? null,
                    $p['kep_statusz'] ?? null,
                    $p['status'],
                    $p['shipping_type'],
                    (int)$p['shipping_fee'],
                    $p['leírás'],
                    $p['te']
                ]);
            }

            $db->commit();
            @unlink($json_import_file);
            write_log('DB_AUTO_SYNC', "Sikeres automatikus Python-JSON szinkronizáció lefutott.");
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        write_log('DB_SYNC_ERROR', "Hiba az automatikus szinkronizáció közben: " . $e->getMessage());
    }
}

// GLOBÁLIS SEGÉDFÜGGVÉNYEK

function has_permission($module) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (!isset($_SESSION['user_id']) || (int)$_SESSION['is_admin'] !== 1) { return false; }
    if (isset($_SESSION['is_super_admin']) && (int)$_SESSION['is_super_admin'] === 1) { return true; }
    $perms = json_decode($_SESSION['admin_permissions'] ?? '[]', true);
    return is_array($perms) && in_array($module, $perms);
}

function write_log($event_type, $message) {
    $log_file = __DIR__ . '/webshop_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $username = $_SESSION['username'] ?? 'Vendég / Rendszer';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    file_put_contents($log_file, "[$timestamp] [IP: $ip] [$username] [$event_type] $message\n", FILE_APPEND);
}

function send_email($subject, $recipient, $html_content) {
    $settings = get_global_settings();
    $test_mode = ($settings['email_test_mode'] ?? '0') === '1';
    
    $is_placeholder_user = (!defined('SMTP_USER') || SMTP_USER === '' || strpos(SMTP_USER, '[[') !== false);
    $is_placeholder_pass = (!defined('SMTP_PASSWORD') || SMTP_PASSWORD === '' || strpos(SMTP_PASSWORD, '[[') !== false);
    
    if ($test_mode || $is_placeholder_user || $is_placeholder_pass || !$recipient) {
        $log = "[" . date('Y-m-d H:i:s') . "] [SZIMULÁLT E-MAIL]\nCímzett: $recipient\nTárgy: $subject\nTartalom:\n$html_content\n------------------\n";
        file_put_contents('mail_log.txt', $log, FILE_APPEND);
        
        write_log('EMAIL_SIMULATED', "Szimulált értesítő e-mail kiküldve ide: $recipient | Tárgy: $subject");
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            if (!isset($_SESSION['simulated_emails'])) {
                $_SESSION['simulated_emails'] = [];
            }
            $_SESSION['simulated_emails'][] = [
                'recipient' => $recipient,
                'subject' => $subject,
                'content' => $html_content,
                'time' => date('H:i:s')
            ];
        }
        return true;
    }
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: " . SMTP_USER . "\r\n";
    
    try {
        $result = mail($recipient, $subject, $html_content, $headers);
        if ($result) {
            write_log('EMAIL_SENT', "Éles értesítő e-mail kiküldve ide: $recipient | Tárgy: $subject");
        }
        return $result;
    } catch (Exception $e) {
        file_put_contents('mail_log.txt', "Küldési hiba: " . $e->getMessage() . "\n", FILE_APPEND);
        write_log('EMAIL_ERROR', "E-mail küldési hiba ide: $recipient | Hiba: " . $e->getMessage());
        return false;
    }
}

function get_order_details($order_id) {
    global $db;
    $stmt = $db->prepare("SELECT orders.*, users.id AS user_id, users.username, users.email, users.company_name, users.vat_number, users.billing_address, users.shipping_address, users.payment_terms 
                          FROM orders 
                          JOIN users ON orders.user_id = users.id 
                          WHERE orders.id = ?");
    $stmt->execute([$order_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_global_settings() {
    global $db;
    $settings = [];
    $stmt = $db->query("SELECT setting_key, setting_value FROM global_settings");
    if ($stmt) {
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

function calculate_cart_shipping_fee($user_id, $cart_items) {
    global $db;
    $settings = get_global_settings();
    
    if (($settings['enable_shipping_fee'] ?? '0') !== '1') {
        return 0;
    }
    
    $user_shipping_type = 'global';
    $user_shipping_fee = 0;
    if ($user_id > 0) {
        $stmt = $db->prepare("SELECT shipping_type, shipping_fee FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($u) {
            $user_shipping_type = $u['shipping_type'] ?? 'global';
            $user_shipping_fee = (int)($u['shipping_fee'] ?? 0);
        }
    }
    
    if ($user_shipping_type !== 'global') {
        return ($user_shipping_type === 'free') ? 0 : $user_shipping_fee;
    }
    
    $max_product_shipping_fee = -1;
    if (is_array($cart_items)) {
        $stmt_sh = $db->prepare("SELECT shipping_type, shipping_fee FROM products WHERE sku = ?");
        foreach ($cart_items as $item) {
            $sku = $item['id'] ?? $item['sku'] ?? '';
            if (!empty($sku)) {
                $stmt_sh->execute([$sku]);
                $p = $stmt_sh->fetch(PDO::FETCH_ASSOC);
                if ($p) {
                    $p_ship_type = $p['shipping_type'] ?? 'global';
                    $p_ship_fee = (int)($p['shipping_fee'] ?? 0);
                    
                    if ($p_ship_type !== 'global') {
                        $fee = ($p_ship_type === 'free') ? 0 : $p_ship_fee;
                        if ($fee > $max_product_shipping_fee) {
                            $max_product_shipping_fee = $fee;
                        }
                    }
                }
            }
        }
    }
    
    if ($max_product_shipping_fee >= 0) {
        return $max_product_shipping_fee;
    }
    
    $global_shipping_type = $settings['shipping_type'] ?? 'free';
    return ($global_shipping_type === 'free') ? 0 : (int)($settings['shipping_fee'] ?? 0);
}

function generate_invoice_email_html($order_id) {
    $order = get_order_details($order_id);
    if (!$order) return "";
    
    $settings = get_global_settings();
    $sel_name = htmlspecialchars($settings['seller_name'] ?? 'Az Ön Cége Kft.');
    $sel_addr = htmlspecialchars($settings['seller_address'] ?? '-');
    $sel_vat = htmlspecialchars($settings['seller_vat'] ?? '-');
    $sel_bank = htmlspecialchars($settings['seller_bank'] ?? '-');
    
    $buyer_name = !empty($order['company_name']) ? htmlspecialchars($order['company_name']) : htmlspecialchars($order['username']);
    $buyer_vat = !empty($order['vat_number']) ? "Adószám: " . htmlspecialchars($order['vat_number']) . "<br>" : "";
    $buyer_billing = !empty($order['billing_address']) ? htmlspecialchars($order['billing_address']) : "-";
    $buyer_shipping = !empty($order['shipping_address']) ? htmlspecialchars($order['shipping_address']) : "-";
    
    $payment_method = $order['payment_method'];
    $payment_terms = (int)($order['payment_terms'] ?? 8);
    
    $due_date_str = "";
    if ($payment_method === 'Átutalás') {
        $due_date = date('Y-m-d', strtotime($order['created_at'] . " +" . $payment_terms . " days"));
        $due_date_str = "<p><strong>Fizetési határidő:</strong> " . $due_date . " (" . $payment_terms . " napos határidő)</p>";
    }
    
    $items_table_html = "";
    $items = json_decode($order['items_json'] ?? '[]', true);
    if (is_array($items)) {
        foreach ($items as $item) {
            $iprice = (int)$item['price'];
            $iqty = (int)$item['qty'];
            $itotal = $iprice * $iqty;
            $items_table_html .= "
            <tr>
                <td style='padding:8px; border-bottom:1px solid #cbd5e1;'>" . htmlspecialchars($item['name']) . "</td>
                <td style='padding:8px; border-bottom:1px solid #cbd5e1; text-align:right;'>" . number_format($iprice, 0, '', ' ') . " Ft</td>
                <td style='padding:8px; border-bottom:1px solid #cbd5e1; text-align:center;'>" . $iqty . " db</td>
                <td style='padding:8px; border-bottom:1px solid #cbd5e1; text-align:right; font-weight:bold;'>" . number_format($itotal, 0, '', ' ') . " Ft</td>
            </tr>
            ";
        }
    }
    
    $final_total = (int)$order['total_price'];
    $shipping_fee = calculate_cart_shipping_fee($order['user_id'], $items);
    $net_total = (int)round(($final_total - $shipping_fee) / 1.27);
    $vat_total = ($final_total - $shipping_fee) - $net_total;
    
    $shipping_html = $shipping_fee > 0 ? "<p style='margin:4px 0; color:#15803d;'>Szállítási díj: <strong>+" . number_format($shipping_fee, 0, '', ' ') . " Ft</strong></p>" : "";
    
    return "
    <div style='font-family:Arial, sans-serif; color:#1e293b; max-width:600px; margin:0 auto; padding:20px; border:1px solid #cbd5e1; border-radius:8px;'>
        <h2 style='color:#1e3a8a; margin-top:0;'>DÍJBEKÉRŐ (Pro-forma) #{$order_id}</h2>
        <table style='width:100%; margin-bottom:20px; font-size:0.9em;'>
            <tr>
                <td style='width:50%; vertical-align:top;'>
                    <strong>Kiállító (Eladó):</strong><br>
                    {$sel_name}<br>Cím: {$sel_addr}<br>Adószám: {$sel_vat}<br>Bankszámla: {$sel_bank}<br>E-mail: " . ADMIN_EMAIL . "
                </td>
                <td style='width:50%; vertical-align:top;'>
                    <strong>Vevő:</strong><br>
                    {$buyer_name}<br>{$buyer_vat}
                    Számlázási cím: {$buyer_billing}<br>
                    Szállítási cím: {$buyer_shipping}
                </td>
            </tr>
        </table>
        <p><strong>Fizetési mód:</strong> {$payment_method}</p>
        {$due_date_str}
        <table style='width:100%; border-collapse:collapse; margin-top:15px; font-size:0.9em;'>
            <thead>
                <tr style='background-color:#f1f5f9; border-bottom:2px solid #cbd5e1; text-align:left;'>
                    <th style='padding:8px;'>Megnevezés</th>
                    <th style='padding:8px; text-align:right;'>Egységár</th>
                    <th style='padding:8px; text-align:center;'>Mennyiség</th>
                    <th style='padding:8px; text-align:right;'>Összesen</th>
                </tr>
            </thead>
            <tbody>{$items_table_html}</tbody>
        </table>
        <div style='text-align:right; margin-top:20px; font-size:0.95em; border-top:1px solid #cbd5e1; padding-top:10px;'>
            <p style='margin:4px 0;'>Nettó érték: " . number_format($net_total, 0, '', ' ') . " Ft</p>
            <p style='margin:4px 0;'>ÁFA (27%): " . number_format($vat_total, 0, '', ' ') . " Ft</p>
            {$shipping_html}
            <p style='margin:4px 0; font-size:1.2em; font-weight:bold; color:#1e3a8a;'>Bruttó végösszeg: " . number_format($final_total, 0, '', ' ') . " Ft</p>
        </div>
        <p style='font-size:0.8em; color:#64748b; margin-top:30px; text-align:center; border-top:1px solid #f1f5f9; padding-top:10px;'>
            Ez egy automatikusan generált díjbekérő / megrendelés összesítő. Nem minősül adóügyi bizonylatnak.
        </p>
    </div>
    ";
}

function generate_status_email_html($order_id) {
    $order = get_order_details($order_id);
    if (!$order) return "";
    
    $username = htmlspecialchars($order['username'] ?? '');
    $status = htmlspecialchars($order['status'] ?? 'Függőben');
    $final_total = (int)$order['total_price'];
    
    return "
    <div style='font-family:Arial, sans-serif; color:#1e293b; max-width:600px; margin:0 auto; padding:20px; border:1px solid #cbd5e1; border-radius:8px;'>
        <h2 style='color:#1e3a8a; margin-top:0;'>Rendelés státusz értesítő #{$order_id}</h2>
        <p>Kedves {$username}!</p>
        <p>Ezúton értesítünk, hogy a #{$order_id} számú rendelésed státusza megváltozott.</p>
        <p><strong>Új státusz: {$status}</strong></p>
        <p><strong>Fizetendő összeg: " . number_format($final_total, 0, '', ' ') . " Ft</strong></p>
        <p>Köszönjük a bizalmadat!</p>
    </div>
    ";
}

function send_manual_order_email($order_id) {
    $order = get_order_details($order_id);
    if (!$order) return false;
    return send_email("Rendelés értesítő #{$order_id}", $order['email'] ?? '', generate_status_email_html($order_id));
}

function send_manual_invoice_email($order_id) {
    $order = get_order_details($order_id);
    if (!$order) return false;
    return send_email("Díjbekérő (Pro-forma) #{$order_id}", $order['email'] ?? '', generate_invoice_email_html($order_id));
}

function render_simulated_emails() {
    if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['simulated_emails'])) {
        echo '
        <div id="simulation-modal-container" style="position:fixed; bottom:20px; right:20px; max-width:440px; width:90%; background:#fff; border:3px solid #10b981; border-radius:12px; box-shadow:0 10px 25px rgba(0,0,0,0.2); z-index:999999; font-family:sans-serif; overflow:hidden;">
            <div style="background:#10b981; color:#fff; padding:12px 15px; display:flex; justify-content:space-between; align-items:center; font-weight:bold;">
                <span>🧪 E-mail Szimulátor (Teszt mód)</span>
                <button onclick="document.getElementById(\'simulation-modal-container\').remove();" style="background:none; border:none; color:#fff; font-size:20px; cursor:pointer; line-height:1;">&times;</button>
            </div>
            <div style="max-height:350px; overflow-y:auto; padding:15px; font-size:13px; color:#1e293b;">
                <p style="margin:0 0 10px 0; color:#059669; font-weight:bold;">Új e-mail kiküldés történt! Kattintson a részletekért:</p>';
        
        foreach ($_SESSION['simulated_emails'] as $idx => $mail) {
            echo '
            <div style="border:1px solid #e2e8f0; border-radius:6px; margin-bottom:10px; background:#f8fafc; overflow:hidden;">
                <div onclick="var target = document.getElementById(\'sim-mail-body-\' + ' . $idx . '); target.style.display = target.style.display === \'none\' ? \'block\' : \'none\';" style="padding:10px; background:#f1f5f9; cursor:pointer; font-weight:bold; display:flex; justify-content:space-between; align-items:center;">
                    <span>[' . $mail['time'] . '] ' . htmlspecialchars($mail['subject']) . '</span>
                    <span>▾</span>
                </div>
                <div id="sim-mail-body-' . $idx . '" style="display:none; padding:12px; background:#ffffff; border-top:1px solid #e2e8f0; word-break:break-word;">
                    <p style="margin:0 0 8px 0; color:#64748b;"><strong>Címzett:</strong> ' . htmlspecialchars($mail['recipient']) . '</p>
                    <hr style="border:0; border-top:1px solid #e2e8f0; margin:8px 0;">
                    <div style="transform:scale(0.9); transform-origin:top left; width:111%;">' . $mail['content'] . '</div>
                </div>
            </div>';
        }
        
        echo '
            </div>
            <div style="padding:10px 15px; background:#f1f5f9; border-top:1px solid #e2e8f0; text-align:right;">
                <a href="?clear_sim_emails=1" style="font-size:11px; color:#be123c; text-decoration:underline; font-weight:bold;">Munkamenet napló kiürítése</a>
            </div>
        </div>';
    }
}

// ADATBÁZIS-SZINTŰ DINAMIKUS ÁRSZÁMÍTÓ MOTOR
function get_product_price_for_user($product, $user_id, $user_discount_percent) {
    $sku = $product['sku'] ?? $product['id'] ?? '';
    
    if ($user_id > 0 && !empty($sku) && isset($GLOBALS['user_custom_prices_cache'][$sku])) {
        return (int)$GLOBALS['user_custom_prices_cache'][$sku];
    }
    
    $base_price = (int)($product['price'] ?? 0);
    $old_price = (isset($product['old_price']) && $product['old_price'] !== null) ? (int)$product['old_price'] : 0;
    
    $active_base = ($old_price > 0 && $old_price < $base_price) ? $old_price : $base_price;
    $is_b2b = isset($GLOBALS['user_is_b2b']) && $GLOBALS['user_is_b2b'] === true;
    
    if ($user_id > 0 && $is_b2b) {
        $base_wholesale_price = !empty($product['custom_price']) ? (int)$product['custom_price'] : $active_base;
        if ($user_discount_percent > 0) {
            return (int)round($base_wholesale_price * (1.0 - $user_discount_percent / 100.0));
        }
        return $base_wholesale_price;
    }
    
    $base_retail_price = $active_base;
    $settings = get_global_settings();
    $markup_percent = (int)($settings['webshop_global_b2c_markup'] ?? 0);
    
    if ($markup_percent > 0) {
        $base_retail_price = (int)round($base_retail_price * (1.0 + $markup_percent / 100.0));
    }
    
    if ($user_id > 0 && $user_discount_percent > 0) {
        return (int)round($base_retail_price * (1.0 - $user_discount_percent / 100.0));
    }
    
    return $base_retail_price;
}

function has_user_custom_price($sku, $user_id) {
    if ($user_id <= 0 || empty($sku)) return false;
    return isset($GLOBALS['user_custom_prices_cache'][$sku]);
}
?>