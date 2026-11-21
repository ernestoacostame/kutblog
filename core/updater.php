<?php
// ============================================================================
// KutBlog · Motor de actualización (Servidor KutStudio + GitHub Fallback)
// ============================================================================
require_once __DIR__ . '/config.php';

define('CMS_UPDATE_SERVER', 'https://kutstudio.org/updates/');
define('CMS_UPDATE_REPO', 'ernestoacostame/KutBlog');
define('CMS_UPDATE_SUBDIR', ''); // KutBlog root is the zip root folder

function cms_update_dir(): string {
  $dir = CACHE_PATH . '/updates';
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  return $dir;
}

// Comprobar actualización disponible
function cms_update_check(): ?array {
  $token = cms_setting('update_github_token', '');
  $localVersion = CMS_VERSION;

  // 1. Modo Desarrollo / Directo: Si el usuario configuró un token de GitHub explícito
  if (!empty($token)) {
    $url = 'https://api.github.com/repos/' . CMS_UPDATE_REPO . '/releases/latest';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => [
        "Authorization: Bearer $token",
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
      ],
      CURLOPT_USERAGENT => 'KutBlog/' . CMS_VERSION,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_MAXREDIRS => 5,
      CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($http === 401 || $http === 403) {
      return ['error' => 'Token de GitHub de desarrollo inválido o sin permisos. HTTP ' . $http];
    }
    if ($http !== 200 || !$resp) {
      return ['error' => "Error al consultar GitHub directamente (HTTP $http). $err"];
    }

    $release = json_decode($resp, true);
    if (!$release || empty($release['tag_name'])) {
      return ['error' => 'No se encontraron releases en el repositorio de GitHub.'];
    }

    $remoteVersion = ltrim($release['tag_name'], 'vV');
    if (version_compare($remoteVersion, $localVersion, '<=')) {
      cms_update_save_cache(['up_to_date' => true, 'version' => $localVersion]);
      return ['up_to_date' => true, 'version' => $localVersion];
    }

    $zipUrl = '';
    $zipIsAsset = false;
    if (!empty($release['assets'])) {
      foreach ($release['assets'] as $asset) {
        if (preg_match('/^kutblog.*\.zip$/i', $asset['name']) || preg_match('/\.zip$/i', $asset['name'])) {
          $zipUrl = $asset['url'];
          $zipIsAsset = true;
          break;
        }
      }
    }
    if (!$zipUrl) {
      $zipUrl = $release['zipball_url'] ?? '';
    }

    $result = [
      'available' => true,
      'channel' => 'github_direct',
      'current_version' => $localVersion,
      'new_version' => $remoteVersion,
      'tag' => $release['tag_name'],
      'name' => $release['name'] ?? $release['tag_name'],
      'changelog' => $release['body'] ?? '',
      'published_at' => $release['published_at'] ?? '',
      'zip_url' => $zipUrl,
      'zip_is_asset' => $zipIsAsset,
      'html_url' => $release['html_url'] ?? '',
    ];

    cms_update_save_cache($result);
    return $result;
  }

  // 2. Modo Estándar Transparente: Servidor Central de Actualizaciones KutStudio
  $server = rtrim(defined('CMS_UPDATE_SERVER') ? CMS_UPDATE_SERVER : 'https://kutstudio.com/updates/', '/');
  $url = $server . '/?app=kutblog&action=check&version=' . rawurlencode($localVersion) . '&nocache=1';

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERAGENT => 'KutBlog/' . CMS_VERSION,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 15,
  ]);
  $resp = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($http !== 200 || !$resp) {
    return ['error' => 'No se pudo conectar con el servidor de actualizaciones KutStudio. ' . ($err ?: "HTTP $http")];
  }

  $data = json_decode($resp, true);
  if (!$data) {
    return ['error' => 'Respuesta no válida del servidor de actualizaciones.'];
  }
  if (!empty($data['error'])) {
    return ['error' => $data['error']];
  }

  if (!empty($data['up_to_date'])) {
    cms_update_save_cache(['up_to_date' => true, 'version' => $localVersion]);
    return ['up_to_date' => true, 'version' => $localVersion];
  }

  $data['channel'] = 'kutstudio';
  cms_update_save_cache($data);
  return $data;
}

