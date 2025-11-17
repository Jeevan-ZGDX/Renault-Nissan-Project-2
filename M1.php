<?php
// Single-file PPT Previewer + Basic Editor (Frontend + PHP backend)
// UPDATED: Uses unoconv -> pdftoppm pipeline, server-side debug logging, and includes an install/test guide.
// Requirements (install before use):
// 1) PHP 7.4+ with exec() enabled and allow_url_fopen as needed
// 2) unoconv (wrapper) and LibreOffice/OpenOffice or UNO runtime available
// 3) poppler-utils (provides pdftoppm) OR ImageMagick (as fallback)
// 4) PHP Zip extension for ZIP export
// 5) Writable directories: uploads/, slides/, edited/, exports/ (script will create them)
//
// Quick Install (Ubuntu/Debian):
// sudo apt update
// sudo apt install -y libreoffice unoconv poppler-utils imagemagick php-zip
// Note: unoconv may require python-uno or the LibreOffice UNO Python bindings depending on distro.
//
// Quick Install (Windows):
// - Install LibreOffice and add it to PATH.
// - Obtain unoconv (Python script) and ensure Python is installed. Windows may require more manual steps.
// - Consider WSL on Windows for easiest compatibility.
//
// Test conversion from command line (after placing sample.pptx in current dir):
// 1) Convert PPTX -> PDF:
//    unoconv -f pdf -o sample.pdf sample.pptx
// 2) Convert PDF -> PNG pages (high-quality):
//    pdftoppm -png -rx 150 -ry 150 sample.pdf slide
//    => creates slide-1.png slide-2.png ...
// If pdftoppm isn't available, try ImageMagick (may require policy.xml adjustments):
//    convert -density 200 sample.pdf slide-%03d.png
//
// Troubleshooting notes:
// - If conversions fail when run from the webserver, try running the same commands as the webserver user.
// - Start a persistent listener if unoconv complains about spawning office:
//    unoconv --listener &
// - Ensure webserver user has a real $HOME and permissions to spawn the LibreOffice process.
// - Use exec() debug output to inspect stdout/stderr returned by the commands.

// -------------------- Configuration --------------------
$BASE_DIR = __DIR__;
$UPLOAD_DIR = $BASE_DIR . '/uploads';
$SLIDE_DIR  = $BASE_DIR . '/slides';
$EDITED_DIR = $BASE_DIR . '/edited';
$EXPORT_DIR = $BASE_DIR . '/exports';
@mkdir($UPLOAD_DIR, 0777, true);
@mkdir($SLIDE_DIR, 0777, true);
@mkdir($EDITED_DIR, 0777, true);
@mkdir($EXPORT_DIR, 0777, true);

// logging helper (appends to php://stdout for docker or a logfile)
function append_log($msg){
    $logfile = __DIR__ . '/conversion_debug.log';
    file_put_contents($logfile, date('[Y-m-d H:i:s] ') . $msg . "\n", FILE_APPEND);
}

function json_resp($data){ header('Content-Type: application/json'); echo json_encode($data); exit; }

// -------------------- API Endpoints --------------------
$action = $_REQUEST['action'] ?? null;

