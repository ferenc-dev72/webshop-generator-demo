<?php
session_start();
require_once 'db.php';

if (!has_permission('files') && !has_permission('products')) {
    die("Hozzáférés megtagadva! Ehhez a művelethez Képszerkesztő jogosultság szükséges.");
}

// 1. UNIVERZÁLIS MENTÉS HANDLER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_edited_image'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    $canvas_mode = $_POST['canvas_mode'] ?? 'product';
    $format = $_POST['format'] ?? 'webp';
    $ext = strtolower($format);
    
    if ($canvas_mode === 'product') {
        $sku = trim($_POST['sku'] ?? '');
        $save_type = $_POST['save_type'] ?? 'main';
        
        if (empty($sku)) {
            echo json_encode(['success' => false, 'error' => 'Hiányzó cikkszám (SKU)!']);
            exit;
        }
        
        if (isset($_FILES['image_blob']) && $_FILES['image_blob']['error'] === UPLOAD_ERR_OK) {
            $img_dir = __DIR__ . '/static/images/';
            if (!is_dir($img_dir)) { @mkdir($img_dir, 0777, true); }
            
            $stmt_p = $db->prepare("SELECT * FROM products WHERE sku = ?");
            $stmt_p->execute([$sku]);
            $prod = $stmt_p->fetch(PDO::FETCH_ASSOC);
            
            $clean_group = 'egyeb';
            if ($prod) {
                $raw_grp = strtolower($prod['group_name'] ?? 'egyeb');
                $unwanted = array('á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ö'=>'o', 'ő'=>'o', 'ú'=>'u', 'ü'=>'u', 'ű'=>'u');
                $clean_group = preg_replace('/[^a-z0-9_-]/', '', strtr($raw_grp, $unwanted));
            }
            
            $sku_lower = strtolower($sku);
            if ($save_type === 'gallery') {
                $existing_gal = !empty($prod['galeria']) ? array_filter(explode(',', $prod['galeria'])) : [];
                $next_idx = count($existing_gal) + 1;
                $filename = "{$sku_lower}_{$clean_group}_galeria_{$next_idx}.{$ext}";
            } else {
                $filename = "{$sku_lower}_{$clean_group}.{$ext}";
            }
            
            $target_path = $img_dir . $filename;
            if (move_uploaded_file($_FILES['image_blob']['tmp_name'], $target_path)) {
                if ($prod) {
                    if ($save_type === 'main') { $prod['image'] = $filename; }
                    $prod['group'] = $prod['group_name'];
                    process_product_images($sku, $prod);
                    
                    $stmt_up = $db->prepare("UPDATE products SET image = ?, galeria = ?, kep_statusz = ? WHERE sku = ?");
                    $stmt_up->execute([$prod['image'], $prod['galeria'], $prod['kep_statusz'], $sku]);
                }
                
                write_log('IMAGE_EDIT_SAVE', "Termékkép elmentve ($sku -> $filename)");
                echo json_encode(['success' => true, 'filename' => $filename, 'folder' => 'static/images/']);
                exit;
            }
        }
    } else {
        $custom_name = trim($_POST['custom_filename'] ?? '');
        $target_folder_rel = trim($_POST['target_folder'] ?? 'static/images/');
        
        if (empty($custom_name)) {
            $custom_name = 'banner_' . time();
        } else {
            $unwanted = array('á'=>'a', 'é'=>'e', 'í'=>'i', 'ó'=>'o', 'ö'=>'o', 'ő'=>'o', 'ú'=>'u', 'ü'=>'u', 'ű'=>'u');
            $custom_name = preg_replace('/[^a-z0-9_-]/', '', strtolower(strtr($custom_name, $unwanted)));
        }
        
        $filename = "{$custom_name}.{$ext}";
        $allowed_folders = ['static/new_images/', 'static/images/', 'static/blog_img/', 'static/themes/'];
        if (!in_array($target_folder_rel, $allowed_folders)) {
            $target_folder_rel = 'static/images/';
        }
        
        $img_dir = __DIR__ . '/' . $target_folder_rel;
        if (!is_dir($img_dir)) { @mkdir($img_dir, 0777, true); }
        
        $target_path = $img_dir . $filename;
        if (isset($_FILES['image_blob']) && $_FILES['image_blob']['error'] === UPLOAD_ERR_OK) {
            if (move_uploaded_file($_FILES['image_blob']['tmp_name'], $target_path)) {
                write_log('IMAGE_EDIT_SAVE', "Banner elmentve ($target_folder_rel$filename)");
                echo json_encode(['success' => true, 'filename' => $filename, 'folder' => $target_folder_rel]);
                exit;
            }
        }
    }
    echo json_encode(['success' => false, 'error' => 'Hiba a kép mentése során!']);
    exit;
}

// 2. TÖMEGES MENTÉS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_batch_file'])) {
    header('Content-Type: application/json; charset=utf-8');
    $target_folder_rel = trim($_POST['target_folder'] ?? 'static/new_images/');
    $filename = trim($_POST['filename'] ?? '');

    $allowed_folders = ['static/new_images/', 'static/images/', 'static/blog_img/', 'static/themes/'];
    if (!in_array($target_folder_rel, $allowed_folders)) {
        $target_folder_rel = 'static/new_images/';
    }

    $img_dir = __DIR__ . '/' . $target_folder_rel;
    if (!is_dir($img_dir)) { @mkdir($img_dir, 0777, true); }

    if (isset($_FILES['image_blob']) && $_FILES['image_blob']['error'] === UPLOAD_ERR_OK && !empty($filename)) {
        $target_path = $img_dir . $filename;
        if (move_uploaded_file($_FILES['image_blob']['tmp_name'], $target_path)) {
            if ($target_folder_rel === 'static/images/') {
                $parts = explode('_', pathinfo($filename, PATHINFO_FILENAME));
                $sku = strtoupper($parts[0] ?? '');
                if (!empty($sku)) {
                    $stmt_p = $db->prepare("SELECT * FROM products WHERE sku = ?");
                    $stmt_p->execute([$sku]);
                    $prod = $stmt_p->fetch(PDO::FETCH_ASSOC);
                    if ($prod) {
                        $prod['group'] = $prod['group_name'];
                        process_product_images($sku, $prod);
                        $stmt_up = $db->prepare("UPDATE products SET image = ?, galeria = ?, kep_statusz = ? WHERE sku = ?");
                        $stmt_up->execute([$prod['image'], $prod['galeria'], $prod['kep_statusz'], $sku]);
                    }
                }
            }
            echo json_encode(['success' => true, 'filename' => $filename, 'folder' => $target_folder_rel]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'error' => 'Sikertelen mentés']);
    exit;
}

$preset_sku = trim($_GET['sku'] ?? '');
$preset_image = trim($_GET['image'] ?? '');
$preset_img_src = '';

if (!empty($preset_sku)) {
    $stmt_find = $db->prepare("SELECT * FROM products WHERE sku = ?");
    $stmt_find->execute([$preset_sku]);
    $found_p = $stmt_find->fetch(PDO::FETCH_ASSOC);
    if ($found_p && !empty($found_p['image'])) {
        $preset_image = $found_p['image'];
    }
}

if (empty($preset_sku) && !empty($preset_image)) {
    $img_name_only = pathinfo($preset_image, PATHINFO_FILENAME);
    $parts = explode('_', $img_name_only);
    if (!empty($parts[0])) {
        $preset_sku = strtoupper($parts[0]);
    }
}