function cms_setting(string $key, $default = '') {
  $config = cms_config();
  return $config[$key] ?? $default;
}

function cms_set_setting(string $key, $value): void {
  $config = cms_config();
  $config[$key] = $value;
  cms_save_config($config);
}

function cms_update_save_cache(array $data): void {
  cms_set_setting('update_cache', $data);
  cms_set_setting('update_cache_at', time());
}

function cms_update_cached(): ?array {
  $cache = cms_setting('update_cache', null);
  if (!is_array($cache)) return null;
  return $cache;
}

function cms_update_cache_stale(): bool {
  $at = (int)cms_setting('update_cache_at', 0);
  return (time() - $at) > 21600; // 6 horas
}

// Descargar el ZIP
function cms_update_download(string $zipUrl): array {
  if (!$zipUrl) return ['error' => 'URL del ZIP no disponible.'];

  $destDir = cms_update_dir();
  $destPath = $destDir . '/update-' . date('Ymd_His') . '.zip';

  $fp = fopen($destPath, 'w');
  if (!$fp) return ['error' => 'No se puede escribir en ' . $destDir];

  $headers = [
    'User-Agent: KutBlog/' . CMS_VERSION,
  ];

  // Si la descarga es directamente desde api.github.com, requerir y adjuntar el token
  $isGitHubApi = (strpos($zipUrl, 'api.github.com') !== false);
  if ($isGitHubApi) {
    $token = cms_setting('update_github_token', '');
    if (!$token) {
      fclose($fp);
      @unlink($destPath);
      return ['error' => 'Token de GitHub no configurado para descarga directa desde la API de GitHub.'];
    }
    $headers[] = "Authorization: Bearer $token";
    $headers[] = 'Accept: application/octet-stream';
    $headers[] = 'X-GitHub-Api-Version: 2022-11-28';
  }

  $ch = curl_init($zipUrl);
  curl_setopt_array($ch, [
    CURLOPT_FILE => $fp,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_USERAGENT => 'KutBlog/' . CMS_VERSION,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => 300,
  ]);
  curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  fclose($fp);

  if ($http >= 400 || !file_exists($destPath) || filesize($destPath) < 1024) {
    @unlink($destPath);
    return ['error' => "Error al descargar el ZIP (HTTP $http). $err"];
  }

  return ['path' => $destPath, 'size' => filesize($destPath)];
}

// Crear backup pre-actualización
function cms_update_backup(): array {
  $rootDir = ROOT_PATH;
  $destDir = cms_update_dir();
  $backupName = 'rollback-' . CMS_VERSION . '-' . date('Ymd_His') . '.tar.gz';
  $backupPath = $destDir . '/' . $backupName;

  // Directorios y archivos protegidos a excluir del backup
  $excludes = ['content', 'uploads', 'media', 'cache', '.git', 'config.json', '.installed', '.htaccess'];

  $excludeArgs = '';
  foreach ($excludes as $ex) {
    $excludeArgs .= ' --exclude=' . escapeshellarg('./' . $ex);
  }

  $cmd = sprintf(
    'tar -czf %s -C %s %s .',
    escapeshellarg($backupPath),
    escapeshellarg($rootDir),
    $excludeArgs
  );

  exec($cmd . ' 2>&1', $output, $exitCode);

  if ($exitCode !== 0 || !file_exists($backupPath)) {
    return ['error' => 'Error al crear el backup: ' . implode("\n", $output)];
  }

  cms_set_setting('update_rollback_path', $backupPath);
  cms_set_setting('update_rollback_version', CMS_VERSION);

  return ['path' => $backupPath, 'size' => filesize($backupPath)];
}

