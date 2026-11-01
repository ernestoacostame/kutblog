<?php
require_once dirname(__DIR__) . '/core/config.php';
require_once dirname(__DIR__) . '/core/auth.php';
require_once dirname(__DIR__) . '/core/theme.php';
require_once __DIR__ . '/layout.php';
require_login();
require_permission('manage_users');

$csrf = generate_csrf();
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf'] ?? '')) {
        $error = 'Error de seguridad CSRF.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_user') {
            $username = strtolower(trim($_POST['username'] ?? ''));
            $name     = trim($_POST['name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $role     = in_array($_POST['role'] ?? '', ['admin', 'editor', 'author']) ? $_POST['role'] : 'editor';
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';

            if (!$username || !preg_match('/^[a-zA-Z0-9_\-\.]{3,30}$/', $username)) {
                $error = 'El nombre de usuario debe tener entre 3 y 30 caracteres alfanuméricos.';
            } elseif (get_user($username)) {
                $error = "El usuario «{$username}» ya existe.";
            } elseif ($password !== $confirm) {
                $error = 'Las contraseñas no coinciden.';
            } elseif (strlen($password) < 8) {
                $error = 'La contraseña debe tener al menos 8 caracteres.';
            } else {
                $avatar = '';
                if (!empty($_FILES['avatar_file']['tmp_name'])) {
                    $up = user_save_avatar($username, $_FILES['avatar_file']);
                    if ($up) $avatar = $up;
                }

                $userData = [
                    'username'           => $username,
                    'name'               => $name ?: $username,
                    'email'              => $email,
                    'role'               => $role,
                    'avatar'             => $avatar,
                    'status'             => 'active',
                    'password'           => hash_password($password),
                    'two_factor_enabled' => false,
                    'created_at'         => date('c'),
                ];
                if (save_user($userData)) {
                    $msg = "Usuario «{$username}» creado con éxito.";
                } else {
                    $error = 'No se pudo guardar el usuario.';
                }
            }
        } elseif ($action === 'edit_user') {
            $username = trim($_POST['username'] ?? '');
            $name     = trim($_POST['name'] ?? '');
            $email    = trim($_POST['email'] ?? '');
            $role     = in_array($_POST['role'] ?? '', ['admin', 'editor', 'author']) ? $_POST['role'] : 'editor';
            $password = $_POST['password'] ?? '';
            $confirm  = $_POST['confirm_password'] ?? '';
            $status   = in_array($_POST['status'] ?? '', ['active', 'suspended']) ? $_POST['status'] : 'active';

            $user = get_user($username);
            if (!$user) {
                $error = 'Usuario no encontrado.';
            } else {
                // Prevent demoting the last admin
                if ($user['role'] === 'admin' && $role !== 'admin') {
                    $admins = array_filter(list_users(), fn($u) => ($u['role'] ?? '') === 'admin');
                    if (count($admins) <= 1) {
                        $role = 'admin';
                    }
                }

                $user['name'] = $name ?: $username;
                $user['email'] = $email;
                $user['role'] = $role;
                $user['status'] = $status;

                // Handle avatar removal or upload
                if (!empty($_POST['remove_avatar']) && $_POST['remove_avatar'] === '1') {
                    if (!empty($user['avatar']) && str_starts_with($user['avatar'], 'media/avatars/')) {
                        $oldFile = ROOT_PATH . '/' . $user['avatar'];
                        if (file_exists($oldFile)) @unlink($oldFile);
                    }
                    $user['avatar'] = '';
                } elseif (!empty($_FILES['avatar_file']['tmp_name'])) {
                    $up = user_save_avatar($username, $_FILES['avatar_file']);
                    if ($up) {
                        $user['avatar'] = $up;
                    } else {
                        $error = 'Formato de imagen no válido o archivo superior a 8 MB.';
                    }
                }

                if ($password) {
                    if ($password !== $confirm) {
                        $error = 'Las contraseñas no coinciden.';
                    } elseif (strlen($password) < 8) {
                        $error = 'La contraseña debe tener al menos 8 caracteres.';
                    } else {
                        $user['password'] = hash_password($password);
                    }
                }

                if (!$error) {
                    save_user($user);
                    $msg = "Usuario «{$username}» actualizado correctamente.";
                }
            }
        } elseif ($action === 'delete_user') {
            $username = trim($_POST['username'] ?? '');
            $currentUser = current_user();

            if ($currentUser && $currentUser['username'] === $username) {
                $error = 'No puedes eliminar tu propia cuenta de usuario en uso.';
            } elseif (delete_user($username)) {
                $msg = "Usuario «{$username}» eliminado correctamente.";
            } else {
                $error = 'No se pudo eliminar el usuario (no es posible eliminar al último administrador).';
            }
        }
    }
}

