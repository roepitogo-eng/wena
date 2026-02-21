<?php
session_start(); 

// --- 1. DEBUG & SETTINGS ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('memory_limit', '512M');
set_time_limit(0);

// --- CRITICAL GD CHECK ---
if (!extension_loaded('gd') && !extension_loaded('gd2')) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#fff;'><h1 style='color:#d97706;'>⚠ System Error</h1><p><strong>PHP GD Library not enabled.</strong></p></div>");
}

 $uploadDir = 'uploads/';
 $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

 $message = "";

// --- Helper Functions ---
function load_image($filepath) {
    if (!file_exists($filepath)) return false;
    $info = @getimagesize($filepath);
    if (!$info) return false;
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg': return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($filepath) : false;
        case 'image/png':  return function_exists('imagecreatefrompng') ? @imagecreatefrompng($filepath) : false;
        case 'image/gif':  return function_exists('imagecreatefromgif') ? @imagecreatefromgif($filepath) : false;
        case 'image/webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filepath) : false;
        default: return false;
    }
}

function save_image($image, $filepath, $mime) {
    switch ($mime) {
        case 'image/jpeg': return @imagejpeg($image, $filepath, 90);
        case 'image/png':  return @imagepng($image, $filepath);
        case 'image/gif':  return @imagegif($image, $filepath);
        case 'image/webp': return @imagewebp($image, $filepath);
        default: return false;
    }
}

