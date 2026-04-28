<?php

function normalizeRoleName(string $roleName): string {
    $role = trim(strtolower($roleName));
    if ($role === 'member' || $role === '') {
        return 'user';
    }
    if ($role === 'super admin') {
        return 'super_admin';
    }
    return $role;
}

function ensureRoleLevels(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS role_rol (
        id_rol INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name_rol VARCHAR(50) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_role_usrrol (
        id_usrrol INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_usr_usrrol INT(11) NOT NULL,
        id_rol_usrrol INT(11) NOT NULL,
        UNIQUE KEY uq_user_role (id_usr_usrrol, id_rol_usrrol),
        KEY fk_usrrol_rol (id_rol_usrrol)
    ) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci");

    $requiredRoles = ['user', 'admin', 'super_admin'];
    foreach ($requiredRoles as $roleName) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO role_rol (name_rol) VALUES (?)");
        $stmt->execute([$roleName]);
    }

    $stmt = $pdo->prepare("SELECT id_rol FROM role_rol WHERE name_rol = 'user' LIMIT 1");
    $stmt->execute();
    $userRoleId = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id_rol FROM role_rol WHERE name_rol = 'member' LIMIT 1");
    $stmt->execute();
    $memberRoleId = (int)$stmt->fetchColumn();

    if ($memberRoleId > 0 && $userRoleId > 0) {
        $stmt = $pdo->prepare("UPDATE user_role_usrrol SET id_rol_usrrol = ? WHERE id_rol_usrrol = ?");
        $stmt->execute([$userRoleId, $memberRoleId]);

        $stmt = $pdo->prepare("DELETE FROM role_rol WHERE id_rol = ?");
        $stmt->execute([$memberRoleId]);
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM role_rol WHERE name_rol = 'user'");
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO role_rol (name_rol) VALUES ('user')");
        $userRoleId = (int)$pdo->lastInsertId();
    }

    $stmt = $pdo->query("SELECT id_usr FROM user_usr WHERE is_active_usr = 1");
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($userIds as $userId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_role_usrrol WHERE id_usr_usrrol = ?");
        $stmt->execute([(int)$userId]);
        if ((int)$stmt->fetchColumn() === 0) {
            $stmtInsert = $pdo->prepare("INSERT INTO user_role_usrrol (id_usr_usrrol, id_rol_usrrol) VALUES (?, ?)");
            $stmtInsert->execute([(int)$userId, $userRoleId]);
        }
    }

    $done = true;
}

function getRoleIdByName(PDO $pdo, string $roleName): int {
    ensureRoleLevels($pdo);
    $normalized = normalizeRoleName($roleName);
    $stmt = $pdo->prepare("SELECT id_rol FROM role_rol WHERE name_rol = ? LIMIT 1");
    $stmt->execute([$normalized]);
    return (int)$stmt->fetchColumn();
}

function getUserPrimaryRole(PDO $pdo, int $userId): string {
    ensureRoleLevels($pdo);
    $stmt = $pdo->prepare("SELECT r.name_rol
        FROM user_role_usrrol ur
        INNER JOIN role_rol r ON r.id_rol = ur.id_rol_usrrol
        WHERE ur.id_usr_usrrol = ?
        ORDER BY FIELD(r.name_rol, 'super_admin', 'admin', 'user'), r.id_rol ASC
        LIMIT 1");
    $stmt->execute([$userId]);
    $role = $stmt->fetchColumn();
    return normalizeRoleName($role ? (string)$role : 'user');
}

function setUserPrimaryRole(PDO $pdo, int $userId, string $roleName): void {
    ensureRoleLevels($pdo);
    $roleId = getRoleIdByName($pdo, $roleName);
    if ($roleId <= 0) {
        throw new RuntimeException('Role not found.');
    }

    $stmt = $pdo->prepare("DELETE FROM user_role_usrrol WHERE id_usr_usrrol = ?");
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare("INSERT INTO user_role_usrrol (id_usr_usrrol, id_rol_usrrol) VALUES (?, ?)");
    $stmt->execute([$userId, $roleId]);
}

function currentUserRole(): string {
    return normalizeRoleName((string)($_SESSION['role'] ?? 'user'));
}

function isAdminRole(string $role): bool {
    $normalized = normalizeRoleName($role);
    return $normalized === 'admin' || $normalized === 'super_admin';
}

function isAdminUser(): bool {
    return isAdminRole(currentUserRole());
}

function isSuperAdminUser(): bool {
    return currentUserRole() === 'super_admin';
}
?>