if (!empty($preset_image) && file_exists(__DIR__ . '/static/images/' . $preset_image)) {
    $preset_img_src = 'static/images/' . $preset_image;
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Képszerkesztő Stúdió - Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 15px; color: #1e293b; }
        .container { max-width: 1280px; margin: 0 auto; background: white; padding: 18px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.04); border: 1px solid #e2e8f0; transition: all 0.2s ease; }

        /* UNIFORM BUTTON STYLES */
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-weight: 600; cursor: pointer; border: 1px solid #cbd5e1; text-align: center; font-size: 12px; background: #ffffff; color: #334155; transition: all 0.15s ease; height: 32px; box-sizing: border-box; }
        .btn:hover { background: #f1f5f9; border-color: #94a3b8; color: #0f172a; }
        
        .btn-primary { background-color: #1e3a8a; color: white; border-color: #1e3a8a; }
        .btn-primary:hover { background-color: #1d4ed8; border-color: #1d4ed8; color: white; }
        
        .btn-success { background-color: #10b981 !important; color: white !important; border-color: #10b981 !important; font-size: 13px !important; }
        .btn-success:hover { background-color: #059669 !important; border-color: #059669 !important; }

        .btn-tool { background: #ffffff !important; color: #334155 !important; border: 1px solid #cbd5e1 !important; font-size: 12px !important; }
        .btn-tool:hover { background: #e2e8f0 !important; }

         /* AI MŰVELETEK KIEMELT, ÉLÉNK GOMBJAI */
        .btn-ai-action { 
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%) !important; 
            color: #ffffff !important; 
            font-size: 12px !important; 
            padding: 7px 14px !important; 
            border: none !important; 
            box-shadow: 0 2px 6px rgba(30, 58, 138, 0.3) !important; 
            font-weight: bold !important; 
        }
        .btn-ai-action:hover { 
            background: linear-gradient(135deg, #1d4ed8 0%, #3b82f6 100%) !important; 
            transform: translateY(-1px); 
        }

        /* 1, 2, 3, 7 AI CSÚSZKÁK (ÉLÉNK KÉK) */
        input[type="range"].slider-ai {
            accent-color: #2563eb !important;
        }
        
        /* 4, 5, 6 NORMÁL TRANSZFORMÁCIÓS CSÚSZKÁK (HALVÁNY SZÜRKE) */
        input[type="range"].slider-std {
            accent-color: #94a3b8 !important;
            opacity: 0.7;
        }
        input[type="range"].slider-std:hover {
            opacity: 1;
        }

        .tabs { display: flex; margin-bottom: 15px; border-bottom: 2px solid #e2e8f0; gap: 8px; }
        .tab-btn { padding: 8px 16px; cursor: pointer; border: none; background: none; font-size: 13px; font-weight: bold; color: #64748b; border-bottom: 3px solid transparent; }
        .tab-btn.active { color: #1e3a8a; border-bottom-color: #1e3a8a; }
        .view { display: none; }
        .view.active { display: block; }

        canvas.eraser-mode { cursor: crosshair; }

        /* KÉTOSZLOPOS STÚDIÓ ELRENDEZÉS */
        .editor-split-container { width: 100%; box-sizing: border-box; }

        .editor-split-container.mode-studio {
            display: grid !important;
            grid-template-columns: 1fr 1fr !important;
            gap: 18px !important;
            align-items: start !important;
        }
        .editor-split-container.mode-studio .editor-left-pane {
            position: sticky !important;
            top: 15px !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
        }

        .editor-split-container.mode-compact {
            display: flex !important;
            flex-direction: column !important;
            gap: 15px !important;
        }
        .editor-split-container.mode-compact .editor-left-pane {
            position: static !important;
            width: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
        }

        .editor-split-container.mode-giant {
            display: grid !important;
            grid-template-columns: 1.3fr 0.7fr !important;
            gap: 18px !important;
            align-items: start !important;
        }
        .editor-split-container.mode-giant .editor-left-pane {
            position: sticky !important;
            top: 15px !important;
            display: flex !important;
            flex-direction: column !important;
            align-items: center !important;
        }

        @media screen and (max-width: 880px) {
            .editor-split-container { display: flex !important; flex-direction: column !important; }
            .editor-left-pane { position: static !important; width: 100% !important; }
        }

        .canvas-wrapper { 
            position: relative !important; 
            display: inline-block !important; 
            margin: 4px auto !important; 
            border-radius: 8px !important; 
            overflow: hidden !important; 
            border: 1px solid #cbd5e1 !important; 
            max-width: 100% !important; 
        }

        canvas { 
            max-width: 100% !important; 
            max-height: 400px !important; 
            width: auto !important; 
            height: auto !important; 
            display: block !important; 
        }

        .canvas-wrapper[data-bg="checkerboard"] {
            background-image: linear-gradient(45deg, #cbd5e1 25%, transparent 25%), linear-gradient(-45deg, #cbd5e1 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #cbd5e1 75%), linear-gradient(-45deg, transparent 75%, #cbd5e1 75%) !important;
            background-size: 18px 18px !important;
            background-position: 0 0, 0 9px, 9px -9px, -9px 0px !important;
            background-color: #ffffff !important;
        }
        .canvas-wrapper[data-bg="white"] { background-image: none !important; background-color: #ffffff !important; }
        .canvas-wrapper[data-bg="dark"] { background-image: none !important; background-color: #0f172a !important; }

        .bg-preview-btn { padding: 4px 10px; border: 1px solid #cbd5e1; border-radius: 4px; background: #ffffff; cursor: pointer; font-size: 11px; font-weight: 600; color: #475569; transition: all 0.15s ease; height: 26px; }
        .bg-preview-btn:hover, .bg-preview-btn.active { border-color: #1e3a8a; color: #1e3a8a; background: #f0f9ff; }

        /* GYORS ELŐBEÁLLÍTÁSOK UNIFORM STÍLUS */
        .preset-btn { padding: 5px 10px; border: 1px solid #cbd5e1; border-radius: 5px; background: #ffffff; color: #334155; cursor: pointer; font-size: 11px; font-weight: 600; transition: all 0.15s ease; height: 28px; }
        .preset-btn:hover { background: #f1f5f9; border-color: #1e3a8a; color: #1e3a8a; }

        .toolbar-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px; display: flex; flex-direction: column; gap: 8px; }
        .toolbar-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; justify-content: center; }
        .toolbar-label { font-size: 11px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-right: 2px; }

        .save-bar { margin-top: 10px; display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; width: 100%; }

        .overlay { display: none; position: absolute !important; top: 0 !important; left: 0 !important; width: 100% !important; height: 100% !important; background: rgba(15, 23, 42, 0.85) !important; color: white !important; z-index: 100 !important; flex-direction: column !important; justify-content: center !important; align-items: center !important; text-align: center !important; box-sizing: border-box !important; padding: 20px !important; }
        .spinner { border: 4px solid rgba(255,255,255,0.2) !important; border-top: 4px solid #10b981 !important; border-radius: 50% !important; width: 40px !important; height: 40px !important; animation: spin 0.8s linear infinite !important; margin: 0 auto 10px auto !important; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .ai-settings { background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px 12px; border-radius: 8px; font-size: 12px; }
        .slider-container { display: flex; align-items: center; gap: 8px; margin-top: 2px; margin-bottom: 6px; }
        input[type="range"] { flex-grow: 1; cursor: pointer; height: 5px; }

        /* SÖTÉT TERMINÁL-STÍLUSÚ ÁLLAPOT KANÁL (KÖVETI A FÁJLKEZELŐ NAPLÓT) */
        .terminal-status-box {
            background: #0f172a;
            color: #4ade80;
            font-family: 'Courier New', Courier, monospace;
            font-size: 12px;
            padding: 8px 12px;
            border-radius: 6px;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid #1e293b;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.5);
            width: 100%;
            box-sizing: border-box;
            text-align: left;
        }
        .terminal-dot {
            width: 8px;
            height: 8px;
            background-color: #22c55e;
            border-radius: 50%;
            box-shadow: 0 0 6px #22c55e;
            flex-shrink: 0;
        }

        .progress-container { width: 100%; background: #e2e8f0; border-radius: 6px; height: 20px; margin-top: 10px; overflow: hidden; display: none; }
        .progress-bar { height: 100%; background: #10b981; width: 0%; transition: width 0.3s; text-align: center; color: white; font-size: 11px; line-height: 20px; font-weight: bold; }
        
        .sku-box { background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 12px; border-radius: 8px; margin-bottom: 10px; text-align: left; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  
      
      /* =====================================================================
       TÖMEGES EGYSÉGESÍTŐ (BATCH VIEW) LETISZTULT KÁRTYÁS DESIGN
       ===================================================================== */
    #batchView {
        background: #ffffff;
        padding: 10px 0;
    }
    
    #batchView h3 {
        color: #1e3a8a;
        margin: 0 0 4px 0;
        font-size: 1.2em;
        font-weight: 700;
    }
    
    #batchView p {
        color: #64748b;
        font-size: 13px;
        margin: 0 0 15px 0;
    }
    
    /* Fájlválasztó Doboz (Kiemelt Letöltő Zóna) */
    #batchView input[type="file"]#fileInputBatch {
        display: block;
        width: 100%;
        padding: 14px 16px;
        background: #f0f9ff;
        border: 2px dashed #38bdf8;
        border-radius: 8px;
        box-sizing: border-box;
        text-align: center;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        color: #0369a1;
        margin-bottom: 15px;
        transition: all 0.2s ease;
    }
    
    #batchView input[type="file"]#fileInputBatch:hover {
        background: #e0f2fe;
        border-color: #0284c7;
    }
    
    /* Beállítások Szimmetrikus Rácsa (Grid) */
    #batchView .settings {
        display: grid !important;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)) !important;
        gap: 12px !important;
        margin: 15px 0 20px 0 !important;
        justify-content: stretch !important;
    }
    
    /* Egyedi Beállítás Kártyák */
    #batchView .settings > div {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 10px 12px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        box-sizing: border-box;
        transition: border-color 0.15s ease;
    }
    
    #batchView .settings > div:hover {
        border-color: #cbd5e1;
    }
    
    /* Kártya Címkék */
    #batchView .settings label {
        font-size: 11px !important;
        font-weight: 700 !important;
        color: #475569 !important;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-bottom: 5px;
        display: block;
    }
    
    /* Beviteli mezők és Lenyíló Menük */
    #batchView input[type="number"],
    #batchView select {
        width: 100% !important;
        height: 32px !important;
        padding: 4px 8px !important;
        border: 1px solid #cbd5e1 !important;
        border-radius: 6px !important;
        background: #ffffff !important;
        font-size: 12.5px !important;
        font-weight: 600 !important;
        color: #1e293b !important;
        box-sizing: border-box !important;
        outline: none;
    }
    
    #batchView input[type="number"]:focus,
    #batchView select:focus {
        border-color: #2563eb !important;
        box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15) !important;
    }
    
    /* Checkboxok kártyákon belül */
    #batchView input[type="checkbox"] {
        width: 15px;
        height: 15px;
        accent-color: #2563eb;
        cursor: pointer;
    }
    
    /* Indítás Gomb */
    #btnStartBatch {
        width: 100% !important;
        height: 42px !important;
        font-size: 14px !important;
        font-weight: 700 !important;
        border-radius: 8px !important;
        margin-top: 10px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 8px !important;
    }
    
    /* Mobilos Reszponzív Nézet */
    @media screen and (max-width: 600px) {
        #batchView .settings {
            grid-template-columns: 1fr !important;
        }
    }
  
    </style>
</head>
<body>

<div class="container" id="mainContainer">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
        <h2 style="margin:0; color:#1e293b; font-size: 1.25em;"><i class="fa fa-wand-magic-sparkles" style="color:#2563eb;"></i> AI Képszerkesztő Stúdió</h2>
        <div style="display: flex; gap: 8px; align-items: center;">
            <button type="button" onclick="openHelpModal()" class="btn"><i class="fa fa-circle-question"></i> Súgó</button>
            <button type="button" onclick="toggleViewSize()" class="btn" id="viewToggleBtn"><i class="fa fa-columns"></i> Nézet: Stúdió</button>
            <a href="admin_files.php" class="btn btn-primary">← Fájlkezelő</a>
        </div>
    </div>

    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('editor')">🖼️ Egyedi Szerkesztő</button>
        <button class="tab-btn" onclick="switchTab('batch')">⚡ Tömeges Egységesítő</button>
    </div>

    <!-- 1. EGYEDI SZERKESZTŐ NÉZET -->
    <div id="editorView" class="view active">
        
        <div class="sku-box">
            <div>
                <label style="font-weight:bold; color:#475569; display:block; font-size: 11px;">Munkaterület Mód:</label>
                <select id="canvasModeSelect" onchange="changeCanvasMode()" style="padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: bold; font-size: 12px; background: #ffffff; height:28px;">
                    <option value="product" selected>📦 Termékkép (800x800 Négyzet)</option>
                    <option value="original">📐 Eredeti Képméret (Szabad arány)</option>
                    <option value="banner">🖼️ Fejléc / Banner (1200x400)</option>
                </select>
            </div>

            <div id="productModeFields" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div>
                    <label style="font-weight:bold; color:#475569; display:block; font-size: 11px;">Cél Cikkszám (SKU) * :</label>
                    <input type="text" id="targetSku" value="<?= htmlspecialchars($preset_sku) ?>" placeholder="pl. ED15" style="padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px; width: 100px; font-weight: bold; font-size: 12px; height:28px; box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-weight:bold; color:#475569; display:block; font-size: 11px;">Mentés típusa:</label>
                    <select id="saveTypeSelect" style="padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: bold; font-size: 12px; height:28px;">
                        <option value="main">● Főképként mentés</option>
                        <option value="gallery">○ Galéria-képként hozzáadás</option>
                    </select>
                </div>
            </div>

            <div id="customModeFields" style="display: none; gap: 10px; align-items: center; flex-wrap: wrap;">
                <div>
                    <label style="font-weight:bold; color:#475569; display:block; font-size: 11px;">Kép fájlneve * :</label>
                    <input type="text" id="customFilename" placeholder="pl. fomenu_banner1" style="padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px; width: 120px; font-weight: bold; font-size: 12px; height:28px; box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-weight:bold; color:#475569; display:block; font-size: 11px;">Cél Mappa:</label>
                    <select id="targetFolderSelect" style="padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: bold; font-size: 12px; height:28px;">
                        <option value="static/images/">static/images/ (Képek)</option>
                        <option value="static/blog_img/">static/blog_img/ (Blog)</option>
                        <option value="static/themes/">static/themes/ (Arculat)</option>
                    </select>
                </div>
            </div>

            <div>
                <label style="font-weight:bold; color:#475569; display:block; font-size: 11px;">Formátum:</label>
                <select id="formatSelect" style="padding: 4px; border: 1px solid #cbd5e1; border-radius: 4px; font-weight: bold; font-size: 12px; height:28px;">
                    <option value="webp">.WebP (Ajánlott)</option>
                    <option value="png">.PNG (Átlátszó)</option>
                    <option value="jpg">.JPG (Fehér háttér)</option>
                </select>
            </div>

            <!-- ÁTHELYEZETT VÍZJEL BEÁLLÍTÁS A MENTÉS GOMB MELLŐL -->
            <div style="margin-left: auto;">
                <label style="font-size: 11px; font-weight: bold; cursor: pointer; color: #334155; display: inline-flex; align-items: center; gap: 4px; margin-top: 14px;">
                    <input type="checkbox" id="chkWatermark"> 🛡️ Vízjel ráégetése (VARIOX.HU)
                </label>
            </div>
        </div>

        <div style="text-align: left; margin-bottom: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
            <label style="font-weight: bold; font-size: 0.8em; color: #475569;">Kép kiválasztása, behúzása (Drag&Drop) vagy beillesztése (Ctrl+V):</label>
            <input type="file" id="fileInputSingle" accept="image/png, image/jpeg, image/webp" style="font-size: 11px;">
        </div>

        <!-- STÚDIÓ KONTÉNER (KÉTOSZLOPOS NÉZET) -->
        <div class="editor-split-container mode-studio" id="splitBox">
            
            <!-- BAL OSZLOP: RÖGZÍTETT VÁSZON ÉS MENTÉS -->
            <div class="editor-left-pane">
                <div style="margin-bottom: 5px; display: flex; align-items: center; justify-content: center; gap: 6px; font-size: 11px; font-weight: bold; flex-wrap: wrap;">
                    <span style="color: #64748b;">Háttér tesztelés:</span>
                    <button type="button" class="bg-preview-btn active" onclick="setCanvasBg('checkerboard', this)">🏁 Sakktábla</button>
                    <button type="button" class="bg-preview-btn" onclick="setCanvasBg('white', this)">⬜ Fehér</button>
                    <button type="button" class="bg-preview-btn" onclick="setCanvasBg('dark', this)" style="background:#0f172a; color:white;">⬛ Sötét</button>
                </div>

                <div class="canvas-wrapper" id="canvasWrapper" data-bg="checkerboard">
                    <canvas id="canvasSingle"></canvas>
                    <div class="overlay" id="loadingOverlay">
                        <div class="spinner"></div>
                        <p id="overlayText" style="font-weight:bold; font-size: 13px; margin: 0;">AI modell feldolgozás...</p>
                    </div>
                </div>

                <div class="save-bar">
                    <button id="btnSaveToWebshop" disabled class="btn btn-success" style="padding: 8px 18px; font-size: 13px;"><i class="fa fa-save"></i> 💾 MENTÉS A WEBSHOPBA</button>
                    <button id="btnDownload" disabled class="btn" style="font-size: 12px; padding: 8px 14px;"><i class="fa fa-download"></i> Letöltés</button>
                </div>

                <!-- SÖTÉT TERMINÁL-STÍLUSÚ ÁLLAPOT KANÁL -->
                <div class="terminal-status-box">
                    <span class="terminal-dot"></span>
                    <span id="statusSingle">Rendszer kész. Válassz egy képet...</span>
                </div>
            </div>

            <!-- JOBB OSZLOP: CSÚSZKÁK ÉS ESZKÖZTÁR -->
            <div class="editor-right-pane">
                
                <!-- ESZKÖZTÁR DOBOZ -->
                <div class="toolbar-box">
                    <div class="toolbar-row">
                        <span class="toolbar-label">Eszközök:</span>
                        <button id="btnCropMode" class="btn btn-tool"><i class="fa fa-crop-simple"></i> 🎯 Keret</button>
                        <button id="btnApplyCrop" disabled class="btn btn-tool" style="color:#d97706 !important;"><i class="fa fa-scissors"></i> ✂️ Vágás</button>
                        <button id="btnEraser" disabled class="btn btn-tool"><i class="fa fa-eraser"></i> 🧽 Radír</button>
                        
                        <button id="btnStudioShadow" disabled class="btn btn-tool"><i class="fa fa-moon"></i> 🌓 Árnyék</button>
                        <button id="btnGlowEffect" disabled class="btn btn-tool"><i class="fa fa-sun"></i> 💡 Ragyogás</button>   
                        <button id="btnReflection" disabled class="btn btn-tool" onclick="applyReflectionFromButton()"><i class="fa fa-images"></i> Tükröződés</button>

                        
                        <button id="btnAutoTrim" disabled class="btn btn-tool"><i class="fa fa-expand-arrows-alt"></i> 🎯 Auto-Trim</button>
                        <button id="btnUndo" disabled class="btn btn-tool"><i class="fa fa-undo"></i> ↩️ Mégse</button>
                        <button id="btnReset" disabled class="btn btn-tool" style="color:#b91c1c !important;"><i class="fa fa-rotate-left"></i> 🔄 Eredeti</button>
                    </div>

                    <div class="toolbar-row" style="border-top: 1px dashed #cbd5e1; padding-top: 8px; margin-top: 2px;">
                        <span class="toolbar-label" style="color:#1e3a8a;">AI Műveletek:</span>
                        <button id="btnRemoveBg" disabled class="btn btn-ai-action"><i class="fa fa-wand-magic-sparkles"></i> Háttér eltávolítása (AI)</button>
                        <button id="btnAutoFit" disabled class="btn btn-ai-action"><i class="fa fa-expand"></i> Középre igazítás (800x800)</button>
                    </div>
                </div>

                <!-- CSÚSZKÁK PANEL -->
              <div class="ai-settings">
                  <div style="margin-bottom: 10px; background: #ffffff; padding: 8px 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                      <strong style="color: #475569; font-size: 11px; display: block; margin-bottom: 6px;"><i class="fa fa-bolt" style="color:#f59e0b;"></i> Gyors Anyag-Előbeállítások:</strong>
                      <div style="display: flex; gap: 4px; flex-wrap: wrap;">
                          <button type="button" class="preset-btn" onclick="applyMaterialPreset('standard')">⚙️ Normál</button>
                          <button type="button" class="preset-btn" onclick="applyMaterialPreset('metal')">🔨 Fém</button>
                          <button type="button" class="preset-btn" onclick="applyMaterialPreset('brush')">🧹 Kefe / Sörtés</button>
                          <button type="button" class="preset-btn" onclick="applyMaterialPreset('pencil')">✏️ Ceruza / Hosszú</button>
                          <button type="button" class="preset-btn" onclick="applyMaterialPreset('box')">📦 Doboz / Csomag</button>
                          <button type="button" class="preset-btn" onclick="applyMaterialPreset('glass')">🧪 Flakon / Üveg</button>
                      </div>
                  </div>
              
                  <!-- AI CSÚSZKÁK (ÉLÉNK KÉK) -->
                  <label style="color:#1e3a8a;"><strong>1. AI Érzékenység / Maszkolás:</strong></label>
                  <div class="slider-container">
                      <span style="font-size: 10px; color:#64748b;">-50</span>
                      <input type="range" id="sensitivitySlider" class="slider-ai" min="-50" max="50" value="0">
                      <span style="font-size: 10px; color:#64748b;">+50</span>
                  </div>
              
                  <label style="color: #b91c1c;"><strong>2. Alpha Vágási Küszöb (Fehér fátyol ellen):</strong></label>
                  <div class="slider-container">
                      <span style="font-size: 10px; color:#64748b;">0</span>
                      <input type="range" id="cutoffSlider" class="slider-ai" min="0" max="80" value="25">
                      <span style="font-size: 10px; color:#64748b;">80</span>
                  </div>
              
                  <label style="color: #0369a1;"><strong>3. Fehér Háttér Tisztító:</strong></label>
                  <div class="slider-container">
                      <span style="font-size: 10px; color:#64748b;">Ki</span>
                      <input type="range" id="whitePurgeSlider" class="slider-ai" min="0" max="100" value="0">
                      <span style="font-size: 10px; color:#64748b;">Erős</span>
                  </div>
              
                  <!-- HALVÁNY NORMÁL TRANSZFORMÁCIÓS CSÚSZKÁK -->
                  <label style="color: #64748b; margin-top: 8px; display: block;"><strong>4. Átlós Elforgatás (Normál):</strong></label>
                  <div class="slider-container">
                      <span style="font-size: 10px; color:#94a3b8;">-180°</span>
                      <input type="range" id="rotateSlider" class="slider-std" min="-180" max="180" value="0">
                      <span style="font-size: 10px; color:#94a3b8;">+180°</span>
                      <button type="button" class="btn" onclick="setAngle(45)" style="height:22px; padding:0 6px; font-size:10px;">45°</button>
                      <button type="button" class="btn" onclick="setAngle(0)" style="height:22px; padding:0 6px; font-size:10px;">0°</button>
                  </div>
              
                  <label style="color: #64748b; margin-top: 6px; display: block;"><strong>5. Optikai Nagyítás / Zoom (Normál):</strong></label>
                  <div class="slider-container">
                      <span style="font-size: 10px; color:#94a3b8;">50%</span>
                      <input type="range" id="zoomSlider" class="slider-std" min="50" max="150" value="100">
                      <span style="font-size: 10px; color:#94a3b8;">150%</span>
                  </div>
              
                  <label style="color: #64748b; margin-top: 6px; display: block;"><strong>6. Fényerő & Szín-telítettség (Normál):</strong></label>
                  <div class="slider-container">
                      <span style="font-size: 10px; color:#94a3b8;">Sötét</span>
                      <input type="range" id="brightnessSlider" class="slider-std" min="50" max="150" value="100">
                      <span style="font-size: 10px; color:#94a3b8;">Világos</span>
                  </div>
              
<!-- 8. TÜKRÖZŐDÉS HOSSZA -->
<label style="color: #64748b; margin-top: 10px; display: block;"><strong>8. Tükröződés Hossza:</strong></label>
<div class="slider-container">
    <span style="font-size: 10px; color:#94a3b8;">10px</span>
    <input type="range" id="slideReflectionLength" class="slider-std" min="10" max="200" value="60" oninput="document.getElementById('lblReflectionLength').innerText = this.value + 'px';">
    <span style="font-size: 10px; color:#94a3b8; min-width: 35px; text-align: right;" id="lblReflectionLength">60px</span>
</div>

<!-- 9. TÜKRÖZŐDÉS SZÖGE (DŐLÉS) -->
<label style="color: #64748b; margin-top: 6px; display: block;"><strong>9. Tükröződés Szöge (Dőlés a síkon):</strong></label>
<div class="slider-container">
    <span style="font-size: 10px; color:#94a3b8;">-45°</span>
    <input type="range" id="slideReflectionSkew" class="slider-std" min="-45" max="45" value="0" oninput="document.getElementById('lblReflectionSkew').innerText = this.value + '°';">
    <span style="font-size: 10px; color:#94a3b8; min-width: 35px; text-align: right;" id="lblReflectionSkew">0°</span>
</div>

              
              
              
                   
              
              
                  <!-- AI CSÚSZKA (ÉLÉNK KÉK) -->
                  <label style="color: #059669; margin-top: 8px; display: block;"><strong>7. Tárgy-test Tömörítés (Solidify AI):</strong></label>
                  <div class="slider-container">
                      <span style="font-size: 10px; color:#64748b;">Eredeti</span>
                      <input type="range" id="solidifySlider" class="slider-ai" min="0" max="100" value="50">
                      <span style="font-size: 10px; color:#64748b;">100%</span>
                  </div>
              </div>

            </div>
        </div>
    </div>

    <!-- 2. TÖMEGES NÉZET -->
    <div id="batchView" class="view">
        <h3 style="color:#1e3a8a; margin-top:0; font-size:1.1em;">Tömeges kép egységesítés</h3>
        <p style="font-size:0.85em; color:#475569;">Méretre vágja és egységes formátumra hozza a kijelölt képeket.</p>
        
        <input type="file" id="fileInputBatch" accept="image/png, image/jpeg, image/webp" multiple style="font-size: 12px;">
        
        <div class="settings">
            <div>
                <label style="font-weight:bold; font-size:0.8em;">Cél méret (Pixel):</label><br>
                <input type="number" id="targetSize" value="800" min="100" max="2000" style="padding:4px; border:1px solid #cbd5e1; border-radius:4px; width:80px; height:28px;">
            </div>
            <div>
                <label style="font-weight:bold; font-size:0.8em;">Méretezés:</label><br>
                <label style="cursor:pointer; font-size:0.8em; font-weight:bold; color:#475569; display:inline-flex; align-items:center; gap:4px; margin-top:5px;">
                    <input type="checkbox" id="keepBatchOriginalSize"> Eredeti méret megőrzése
                </label>
            </div>
            <div>
                <label style="font-weight:bold; font-size:0.8em;">Max méret (MB):</label><br>
                <input type="number" id="maxFileSize" value="0.5" min="0.0" max="5" step="0.1" style="padding:4px; border:1px solid #cbd5e1; border-radius:4px; width:80px; height:28px;" placeholder="0 = Korlátlan">
            </div>
            <div>
                <label style="font-weight:bold; font-size:0.8em;">Formátum:</label><br>
                <select id="batchFormat" style="padding:4px; border:1px solid #cbd5e1; border-radius:4px; font-weight:bold; height:28px;">
                    <option value="webp">.WebP</option>
                    <option value="png">.PNG</option>
                    <option value="jpg">.JPG</option>
                </select>
            </div>
            <div>
                <label style="font-weight:bold; font-size:0.8em;">Mentés helye:</label><br>
                <select id="batchDestination" style="padding:4px; border:1px solid #cbd5e1; border-radius:4px; font-weight:bold; color:#1e3a8a; height:28px;">
                    <option value="static/new_images/" selected>📂 static/new_images/</option>
                    <option value="static/images/">🚀 static/images/</option>
                    <option value="download">📥 Letöltés a gépre</option>
                </select>
            </div>
            <div>
                <label style="font-weight:bold; font-size:0.8em;">AI Háttéreltávolítás:</label><br>
                <label style="cursor:pointer; font-size:0.8em; font-weight:bold; color:#475569;">
                    <input type="checkbox" id="useBatchAI"> Be van kapcsolva
                </label>
            </div>
            <div>
                <label style="font-weight:bold; font-size:0.8em;">Vízjelezés:</label><br>
                <label style="cursor:pointer; font-size:0.8em; font-weight:bold; color:#475569;">
                    <input type="checkbox" id="chkBatchWatermark"> VARIOX.HU vízjel
                </label>
            </div>
        </div>

        <button id="btnStartBatch" disabled class="btn btn-success" style="width:100%; padding:10px; height:auto; justify-content:center;"><i class="fa fa-play"></i> Indítás (Tömeges Feldolgozás)</button>
        
        <div class="progress-container" id="progressContainer">
            <div class="progress-bar" id="progressBar">0%</div>
        </div>
        
        <div class="terminal-status-box" style="margin-top:15px;">
            <span class="terminal-dot"></span>
            <span id="statusBatch">Válassz ki képeket a kezdéshez...</span>
        </div>
        <div id="log"></div>
    </div>
</div>

<script src="static/models/wasm/ort.min.js" onerror="loadOrtCdn()"></script>
<script>
function loadOrtCdn() {
    if (typeof ort === 'undefined') {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.18.0/dist/ort.min.js';
        s.onload = function() { if (typeof initAI === 'function') initAI(); };
        document.head.appendChild(s);
    }
}

function setCanvasBg(mode, btn) {
    const wrapper = document.getElementById('canvasWrapper');
    if (!wrapper) return;
    wrapper.style.backgroundImage = '';
    wrapper.style.backgroundColor = '';
    wrapper.setAttribute('data-bg', mode);
    document.querySelectorAll('.bg-preview-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
}
</script>

<script>
window.presetSku = "<?= htmlspecialchars($preset_sku) ?>";
window.presetImgSrc = "<?= htmlspecialchars($preset_img_src) ?>";

function safeBind(id, eventType, fn) {
    const el = document.getElementById(id);
    if (el) {
        el.addEventListener(eventType, fn);
    }
}

function updateStatus(msg) {
    const s = document.getElementById('statusSingle');
    const b = document.getElementById('statusBatch');
    if (s) s.innerText = msg;
    if (b) b.innerText = msg;
}

const canvasS = document.getElementById('canvasSingle');
const ctxS = canvasS ? canvasS.getContext('2d', { willReadFrequently: true }) : null;
const overlay = document.getElementById('loadingOverlay');

let originalImage = null;
let workingImage = null;
let historyStack = [];

let isErasing = false;
let eraserMode = false;
let cropMode = false;
let isCropping = false;
let cropStartX = 0, cropStartY = 0;
let cropBox = null;

let ortSession = null;
if (typeof ort !== 'undefined') {
    ort.env.wasm.wasmPaths = 'static/models/wasm/'; 
    ort.env.wasm.numThreads = 1;
}

async function initAI() {
    if (typeof ort === 'undefined') {
        setTimeout(initAI, 150);
        return;
    }

    updateStatus("AI Modell inicializálása...");
    try {
        ortSession = await ort.InferenceSession.create('static/models/isnet_fp16/model.onnx');
        updateStatus("AI Modell kész (Helyi offline). Válassz képet!");
    } catch (e) { 
        console.log("Helyi modell hiba, átváltás hálózati CDN-re...", e); 
        try {
            ort.env.wasm.wasmPaths = 'https://cdn.jsdelivr.net/npm/onnxruntime-web@1.18.0/dist/';
            ortSession = await ort.InferenceSession.create('models/isnet_fp16/model.onnx');
            updateStatus("AI Modell kész (CDN hálózati). Válassz képet!");
        } catch(err) {
            updateStatus("HIBA: Az AI modellt nem sikerült betölteni!");
        }
    }
}

document.addEventListener('DOMContentLoaded', initAI);

// 3-FOKOZATÚ NÉZETVÁLTÓ (STÚDIÓ, KOMPAKT, ÓRIÁS VÁSZON)
var viewState = 0;
function toggleViewSize() {
    const container = document.getElementById('mainContainer');
    const splitBox = document.getElementById('splitBox');
    const btn = document.getElementById('viewToggleBtn');
    
    if (!container || !splitBox || !btn) return;

    viewState = (viewState + 1) % 3;
    splitBox.classList.remove('mode-studio', 'mode-compact', 'mode-giant');

    if (viewState === 0) {
        container.style.maxWidth = '1280px';
        splitBox.classList.add('mode-studio');
        btn.innerHTML = '<i class="fa fa-columns"></i> Nézet: Stúdió';
    } else if (viewState === 1) {
        container.style.maxWidth = '800px';
        splitBox.classList.add('mode-compact');
        btn.innerHTML = '<i class="fa fa-compress"></i> Nézet: Kompakt';
    } else {
        container.style.maxWidth = '100%';
        splitBox.classList.add('mode-giant');
        btn.innerHTML = '<i class="fa fa-expand"></i> Nézet: Óriás';
    }
}

function switchTab(tabName) {
    document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    if (tabName === 'editor') {
        document.getElementById('editorView').classList.add('active');
        document.querySelectorAll('.tab-btn')[0].classList.add('active');
    } else {
        document.getElementById('batchView').classList.add('active');
        document.querySelectorAll('.tab-btn')[1].classList.add('active');
    }
}

function changeCanvasMode() {
    const mode = document.getElementById('canvasModeSelect').value;
    const prodFields = document.getElementById('productModeFields');
    const customFields = document.getElementById('customModeFields');

    if (mode === 'product') {
        if (prodFields) prodFields.style.display = 'flex';
        if (customFields) customFields.style.display = 'none';
    } else {
        if (prodFields) prodFields.style.display = 'none';
        if (customFields) customFields.style.display = 'flex';
    }

    applyTransforms();
}

function applyMaterialPreset(type) {
    saveHistory();
    const presets = {
        'standard': { sens: 0,  cutoff: 25, purge: 0,  rotate: 0,  zoom: 100, bright: 100, solidify: 50 },
        'metal':    { sens: 20, cutoff: 35, purge: 30, rotate: 0,  zoom: 100, bright: 105, solidify: 80 },
        'brush':    { sens: -10,cutoff: 15, purge: 10, rotate: 0,  zoom: 100, bright: 100, solidify: 35 },
        'pencil':   { sens: 10, cutoff: 25, purge: 20, rotate: 45, zoom: 115, bright: 100, solidify: 65 },
        'box':      { sens: 5,  cutoff: 30, purge: 15, rotate: 0,  zoom: 100, bright: 100, solidify: 90 }, // Doboz profil
        'glass':    { sens: -15,cutoff: 10, purge: 5,  rotate: 0,  zoom: 100, bright: 100, solidify: 25 }  // Flakon/Üveg profil
    };
    const p = presets[type] || presets['standard'];

    if(document.getElementById('sensitivitySlider')) document.getElementById('sensitivitySlider').value = p.sens;
    if(document.getElementById('cutoffSlider')) document.getElementById('cutoffSlider').value = p.cutoff;
    if(document.getElementById('whitePurgeSlider')) document.getElementById('whitePurgeSlider').value = p.purge;
    if(document.getElementById('rotateSlider')) document.getElementById('rotateSlider').value = p.rotate;
    if(document.getElementById('zoomSlider')) document.getElementById('zoomSlider').value = p.zoom;
    if(document.getElementById('brightnessSlider')) document.getElementById('brightnessSlider').value = p.bright;
    if(document.getElementById('solidifySlider')) document.getElementById('solidifySlider').value = p.solidify;

    applyTransforms();
    
    const names = {
        'standard': 'Normál alapértelmezés',
        'metal': 'Fém / Csillogó szerszám profil',
        'brush': 'Kefe / Finom sörtés profil',
        'pencil': 'Ácsceruza / Hosszú tárgy profil',
        'box': 'Doboz / Csomagolt áru profil',
        'glass': 'Flakon / Félig áttetsző üveg profil'
    };
    updateStatus(`✓ Gyorsbeállítás betöltve: ${names[type] || ''}`);
}

async function processAI(canvas, ctx, sensitivity = 0, alphaCutoff = 25, whitePurge = 0, solidify = 50) {
    const modelInputSize = 1024;
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = modelInputSize; tempCanvas.height = modelInputSize;
    const tempCtx = tempCanvas.getContext('2d', { willReadFrequently: true });
    tempCtx.drawImage(canvas, 0, 0, modelInputSize, modelInputSize);
    
    const tempData = tempCtx.getImageData(0, 0, modelInputSize, modelInputSize).data;
    const inputTensor = new Float32Array(1 * 3 * modelInputSize * modelInputSize);
    for (let i = 0; i < tempData.length; i += 4) {
        inputTensor[i / 4] = tempData[i] / 255.0;
        inputTensor[(i / 4) + (modelInputSize * modelInputSize)] = tempData[i + 1] / 255.0;
        inputTensor[(i / 4) + 2 * (modelInputSize * modelInputSize)] = tempData[i + 2] / 255.0;
    }
    const tensor = new ort.Tensor('float32', inputTensor, [1, 3, modelInputSize, modelInputSize]);
    const results = await ortSession.run({ [ortSession.inputNames[0]]: tensor });
    const output = results[ortSession.outputNames[0]].data;

    const imgData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const data = imgData.data;
    let minX = canvas.width, minY = canvas.height, maxX = 0, maxY = 0;
    const bias = sensitivity / 100.0;
    const cutoffThreshold = (alphaCutoff / 100.0) * 255.0;

    for (let y = 0; y < canvas.height; y++) {
        for (let x = 0; x < canvas.width; x++) {
            const maskX = Math.floor(x * (modelInputSize / canvas.width));
            const maskY = Math.floor(y * (modelInputSize / canvas.height));
            const maskIdx = maskY * modelInputSize + maskX;
            
            let adjustedAlpha = output[maskIdx] - bias;
            if (adjustedAlpha < 0) adjustedAlpha = 0; if (adjustedAlpha > 1) adjustedAlpha = 1;
            
            let finalAlpha = Math.round(adjustedAlpha * 255);
            const pixelIdx = (y * canvas.width + x) * 4;

            if (finalAlpha < cutoffThreshold) { finalAlpha = 0; }

            if (solidify > 0 && finalAlpha > 30) {
                let norm = finalAlpha / 255.0;
                let boosted = Math.pow(norm, 1.0 - (solidify / 120.0));
                finalAlpha = Math.min(255, Math.round(boosted * 255));
            }

            if (whitePurge > 0 && finalAlpha > 0) {
                const r = data[pixelIdx];
                const g = data[pixelIdx + 1];
                const b = data[pixelIdx + 2];
                const brightness = (r + g + b) / 3.0;
                const whiteLimit = 255.0 - (whitePurge * 1.2);
                if (brightness > whiteLimit) {
                    const factor = (255.0 - brightness) / (255.0 - whiteLimit);
                    finalAlpha = Math.round(finalAlpha * Math.max(0, factor));
                }
            }

            data[pixelIdx + 3] = finalAlpha;
            if (finalAlpha > 30) {
                if (x < minX) minX = x; if (x > maxX) maxX = x;
                if (y < minY) minY = y; if (y > maxY) maxY = y;
            }
        }
    }
    ctx.putImageData(imgData, 0, 0);
    return { minX, minY, maxX, maxY };
}


// === PROFESSZIONÁLIS PARAMÉTEREZHETŐ TÜKRÖZŐDÉS (KÖZVETLEN VÁSZNON ALAPULÓ) ===
function applyReflectionFromButton() {
    if (!canvasS || !ctxS) return;

    // 1. Csúszkák értékeinek beolvasása
    const lengthInput = document.getElementById('slideReflectionLength');
    const skewInput = document.getElementById('slideReflectionSkew');
    
    const currentLength = lengthInput ? parseInt(lengthInput.value) : 60;
    const currentSkewDeg = skewInput ? parseInt(skewInput.value) : 0;

    // KÉPERNYŐN LÉVŐ VÁSZON KÖZVETLEN BEOLVASÁSA (Ez oldja meg a szinkronizációs hibát!)
    const w = canvasS.width;
    const h = canvasS.height;

    // Eredeti kép másolása a vászonról egy ideiglenes tárolóba
    const srcCanvas = document.createElement('canvas');
    srcCanvas.width = w;
    srcCanvas.height = h;
    const srcCtx = srcCanvas.getContext('2d', { willReadFrequently: true });
    srcCtx.drawImage(canvasS, 0, 0); // <-- EZ A LÉNYEG! A látható állapotot menti el

    // 2. PIXEL DETEKTÁLÁS: Megkeressük a tárgy legalját
    const imgData = srcCtx.getImageData(0, 0, w, h);
    const data = imgData.data;
    let objectBottomY = 0;
    let totalX = 0;
    let countX = 0;

    for (let y = h - 1; y >= 0; y--) {
        let foundInRow = false;
        for (let x = 0; x < w; x++) {
            const alphaIdx = (y * w + x) * 4 + 3;
            if (data[alphaIdx] > 10) {
                if (objectBottomY === 0) objectBottomY = y;
                if (y > objectBottomY - 15) {
                    totalX += x;
                    countX++;
                }
                foundInRow = true;
            }
        }
        if (objectBottomY > 0 && y < objectBottomY - 15) break;
    }

    // Ha nincs látható tárgy a képen (mindent töröltek)
    if (objectBottomY === 0) {
        if (typeof updateStatus === 'function') updateStatus("Hiba: Nem található tárgy a vásznon!");
        return;
    }

    const pivotX = countX > 0 ? Math.round(totalX / countX) : w / 2;

    // 3. Ideiglenes vászon a tükörkép transzformációjához
    const reflCanvas = document.createElement('canvas');
    reflCanvas.width = w;
    reflCanvas.height = h;
    const reflCtx = reflCanvas.getContext('2d');

    const skewRad = (currentSkewDeg * Math.PI) / 180;

    reflCtx.save();
    reflCtx.translate(pivotX, objectBottomY);
    reflCtx.scale(1, -1);
    reflCtx.transform(1, 0, Math.tan(skewRad), 1, 0, 0);
    reflCtx.translate(-pivotX, -objectBottomY);
    reflCtx.drawImage(srcCanvas, 0, 0);
    reflCtx.restore();

    // 4. DINAMIKUS ELHALVÁNYÍTÁS
    const reflectionH = Math.min(currentLength, h - objectBottomY);
    
    reflCtx.globalCompositeOperation = 'destination-in';
    const gradient = reflCtx.createLinearGradient(0, objectBottomY, 0, objectBottomY + reflectionH);
    gradient.addColorStop(0, 'rgba(0, 0, 0, 0.35)');   
    gradient.addColorStop(0.4, 'rgba(0, 0, 0, 0.15)'); 
    gradient.addColorStop(1, 'rgba(0, 0, 0, 0.0)');     
    
    reflCtx.fillStyle = gradient;
    reflCtx.fillRect(0, objectBottomY, w, reflectionH + 1); 
    reflCtx.globalCompositeOperation = 'source-over';

    // 5. Végleges vászon összeállítása
    const finalCanvas = document.createElement('canvas');
    finalCanvas.width = w;
    finalCanvas.height = h;
    const finalCtx = finalCanvas.getContext('2d');

    finalCtx.drawImage(reflCanvas, 0, 0);
    finalCtx.drawImage(srcCanvas, 0, 0);

    // 6. Rendszerszintű mentés és frissítés
    if (typeof saveHistory === 'function') saveHistory();

    // AZONNALI MEGJELENÍTÉS: Egyből a képernyőre rajzoljuk, nincs késleltetés!
    ctxS.clearRect(0, 0, w, h);
    ctxS.drawImage(finalCanvas, 0, 0);

    // Frissítjük a memóriát is a háttérben
    const newWorkingImg = new Image();
    newWorkingImg.crossOrigin = "Anonymous";
    newWorkingImg.onload = function() {
        workingImage = newWorkingImg;
        // Visszaállítjuk a transzformációs csúszkákat, hogy ne legyen dupla effekt
        if(document.getElementById('rotateSlider')) document.getElementById('rotateSlider').value = 0;
        if(document.getElementById('zoomSlider')) document.getElementById('zoomSlider').value = 100;
        if (typeof updateStatus === 'function') {
            updateStatus("✓ Tükröződés alkalmazva! (Módosításhoz előbb kattints a Mégse gombra)");
        }
    };
    newWorkingImg.src = finalCanvas.toDataURL('image/png');
}



// AUTOMATIKUS KERETVÁGÓ
function autoTrimCanvas(inputCanvas, targetSize = 800, paddingPercent = 0.85, whiteTolerance = 20) {
    const ctx = inputCanvas.getContext('2d', { willReadFrequently: true });
    const w = inputCanvas.width;
    const h = inputCanvas.height;
    const imgData = ctx.getImageData(0, 0, w, h);
    const data = imgData.data;

    let minX = w, minY = h, maxX = 0, maxY = 0;
    let found = false;

    for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
            const idx = (y * w + x) * 4;
            const r = data[idx], g = data[idx + 1], b = data[idx + 2], a = data[idx + 3];

            const isTransparent = (a < 20);
            const isWhite = (r >= 255 - whiteTolerance && g >= 255 - whiteTolerance && b >= 255 - whiteTolerance);

            if (!isTransparent && !isWhite) {
                if (x < minX) minX = x;
                if (x > maxX) maxX = x;
                if (y < minY) minY = y;
                if (y > maxY) maxY = y;
                found = true;
            }
        }
    }

    if (!found || maxX <= minX || maxY <= minY) return inputCanvas;

    const cropW = maxX - minX;
    const cropH = maxY - minY;

    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = cropW; tempCanvas.height = cropH;
    tempCanvas.getContext('2d').drawImage(inputCanvas, minX, minY, cropW, cropH, 0, 0, cropW, cropH);

    const finalCanvas = document.createElement('canvas');
    finalCanvas.width = targetSize; finalCanvas.height = targetSize;
    const finalCtx = finalCanvas.getContext('2d');
    finalCtx.clearRect(0, 0, targetSize, targetSize);

    const scale = Math.min((targetSize * paddingPercent) / cropW, (targetSize * paddingPercent) / cropH);
    const fitW = cropW * scale;
    const fitH = cropH * scale;

    finalCtx.drawImage(tempCanvas, (targetSize - fitW) / 2, (targetSize - fitH) / 2, fitW, fitH);

    return finalCanvas;
}

// VÍZJEL RÁÉGETŐ FÜGGVÉNY
function applyWatermarkToCanvas(targetCtx, width, height, watermarkText = 'VARIOX.HU') {
    targetCtx.save();
    const fontSize = Math.max(14, Math.round(width * 0.028));
    targetCtx.font = `bold ${fontSize}px Arial, sans-serif`;
    targetCtx.textAlign = 'right';
    targetCtx.textBaseline = 'bottom';

    const padding = Math.round(width * 0.025);
    const x = width - padding;
    const y = height - padding;

    targetCtx.strokeStyle = 'rgba(0, 0, 0, 0.4)';
    targetCtx.lineWidth = 3;
    targetCtx.strokeText(watermarkText, x, y);

    targetCtx.fillStyle = 'rgba(255, 255, 255, 0.65)';
    targetCtx.fillText(watermarkText, x, y);
    targetCtx.restore();
}

function saveHistory() {
    if (canvasS && canvasS.width > 0 && canvasS.height > 0) {
        historyStack.push(ctxS.getImageData(0, 0, canvasS.width, canvasS.height));
        if (historyStack.length > 15) historyStack.shift();
        const btnUndo = document.getElementById('btnUndo');
        if (btnUndo) btnUndo.disabled = false;
    }
}

function commitCanvasToWorkingImage() {
    const tempImg = new Image();
    tempImg.crossOrigin = "Anonymous";
    tempImg.onload = () => {
        workingImage = tempImg;
        if(document.getElementById('rotateSlider')) document.getElementById('rotateSlider').value = 0;
        if(document.getElementById('zoomSlider')) document.getElementById('zoomSlider').value = 100;
    };
    tempImg.src = canvasS.toDataURL('image/png');
}

function loadImgToCanvas(imgSrc) {
    const img = new Image();
    img.crossOrigin = "Anonymous";
    img.onload = () => {
        originalImage = img;
        workingImage = img;
        historyStack = [];
        if(document.getElementById('rotateSlider')) document.getElementById('rotateSlider').value = 0;
        if(document.getElementById('zoomSlider')) document.getElementById('zoomSlider').value = 100;
        if(document.getElementById('brightnessSlider')) document.getElementById('brightnessSlider').value = 100;
        applyTransforms();
        saveHistory();
        updateStatus("Kép betöltve a vászonra.");
        document.querySelectorAll('#editorView button').forEach(b => b.disabled = false);
    };
    img.src = imgSrc;
}

document.addEventListener('DOMContentLoaded', () => {
    if (window.presetImgSrc) {
        loadImgToCanvas(window.presetImgSrc);
    }
});

safeBind('fileInputSingle', 'change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const skuInput = document.getElementById('targetSku');
    const customInput = document.getElementById('customFilename');
    const fname = file.name.split('.')[0];
    if (skuInput && !skuInput.value.trim()) {
        const parts = fname.split('_');
        if (parts[0]) skuInput.value = parts[0].toUpperCase();
    }
    if (customInput && !customInput.value.trim()) customInput.value = fname.toLowerCase();
    loadImgToCanvas(URL.createObjectURL(file));
});

function showOverlay(show) { if (overlay) overlay.style.display = show ? 'flex' : 'none'; }
function updateOverlayText(txt) { const el = document.getElementById('overlayText'); if (el) el.innerText = txt; }

function setAngle(deg) {
    const el = document.getElementById('rotateSlider');
    if (el) el.value = deg;
    saveHistory();
    applyTransforms();
}

safeBind('rotateSlider', 'change', saveHistory);
safeBind('zoomSlider', 'change', saveHistory);
safeBind('brightnessSlider', 'change', saveHistory);

safeBind('rotateSlider', 'input', applyTransforms);
safeBind('zoomSlider', 'input', applyTransforms);
safeBind('brightnessSlider', 'input', applyTransforms);

function applyTransforms() {
    const activeImg = workingImage || originalImage;
    if (!activeImg || !canvasS || !ctxS) return;
    
    const mode = document.getElementById('canvasModeSelect').value;
    const angle = parseInt(document.getElementById('rotateSlider').value || 0) * Math.PI / 180;
    const zoom = parseInt(document.getElementById('zoomSlider').value || 100) / 100.0;
    const bright = parseInt(document.getElementById('brightnessSlider').value || 100);

    let targetW = 800; let targetH = 800;
    if (mode === 'original') {
        targetW = activeImg.naturalWidth || activeImg.width || 800;
        targetH = activeImg.naturalHeight || activeImg.height || 800;
    } else if (mode === 'banner') {
        targetW = 1200; targetH = 400;
    }

    canvasS.width = targetW; canvasS.height = targetH;
    ctxS.clearRect(0, 0, targetW, targetH);

    ctxS.save();
    ctxS.filter = `brightness(${bright}%)`;
    ctxS.translate(targetW / 2, targetH / 2);
    ctxS.rotate(angle);
    ctxS.scale(zoom, zoom);

    const fitScale = Math.min((targetW * 0.85) / activeImg.width, (targetH * 0.85) / activeImg.height);
    const w = activeImg.width * fitScale;
    const h = activeImg.height * fitScale;

    ctxS.drawImage(activeImg, -w / 2, -h / 2, w, h);
    ctxS.restore();
}

// INTERAKTÍV KIJELÖLŐ & KIVÁGÓ ESZKÖZ
safeBind('btnCropMode', 'click', () => {
    cropMode = !cropMode;
    const btnCrop = document.getElementById('btnCropMode');
    if (cropMode) {
        eraserMode = false;
        canvasS.classList.remove('eraser-mode');
        canvasS.style.cursor = 'crosshair';
        updateStatus("Kijelölő keret bekapcsolva: Húzz egy téglalapot a kívánt tárgyra!");
        if (btnCrop) btnCrop.style.background = '#e2e8f0';
    } else {
        canvasS.style.cursor = 'default';
        if (btnCrop) btnCrop.style.background = '#ffffff';
        cropBox = null;
        applyTransforms();
        updateStatus("Kijelölő keret kikapcsolva.");
        const btnApply = document.getElementById('btnApplyCrop');
        if (btnApply) btnApply.disabled = true;
    }
});

function drawCropOverlay(x, y, w, h) {
    ctxS.save();
    ctxS.fillStyle = 'rgba(15, 23, 42, 0.5)';
    ctxS.fillRect(0, 0, canvasS.width, y);
    ctxS.fillRect(0, y, x, h);
    ctxS.fillRect(x + w, y, canvasS.width - (x + w), h);
    ctxS.fillRect(0, y + h, canvasS.width, canvasS.height - (y + h));

    ctxS.strokeStyle = '#ffffff';
    ctxS.lineWidth = 2;
    ctxS.setLineDash([6, 4]);
    ctxS.strokeRect(x, y, w, h);
    ctxS.restore();
}

safeBind('btnApplyCrop', 'click', () => {
    if (!cropBox || cropBox.w <= 0 || cropBox.h <= 0) return;

    saveHistory();
    const croppedData = ctxS.getImageData(cropBox.x, cropBox.y, cropBox.w, cropBox.h);

    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = cropBox.w; tempCanvas.height = cropBox.h;
    tempCanvas.getContext('2d', { willReadFrequently: true }).putImageData(croppedData, 0, 0);

    let targetW = canvasS.width; let targetH = canvasS.height;
    ctxS.clearRect(0, 0, targetW, targetH);

    const scale = Math.min((targetW * 0.85) / cropBox.w, (targetH * 0.85) / cropBox.h);
    const fitW = cropBox.w * scale;
    const fitH = cropBox.h * scale;

    ctxS.drawImage(tempCanvas, (targetW - fitW) / 2, (targetH - fitH) / 2, fitW, fitH);
    commitCanvasToWorkingImage();

    cropMode = false;
    canvasS.style.cursor = 'default';
    const btnCrop = document.getElementById('btnCropMode');
    if (btnCrop) btnCrop.style.background = '#ffffff';
    const btnApply = document.getElementById('btnApplyCrop');
    if (btnApply) btnApply.disabled = true;
    cropBox = null;

    updateStatus("✂️ Kijelölés sikeresen kivágva és középre igazítva!");
});

// INTERAKTÍV RADÍR
safeBind('btnEraser', 'click', () => {
    eraserMode = !eraserMode;
    const btnCrop = document.getElementById('btnCropMode');
    if (eraserMode) {
        cropMode = false;
        if (btnCrop) btnCrop.style.background = '#ffffff';
        saveHistory();
        canvasS.classList.add('eraser-mode');
        updateStatus("Radír bekapcsolva. Húzd az egeret a képen.");
    } else {
        canvasS.classList.remove('eraser-mode');
        updateStatus("Radír kikapcsolva.");
    }
});

// EGÉR ESEMÉNYEK A VÁSZNON
if (canvasS) {
    canvasS.addEventListener('mousedown', (e) => { 
        if (cropMode) {
            saveHistory();
            isCropping = true;
            const rect = canvasS.getBoundingClientRect();
            const scaleX = canvasS.width / rect.width;
            const scaleY = canvasS.height / rect.height;
            cropStartX = (e.clientX - rect.left) * scaleX;
            cropStartY = (e.clientY - rect.top) * scaleY;
            cropBox = { x: cropStartX, y: cropStartY, w: 0, h: 0 };
        } else if (eraserMode) {
            saveHistory();
            isErasing = true; 
        }
    });

    canvasS.addEventListener('mouseup', () => { 
        if (isCropping) {
            isCropping = false;
            if (cropBox && cropBox.w > 10 && cropBox.h > 10) {
                const btnApply = document.getElementById('btnApplyCrop');
                if (btnApply) btnApply.disabled = false;
                updateStatus("Kijelölés kész! Kattints a [✂️ Vágás] gombra.");
            }
        } else if (isErasing) {
            isErasing = false; 
            commitCanvasToWorkingImage();
        }
    });

    canvasS.addEventListener('mouseleave', () => { 
        if (isCropping) { isCropping = false; } 
        else if (isErasing) { isErasing = false; commitCanvasToWorkingImage(); }
    });

    canvasS.addEventListener('mousemove', (e) => {
        if (cropMode && isCropping) {
            const rect = canvasS.getBoundingClientRect();
            const scaleX = canvasS.width / rect.width;
            const scaleY = canvasS.height / rect.height;
            const currentX = (e.clientX - rect.left) * scaleX;
            const currentY = (e.clientY - rect.top) * scaleY;

            const x = Math.min(cropStartX, currentX);
            const y = Math.min(cropStartY, currentY);
            const w = Math.abs(currentX - cropStartX);
            const h = Math.abs(currentY - cropStartY);

            cropBox = { x, y, w, h };
            applyTransforms();
            drawCropOverlay(x, y, w, h);
        } else if (eraserMode && isErasing) {
            const rect = canvasS.getBoundingClientRect();
            const scaleX = canvasS.width / rect.width;
            const scaleY = canvasS.height / rect.height;
            const x = (e.clientX - rect.left) * scaleX;
            const y = (e.clientY - rect.top) * scaleY;
            
            ctxS.save();
            ctxS.globalCompositeOperation = 'destination-out';
            ctxS.beginPath();
            ctxS.arc(x, y, 20, 0, Math.PI * 2);
            ctxS.fill();
            ctxS.restore();
        }
    });
}

// DRAG & DROP FÁJL BEHÚZÁS
const canvasWrap = document.getElementById('canvasWrapper');
if (canvasWrap) {
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        canvasWrap.addEventListener(eventName, (e) => { e.preventDefault(); e.stopPropagation(); }, false);
    });

    ['dragenter', 'dragover'].forEach(eventName => {
        canvasWrap.addEventListener(eventName, () => {
            canvasWrap.style.borderColor = '#10b981';
            canvasWrap.style.boxShadow = '0 0 0 3px rgba(16, 185, 129, 0.3)';
        }, false);
    });

    ['dragleave', 'drop'].forEach(eventName => {
        canvasWrap.addEventListener(eventName, () => {
            canvasWrap.style.borderColor = '#cbd5e1';
            canvasWrap.style.boxShadow = 'none';
        }, false);
    });

    canvasWrap.addEventListener('drop', (e) => {
        const dt = e.dataTransfer;
        const files = dt.files;
        if (files && files.length > 0) {
            const file = files[0];
            if (file.type.startsWith('image/')) {
                const skuInput = document.getElementById('targetSku');
                if (skuInput && !skuInput.value.trim()) {
                    const fname = file.name.split('.')[0];
                    const parts = fname.split('_');
                    if (parts[0]) skuInput.value = parts[0].toUpperCase();
                }
                loadImgToCanvas(URL.createObjectURL(file));
                updateStatus("🖱️ Kép sikeresen behúzva az egérrel!");
            }
        }
    });
}