// --- 2. MAIN LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ACTION: Reset Session (Delete all images)
    if (isset($_POST['action']) && $_POST['action'] === 'reset_session') {
        $files = glob($uploadDir . '*');
        foreach($files as $file) {
            if(is_file($file)) @unlink($file);
        }
        exit('cleared');
    }

    // ACTION: Upload
    if (isset($_POST['action']) && $_POST['action'] === 'upload') {
        try {
            if (!empty($_FILES['image']['tmp_name'])) {
                $fileTmp = $_FILES['image']['tmp_name'];
                $fileType = mime_content_type($fileTmp);
                
                if (in_array($fileType, $allowedTypes)) {
                    $fileName = basename($_FILES['image']['name']);
                    $newFileName = time() . "_" . preg_replace('/\s+/', '_', $fileName);
                    $targetPath = $uploadDir . $newFileName;
                    
                    if (move_uploaded_file($fileTmp, $targetPath)) {
                        $_SESSION['flash_msg'] = "<div class='toast success'>Upload successful! ✨</div>";
                    } else {
                        $_SESSION['flash_msg'] = "<div class='toast error'>Upload failed.</div>";
                    }
                } else {
                    $_SESSION['flash_msg'] = "<div class='toast error'>Invalid file type.</div>";
                }
            }
        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='toast error'>Error: " . $e->getMessage() . "</div>";
        }
    }

    // ACTION: Delete Single
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $targetPath = $uploadDir . basename($_POST['target_image']);
        if (file_exists($targetPath)) {
            @unlink($targetPath);
            $_SESSION['flash_msg'] = "<div class='toast success'>Image deleted.</div>";
        }
    }

    // ACTION: Edit
    if (isset($_POST['action']) && $_POST['action'] === 'edit') {
        try {
            if (empty($_POST['target_image'])) throw new Exception("Please select an image.");
            $targetPath = $uploadDir . basename($_POST['target_image']);
            if (!file_exists($targetPath)) throw new Exception("File not found.");

            $saveAsCopy = isset($_POST['save_as_copy']);
            $finalSavePath = $targetPath;
            
            if ($saveAsCopy) {
                $pathInfo = pathinfo($targetPath);
                $finalSavePath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_edited.' . $pathInfo['extension'];
            }

            $image = load_image($targetPath);
            if (!$image) throw new Exception("Failed to load image.");

            $info = getimagesize($targetPath);
            $mime = $info['mime'];
            $width = imagesx($image);
            $height = imagesy($image);
            $effectType = $_POST['effect_type'];
            $edited = false;

            switch ($effectType) {
                case 'grayscale': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_GRAYSCALE); $edited = true; } break;
                case 'invert': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_NEGATE); $edited = true; } break;
                case 'sepia': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_GRAYSCALE); imagefilter($image, IMG_FILTER_COLORIZE, 90, 60, 30); $edited = true; } break;
                case 'colorize': 
                    $hex = $_POST['tint_color'] ?? '#ff0000';
                    $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
                    if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_COLORIZE, $r, $g, $b); $edited = true; } 
                    break;
                case 'brightness': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_BRIGHTNESS, intval($_POST['brightness_val'])); $edited = true; } break;
                case 'contrast': if(function_exists('imagefilter')) { imagefilter($image, IMG_FILTER_CONTRAST, intval($_POST['contrast_val'])); $edited = true; } break;
                case 'watermark':
                    $text = trim($_POST['wm_text'] ?? '');
                    if ($text) {
                        $color = imagecolorallocate($image, 255, 255, 255); 
                        $shadow = imagecolorallocate($image, 0, 0, 0);
                        $font = 5; $w = imagefontwidth($font) * strlen($text); $h = imagefontheight($font);
                        $pos = $_POST['wm_position'] ?? 'br';
                        $x=10; $y=10;
                        if($pos=='tr'||$pos=='br') $x=$width-$w-20;
                        if($pos=='bl'||$pos=='br') $y=$height-$h-20;
                        if($pos=='c'){$x=($width/2)-($w/2); $y=($height/2)-($h/2);}
                        imagestring($image, $font, $x+1, $y+1, $text, $shadow);
                        imagestring($image, $font, $x, $y, $text, $color);
                        $edited = true;
                    }
                    break;
                case 'rotate':
                    if(function_exists('imagerotate')) {
                        $image = imagerotate($image, intval($_POST['rotate_deg']), imagecolorallocatealpha($image, 0, 0, 0, 127));
                        imagesavealpha($image, true); $edited = true;
                    }
                    break;
                case 'resize':
                    $nw = intval($_POST['resize_width']);
                    if($nw > 0){
                        $nh = intval($nw * ($height/$width));
                        $newImg = imagecreatetruecolor($nw, $nh);
                        if($mime=='image/png'||$mime=='image/gif') { 
                            imagealphablending($newImg, false); 
                            imagesavealpha($newImg, true); 
                            $transparent = imagecolorallocatealpha($newImg, 255, 255, 255, 127); 
                            imagefilledrectangle($newImg, 0, 0, $nw, $nh, $transparent); 
                        }
                        imagecopyresampled($newImg, $image, 0,0,0,0, $nw, $nh, $width, $height);
                        imagedestroy($image); 
                        $image = $newImg; 
                        $edited = true;
                    }
                    break;
            }

            if ($edited) {
                save_image($image, $finalSavePath, $mime);
                $_SESSION['flash_msg'] = "<div class='toast success'>Effect applied! ✅</div>";
                imagedestroy($image);
            }

        } catch (Exception $e) {
            $_SESSION['flash_msg'] = "<div class='toast error'>" . $e->getMessage() . "</div>";
        }
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

 $message = isset($_SESSION['flash_msg']) ? $_SESSION['flash_msg'] : "";
unset($_SESSION['flash_msg']);
 $images = glob($uploadDir . "*.{jpg,jpeg,png,gif,webp}", GLOB_BRACE);
