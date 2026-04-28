<?php

function ensureRecipeSaveTable(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS recipe_save_rsv (
        id_rsv INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_usr_rsv INT UNSIGNED NOT NULL,
        id_rcp_rsv INT UNSIGNED NOT NULL,
        created_at_rsv TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_recipe (id_usr_rsv, id_rcp_rsv),
        KEY idx_rsv_user (id_usr_rsv),
        KEY idx_rsv_recipe (id_rcp_rsv)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done = true;
}

function saveRecipeForUser(PDO $pdo, int $userId, int $recipeId): void {
    ensureRecipeSaveTable($pdo);
    $stmt = $pdo->prepare("INSERT IGNORE INTO recipe_save_rsv (id_usr_rsv, id_rcp_rsv) VALUES (?, ?)");
    $stmt->execute([$userId, $recipeId]);
}

function unsaveRecipeForUser(PDO $pdo, int $userId, int $recipeId): void {
    ensureRecipeSaveTable($pdo);
    $stmt = $pdo->prepare("DELETE FROM recipe_save_rsv WHERE id_usr_rsv = ? AND id_rcp_rsv = ?");
    $stmt->execute([$userId, $recipeId]);
}

function getSavedRecipeIdsForUser(PDO $pdo, int $userId): array {
    ensureRecipeSaveTable($pdo);
    $stmt = $pdo->prepare("SELECT id_rcp_rsv FROM recipe_save_rsv WHERE id_usr_rsv = ?");
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}