$users = list_users();
admin_header('Gestión de Usuarios', 'users');
?>
      <button class="btn btn-primary" onclick="openCreateUserModal()">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Nuevo usuario
      </button>
    </div>
  </div>
  <div class="page-body">
    <?php if ($msg): ?><div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <span class="card-title"><?= count($users) ?> Usuarios registrados</span>
      </div>
      <table class="table">
        <thead>
          <tr>
            <th>Usuario</th>
            <th>Email</th>
            <th>Rol</th>
            <th>Seguridad 2FA</th>
            <th>Último acceso</th>
            <th style="text-align:right">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): 
            $isSelf = ($_SESSION['username'] ?? '') === $u['username'];
            $avatarUrl = !empty($u['avatar']) ? user_avatar_url($u['avatar']) : '';
          ?>
          <tr>
            <td>
              <div style="display:flex;align-items:center;gap:0.85rem">
                <?php if ($avatarUrl): ?>
                  <img src="<?= htmlspecialchars(image_cache_url($avatarUrl, 80, 80)) ?>" alt="<?= htmlspecialchars($u['name'] ?: $u['username']) ?>" class="user-avatar" style="object-fit:cover;border:1px solid var(--border)">
                <?php else: ?>
                  <div class="user-avatar" style="width:36px;height:36px;font-size:0.9rem">
                    <?= strtoupper(substr($u['name'] ?: $u['username'], 0, 1)) ?>
                  </div>
                <?php endif; ?>
                <div>
                  <div style="font-weight:700;color:var(--text)"><?= htmlspecialchars($u['name'] ?: $u['username']) ?> <?= $isSelf ? '<span style="font-size:0.75rem;opacity:0.65;font-weight:600">(Tú)</span>' : '' ?></div>
                  <div style="font-size:0.82rem;color:var(--muted)">@<?= htmlspecialchars($u['username']) ?></div>
                </div>
              </div>
            </td>
            <td style="color:var(--text2);font-size:0.9rem">
              <?= htmlspecialchars($u['email'] ?? '—') ?>
            </td>
            <td>
              <?php
                $roleBadge = match($u['role'] ?? 'author') {
                  'admin'  => ['badge-green', 'Administrador'],
                  'editor' => ['badge-yellow', 'Editor'],
                  default  => ['badge-gray', 'Autor'],
                };
              ?>
              <span class="badge <?= $roleBadge[0] ?>"><?= $roleBadge[1] ?></span>
            </td>
            <td>
              <?php if (!empty($u['two_factor_enabled'])): ?>
                <span class="badge badge-green">✓ 2FA Activo</span>
              <?php else: ?>
                <span class="badge badge-gray">Inactivo</span>
              <?php endif; ?>
            </td>
            <td style="color:var(--muted);font-size:0.88rem">
              <?= !empty($u['last_login']) ? format_date($u['last_login'], 'admin') : __('never', 'Nunca') ?>
            </td>
            <td style="text-align:right">
              <button class="btn btn-secondary btn-sm" onclick='openEditUserModal(<?= json_encode($u) ?>)'>Editar</button>
              <?php if (!$isSelf): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('¿Eliminar el usuario «<?= htmlspecialchars($u['username']) ?>»?')">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="username" value="<?= htmlspecialchars($u['username']) ?>">
                <button type="submit" class="btn btn-danger btn-sm" style="padding:0.4rem 0.65rem">✕</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal: Crear Usuario -->