// STÚDIÓ ÁRNYÉK
safeBind('btnStudioShadow', 'click', () => {
    saveHistory();
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = canvasS.width; tempCanvas.height = canvasS.height;
    const tempCtx = tempCanvas.getContext('2d');
    tempCtx.drawImage(canvasS, 0, 0);

    ctxS.clearRect(0, 0, canvasS.width, canvasS.height);
    ctxS.save();
    ctxS.shadowColor = 'rgba(0, 0, 0, 0.35)';
    ctxS.shadowBlur = 22;
    ctxS.shadowOffsetY = 12;
    ctxS.drawImage(tempCanvas, 0, 0);
    ctxS.restore();

    commitCanvasToWorkingImage();
    updateStatus("🌓 Stúdió-árnyék hozzáadva!");
});

// SÖTÉT HÁTTÉR RAGYOGÁS
safeBind('btnGlowEffect', 'click', () => {
    saveHistory();
    const tempCanvas = document.createElement('canvas');
    tempCanvas.width = canvasS.width; tempCanvas.height = canvasS.height;
    const tempCtx = tempCanvas.getContext('2d');
    tempCtx.drawImage(canvasS, 0, 0);

    ctxS.clearRect(0, 0, canvasS.width, canvasS.height);
    
    ctxS.save();
    ctxS.shadowColor = 'rgba(255, 255, 255, 0.7)';
    ctxS.shadowBlur = 25;
    ctxS.shadowOffsetX = 0;
    ctxS.shadowOffsetY = 0;
    ctxS.drawImage(tempCanvas, 0, 0);
    
    ctxS.shadowColor = 'rgba(56, 189, 248, 0.4)';
    ctxS.shadowBlur = 12;
    ctxS.drawImage(tempCanvas, 0, 0);
    ctxS.restore();

    commitCanvasToWorkingImage();
    updateStatus("💡 Fény-Aura / Ragyogás hozzáadva!");
});