// Aplicar actualización desde el ZIP
function cms_update_apply(string $zipPath, string $newVersion): array {
  if (!file_exists($zipPath)) return ['error' => 'El fichero ZIP no existe.'];
  if (!class_exists('ZipArchive')) return ['error' => 'La extensión ZipArchive de PHP no está instalada.'];

  $rootDir = ROOT_PATH;

  // 1. Crear backup
  $backup = cms_update_backup();
  if (!empty($backup['error'])) return $backup;

  // 2. Extraer ZIP
  $tmpDir = cms_update_dir() . '/tmp-' . uniqid();
  @mkdir($tmpDir, 0755, true);

  $zip = new ZipArchive();
  $res = $zip->open($zipPath);
  if ($res !== true) {
    cms_update_cleanup_tmp($tmpDir);
    return ['error' => 'No se pudo abrir el ZIP (código: ' . $res . ')'];
  }
  $zip->extractTo($tmpDir);
  $zip->close();

  // 3. Localizar la raíz de KutBlog en el ZIP
  $sourceDir = cms_update_find_cms_dir($tmpDir);
  if (!$sourceDir) {
    cms_update_cleanup_tmp($tmpDir);
    return ['error' => 'No se encontró la carpeta de KutBlog dentro del ZIP. Asegúrate de que contiene un index.php o similar.'];
  }

  // 4. Directorios protegidos
  $protected = ['content', 'uploads', 'media', 'cache', '.git', 'config.json', '.installed', '.htaccess'];

  // 5. Copiar ficheros recursivamente
  $copied = 0;
  $errors = [];

  try {
    $copied = cms_update_copy_recursive($sourceDir, $rootDir, $protected, $errors);
  } catch (Throwable $e) {
    $errors[] = $e->getMessage();
  }

  if (!empty($errors)) {
    // Rollback automático
    $rb = cms_update_rollback_from_path($backup['path']);
    cms_update_cleanup_tmp($tmpDir);
    return ['error' => 'Error al copiar ficheros. Se restauró el backup automáticamente. Errores: ' . implode('; ', array_slice($errors, 0, 5))];
  }

  // 6. Actualizar core/config.php por si acaso la versión no se copió bien
  $config_file = $rootDir . '/core/config.php';
  if (file_exists($config_file)) {
    $content = file_get_contents($config_file);
    $content = preg_replace("/define\('CMS_VERSION',\s*'[^']+'\);/", "define('CMS_VERSION', '$newVersion');", $content);
    file_put_contents($config_file, $content);
  }

  // 7. Registrar en el historial
  $history = cms_setting('update_history', []);
  $history[] = [
    'from_version' => CMS_VERSION,
    'to_version' => $newVersion,
    'status' => 'applied',
    'backup_path' => $backup['path'],
    'applied_at' => date('Y-m-d H:i:s'),
  ];
  cms_set_setting('update_history', $history);

  // 8. Limpiar archivos temporales
  cms_update_cleanup_tmp($tmpDir);
  @unlink($zipPath);

  // 9. Limpiar caché de actualización
  cms_update_save_cache(['up_to_date' => true, 'version' => $newVersion]);

  return ['success' => true, 'from' => CMS_VERSION, 'to' => $newVersion, 'files_updated' => $copied];
}

/**
 * Aplica una actualización desde un archivo ZIP local (manual o descargado)
 * detectando automáticamente la nueva versión desde core/config.php o version.php.
 */
function cms_update_apply_zip(string $zipPath, ?string $manualVersion = null): array {
  if (!file_exists($zipPath)) return ['error' => 'El fichero ZIP no existe.'];
  if (!class_exists('ZipArchive')) return ['error' => 'La extensión ZipArchive de PHP no está instalada.'];

  $zip = new ZipArchive();
  $res = $zip->open($zipPath);
  if ($res !== true) {
    @unlink($zipPath);
    return ['error' => 'No se pudo abrir el archivo ZIP (código: ' . $res . '). Verifica que sea un archivo ZIP válido.'];
  }

  // Buscar core/config.php o version.php dentro del ZIP para auto-detectar la versión
  $detectedVersion = '';
  for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    $name = $stat['name'] ?? '';
    if (preg_match('#(?:^|/)core/config\.php$#i', $name)) {
      $content = $zip->getFromIndex($i);
      if ($content && preg_match("/define\('CMS_VERSION',\s*'([^']+)'\)/", $content, $m)) {
        $detectedVersion = trim($m[1]);
        break;
      }
    } elseif (preg_match('#(?:^|/)version\.php$#i', $name)) {
      $content = $zip->getFromIndex($i);
      if ($content && preg_match("/CMS_VERSION',\s*'([^']+)'/", $content, $m)) {
        $detectedVersion = trim($m[1]);
        break;
      }
    }
  }
  $zip->close();

  $versionToApply = $manualVersion ?: $detectedVersion;
  if (!$versionToApply) {
    // Si no se encontró en el archivo, intentar extraer del nombre de archivo (ej: kutblog-v2.1.16.zip)
    if (preg_match('/(?:v|kutblog-v?)([0-9]+\.[0-9]+\.[0-9]+)/i', basename($zipPath), $vm)) {
      $versionToApply = $vm[1];
    }
  }

  if (!$versionToApply) {
    @unlink($zipPath);
    return ['error' => 'No se pudo determinar la versión de KutBlog dentro del ZIP. Asegúrate de subir un archivo de release oficial de KutBlog.'];
  }

  return cms_update_apply($zipPath, $versionToApply);
}