<div id="modal-create-user" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999;backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:1.5rem">
  <div class="card" style="width:100%;max-width:500px;box-shadow:0 16px 40px rgba(0,0,0,0.5)">
    <div class="card-header">
      <span class="card-title">Nuevo Usuario</span>
      <button onclick="closeModal('modal-create-user')" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem">✕</button>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="create_user">
        
        <!-- Avatar Field -->
        <div class="form-group" style="margin-bottom:1.15rem">
          <label class="form-label">Foto de perfil / Avatar</label>
          <div style="display:flex;align-items:center;gap:1rem">
            <div id="create-avatar-box" style="width:52px;height:52px;border-radius:50%;background:var(--surface2);border:2px dashed var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0">
              <span style="font-size:1.4rem">👤</span>
            </div>
            <div style="flex:1">
              <input type="file" name="avatar_file" accept="image/*" class="form-control" onchange="previewUserAvatar(this, 'create-avatar-box')">
              <small style="display:block;color:var(--muted);font-size:0.78rem;margin-top:0.25rem">JPG, PNG, WebP o GIF (máx. 8 MB)</small>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Nombre de usuario</label>
          <input type="text" name="username" placeholder="juan_perez" required pattern="^[a-zA-Z0-9_\-\.]{3,30}$">
        </div>
        <div class="form-group">
          <label class="form-label">Nombre completo / Mostrado</label>
          <input type="text" name="name" placeholder="Juan Pérez">
        </div>
        <div class="form-group">
          <label class="form-label">Correo electrónico</label>
          <input type="email" name="email" placeholder="juan@ejemplo.com">
        </div>
        <div class="form-group">
          <label class="form-label">Rol y Permisos</label>
          <select name="role">
            <option value="author">Autor (crear y redactar sus propios artículos)</option>
            <option value="editor">Editor (publicar y editar cualquier artículo/página)</option>
            <option value="admin">Administrador (acceso total a configuración y usuarios)</option>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Contraseña</label>
            <input type="password" name="password" required minlength="8">
          </div>
          <div class="form-group">
            <label class="form-label">Confirmar contraseña</label>
            <input type="password" name="confirm_password" required minlength="8">
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.25rem">
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create-user')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Crear Usuario</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Editar Usuario -->
<div id="modal-edit-user" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.7);z-index:999;backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:1.5rem">
  <div class="card" style="width:100%;max-width:500px;box-shadow:0 16px 40px rgba(0,0,0,0.5)">
    <div class="card-header">
      <span class="card-title">Editar Usuario <span id="edit-user-title-name" style="color:var(--accent)"></span></span>
      <button onclick="closeModal('modal-edit-user')" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.2rem">✕</button>
    </div>
    <div class="card-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="username" id="edit-username">

        <!-- Avatar Field -->
        <div class="form-group" style="margin-bottom:1.15rem">
          <label class="form-label">Foto de perfil / Avatar</label>
          <div style="display:flex;align-items:center;gap:1.1rem">
            <div id="edit-avatar-box" style="width:58px;height:58px;border-radius:50%;background:var(--surface2);border:2px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;box-shadow:0 2px 8px rgba(0,0,0,0.1)">
              <img id="edit-avatar-img" src="" alt="" style="width:100%;height:100%;object-fit:cover;display:none">
              <span id="edit-avatar-placeholder" style="font-size:1.5rem;font-weight:700;color:var(--accent)">A</span>
            </div>
            <div style="flex:1">
              <input type="file" name="avatar_file" accept="image/*" class="form-control" onchange="previewUserAvatar(this, 'edit-avatar-box')">
              <label style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.84rem;color:var(--muted);margin-top:0.4rem;cursor:pointer">
                <input type="checkbox" name="remove_avatar" value="1" id="edit-remove-avatar">
                Eliminar avatar actual
              </label>
            </div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Nombre completo / Mostrado</label>
          <input type="text" name="name" id="edit-name">
        </div>
        <div class="form-group">
          <label class="form-label">Correo electrónico</label>
          <input type="email" name="email" id="edit-email">
        </div>
        <div class="form-group">
          <label class="form-label">Rol y Permisos</label>
          <select name="role" id="edit-role">
            <option value="author">Autor</option>
            <option value="editor">Editor</option>
            <option value="admin">Administrador</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Estado de la cuenta</label>
          <select name="status" id="edit-status">
            <option value="active">Activa</option>
            <option value="suspended">Suspendida</option>
          </select>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Nueva contraseña (opcional)</label>
            <input type="password" name="password" placeholder="Dejar en blanco para no cambiar">
          </div>
          <div class="form-group">
            <label class="form-label">Confirmar nueva contraseña</label>
            <input type="password" name="confirm_password">
          </div>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:0.75rem;margin-top:1.25rem">
          <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit-user')">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar Cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openCreateUserModal() {
  const m = document.getElementById('modal-create-user');
  m.style.display = 'flex';
}
function openEditUserModal(user) {
  document.getElementById('edit-username').value = user.username;
  document.getElementById('edit-user-title-name').textContent = '@' + user.username;
  document.getElementById('edit-name').value = user.name || '';
  document.getElementById('edit-email').value = user.email || '';
  document.getElementById('edit-role').value = user.role || 'author';
  document.getElementById('edit-status').value = user.status || 'active';

  const removeChk = document.getElementById('edit-remove-avatar');
  if (removeChk) removeChk.checked = false;

  const box = document.getElementById('edit-avatar-box');
  if (user.avatar) {
    const avatarUrl = user.avatar.startsWith('http') ? user.avatar : ('<?= rtrim(base_url(), "/") ?>/' + user.avatar.replace(/^\//, ''));
    box.innerHTML = `<img src="${avatarUrl}" style="width:100%;height:100%;object-fit:cover">`;
  } else {
    const initial = (user.name || user.username || 'U').substring(0, 1).toUpperCase();
    box.innerHTML = `<span style="font-size:1.5rem;font-weight:700;color:var(--accent)">${initial}</span>`;
  }

  const m = document.getElementById('modal-edit-user');
  m.style.display = 'flex';
}
function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}
function previewUserAvatar(input, targetId) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      const box = document.getElementById(targetId);
      box.innerHTML = `<img src="${e.target.result}" style="width:100%;height:100%;object-fit:cover">`;
    };
    reader.readAsDataURL(input.files[0]);
  }
}
</script>
</body></html>