// AUTO-TRIM KERETVÁGÓ
safeBind('btnAutoTrim', 'click', () => {
    saveHistory();
    const resultCanvas = autoTrimCanvas(canvasS, 800, 0.85, 20);
    canvasS.width = 800; canvasS.height = 800;
    ctxS.clearRect(0, 0, 800, 800);
    ctxS.drawImage(resultCanvas, 0, 0);
    commitCanvasToWorkingImage();
    updateStatus("🎯 Auto-Trim keretvágás elvégezve!");
});

// AI HÁTTÉRELTÁVOLÍTÁS
safeBind('btnRemoveBg', 'click', async () => {
    saveHistory(); showOverlay(true); updateOverlayText("AI Háttér-eltávolítás...");
    await new Promise(r => setTimeout(r, 60));
    try {
        const sens = parseInt(document.getElementById('sensitivitySlider').value || 0);
        const cutoff = parseInt(document.getElementById('cutoffSlider').value || 25);
        const whitePurge = parseInt(document.getElementById('whitePurgeSlider').value || 0);
        const solidify = parseInt(document.getElementById('solidifySlider').value || 50);
        
        await processAI(canvasS, ctxS, sens, cutoff, whitePurge, solidify);
        commitCanvasToWorkingImage();
        updateStatus("Háttér eltávolítva!");
    } catch (err) { alert("Hiba az AI során: " + err.message); } finally { showOverlay(false); }
});

