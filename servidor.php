<?php
/**
 * HashWeb - Servidor
 * Armazena sites identificados por hash (SHA-256 da chave privada) e serve
 * seus arquivos (html/css/js). Um único arquivo, SQLite + filesystem.
 *
 * Rotas:
 *   GET  servidor.php/raw/{hash}/{caminho}   -> serve o arquivo cru do site
 *   *    servidor.php?action=...             -> API JSON (ver switch abaixo)
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$dbFile   = __DIR__ . '/hashweb.db';
$sitesDir = __DIR__ . '/sites';
if (!is_dir($sitesDir)) mkdir($sitesDir, 0777, true);

$pdo = new PDO('sqlite:' . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE IF NOT EXISTS sites (
    hash TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    created_at TEXT NOT NULL
)");

// ---------- helpers ----------

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function siteDir($hash) {
    global $sitesDir;
    if (!preg_match('/^[a-f0-9]{64}$/', $hash)) jsonResponse(['error' => 'hash inválido'], 400);
    return $sitesDir . '/' . $hash;
}

// Resolve um caminho relativo com segurança dentro de $base (sem escapar via ../)
function safePath($base, $rel) {
    $rel = str_replace('\\', '/', (string) $rel);
    $parts = [];
    foreach (explode('/', $rel) as $seg) {
        if ($seg === '' || $seg === '.') continue;
        if ($seg === '..') { array_pop($parts); continue; }
        $parts[] = $seg;
    }
    return rtrim($base, '/') . '/' . implode('/', $parts);
}

function verifyOwner($hash, $privateKey) {
    if (!$privateKey || !hash_equals($hash, hash('sha256', $privateKey))) {
        jsonResponse(['error' => 'chave privada inválida'], 403);
    }
}

function deleteDirRecursive($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $p = $dir . '/' . $item;
        if (is_dir($p)) deleteDirRecursive($p); else unlink($p);
    }
    rmdir($dir);
}

function copyDirRecursive($src, $dst) {
    mkdir($dst, 0777, true);
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) copyDirRecursive($s, $d); else copy($s, $d);
    }
}

// ---------- servir arquivo cru do site (tela inicial / iframe) ----------

$pathInfo = $_SERVER['PATH_INFO'] ?? '';
if ($pathInfo && preg_match('#^/raw/([a-f0-9]{64})(/.*)?$#', $pathInfo, $m)) {
    $hash = $m[1];
    $rel  = isset($m[2]) && $m[2] !== '/' ? $m[2] : '/index.html';
    $dir  = siteDir($hash);
    $full = safePath($dir, $rel);

    if (!is_file($full)) { http_response_code(404); echo 'Arquivo não encontrado no site.'; exit; }

    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mimes = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'application/javascript'];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    readfile($full);
    exit;
}

// ---------- API ----------

$action = $_REQUEST['action'] ?? '';

switch ($action) {

    case 'create_site': {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') jsonResponse(['error' => 'nome do site é obrigatório'], 400);

        $privateKey = bin2hex(random_bytes(32));
        $hash = hash('sha256', $privateKey);
        $dir = siteDir($hash);
        mkdir($dir, 0777, true);

        $stmt = $pdo->prepare('INSERT INTO sites (hash, name, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$hash, $name, date('c')]);

        jsonResponse(['hash' => $hash, 'private_key' => $privateKey, 'name' => $name]);
    }

    case 'import_site': {
        $name = trim($_POST['name'] ?? '');
        $privateKey = $_POST['private_key'] ?? '';
        if ($name === '') jsonResponse(['error' => 'nome do site é obrigatório'], 400);
        if (!$privateKey) jsonResponse(['error' => 'chave privada é obrigatória'], 400);
        $hash = hash('sha256', $privateKey);
        $dir = siteDir($hash);
        $stmt = $pdo->prepare('SELECT hash FROM sites WHERE hash = ?');
        $stmt->execute([$hash]);
        if ($stmt->fetch()) {
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            jsonResponse(['hash' => $hash, 'name' => $name, 'exists' => true]);
        }
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        $stmt = $pdo->prepare('INSERT INTO sites (hash, name, created_at) VALUES (?, ?, ?)');
        $stmt->execute([$hash, $name, date('c')]);
        jsonResponse(['hash' => $hash, 'name' => $name, 'exists' => false]);
    }

    case 'recover': {
        $privateKey = $_REQUEST['private_key'] ?? '';
        $hash = hash('sha256', $privateKey);
        $stmt = $pdo->prepare('SELECT hash, name FROM sites WHERE hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResponse(['error' => 'nenhum site encontrado para essa chave'], 404);
        jsonResponse(['hash' => $row['hash'], 'name' => $row['name']]);
    }

    case 'get_site': {
        $hash = $_REQUEST['hash'] ?? '';
        $stmt = $pdo->prepare('SELECT hash, name, created_at FROM sites WHERE hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsonResponse(['error' => 'site não encontrado'], 404);
        jsonResponse($row);
    }

    case 'rename_site': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        $newName = trim($_POST['name'] ?? '');
        if ($newName === '') jsonResponse(['error' => 'nome inválido'], 400);
        $stmt = $pdo->prepare('UPDATE sites SET name = ? WHERE hash = ?');
        $stmt->execute([$newName, $hash]);
        jsonResponse(['ok' => true]);
    }

    case 'delete_site': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        deleteDirRecursive(siteDir($hash));
        $stmt = $pdo->prepare('DELETE FROM sites WHERE hash = ?');
        $stmt->execute([$hash]);
        jsonResponse(['ok' => true]);
    }

    case 'list_files': {
        $hash = $_REQUEST['hash'] ?? '';
        $path = $_REQUEST['path'] ?? '/';
        $dir = safePath(siteDir($hash), $path);
        if (!is_dir($dir)) jsonResponse(['error' => 'pasta não encontrada'], 404);

        $items = [];
        foreach (scandir($dir) as $it) {
            if ($it === '.' || $it === '..') continue;
            $full = $dir . '/' . $it;
            $items[] = [
                'name'   => $it,
                'is_dir' => is_dir($full),
                'size'   => is_file($full) ? filesize($full) : 0,
            ];
        }
        usort($items, function ($a, $b) {
            if ($a['is_dir'] !== $b['is_dir']) return $a['is_dir'] ? -1 : 1;
            return strcasecmp($a['name'], $b['name']);
        });
        jsonResponse(['items' => $items]);
    }

    case 'create_folder': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        $path = $_POST['path'] ?? '/';
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || !preg_match('/^[^\/\\\\]+$/', $name)) jsonResponse(['error' => 'nome de pasta inválido'], 400);
        $full = safePath(siteDir($hash), rtrim($path, '/') . '/' . $name);
        if (file_exists($full)) jsonResponse(['error' => 'já existe um item com esse nome'], 400);
        mkdir($full, 0777, true);
        jsonResponse(['ok' => true]);
    }

    case 'create_file': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        $path = $_POST['path'] ?? '/';
        $name = trim($_POST['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($name === '' || !in_array($ext, ['html', 'css', 'js'], true)) {
            jsonResponse(['error' => 'use um nome de arquivo terminando em .html, .css ou .js'], 400);
        }
        $full = safePath(siteDir($hash), rtrim($path, '/') . '/' . $name);
        if (file_exists($full)) jsonResponse(['error' => 'já existe um item com esse nome'], 400);
        file_put_contents($full, '');
        jsonResponse(['ok' => true]);
    }

    case 'get_file': {
        $hash = $_REQUEST['hash'] ?? '';
        $path = $_REQUEST['path'] ?? '';
        $full = safePath(siteDir($hash), $path);
        if (!is_file($full)) jsonResponse(['error' => 'arquivo não encontrado'], 404);
        jsonResponse(['content' => file_get_contents($full)]);
    }

    case 'save_file': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        $path = $_POST['path'] ?? '';
        $content = $_POST['content'] ?? '';
        $full = safePath(siteDir($hash), $path);
        if (!is_file($full)) jsonResponse(['error' => 'arquivo não encontrado'], 404);
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        if (!in_array($ext, ['html', 'css', 'js'], true)) jsonResponse(['error' => 'formato não suportado'], 400);
        file_put_contents($full, $content);
        jsonResponse(['ok' => true]);
    }

    case 'rename': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        $path = $_POST['path'] ?? '';
        $newName = trim($_POST['new_name'] ?? '');
        if ($newName === '' || !preg_match('/^[^\/\\\\]+$/', $newName)) jsonResponse(['error' => 'nome inválido'], 400);
        $full = safePath(siteDir($hash), $path);
        if (!file_exists($full)) jsonResponse(['error' => 'item não encontrado'], 404);
        if (is_file($full)) {
            $ext = strtolower(pathinfo($newName, PATHINFO_EXTENSION));
            if (!in_array($ext, ['html', 'css', 'js'], true)) jsonResponse(['error' => 'use .html, .css ou .js'], 400);
        }
        $newFull = dirname($full) . '/' . $newName;
        if (file_exists($newFull)) jsonResponse(['error' => 'já existe um item com esse nome'], 400);
        rename($full, $newFull);
        jsonResponse(['ok' => true]);
    }

    case 'delete': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        $path = $_POST['path'] ?? '';
        if (rtrim($path, '/') === '') jsonResponse(['error' => 'não é possível apagar a raiz do site'], 400);
        $full = safePath(siteDir($hash), $path);
        if (!file_exists($full)) jsonResponse(['error' => 'item não encontrado'], 404);
        if (is_dir($full)) deleteDirRecursive($full); else unlink($full);
        jsonResponse(['ok' => true]);
    }

    case 'move':
    case 'copy': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        $path = $_POST['path'] ?? '';
        $dest = $_POST['dest'] ?? '';
        if (trim($dest) === '') jsonResponse(['error' => 'pasta de destino não informada'], 400);
        $srcFull = safePath(siteDir($hash), $path);
        if (!file_exists($srcFull)) jsonResponse(['error' => 'origem não encontrada'], 404);
        $destDir = safePath(siteDir($hash), $dest);
        if (!is_dir($destDir)) jsonResponse(['error' => 'pasta de destino inválida'], 400);
        $target = rtrim($destDir, '/') . '/' . basename($srcFull);
        if (strpos($target . '/', rtrim($srcFull, '/') . '/') === 0) jsonResponse(['error' => 'destino inválido (dentro da própria pasta)'], 400);
        if (file_exists($target)) jsonResponse(['error' => 'já existe um item com esse nome no destino'], 400);
        if ($action === 'move') {
            rename($srcFull, $target);
        } else {
            if (is_dir($srcFull)) copyDirRecursive($srcFull, $target); else copy($srcFull, $target);
        }
        jsonResponse(['ok' => true]);
    }

    case 'upload_file': {
        $hash = $_POST['hash'] ?? '';
        verifyOwner($hash, $_POST['private_key'] ?? '');
        $path = $_POST['path'] ?? '/';
        $name = trim($_POST['name'] ?? '');
        $contentB64 = $_POST['content'] ?? '';
        if ($name === '' || !preg_match('/^[^\/\\\\]+$/', $name)) jsonResponse(['error' => 'nome de arquivo inválido'], 400);
        $full = safePath(siteDir($hash), rtrim($path, '/') . '/' . $name);
        @mkdir(dirname($full), 0777, true);
        file_put_contents($full, base64_decode($contentB64));
        jsonResponse(['ok' => true]);
    }

    case 'get_file_b64': {
        $hash = $_REQUEST['hash'] ?? '';
        $path = $_REQUEST['path'] ?? '';
        $full = safePath(siteDir($hash), $path);
        if (!is_file($full)) jsonResponse(['error' => 'arquivo não encontrado'], 404);
        jsonResponse(['content' => base64_encode(file_get_contents($full))]);
    }

    default:
        jsonResponse(['error' => 'ação inválida'], 400);
}