// Revertir a versión seleccionada o anterior
function cms_update_rollback(?string $specificFilename = null): array {
  if ($specificFilename !== null) {
    if (!preg_match('/^rollback-(.*?)-(\d{8}_\d{6})\.tar\.gz$/', $specificFilename, $matches)) {
      return ['error' => 'Nombre de backup inválido.'];
    }
    $backupPath = cms_update_dir() . '/' . $specificFilename;
    if (!file_exists($backupPath)) {
      return ['error' => 'El archivo de backup seleccionado no existe.'];
    }
    $rollbackVersion = $matches[1];
  } else {
    $backupPath = cms_setting('update_rollback_path', '');
    $rollbackVersion = cms_setting('update_rollback_version', '');
  }

  if (!$backupPath || !file_exists($backupPath)) {
    return ['error' => 'No hay backup de rollback disponible.'];
  }

  $result = cms_update_rollback_from_path($backupPath);

  if (!empty($result['error'])) return $result;

  // Restaurar versión en core/config.php
  if ($rollbackVersion) {
    $config_file = ROOT_PATH . '/core/config.php';
    if (file_exists($config_file)) {
      $content = file_get_contents($config_file);
      $content = preg_replace("/define\('CMS_VERSION',\s*'[^']+'\);/", "define('CMS_VERSION', '$rollbackVersion');", $content);
      file_put_contents($config_file, $content);
    }
  }

  // Actualizar historial
  $history = cms_setting('update_history', []);
  if (!empty($history)) {
    // Buscar la última actualización aplicada que coincida con este backup
    for ($i = count($history) - 1; $i >= 0; $i--) {
      if (basename($history[$i]['backup_path']) === basename($backupPath)) {
        $history[$i]['status'] = 'rolled_back';
        $history[$i]['rolled_back_at'] = date('Y-m-d H:i:s');
        break;
      }
    }
    cms_set_setting('update_history', $history);
  }

  // Limpiar referencia de settings si coincide
  $defaultBackupPath = cms_setting('update_rollback_path', '');
  if ($backupPath === $defaultBackupPath) {
    cms_set_setting('update_rollback_path', '');
    cms_set_setting('update_rollback_version', '');
  }

  cms_update_save_cache(['up_to_date' => true, 'version' => $rollbackVersion]);

  return ['success' => true, 'restored_version' => $rollbackVersion];
}

// Obtener copias de seguridad de rollback disponibles en cache/updates/
function cms_update_get_available_rollbacks(): array {
  $dir = cms_update_dir();
  $files = glob($dir . '/rollback-*.tar.gz');
  if (!$files) return [];
  
  $rollbacks = [];
  foreach ($files as $file) {
    $filename = basename($file);
    if (preg_match('/^rollback-(.*?)-(\d{8}_\d{6})\.tar\.gz$/', $filename, $matches)) {
      $version = $matches[1];
      $datetimeStr = $matches[2];
      
      $year = substr($datetimeStr, 0, 4);
      $month = substr($datetimeStr, 4, 2);
      $day = substr($datetimeStr, 6, 2);
      $hour = substr($datetimeStr, 9, 2);
      $minute = substr($datetimeStr, 11, 2);
      $second = substr($datetimeStr, 13, 2);
      $formattedDate = "$day/$month/$year $hour:$minute:$second";
      
      $rollbacks[] = [
        'filename' => $filename,
        'path' => $file,
        'version' => $version,
        'date' => $formattedDate,
        'timestamp' => strtotime("$year-$month-$day $hour:$minute:$second"),
        'size' => filesize($file)
      ];
    }
  }
  
  // Ordenar por fecha desc (más reciente primero)
  usort($rollbacks, function($a, $b) {
    return $b['timestamp'] <=> $a['timestamp'];
  });
  
  return $rollbacks;
}