// AI KÖZÉPRE IGAZÍTÁS
safeBind('btnAutoFit', 'click', async () => {
    saveHistory(); showOverlay(true); updateOverlayText("Termék középre igazítása...");
    await new Promise(r => setTimeout(r, 60));
    try {
        const bounds = await processAI(canvasS, ctxS, 0, 25, 0, 50);
        const cropW = bounds.maxX - bounds.minX; const cropH = bounds.maxY - bounds.minY;
        if (cropW <= 0 || cropH <= 0) { alert("Nem található termék!"); return; }
        
        const tempCanvas = document.createElement('canvas');
        tempCanvas.width = cropW; tempCanvas.height = cropH;
        tempCanvas.getContext('2d').drawImage(canvasS, bounds.minX, bounds.minY, cropW, cropH, 0, 0, cropW, cropH);
        
        ctxS.clearRect(0, 0, canvasS.width, canvasS.height);
        const scale = Math.min((canvasS.width * 0.85) / cropW, (canvasS.height * 0.85) / cropH);
        ctxS.drawImage(tempCanvas, (canvasS.width - cropW * scale) / 2, (canvasS.height - cropH * scale) / 2, cropW * scale, cropH * scale);
        
        commitCanvasToWorkingImage();
        updateStatus("Tárgy középre igazítva!");
    } catch (err) { alert("Hiba: " + err.message); } finally { showOverlay(false); }
});