if ($images) usort($images, function($a, $b) { return filemtime($b) - filemtime($a); });
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wena Albums</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-deep: #121212;
            --bg-card: rgba(30, 30, 35, 0.7);
            --glass-border: rgba(255, 255, 255, 0.08);
            --accent-primary: #bb86fc;
            --accent-secondary: #cf6679;
            --accent-gradient: linear-gradient(135deg, #bb86fc, #cf6679);
            --text-main: #e1e1e1;
            --text-highlight: #ffffff;
            --text-muted: #a1a1a1;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; outline: none; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: var(--bg-deep);
            color: var(--text-main);
            min-height: 100vh;
            overflow-x: hidden;
            position: relative;
        }

        /* --- ANIMATED BACKGROUND ORBS --- */
        .bg-shape {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 0;
            opacity: 0.2;
            animation: float 20s infinite ease-in-out alternate;
        }
        .shape-1 { top: -5%; left: -5%; width: 400px; height: 400px; background: #bb86fc; }
        .shape-2 { bottom: -5%; right: -5%; width: 500px; height: 500px; background: #03dac6; opacity: 0.15; }
        .shape-3 { top: 40%; left: 50%; width: 300px; height: 300px; background: #cf6679; opacity: 0.1; }
        
        @keyframes float {
            0% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(30px, 50px) scale(1.1); }
            100% { transform: translate(-20px, -20px) scale(0.95); }
        }

        /* --- FLYING BIRDS ANIMATION --- */
        .birds-container {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            pointer-events: none; z-index: 1;
            overflow: hidden;
        }

        .bird {
            position: absolute;
            left: -10%; /* Start off-screen left */
            top: 20%;
            opacity: 0.6;
            animation: flyBird linear infinite;
            z-index: 1;
        }

        /* Bird Shape using borders (V shape) */
        .bird-body {
            width: 0; height: 0;
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-bottom: 15px solid rgba(255, 255, 255, 0.25);
            transform: rotate(-45deg); /* Tilt to look like flying */
            animation: flapWings 0.3s infinite alternate;
        }

        @keyframes flyBird {
            0% { 
                transform: translate(0, 0); 
                opacity: 0; 
            }
            10% { opacity: 0.6; }
            90% { opacity: 0.6; }
            100% { 
                transform: translate(120vw, -100px); 
                opacity: 0; 
            }
        }

        @keyframes flapWings {
            0% { transform: rotate(-45deg) scaleY(1); }
            100% { transform: rotate(-45deg) scaleY(0.6); }
        }

        .container { width: 100%; max-width: 1200px; margin: 0 auto; padding: 20px; position: relative; z-index: 2; }

        /* --- HEADER --- */
        header { text-align: center; padding: 60px 0 40px; animation: fadeInDown 1s ease-out; }
        h1 { 
            font-size: 3.5rem; 
            font-weight: 700; 
            background: var(--accent-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 10px;
        }
        
        p.subtitle { color: var(--text-muted); font-size: 1.1rem; letter-spacing: 3px; text-transform: uppercase; font-weight: 600; }

        /* --- TOAST --- */
        .toast {
            position: fixed; top: 20px; right: 20px; padding: 16px 30px;
            border-radius: 50px; background: #2d2d35; border: 1px solid var(--glass-border);
            color: var(--text-highlight); 
            box-shadow: 0 10px 30px rgba(0,0,0,0.3); z-index: 5000;
            transform: translateX(200%); transition: transform 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }
        .toast.show { transform: translateX(0); }
        .toast.success { border: 1px solid var(--accent-primary); color: var(--accent-primary); }
        .toast.error { border: 1px solid var(--accent-secondary); color: var(--accent-secondary); }

        /* --- GALLERY GRID --- */
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 25px;
            padding-bottom: 100px;
        }

        .gallery-item {
            position: relative;
            border-radius: 16px;
            overflow: hidden;
            background: var(--bg-card);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
            transition: transform 0.4s ease, box-shadow 0.4s ease, border-color 0.4s ease;
            opacity: 0; animation: fadeUp 0.6s forwards;
        }
        
        .gallery-item:hover { 
            transform: translateY(-8px) scale(1.01);
            box-shadow: 0 20px 40px rgba(187, 134, 252, 0.15);
            border-color: rgba(187, 134, 252, 0.3);
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .img-box { width: 100%; height: 250px; overflow: hidden; cursor: zoom-in; background: #1e1e1e; display: flex; align-items: center; justify-content: center; }
        .gallery-img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.6s ease; }
        .gallery-item:hover .gallery-img { transform: scale(1.08); }

        /* Overlay Actions */
        .overlay {
            position: absolute; bottom: 0; left: 0; width: 100%;
            background: linear-gradient(to top, rgba(18, 18, 18, 0.95), transparent);
            padding: 50px 15px 15px; display: flex; flex-direction: column; gap: 10px;
            opacity: 0; transform: translateY(10px);
            transition: all 0.4s ease;
        }
        .gallery-item:hover .overlay { opacity: 1; transform: translateY(0); }

        .file-name {
            position: absolute; top: 15px; left: 15px;
            background: rgba(18, 18, 18, 0.7); backdrop-filter: blur(8px);
            padding: 6px 14px; border-radius: 20px; font-size: 0.75rem;
            color: var(--text-highlight); border: 1px solid rgba(255,255,255,0.1);
            font-weight: 600;
        }

        /* --- BUTTONS --- */
        .btn {
            padding: 10px 18px; border: none; border-radius: 50px;
            font-family: 'Poppins', sans-serif; font-weight: 600; font-size: 0.85rem;
            cursor: pointer; transition: all 0.3s ease;
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
        }
        
        .btn-primary { 
            background: var(--accent-gradient); color: white; 
            box-shadow: 0 4px 15px rgba(187, 134, 252, 0.3);
        }
        .btn-primary:hover { box-shadow: 0 6px 20px rgba(187, 134, 252, 0.5); transform: translateY(-2px); }
        
        .btn-sm { padding: 8px 16px; font-size: 0.8rem; }
        
        .btn-ghost { background: rgba(255,255,255,0.08); color: var(--text-main); border: 1px solid rgba(255,255,255,0.1); }
        .btn-ghost:hover { background: rgba(255,255,255,0.15); color: var(--text-highlight); }
        
        .btn-danger { background: transparent; color: var(--accent-secondary); border: 1px solid rgba(207, 102, 121, 0.3); }
        .btn-danger:hover { background: rgba(207, 102, 121, 0.15); }

        /* --- MODALS --- */
        .modal-wrapper {
            display: none; position: fixed; z-index: 2000;
            left: 0; top: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            justify-content: center; align-items: center;
            opacity: 0; transition: opacity 0.3s ease;
        }
        .modal-wrapper.show { opacity: 1; }
        
        .glass-card {
            background: #1e1e24;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 25px 50px rgba(0,0,0,0.5);
            padding: 40px;
            width: 90%; max-width: 600px;
            position: relative;
            transform: scale(0.9) translateY(30px);
            transition: transform 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            color: var(--text-main);
        }
        .modal-wrapper.show .glass-card { transform: scale(1) translateY(0); }

        .close-btn {
            position: absolute; top: 20px; right: 25px;
            font-size: 2rem; color: var(--text-muted); cursor: pointer;
            transition: 0.3s; line-height: 1;
        }
        .close-btn:hover { color: var(--accent-primary); transform: rotate(90deg); }

        .section-header { margin-bottom: 30px; }
        .section-header h2 { font-size: 1.5rem; color: var(--text-highlight); font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .section-header h2::before {
            content: ''; display: block; width: 4px; height: 24px;
            background: var(--accent-gradient); border-radius: 2px;
        }

        /* Form Styles */
        .form-group { margin-bottom: 20px; }
        label { display: block; font-size: 0.9rem; color: var(--text-muted); margin-bottom: 8px; font-weight: 500; }
        
        input[type="text"], input[type="number"], select, input[type="file"] {
            width: 100%; padding: 14px 18px; border-radius: 12px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(0,0,0,0.3);
            color: var(--text-highlight); font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
        }
        input:focus, select:focus { border-color: var(--accent-primary); background: rgba(0,0,0,0.5); box-shadow: 0 0 0 3px rgba(187, 134, 252, 0.1); }
        
        select option { background: #1e1e24; color: white; }

        .editor-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 600px) { .editor-grid { grid-template-columns: 1fr; } }

        /* Floating Action Button (FAB) */
        .fab-container { position: fixed; bottom: 30px; right: 30px; z-index: 1000; display: flex; flex-direction: column-reverse; align-items: center; gap: 15px; }
        .fab-main {
            width: 60px; height: 60px; border-radius: 50%;
            background: var(--accent-gradient);
            color: white; display: flex; align-items: center; justify-content: center;
            font-size: 24px; cursor: pointer; box-shadow: 0 5px 20px rgba(187, 134, 252, 0.4);
            transition: all 0.3s;
        }
        .fab-main:hover { transform: scale(1.1) rotate(90deg); box-shadow: 0 8px 25px rgba(187, 134, 252, 0.6); }
        
        .fab-items { display: flex; flex-direction: column; gap: 15px; opacity: 0; visibility: hidden; transition: all 0.3s; transform: translateY(20px); }
        .fab-container.active .fab-items { opacity: 1; visibility: visible; transform: translateY(0); }
        
        .fab-item {
            background: #1e1e24; border: 1px solid rgba(255,255,255,0.1);
            padding: 12px 20px; border-radius: 30px; display: flex; align-items: center; gap: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2); cursor: pointer; font-weight: 600;
            color: var(--text-highlight); transition: all 0.2s;
        }
        .fab-item:hover { border-color: var(--accent-primary); color: var(--accent-primary); transform: translateX(-5px); }

        /* Zoom Modal */
        .zoom-modal { display: none; position: fixed; z-index: 4000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.95); justify-content: center; align-items: center; opacity: 0; transition: opacity 0.4s; }
        .zoom-modal.show { opacity: 1; }
        .modal-content { max-width: 95%; max-height: 95%; border-radius: 10px; box-shadow: 0 0 50px rgba(187, 134, 252, 0.1); animation: zoomIn 0.4s; }
        .close-modal { position: absolute; top: 30px; right: 40px; color: white; font-size: 50px; font-weight: 300; cursor: pointer; z-index: 4001; transition: 0.3s; }
        .close-modal:hover { color: var(--accent-primary); transform: rotate(90deg); }

        .hidden { display: none !important; }
    </style>
</head>
<body>

<!-- Animated Background Shapes -->
<div class="bg-shape shape-1"></div>
<div class="bg-shape shape-2"></div>
<div class="bg-shape shape-3"></div>

<!-- Birds Container -->
<div class="birds-container" id="birdsContainer"></div>

<div class="container">
    <?= $message ?>

    <header>
        <h1>Wena Albums</h1>
        <p class="subtitle">My Personal Collection</p>
    </header>

    <!-- GALLERY -->
    <div class="gallery-grid">
        <?php if (empty($images)): ?>
            <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: var(--bg-card); border-radius: 20px; border: 1px dashed rgba(255,255,255,0.1);">
                <h3 style="color: var(--text-highlight);">No memories yet.</h3>
                <p style="color: var(--text-muted);">Click the <strong style="color:var(--accent-primary)">+</strong> button below to get started.</p>
            </div>
        <?php else: ?>
            <?php foreach($images as $img): $imgUrl = $img . '?v=' . filemtime($img); ?>
                <div class="gallery-item">
                    <span class="file-name"><?= htmlspecialchars(basename($img)) ?></span>
                    <div class="img-box" onclick="zoomImage('<?= $imgUrl ?>')">
                        <img src="<?= $imgUrl ?>" class="gallery-img" alt="Image">
                    </div>
                    <div class="overlay">
                        <div style="display:flex; gap:10px; justify-content: center;">
                            <a href="<?= $img ?>" download class="btn btn-sm btn-primary">Download</a>
                            <button class="btn btn-sm btn-ghost" onclick="openEditor('<?= basename($img) ?>')">Edit</button>
                            <button class="btn btn-sm btn-danger" onclick="confirmDelete('<?= basename($img) ?>')">
                                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- FLOATING ACTION BUTTON -->
<div class="fab-container" id="fabBtn">
    <div class="fab-main" onclick="toggleFab()">+</div>
    <div class="fab-items">
        <div class="fab-item" onclick="openModal('upload')">
            <span>📤</span> Upload Photo
        </div>
        <div class="fab-item" onclick="openModal('editor')">
            <span>🎨</span> Edit Photo
        </div>
    </div>
</div>

<!-- MODAL: UPLOAD -->
<div id="uploadModal" class="modal-wrapper" onclick="closeModalOutside(event, 'uploadModal')">
    <div class="glass-card" onclick="event.stopPropagation()">
        <span class="close-btn" onclick="closeModal('uploadModal')">&times;</span>
        <div class="section-header"><h2>Upload New Photo</h2></div>
        <form action="" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">
            <div class="form-group">
                <label>Select Image</label>
                <input type="file" name="image" required accept="image/*">
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%;">Upload Now</button>
        </form>
    </div>
</div>

<!-- MODAL: EDITOR -->
<div id="editorModal" class="modal-wrapper" onclick="closeModalOutside(event, 'editorModal')">
    <div class="glass-card" onclick="event.stopPropagation()">
        <span class="close-btn" onclick="closeModal('editorModal')">&times;</span>
        <div class="section-header"><h2>Photo Editor</h2></div>
        <form action="" method="post">
            <input type="hidden" name="action" value="edit">
            
            <div class="form-group">
                <label>Select Image</label>
                <select name="target_image" required id="imgSelect" style="width:100%;">
                    <option value="">-- Select --</option>
                    <?php if($images): foreach($images as $img): ?>
                        <option value="<?= basename($img) ?>"><?= htmlspecialchars(basename($img)) ?></option>
                    <?php endforeach; endif; ?>
                </select>
            </div>

            <div class="editor-grid">
                <div class="form-group">
                    <label>Effect Type</label>
                    <select name="effect_type" id="effectSelect" onchange="toggleInputs()" style="width:100%;">
                        <option value="grayscale">Grayscale</option>
                        <option value="invert">Invert</option>
                        <option value="sepia">Sepia</option>
                        <option value="colorize">Colorize</option>
                        <option value="brightness">Brightness</option>
                        <option value="contrast">Contrast</option>
                        <option value="watermark">Watermark</option>
                        <option value="rotate">Rotate</option>
                        <option value="resize">Resize Width</option>
                    </select>
                </div>

                <!-- Dynamic Inputs -->
                <div class="form-group hidden" id="colorizeInput"><label>Tint Color</label><input type="color" name="tint_color" value="#ff0000"></div>
                <div class="form-group hidden" id="brightnessInput"><label>Level</label><input type="range" name="brightness_val" min="-255" max="255" value="0" style="width:100%"></div>
                <div class="form-group hidden" id="contrastInput"><label>Level</label><input type="range" name="contrast_val" min="-100" max="100" value="0" style="width:100%"></div>
                <div class="form-group hidden" id="wmInput"><label>Text</label><input type="text" name="wm_text" placeholder="Watermark text..."></div>
                <div class="form-group hidden" id="wmPosInput"><label>Position</label><select name="wm_position"><option value="br">Bottom Right</option><option value="bl">Bottom Left</option><option value="tr">Top Right</option><option value="tl">Top Left</option><option value="c">Center</option></select></div>
                <div class="form-group hidden" id="rotateInput"><label>Degrees</label><select name="rotate_deg"><option value="90">90° CW</option><option value="180">180°</option><option value="-90">90° CCW</option></select></div>
                
                <!-- Resize Width Input -->
                <div class="form-group hidden" id="resizeInput">
                    <label>New Width (px)</label>
                    <input type="number" name="resize_width" placeholder="e.g. 800" min="50">
                    <small style="color:var(--text-muted); font-size:0.8rem; margin-top:5px; display:block;">Height will adjust automatically.</small>
                </div>
            </div>

            <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                    <input type="checkbox" name="save_as_copy" id="saveCopy" style="width:auto;"> Save as Copy
                </label>
                <button type="submit" class="btn btn-primary">Apply Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- ZOOM MODAL -->
<div id="imageModal" class="zoom-modal">
    <span class="close-modal" onclick="closeZoom()">&times;</span>
    <img class="modal-content" id="zoomedImg">
</div>

<!-- Hidden Forms -->
<form id="deleteForm" action="" method="post">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="target_image" id="deleteTarget">
</form>

<script>
// --- Logic: Clear Images on Tab Close ---
document.addEventListener("DOMContentLoaded", () => {
    const sessionKey = 'album_is_active';
    const dirtyKey = 'album_has_data';
    const isNewTab = !sessionStorage.getItem(sessionKey);
    const hadData = localStorage.getItem(dirtyKey) === 'true';
    const serverHasFiles = <?= empty($images) ? 'false' : 'true' ?>;

    if (isNewTab) {
        sessionStorage.setItem(sessionKey, 'true');
        if (hadData || serverHasFiles) {
            fetch(window.location.href, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=reset_session'
            }).then(() => {
                localStorage.removeItem(dirtyKey);
                location.reload();
            });
        }
    }

    const uploadForm = document.querySelector('form[enctype="multipart/form-data"]');
    if (uploadForm) {
        uploadForm.addEventListener('submit', () => {
            localStorage.setItem(dirtyKey, 'true');
        });
    }

    const toast = document.querySelector('.toast');
    if(toast) { setTimeout(() => toast.classList.add('show'), 100); setTimeout(() => toast.classList.remove('show'), 4000); }
    
    // Initialize Birds
    spawnBirds();
});

// --- Bird Spawner Logic ---
function spawnBirds() {
    const container = document.getElementById('birdsContainer');
    
    function createBird() {
        const bird = document.createElement('div');
        bird.classList.add('bird');
        
        // Create bird body (V shape)
        const body = document.createElement('div');
        body.classList.add('bird-body');
        bird.appendChild(body);
        
        // Randomize vertical position (0% to 60% of screen height)
        const topPos = Math.random() * 60;
        bird.style.top = topPos + '%';
        
        // Randomize size (scale)
        const scale = Math.random() * 0.5 + 0.5; // Between 0.5 and 1.0
        bird.style.transform = `scale(${scale})`;
        
        // Randomize animation duration (speed)
        const duration = Math.random() * 10 + 15; // Between 15s and 25s
        bird.style.animationDuration = duration + 's';
        
        // Randomize delay
        bird.style.animationDelay = Math.random() * 5 + 's';
        
        container.appendChild(bird);
        
        // Remove bird after animation finishes to keep DOM clean
        setTimeout(() => {
            bird.remove();
        }, (duration + 5) * 1000);
    }
    
    // Spawn initial set
    for(let i=0; i<5; i++) {
        setTimeout(createBird, i * 3000);
    }
    
    // Keep spawning
    setInterval(createBird, 8000);
}

// --- Modal & UI Controls ---
function toggleFab() { document.getElementById('fabBtn').classList.toggle('active'); }

function openModal(type) {
    toggleFab(); 
    const modal = document.getElementById(type + 'Modal');
    modal.style.display = 'flex';
    setTimeout(() => modal.classList.add('show'), 10);
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = 'none', 300);
}