// Restaurar desde un archivo tar.gz
function cms_update_rollback_from_path(string $backupPath): array {
  if (!file_exists($backupPath)) return ['error' => 'Archivo de backup no encontrado.'];

  $rootDir = ROOT_PATH;
  $cmd = sprintf(
    'tar -xzf %s -C %s --exclude=%s --exclude=%s --exclude=%s --exclude=%s --exclude=%s --exclude=%s --exclude=%s --exclude=%s 2>&1',
    escapeshellarg($backupPath),
    escapeshellarg($rootDir),
    escapeshellarg('./content'),
    escapeshellarg('./uploads'),
    escapeshellarg('./media'),
    escapeshellarg('./cache'),
    escapeshellarg('./.htaccess'),
    escapeshellarg('./.git'),
    escapeshellarg('./config.json'),
    escapeshellarg('./.installed')
  );

  exec($cmd, $output, $exitCode);

  if ($exitCode !== 0) {
    return ['error' => 'Error al restaurar el backup: ' . implode("\n", $output)];
  }

  return ['success' => true];
}

// Buscar el directorio principal de KutBlog dentro del ZIP
function cms_update_find_cms_dir(string $tmpDir): ?string {
  if (file_exists("$tmpDir/index.php")) return $tmpDir;

  $entries = @scandir($tmpDir);
  if (!$entries) return null;

  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = "$tmpDir/$entry";
    if (!is_dir($path)) continue;

    if (file_exists("$path/index.php")) return $path;
  }

  return null;
}

// Copiar recursivamente ignorando directorios protegidos en el nivel raíz
function cms_update_copy_recursive(string $src, string $dst, array $protected, array &$errors, string $relPath = ''): int {
  $copied = 0;
  $entries = @scandir($src);
  if (!$entries) return 0;

  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;

    $currentRel = $relPath ? "$relPath/$entry" : $entry;

    if (!$relPath && in_array($entry, $protected, true)) continue;

    $srcPath = "$src/$entry";
    $dstPath = "$dst/$entry";

    if (is_dir($srcPath)) {
      if (!is_dir($dstPath)) {
        if (!@mkdir($dstPath, 0755, true)) {
          $errors[] = "No se pudo crear directorio: $currentRel";
          continue;
        }
      }
      $copied += cms_update_copy_recursive($srcPath, $dstPath, $protected, $errors, $currentRel);
    } else {
      $ok = @copy($srcPath, $dstPath);
      if ($ok) {
        @chmod($dstPath, 0644);
        $copied++;
      } else {
        $reason = '';
        if (!is_readable($srcPath)) {
          $reason = 'origen no legible';
        } elseif (file_exists($dstPath) && !is_writable($dstPath)) {
          $reason = 'destino no escribible';
        } elseif (!is_writable(dirname($dstPath))) {
          $reason = 'directorio destino no escribible';
        } else {
          $err = error_get_last();
          $reason = $err['message'] ?? 'error desconocido';
        }
        $errors[] = "No se pudo copiar: $currentRel — $reason";
      }
    }
  }

  return $copied;
}

// Limpiar carpeta temporal
function cms_update_cleanup_tmp(string $dir): void {
  if (!is_dir($dir)) return;
  $entries = @scandir($dir);
  if (!$entries) return;
  foreach ($entries as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $path = "$dir/$entry";
    if (is_dir($path)) {
      cms_update_cleanup_tmp($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}

function cms_update_delete_backup(string $filename): array {
  if (!preg_match('/^rollback-([^\/]+?)-(\d{8}_\d{6})\.tar\.gz$/', $filename)) {
    return ['error' => 'Nombre de archivo de respaldo inválido.'];
  }
  $filePath = cms_update_dir() . '/' . $filename;
  if (!file_exists($filePath)) {
    return ['error' => 'El archivo de respaldo no existe.'];
  }
  if (@unlink($filePath)) {
    $defaultBackupPath = cms_setting('update_rollback_path', '');
    if (basename($defaultBackupPath) === $filename) {
      cms_set_setting('update_rollback_path', '');
      cms_set_setting('update_rollback_version', '');
    }
    return ['success' => true];
  }
  return ['error' => 'No se pudo eliminar el archivo de respaldo.'];
}