safeBind('btnUndo', 'click', () => {
    if (historyStack.length > 1) {
        historyStack.pop();
        const prevState = historyStack[historyStack.length - 1];
        ctxS.putImageData(prevState, 0, 0);
        commitCanvasToWorkingImage();
        updateStatus("Visszavonva!");
    } else {
        const btnUndo = document.getElementById('btnUndo');
        if (btnUndo) btnUndo.disabled = true;
    }
});

safeBind('btnReset', 'click', () => {
    if (originalImage && confirm("Visszaállítja az eredeti képet?")) {
        loadImgToCanvas(originalImage.src);
    }
});

safeBind('btnSaveToWebshop', 'click', () => {
    const canvasMode = document.getElementById('canvasModeSelect').value;
    const format = document.getElementById('formatSelect').value;
    const mimeType = format === 'png' ? 'image/png' : (format === 'jpg' ? 'image/jpeg' : 'image/webp');

    const exportCanvas = document.createElement('canvas');
    exportCanvas.width = canvasS.width; exportCanvas.height = canvasS.height;
    const exportCtx = exportCanvas.getContext('2d');
    exportCtx.drawImage(canvasS, 0, 0);

    const chk = document.getElementById('chkWatermark');
    if (chk && chk.checked) {
        applyWatermarkToCanvas(exportCtx, exportCanvas.width, exportCanvas.height);
    }

    const formData = new FormData();
    formData.append('canvas_mode', canvasMode);
    formData.append('format', format);
    formData.append('upload_edited_image', '1');

    if (canvasMode === 'product') {
        const sku = document.getElementById('targetSku').value.trim();
        if (!sku) { alert("Adja meg a Cikkszámot (SKU)!"); return; }
        formData.append('sku', sku);
        formData.append('save_type', document.getElementById('saveTypeSelect').value);
    } else {
        formData.append('custom_filename', document.getElementById('customFilename').value.trim());
        formData.append('target_folder', document.getElementById('targetFolderSelect').value);
    }

    exportCanvas.toBlob((blob) => {
        formData.append('image_blob', blob, 'edited.' + format);
        fetch('admin_image_editor.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert(`✓ Kép elmentve a szerverre! (${data.folder}${data.filename})`);
                    updateStatus("Sikeresen elmentve!");
                } else { alert("Hiba: " + (data.error || "Sikertelen")); }
            });
    }, mimeType, 0.85);
});