if($action === 'upload'){
    if(empty($_FILES['ppt']) || $_FILES['ppt']['error'] !== UPLOAD_ERR_OK) json_resp(['ok'=>false,'error'=>'No file or upload error']);
    $fn = basename($_FILES['ppt']['name']);
    $ext = pathinfo($fn, PATHINFO_EXTENSION);
    if(!in_array(strtolower($ext), ['ppt','pptx'])) json_resp(['ok'=>false,'error'=>'Only PPT/PPTX allowed']);
    $target = $UPLOAD_DIR . '/' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/','_', $fn);
    if(!move_uploaded_file($_FILES['ppt']['tmp_name'],$target)) json_resp(['ok'=>false,'error'=>'Failed to move uploaded file']);

    // Create output folder per upload
    $outdir = $SLIDE_DIR . '/' . pathinfo($target, PATHINFO_FILENAME);
    @mkdir($outdir,0777,true);

    // === Conversion pipeline: unoconv -> pdftoppm (recommended) ===
    $basename = pathinfo($target, PATHINFO_FILENAME);
    $pdfFile = $outdir . '/' . $basename . '.pdf';
    $cmds = [];

    // 1) Convert PPT/PPTX -> PDF with unoconv
    $cmd1 = 'unoconv -f pdf -o ' . escapeshellarg($pdfFile) . ' ' . escapeshellarg($target) . ' 2>&1';
    append_log("Running: $cmd1");
    exec($cmd1, $out1, $rc1);
    append_log("Exit code: $rc1; Output: " . implode(" | ", $out1));
    $cmds[] = ['cmd'=>$cmd1,'rc'=>$rc1,'out'=>$out1];

    // If PDF wasn't produced, attempt direct image conversion via unoconv (some systems support -f png)
    $pngs_created = 0;
    if(is_file($pdfFile)){
        // 2) Convert PDF -> PNG pages with pdftoppm (poppler)
        $pngPrefix = $outdir . '/slide';
        // choose DPI; increase for higher quality
        $dpi = 150;
        $cmd2 = 'pdftoppm -png -rx ' . intval($dpi) . ' -ry ' . intval($dpi) . ' ' . escapeshellarg($pdfFile) . ' ' . escapeshellarg($outdir . '/slide') . ' 2>&1';
        append_log("Running: $cmd2");
        exec($cmd2, $out2, $rc2);
        append_log("Exit code: $rc2; Output: " . implode(" | ", $out2));
        $cmds[] = ['cmd'=>$cmd2,'rc'=>$rc2,'out'=>$out2];

        // Collect PNGs
        $imgs = array_values(array_filter(scandir($outdir), function($f) use($outdir){ return preg_match('/\.png$/i',$f) && is_file($outdir.'/'.$f); }));
        $pngs_created = count($imgs);
    } else {
        // Try unoconv to png directly (less reliable across installs)
        $cmd_direct = 'unoconv -f png -o ' . escapeshellarg($outdir) . ' ' . escapeshellarg($target) . ' 2>&1';
        append_log("PDF missing; trying direct PNG: $cmd_direct");
        exec($cmd_direct, $outd, $rcd);
        append_log("Exit code: $rcd; Output: " . implode(" | ", $outd));
        $cmds[] = ['cmd'=>$cmd_direct,'rc'=>$rcd,'out'=>$outd];
        $imgs = array_values(array_filter(scandir($outdir), function($f) use($outdir){ return preg_match('/\.png$/i',$f) && is_file($outdir.'/'.$f); }));
        $pngs_created = count($imgs);
    }

    // Fallback: if no PNGs created and ImageMagick is available, try convert on PDF
    if($pngs_created === 0 && is_file($pdfFile)){
        $cmd3 = 'convert -density 200 ' . escapeshellarg($pdfFile) . ' ' . escapeshellarg($outdir . '/slide-%03d.png') . ' 2>&1';
        append_log("Trying ImageMagick convert: $cmd3");
        exec($cmd3, $out3, $rc3);
        append_log("Exit code: $rc3; Output: " . implode(" | ", $out3));
        $cmds[] = ['cmd'=>$cmd3,'rc'=>$rc3,'out'=>$out3];
        $imgs = array_values(array_filter(scandir($outdir), function($f) use($outdir){ return preg_match('/\.png$/i',$f) && is_file($outdir.'/'.$f); }));
        $pngs_created = count($imgs);
    }

    if($pngs_created === 0){
        // Return detailed debug info for troubleshooting
        json_resp(['ok'=>false,'error'=>'Conversion failed or no PNGs produced','cmds'=>$cmds]);
    }

    // Return list of slide URLs
    $imgs = array_values(array_filter(scandir($outdir), function($f) use($outdir){ return preg_match('/\.png$/i',$f) && is_file($outdir.'/'.$f); }));
    sort($imgs); // ensure order
    $urls = array_map(function($f) use($outdir){ return 'slides/'.basename($outdir).'/'.rawurlencode($f); }, $imgs);
    json_resp(['ok'=>true,'upload'=>basename($target),'slides'=>array_values($urls),'debug_cmds'=>$cmds]);
}

if($action === 'list_slides'){
    $folder = $_GET['folder'] ?? '';
    $path = $SLIDE_DIR . '/' . basename($folder);
    if(!is_dir($path)) json_resp(['ok'=>false,'error'=>'Folder not found']);
    $imgs = array_values(array_filter(scandir($path), function($f) use($path){ return preg_match('/\.png$/i',$f) && is_file($path.'/'.$f); }));
    sort($imgs);
    $urls = array_map(function($f) use($path){ return 'slides/'.basename($path).'/'.rawurlencode($f); }, $imgs);
    json_resp(['ok'=>true,'slides'=>$urls]);
}

