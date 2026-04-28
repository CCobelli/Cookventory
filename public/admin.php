<?php
require_once __DIR__ . '/includes/admin-check.php';
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';
require_once '../private/role-helpers.php';

function setAdminFlash(string $message, string $type = 'success'): void {
    $_SESSION['admin_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeAdminFlash(): ?array {
    if (!isset($_SESSION['admin_flash']) || !is_array($_SESSION['admin_flash'])) {
        return null;
    }

    $flash = $_SESSION['admin_flash'];
    unset($_SESSION['admin_flash']);
    return $flash;
}

function redirectToAdmin(): void {
    header('Location: admin.php');
    exit();
}

$currentUserId = (int)$_SESSION['user_id'];
$isSuperAdmin = isSuperAdminUser();
$errors = [];
$success = '';

$flash = consumeAdminFlash();
if ($flash) {
    if (($flash['type'] ?? 'success') === 'error') {
        $errors[] = (string)($flash['message'] ?? '');
    } else {
        $success = (string)($flash['message'] ?? '');
    }
}

if (isset($_GET['recipe_removed']) && $_GET['recipe_removed'] === '1') {
    $success = 'Recipe removed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['deactivate_user'])) {
        $targetUserId = isset($_POST['target_user_id']) && ctype_digit((string)$_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
        if ($targetUserId <= 0) {
            $errors[] = 'Invalid user selected.';
        } elseif ($targetUserId === $currentUserId) {
            $errors[] = 'You cannot remove your own account.';
        } else {
            $targetRole = getUserPrimaryRole($pdo, $targetUserId);
            if (!$isSuperAdmin && $targetRole !== 'user') {
                $errors[] = 'Only a super admin can manage admin accounts.';
            } else {
                $stmt = $pdo->prepare('UPDATE user_usr SET is_active_usr = 0 WHERE id_usr = ?');
                $stmt->execute([$targetUserId]);
                setAdminFlash('Account removed.');
            }
        }
    }

    if (isset($_POST['remove_recipe'])) {
        $targetRecipeId = isset($_POST['target_recipe_id']) && ctype_digit((string)$_POST['target_recipe_id']) ? (int)$_POST['target_recipe_id'] : 0;
        if ($targetRecipeId <= 0) {
            $errors[] = 'Invalid recipe selected.';
        } else {
            $stmt = $pdo->prepare('SELECT id_usr_rcp FROM recipe_rcp WHERE id_rcp = ? LIMIT 1');
            $stmt->execute([$targetRecipeId]);
            $ownerId = (int)$stmt->fetchColumn();

            if ($ownerId <= 0) {
                $errors[] = 'Recipe not found.';
            } elseif ($ownerId === $currentUserId) {
                $errors[] = 'Use your normal recipe controls for your own recipes.';
            } else {
                $ownerRole = getUserPrimaryRole($pdo, $ownerId);
                if (!$isSuperAdmin && $ownerRole !== 'user') {
                    $errors[] = 'Only a super admin can moderate admin-owned recipes.';
                } else {
                    $stmt = $pdo->prepare('UPDATE recipe_rcp SET is_active_rcp = 0 WHERE id_rcp = ?');
                    $stmt->execute([$targetRecipeId]);
                    setAdminFlash('Recipe removed.');
                }
            }
        }
    }

    if ($isSuperAdmin && isset($_POST['promote_admin'])) {
        $targetUserId = isset($_POST['target_user_id']) && ctype_digit((string)$_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
        if ($targetUserId <= 0 || $targetUserId === $currentUserId) {
            $errors[] = 'Invalid admin promotion request.';
        } else {
            setUserPrimaryRole($pdo, $targetUserId, 'admin');
            setAdminFlash('User promoted to admin.');
        }
    }

    if ($isSuperAdmin && isset($_POST['demote_admin'])) {
        $targetUserId = isset($_POST['target_user_id']) && ctype_digit((string)$_POST['target_user_id']) ? (int)$_POST['target_user_id'] : 0;
        if ($targetUserId <= 0 || $targetUserId === $currentUserId) {
            $errors[] = 'Invalid admin update request.';
        } else {
            setUserPrimaryRole($pdo, $targetUserId, 'user');
            setAdminFlash('Admin changed back to general user.');
        }
    }

    if ($errors) {
        setAdminFlash($errors[0], 'error');
    }
    redirectToAdmin();
}

$stmt = $pdo->query("SELECT u.id_usr, u.username_usr, u.email_usr, u.is_active_usr,
    COALESCE(r.name_rol, 'user') AS role_name
    FROM user_usr u
    LEFT JOIN user_role_usrrol ur ON ur.id_usr_usrrol = u.id_usr
    LEFT JOIN role_rol r ON r.id_rol = ur.id_rol_usrrol
    ORDER BY u.username_usr ASC");
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmt = $pdo->query("SELECT r.id_rcp, r.title_rcp, r.created_at_rcp, r.id_usr_rcp, u.username_usr,
    COALESCE(ro.name_rol, 'user') AS owner_role
    FROM recipe_rcp r
    INNER JOIN user_usr u ON u.id_usr = r.id_usr_rcp
    LEFT JOIN user_role_usrrol ur ON ur.id_usr_usrrol = u.id_usr
    LEFT JOIN role_rol ro ON ro.id_rol = ur.id_rol_usrrol
    WHERE r.is_active_rcp = 1
    ORDER BY r.created_at_rcp DESC, r.title_rcp ASC");
$allRecipes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Admin Panel - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main class="cv-page admin-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">Admin Panel</h1>
    <p class="cv-page-subtitle">Manage accounts, moderate recipes, and <?php echo $isSuperAdmin ? 'assign admin access.' : 'review site activity.'; ?></p>
  </header>

  <?php if ($success): ?>
    <div class="cv-alert cv-alert--success"><p><?php echo h($success); ?></p></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="cv-alert cv-alert--error cv-stack-sm">
      <?php foreach ($errors as $error): ?>
        <p><?php echo h($error); ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="cv-card cv-panel cv-stack-md">
    <h2 class="cv-card-title">User Management</h2>
    <div class="cv-table-wrap">
      <table class="cv-table">
        <thead>
          <tr>
            <th>Username</th>
            <th>Email</th>
            <th>Role</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allUsers as $user): ?>
            <?php
              $userRole = normalizeRoleName((string)$user['role_name']);
              $isSelf = (int)$user['id_usr'] === $currentUserId;
              $canManageUser = !$isSelf && ($isSuperAdmin || $userRole === 'user');
            ?>
            <tr>
              <td data-label="Username"><?php echo h($user['username_usr']); ?></td>
              <td data-label="Email"><?php echo h($user['email_usr']); ?></td>
              <td data-label="Role"><?php echo h($userRole); ?></td>
              <td data-label="Status"><?php echo (int)$user['is_active_usr'] === 1 ? 'Active' : 'Inactive'; ?></td>
              <td data-label="Actions">
                <div class="admin-action-group">
                  <?php if ($canManageUser && (int)$user['is_active_usr'] === 1): ?>
                    <form method="POST" action="" class="cv-inline-form">
                      <input type="hidden" name="target_user_id" value="<?php echo (int)$user['id_usr']; ?>">
                      <button type="submit" name="deactivate_user" value="1">Remove Account</button>
                    </form>
                  <?php endif; ?>

                  <?php if ($isSuperAdmin && !$isSelf && (int)$user['is_active_usr'] === 1): ?>
                    <?php if ($userRole === 'user'): ?>
                      <form method="POST" action="" class="cv-inline-form">
                        <input type="hidden" name="target_user_id" value="<?php echo (int)$user['id_usr']; ?>">
                        <button type="submit" name="promote_admin" value="1">Make Admin</button>
                      </form>
                    <?php elseif ($userRole === 'admin'): ?>
                      <form method="POST" action="" class="cv-inline-form">
                        <input type="hidden" name="target_user_id" value="<?php echo (int)$user['id_usr']; ?>">
                        <button type="submit" name="demote_admin" value="1">Remove Admin</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="cv-card cv-panel cv-stack-md">
    <h2 class="cv-card-title">Recipe Moderation</h2>
    <div class="cv-table-wrap">
      <table class="cv-table">
        <thead>
          <tr>
            <th>Recipe</th>
            <th>Owner</th>
            <th>Owner Role</th>
            <th>Created</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allRecipes as $recipe): ?>
            <?php
              $ownerRole = normalizeRoleName((string)$recipe['owner_role']);
              $isOwnRecipe = (int)$recipe['id_usr_rcp'] === $currentUserId;
              $canModerateRecipe = !$isOwnRecipe && ($isSuperAdmin || $ownerRole === 'user');
            ?>
            <tr>
              <td data-label="Recipe"><a href="recipe.php?id=<?php echo (int)$recipe['id_rcp']; ?>"><?php echo h($recipe['title_rcp']); ?></a></td>
              <td data-label="Owner"><?php echo h($recipe['username_usr']); ?></td>
              <td data-label="Owner Role"><?php echo h($ownerRole); ?></td>
              <td data-label="Created"><?php echo h($recipe['created_at_rcp']); ?></td>
              <td data-label="Action">
                <?php if ($canModerateRecipe): ?>
                  <form method="POST" action="" class="cv-inline-form">
                    <input type="hidden" name="target_recipe_id" value="<?php echo (int)$recipe['id_rcp']; ?>">
                    <button type="submit" name="remove_recipe" value="1">Remove Recipe</button>
                  </form>
                <?php else: ?>
                  <span class="cv-help-text">No action</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>