safeBind('btnDownload', 'click', () => {
    const format = document.getElementById('formatSelect').value;
    const mimeType = format === 'png' ? 'image/png' : (format === 'jpg' ? 'image/jpeg' : 'image/webp');
    const exportCanvas = document.createElement('canvas');
    exportCanvas.width = canvasS.width; exportCanvas.height = canvasS.height;
    const exportCtx = exportCanvas.getContext('2d');
    exportCtx.drawImage(canvasS, 0, 0);

    const chk = document.getElementById('chkWatermark');
    if (chk && chk.checked) {
        applyWatermarkToCanvas(exportCtx, exportCanvas.width, exportCanvas.height);
    }

    exportCanvas.toBlob((blob) => {
        const a = document.createElement('a');
        a.download = `webshop_edited.${format}`;
        a.href = URL.createObjectURL(blob);
        a.click();
    }, mimeType, 0.85);
});

// VÁGÓLAP CTRL+V BEILLESZTÉS
window.addEventListener('paste', (e) => {
    const items = (e.clipboardData || e.originalEvent.clipboardData).items;
    for (let i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && items[i].type.startsWith('image/')) {
            loadImgToCanvas(URL.createObjectURL(items[i].getAsFile()));
            updateStatus("📋 Kép bemásolva a vágólapról!");
            break;
        }
    }
});

// ================= 2. TÖMEGES EGYSÉGESÍTŐ =================
const fileInputB = document.getElementById('fileInputBatch');
const btnStartB = document.getElementById('btnStartBatch');
let selectedFiles = [];

if (fileInputB) {
    fileInputB.addEventListener('change', (e) => {
        selectedFiles = Array.from(e.target.files);
        if (selectedFiles.length > 0) {
            document.getElementById('statusBatch').innerText = `${selectedFiles.length} kép kiválasztva.`;
            if (btnStartB) btnStartB.disabled = false;
        }
    });
}