function closeModalOutside(e, id) { if (e.target.id === id) closeModal(id); }

function openEditor(filename) {
    openModal('editor');
    const select = document.getElementById('imgSelect');
    select.value = filename;
}

function confirmDelete(filename) {
    if(confirm("Are you sure you want to delete this image?")) {
        document.getElementById('deleteTarget').value = filename;
        document.getElementById('deleteForm').submit();
    }
}

// --- Editor Logic ---
function toggleInputs() {
    const effect = document.getElementById('effectSelect').value;
    const ids = ['colorizeInput', 'brightnessInput', 'contrastInput', 'wmInput', 'wmPosInput', 'rotateInput', 'resizeInput'];
    ids.forEach(id => document.getElementById(id).classList.add('hidden'));
    
    if (effect === 'colorize') document.getElementById('colorizeInput').classList.remove('hidden');
    if (effect === 'brightness') document.getElementById('brightnessInput').classList.remove('hidden');
    if (effect === 'contrast') document.getElementById('contrastInput').classList.remove('hidden');
    if (effect === 'watermark') { 
        document.getElementById('wmInput').classList.remove('hidden'); 
        document.getElementById('wmPosInput').classList.remove('hidden'); 
    }
    if (effect === 'rotate') document.getElementById('rotateInput').classList.remove('hidden');
    if (effect === 'resize') document.getElementById('resizeInput').classList.remove('hidden');
}

// --- Zoom ---
function zoomImage(src) {
    const modal = document.getElementById("imageModal");
    const modalImg = document.getElementById("zoomedImg");
    modal.style.display = "flex";
    setTimeout(() => modal.classList.add('show'), 10);
    modalImg.src = src;
}

function closeZoom() {
    const modal = document.getElementById("imageModal");
    modal.classList.remove('show');
    setTimeout(() => modal.style.display = "none", 300);
}

window.onclick = function(event) {
    const modal = document.getElementById("imageModal");
    if (event.target == modal) closeZoom();
}
</script>

</body>
</html>