if($action === 'save_edited'){
    $data = $_POST['img'] ?? null; // dataURL
    $orig = $_POST['orig'] ?? 'slide.png';
    $folder = $_POST['folder'] ?? 'edited_session';
    $outdir = $EDITED_DIR . '/' . preg_replace('/[^a-zA-Z0-9_-]/','_',$folder);
    @mkdir($outdir,0777,true);
    if(!$data) json_resp(['ok'=>false,'error'=>'No image data']);
    if(preg_match('/^data:image\/(png|jpeg);base64,(.*)$/', $data, $m)){
        $bin = base64_decode($m[2]);
        $name = preg_replace('/[^a-zA-Z0-9._-]/','_', $orig);
        $out = $outdir . '/' . $name;
        file_put_contents($out,$bin);
        append_log("Saved edited image: $out");
        json_resp(['ok'=>true,'path'=> 'edited/'.basename($outdir).'/'.basename($out)]);
    } else json_resp(['ok'=>false,'error'=>'Invalid dataURL']);
}

if($action === 'export_zip'){
    $folder = $_GET['folder'] ?? '';
    $path = $EDITED_DIR . '/' . basename($folder);
    if(!is_dir($path)) json_resp(['ok'=>false,'error'=>'Edited folder not found']);
    $zipname = $EXPORT_DIR . '/slides_' . basename($path) . '_' . time() . '.zip';
    $zip = new ZipArchive();
    if($zip->open($zipname, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) json_resp(['ok'=>false,'error'=>'Failed to create zip']);
    foreach(scandir($path) as $f) if(preg_match('/\.png$/i',$f)) $zip->addFile($path.'/'.$f, $f);
    $zip->close();
    json_resp(['ok'=>true,'download'=>'exports/'.basename($zipname)]);
}

if($action === 'export_pptx'){
    $folder = $_GET['folder'] ?? '';
    $path = $EDITED_DIR . '/' . basename($folder);
    if(!is_dir($path)) json_resp(['ok'=>false,'error'=>'Edited folder not found']);
    if(!class_exists('PhpOffice\\PhpPresentation\\PhpPresentation')){
        json_resp(['ok'=>false,'error'=>'PHPPresentation not installed. Install with Composer: composer require phpoffice/phppresentation']);
    }

    $pptPath = $EXPORT_DIR . '/presentation_' . basename($path) . '_' . time() . '.pptx';
    $ppt = new PhpOffice\\PhpPresentation\\PhpPresentation();
    $ppt->removeSlideByIndex(0);
    $files = array_values(array_filter(scandir($path), function($f) use($path){ return preg_match('/\.png$/i',$f); }));
    sort($files);
    foreach($files as $f){
        $slide = $ppt->createSlide();
        $shape = $slide->createDrawingShape();
        $shape->setName('Slide image')->setPath($path.'/'.$f)->setHeight(540)->setOffsetX(0)->setOffsetY(0);
    }
    $oWriterPPTX = PhpOffice\\PhpPresentation\\IOFactory::createWriter($ppt, 'PowerPoint2007');
    $oWriterPPTX->save($pptPath);
    json_resp(['ok'=>true,'download'=>'exports/'.basename($pptPath)]);
}

// If no action — render the frontend page
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PPT Previewer + Editor (Single file) — unoconv pipeline</title>
<style>
body{font-family:system-ui,Segoe UI,Roboto,Arial;margin:0;padding:16px;background:#f4f6f8}
.container{max-width:1100px;margin:0 auto}
.card{background:#fff;border-radius:8px;box-shadow:0 6px 18px rgba(0,0,0,0.08);padding:16px;margin-bottom:16px}
.row{display:flex;gap:12px;flex-wrap:wrap}
.thumbnail{width:160px;height:120px;object-fit:cover;border:1px solid #ddd;cursor:pointer}
.canvas-wrap{position:relative;display:inline-block}
.toolbar{display:flex;gap:8px;margin-bottom:8px}
.btn{padding:8px 10px;border-radius:6px;border:1px solid #ccc;background:#fff;cursor:pointer}
.input-inline{display:inline-block}
#slideList{display:flex;gap:8px;flex-wrap:wrap}
.debug{font-family:monospace;background:#fff3cd;padding:8px;border-radius:6px;border:1px solid #ffeeba}
</style>
</head>
<body>