if (btnStartB) {
    btnStartB.addEventListener('click', async () => {
        if (selectedFiles.length === 0) return;
        btnStartB.disabled = true; if (fileInputB) fileInputB.disabled = true;
        document.getElementById('log').innerHTML = "";
        
        const progressContainer = document.getElementById('progressContainer');
        const progressBar = document.getElementById('progressBar');
        if (progressContainer) progressContainer.style.display = 'block';
        if (progressBar) { progressBar.style.width = '0%'; progressBar.innerText = '0%'; }
        
        const keepOriginalSize = document.getElementById('keepBatchOriginalSize').checked;
        const targetSize = parseInt(document.getElementById('targetSize').value || 800);
        const maxMB = parseFloat(document.getElementById('maxFileSize').value) || 0;
        const useAI = document.getElementById('useBatchAI').checked;
        const format = document.getElementById('batchFormat').value;
        const destination = document.getElementById('batchDestination').value;
        const mimeType = format === 'png' ? 'image/png' : (format === 'jpg' ? 'image/jpeg' : 'image/webp');
        const useWatermark = document.getElementById('chkBatchWatermark') ? document.getElementById('chkBatchWatermark').checked : false;

        for (let i = 0; i < selectedFiles.length; i++) {
            const file = selectedFiles[i];
            let percent = Math.round(((i) / selectedFiles.length) * 100);
            if (progressBar) { progressBar.style.width = percent + '%'; progressBar.innerText = percent + '%'; }
            document.getElementById('statusBatch').innerText = `Feldolgozás: ${file.name} (${i + 1}/${selectedFiles.length})...`;
            
            try {
                const img = await new Promise((res, rej) => { let i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = URL.createObjectURL(file); });
                const c = document.createElement('canvas');
                const cx = c.getContext('2d', { willReadFrequently: true });
                c.width = img.width; c.height = img.height; cx.drawImage(img, 0, 0);

                let cropC = c;
                let cropW = img.width;
                let cropH = img.height;

                if (useAI && ortSession) {
                    const bounds = await processAI(c, cx, 0, 25, 20, 50);
                    cropW = bounds.maxX - bounds.minX;
                    cropH = bounds.maxY - bounds.minY;
                    if (cropW > 0 && cropH > 0) {
                        cropC = document.createElement('canvas');
                        cropC.width = cropW; cropC.height = cropH;
                        cropC.getContext('2d', { willReadFrequently: true }).drawImage(c, bounds.minX, bounds.minY, cropW, cropH, 0, 0, cropW, cropH);
                    }
                }

                const finalC = document.createElement('canvas');
                const finalCx = finalC.getContext('2d', { willReadFrequently: true });

                if (keepOriginalSize) {
                    finalC.width = cropW; finalC.height = cropH;
                    finalCx.clearRect(0, 0, cropW, cropH);
                    finalCx.drawImage(cropC, 0, 0, cropW, cropH);
                } else {
                    finalC.width = targetSize; finalC.height = targetSize;
                    finalCx.clearRect(0, 0, targetSize, targetSize);
                    const scale = Math.min((targetSize * 0.88) / cropW, (targetSize * 0.88) / cropH);
                    finalCx.drawImage(cropC, (targetSize - cropW * scale) / 2, (targetSize - cropH * scale) / 2, cropW * scale, cropH * scale);
                }

                if (useWatermark) {
                    applyWatermarkToCanvas(finalCx, finalC.width, finalC.height);
                }

                let q = 0.85;
                let dUrl = finalC.toDataURL(mimeType, q);
                let sMB = (dUrl.length * 0.75) / (1024 * 1024);

                while (maxMB > 0 && sMB > maxMB && q > 0.15) {
                    q -= 0.1;
                    dUrl = finalC.toDataURL(mimeType, q);
                    sMB = (dUrl.length * 0.75) / (1024 * 1024);
                }

                const cleanFileName = file.name.replace(/\.[^/.]+$/, "") + `.${format}`;

                if (destination !== 'download') {
                    const blob = await new Promise(r => finalC.toBlob(r, mimeType, q));
                    const formData = new FormData();
                    formData.append('image_blob', blob, cleanFileName);
                    formData.append('filename', cleanFileName);
                    formData.append('target_folder', destination);
                    formData.append('upload_batch_file', '1');

                    const res = await fetch('admin_image_editor.php', { method: 'POST', body: formData });
                    const jsonRes = await res.json();
                    if (jsonRes.success) {
                        document.getElementById('log').innerHTML += `✓ ${cleanFileName} -> Mentve ide: ${jsonRes.folder}<br>`;
                    } else { throw new Error("Mentési hiba"); }
                } else {
                    const link = document.createElement('a');
                    link.download = cleanFileName;
                    link.href = dUrl; link.click();
                    document.getElementById('log').innerHTML += `✓ ${cleanFileName} -> Letöltve (${(sMB * 1024).toFixed(0)} KB)<br>`;
                }

                await new Promise(r => setTimeout(r, useAI ? 150 : 20));
            } catch (e) {
                document.getElementById('log').innerHTML += `✗ HIBA: ${file.name}<br>`;
            }
        }
        if (progressBar) { progressBar.style.width = '100%'; progressBar.innerText = '100%'; }
        document.getElementById('statusBatch').innerText = "Tömeges feldolgozás befejezve!";
        btnStartB.disabled = false; if (fileInputB) fileInputB.disabled = false;
    });
}
</script>


<!-- RÉSZLETES SÚGÓ ÉS HASZNÁLATI ÚTMUTATÓ MODAL -->
<div id="helpModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.85); z-index: 20000; justify-content: center; align-items: center; padding: 20px; box-sizing: border-box;">
    <div style="background: white; border-radius: 12px; max-width: 750px; width: 100%; max-height: 88vh; overflow-y: auto; padding: 25px; box-sizing: border-box; position: relative; font-size: 13.5px; line-height: 1.6; color: #1e293b; text-align: left;">
        <span style="position: absolute; top: 15px; right: 20px; font-size: 24px; cursor: pointer; color: #64748b; font-weight: bold;" onclick="closeHelpModal()">&times;</span>
        
        <h3 style="margin-top: 0; color: #1e3a8a; border-bottom: 2px solid #cbd5e1; padding-bottom: 10px;"><i class="fa fa-book"></i> AI Képszerkesztő Stúdió – Részletes Útmutató</h3>
        
        <div style="background: #f0f9ff; border: 1px solid #bae6fd; color: #0369a1; padding: 10px 14px; border-radius: 6px; font-weight: bold; margin-bottom: 15px; font-size: 13px;">
            💡 Ezzel a professzionális eszközzel termékképeket, főoldali bannereket és témaképeket szerkeszthetsz, közvetlenül a webshop adatbázisába és a szerverre mentve!
        </div>

        <!-- 1. MUNKATERÜLET MÓDOK -->
        <strong style="color: #1e3a8a; font-size: 14.5px;"><i class="fa fa-layer-group"></i> 1. Munkaterület Mód Kiválasztása</strong>
        <p style="margin: 4px 0 14px 0; color: #475569;">
            • <strong>📦 Termékkép (800x800 Négyzet):</strong> Szabványos webshop termékkép Cikkszám (SKU) megadásával.<br>
            • <strong>📐 Eredeti Képméret:</strong> Megőrzi a betöltött fotó eredeti képarányát.<br>
            • <strong>🖼️ Fejléc / Banner (1200x400):</strong> Fix széles fekvő banner formátum a főoldalhoz.
        </p>

        <!-- 2. AI HÁTTÉRELTÁVOLÍTÁS ÉS CSÚSZKÁK -->
        <strong style="color: #1e3a8a; font-size: 14.5px;"><i class="fa fa-wand-magic-sparkles"></i> 2. Az AI Háttéreltávolító és az AI Csúszkák (Élénk Kék Csúszkák)</strong>
        <p style="margin: 4px 0 6px 0; color: #475569;">
            A beépített <strong>ISNet mesterséges intelligencia modell</strong> nem a színeket, hanem a tárgyak formáját és kontúrját ismeri fel. Mielőtt a <code>[Háttér eltávolítása (AI)]</code> gombra kattintanál, az alábbi kék csúszkákkal állíthatod be az AI működését:
        </p>
        
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #2563eb; padding:10px 12px; margin-bottom:12px; border-radius:4px; font-size:12.5px;">
            <p style="margin: 0 0 6px 0;"><strong>1. AI Érzékenység / Maszkolás (-50 ... +50):</strong> Finomhangolja, hogy az AI mennyire agresszívan vágjon. Ha a tárgy vékony nyele vagy széle eltűnne, állítsd negatívba (-10...-30). Ha sok a háttérszemét, állítsd pozitívba (+10...+30).</p>
            
            <p style="margin: 0 0 6px 0;"><strong>2. Alpha Vágási Küszöb (0 ... 80):</strong> Megszünteti a tárgy körüli halvány fehér fátylat, tükröződést és derengést. Ha a levágott termék szélén csúnya fehér vonal marad, emeld a csúszkát 20–35 közé!</p>
            
            <p style="margin: 0 0 6px 0;"><strong>3. Fehér Háttér Tisztító (0 ... 100):</strong> Törli a stúdiófotón maradt világos háttérmaradványokat anélkül, hogy a tárgyat bántaná.</p>

            <p style="margin: 0; color:#059669;"><strong>7. Tárgy-test Tömörítés / Solidify (0 ... 100%):</strong><br>
            🛠️ <strong>Példa (Fém és fényes tárgyaknál):</strong> A polírozott fémeknél, króm szerszámoknál vagy csillogó műanyagoknál az erős stúdiófény miatt az AI azt hiszi, hogy a tárgy közepe "átlátszó" (csak kb. 80%-os áttetszőségűnek érzékeli). Emiatt az AI futtatása után a fém szerszám áttetsző maradna. A <strong>Tömörítés csúszkával (50–90%)</strong> felbicskázhatod a pixeleket: az AI felerősíti a fém belsejét, így a tárgy 100%-osan tömör, szilárd és áttetszetlen lesz!</p>
        </div>

        <!-- 3. NORMÁL TRANSZFORMÁCIÓK -->
        <strong style="color: #1e3a8a; font-size: 14.5px;"><i class="fa fa-sliders"></i> 3. Normál Transzformációs Csúszkák (Halvány Szürke Csúszkák)</strong>
        <p style="margin: 4px 0 6px 0; color: #475569;">
            Ezek a hagyományos geometriai és fényerő állító csúszkák az AI-tól függetlenül, azonnal működnek:
        </p>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-left:4px solid #94a3b8; padding:10px 12px; margin-bottom:12px; border-radius:4px; font-size:12.5px;">
            <p style="margin: 0 0 6px 0;"><strong>4. Átlós Elforgatás:</strong> Ácsceruzákhoz, fúrófejekhez és hosszú szerszámokhoz. A <code>45° Átló</code> gombra kattintva a hosszú tárgy átlósan fekszik fel a 800x800-as vászonra, így sokkal nagyobb és élesebb lesz a webshop rácsában!</p>
            
            <p style="margin: 0 0 6px 0;"><strong>5. Optikai Nagyítás (Zoom):</strong> Közelebb hozza vagy távolabb viszi a terméket a vásznon.</p>
            
            <p style="margin: 0;"><strong>6. Fényerő & Szín-telítettség:</strong> A sötét vagy tompa beszállítói fotókat világosabbá és élénkebbé teszi.</p>
               
        
        </div>
        
        

        <!-- 4. EFFEKTEK ÉS MENTÉS -->
        <strong style="color: #1e3a8a; font-size: 14.5px;"><i class="fa fa-wand-magic"></i> 4. Látványos Effektek és Mentés</strong>
        <p style="margin: 4px 0 12px 0; color: #475569;">
            • <strong>🎯 Auto-Trim:</strong> 0,01 mp alatt levágja a felesleges fehér/átlátszó margókat AI nélkül, és felnagyítja a tárgyat a keret 85%-ára!<br>
            • <strong>🌓 Stúdió Árnyék:</strong> Lágy, 3D hatású stúdióárnyékot rajzol a termék alá (világos sablonokhoz).<br>
            • <strong>💡 Sötét Háttér Ragyogás:</strong> Lágy fény-aurát ad a termék köré, így a sötét témákon (Obszidián, Midnight) a tárgy csodásan kiemelkedik!<br>
            • <strong>🛡️ Vízjel:</strong> Ráégeti a diszkrét <code>VARIOX.HU</code> feliratot a kép sarkára mentéskor.<br>
            • Kattints a <span style="background:#10b981; color:white; padding:2px 6px; border-radius:3px; font-size:11px; font-weight:bold;">💾 MENTÉS A WEBSHOPBA</span> gombra a közvetlen szerverre íráshoz!
        </p>

        <button type="button" onclick="closeHelpModal()" class="btn btn-primary" style="width: 100%; height:auto; padding: 10px; margin-top: 5px;">Megértettem, bezárás</button>
    </div>
</div>

<script>
function openHelpModal() { document.getElementById('helpModal').style.display = 'flex'; }
function closeHelpModal() { document.getElementById('helpModal').style.display = 'none'; }
</script>

</body>
</html>