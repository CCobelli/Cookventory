<?php

function ensureShoppingListTable(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS shopping_list_item_shli (
        id_shli INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_usr_shli INT UNSIGNED NOT NULL,
        id_ing_shli INT UNSIGNED NOT NULL,
        quantity_shli DECIMAL(12,4) NOT NULL,
        id_uni_shli INT UNSIGNED NOT NULL,
        id_display_uni_shli INT UNSIGNED NULL DEFAULT NULL,
        created_at_shli TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at_shli TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_shli_user (id_usr_shli),
        KEY idx_shli_ing (id_ing_shli),
        KEY idx_shli_uni (id_uni_shli),
        KEY idx_shli_display_uni (id_display_uni_shli)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM shopping_list_item_shli LIKE 'id_display_uni_shli'");
        $hasDisplayUnitColumn = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        if (!$hasDisplayUnitColumn) {
            $pdo->exec("ALTER TABLE shopping_list_item_shli ADD COLUMN id_display_uni_shli INT UNSIGNED NULL DEFAULT NULL AFTER id_uni_shli");
        }
    } catch (Throwable $e) {
        // Ignore if schema changes are not available here.
    }

    $done = true;
}

function getUnitById(PDO $pdo, int $unitId): ?array {
    $stmt = $pdo->prepare("SELECT id_uni, name_uni, unit_type_uni, base_unit_uni, conversion_to_base_uni FROM unit_uni WHERE id_uni = ? LIMIT 1");
    $stmt->execute([$unitId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getBaseUnitByName(PDO $pdo, string $baseUnitName): ?array {
    $stmt = $pdo->prepare("SELECT id_uni, name_uni, unit_type_uni, base_unit_uni, conversion_to_base_uni FROM unit_uni WHERE LOWER(name_uni) = LOWER(?) LIMIT 1");
    $stmt->execute([$baseUnitName]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pantryDisplayKey(int $ingredientId, string $unitType, string $baseUnitName): string {
    return $ingredientId . '|' . $unitType . '|' . mb_strtolower($baseUnitName, 'UTF-8');
}

function getCompatibleUnits(PDO $pdo, string $unitType, string $baseUnitName): array {
    $stmt = $pdo->prepare("
        SELECT id_uni, name_uni, unit_type_uni, base_unit_uni, conversion_to_base_uni
        FROM unit_uni
        WHERE unit_type_uni = ?
          AND base_unit_uni = ?
          AND conversion_to_base_uni IS NOT NULL
          AND conversion_to_base_uni > 0
        ORDER BY conversion_to_base_uni DESC, name_uni ASC
    ");
    $stmt->execute([$unitType, $baseUnitName]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getCompatibleDisplayUnits(PDO $pdo, string $unitType, string $baseUnitName): array {
    return getCompatibleUnits($pdo, $unitType, $baseUnitName);
}

function convertFromStoredBaseToDisplayQty(float $storedQty, array $displayUnit): float {
    $conversion = isset($displayUnit['conversion_to_base_uni']) ? (float)$displayUnit['conversion_to_base_uni'] : 0.0;
    if ($conversion <= 0) {
        return $storedQty;
    }
    return $storedQty / $conversion;
}

function convertDisplayQtyToStoredBase(float $displayQty, array $displayUnit): float {
    $conversion = isset($displayUnit['conversion_to_base_uni']) ? (float)$displayUnit['conversion_to_base_uni'] : 0.0;
    if ($conversion <= 0) {
        return $displayQty;
    }
    return $displayQty * $conversion;
}

function addOrMergeShoppingListItem(PDO $pdo, int $userId, int $ingredientId, float $qty, int $unitId): void {
    $unit = getUnitById($pdo, $unitId);
    if (!$unit) {
        throw new RuntimeException('Unit not found.');
    }

    $unitType = $unit['unit_type_uni'] ?? null;
    $baseUnitName = $unit['base_unit_uni'] ?? null;
    $conversion = isset($unit['conversion_to_base_uni']) ? (float)$unit['conversion_to_base_uni'] : null;

    $pdo->beginTransaction();

    try {
        if ($unitType && $baseUnitName && $conversion !== null && $conversion > 0) {
            $baseUnit = getBaseUnitByName($pdo, $baseUnitName);
            if (!$baseUnit) {
                throw new RuntimeException('Base unit not found.');
            }

            $baseQtyToAdd = $qty * $conversion;

            $stmt = $pdo->prepare("
                SELECT s.id_shli, s.quantity_shli, s.id_uni_shli, u.conversion_to_base_uni
                FROM shopping_list_item_shli s
                INNER JOIN unit_uni u ON u.id_uni = s.id_uni_shli
                WHERE s.id_usr_shli = ? AND s.id_ing_shli = ? AND u.unit_type_uni = ? AND u.base_unit_uni = ?
                ORDER BY s.id_shli ASC FOR UPDATE
            ");
            $stmt->execute([$userId, $ingredientId, $unitType, $baseUnitName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalBaseQty = $baseQtyToAdd;
            foreach ($rows as $row) {
                $rowQty = (float)$row['quantity_shli'];
                $rowConv = isset($row['conversion_to_base_uni']) ? (float)$row['conversion_to_base_uni'] : 1.0;
                if ($rowConv <= 0) {
                    $rowConv = 1.0;
                }
                $totalBaseQty += ($rowQty * $rowConv);
            }

            if ($rows) {
                $keepId = (int)$rows[0]['id_shli'];
                $stmt = $pdo->prepare("
                    UPDATE shopping_list_item_shli
                    SET quantity_shli = ?, id_uni_shli = ?, id_display_uni_shli = ?
                    WHERE id_shli = ? AND id_usr_shli = ?
                ");
                $stmt->execute([$totalBaseQty, (int)$baseUnit['id_uni'], $unitId, $keepId, $userId]);

                if (count($rows) > 1) {
                    $deleteIds = [];
                    for ($i = 1; $i < count($rows); $i++) {
                        $deleteIds[] = (int)$rows[$i]['id_shli'];
                    }
                    if ($deleteIds) {
                        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                        $params = array_merge([$userId], $deleteIds);
                        $stmt = $pdo->prepare("DELETE FROM shopping_list_item_shli WHERE id_usr_shli = ? AND id_shli IN ($placeholders)");
                        $stmt->execute($params);
                    }
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO shopping_list_item_shli (id_usr_shli, id_ing_shli, quantity_shli, id_uni_shli, id_display_uni_shli)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $ingredientId, $totalBaseQty, (int)$baseUnit['id_uni'], $unitId]);
            }

            $pdo->commit();
            return;
        }

        $stmt = $pdo->prepare("
            SELECT id_shli, quantity_shli
            FROM shopping_list_item_shli
            WHERE id_usr_shli = ? AND id_ing_shli = ? AND id_uni_shli = ?
            ORDER BY id_shli ASC FOR UPDATE
        ");
        $stmt->execute([$userId, $ingredientId, $unitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $keepId = (int)$rows[0]['id_shli'];
            $newQty = $qty;
            foreach ($rows as $row) {
                $newQty += (float)$row['quantity_shli'];
            }

            $stmt = $pdo->prepare("
                UPDATE shopping_list_item_shli
                SET quantity_shli = ?, id_display_uni_shli = ?
                WHERE id_shli = ? AND id_usr_shli = ?
            ");
            $stmt->execute([$newQty, $unitId, $keepId, $userId]);

            if (count($rows) > 1) {
                $deleteIds = [];
                for ($i = 1; $i < count($rows); $i++) {
                    $deleteIds[] = (int)$rows[$i]['id_shli'];
                }
                if ($deleteIds) {
                    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                    $params = array_merge([$userId], $deleteIds);
                    $stmt = $pdo->prepare("DELETE FROM shopping_list_item_shli WHERE id_usr_shli = ? AND id_shli IN ($placeholders)");
                    $stmt->execute($params);
                }
            }
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO shopping_list_item_shli (id_usr_shli, id_ing_shli, quantity_shli, id_uni_shli, id_display_uni_shli)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $ingredientId, $qty, $unitId, $unitId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function addOrMergePantryItem(PDO $pdo, int $userId, int $ingredientId, float $qty, int $unitId): string {
    $unit = getUnitById($pdo, $unitId);
    if (!$unit) {
        throw new RuntimeException('Unit not found.');
    }

    $unitType = $unit['unit_type_uni'] ?? null;
    $baseUnitName = $unit['base_unit_uni'] ?? null;
    $conversion = isset($unit['conversion_to_base_uni']) ? (float)$unit['conversion_to_base_uni'] : null;

    $pdo->beginTransaction();

    try {
        if ($unitType && $baseUnitName && $conversion !== null && $conversion > 0) {
            $baseUnit = getBaseUnitByName($pdo, $baseUnitName);
            if (!$baseUnit) {
                throw new RuntimeException('Base unit not found.');
            }

            $baseQtyToAdd = $qty * $conversion;

            $stmt = $pdo->prepare("
                SELECT
                    p.id_pan,
                    p.quantity_pan,
                    p.id_uni_pan,
                    u.conversion_to_base_uni
                FROM pantry_item_pan p
                INNER JOIN unit_uni u ON u.id_uni = p.id_uni_pan
                WHERE p.id_usr_pan = ?
                  AND p.id_ing_pan = ?
                  AND u.unit_type_uni = ?
                  AND u.base_unit_uni = ?
                ORDER BY p.id_pan ASC
                FOR UPDATE
            ");
            $stmt->execute([$userId, $ingredientId, $unitType, $baseUnitName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalBaseQty = $baseQtyToAdd;
            foreach ($rows as $row) {
                $rowQty = (float)$row['quantity_pan'];
                $rowConv = isset($row['conversion_to_base_uni']) ? (float)$row['conversion_to_base_uni'] : 1.0;
                if ($rowConv <= 0) {
                    $rowConv = 1.0;
                }
                $totalBaseQty += ($rowQty * $rowConv);
            }

            if ($rows) {
                $keepId = (int)$rows[0]['id_pan'];

                $stmt = $pdo->prepare("
                    UPDATE pantry_item_pan
                    SET quantity_pan = ?, id_uni_pan = ?
                    WHERE id_pan = ? AND id_usr_pan = ?
                ");
                $stmt->execute([$totalBaseQty, (int)$baseUnit['id_uni'], $keepId, $userId]);

                if (count($rows) > 1) {
                    $deleteIds = [];
                    for ($i = 1; $i < count($rows); $i++) {
                        $deleteIds[] = (int)$rows[$i]['id_pan'];
                    }

                    if ($deleteIds) {
                        $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                        $params = array_merge([$userId], $deleteIds);

                        $stmt = $pdo->prepare("
                            DELETE FROM pantry_item_pan
                            WHERE id_usr_pan = ?
                              AND id_pan IN ($placeholders)
                        ");
                        $stmt->execute($params);
                    }
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO pantry_item_pan (id_usr_pan, id_ing_pan, quantity_pan, id_uni_pan)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $ingredientId, $totalBaseQty, (int)$baseUnit['id_uni']]);
            }

            $pdo->commit();
            return 'Pantry item added and normalized.';
        }

        $stmt = $pdo->prepare("
            SELECT id_pan, quantity_pan
            FROM pantry_item_pan
            WHERE id_usr_pan = ? AND id_ing_pan = ? AND id_uni_pan = ?
            ORDER BY id_pan ASC FOR UPDATE
        ");
        $stmt->execute([$userId, $ingredientId, $unitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($rows) {
            $keepId = (int)$rows[0]['id_pan'];
            $newQty = $qty;
            foreach ($rows as $row) {
                $newQty += (float)$row['quantity_pan'];
            }

            $stmt = $pdo->prepare("
                UPDATE pantry_item_pan
                SET quantity_pan = ?
                WHERE id_pan = ? AND id_usr_pan = ?
            ");
            $stmt->execute([$newQty, $keepId, $userId]);

            if (count($rows) > 1) {
                $deleteIds = [];
                for ($i = 1; $i < count($rows); $i++) {
                    $deleteIds[] = (int)$rows[$i]['id_pan'];
                }
                if ($deleteIds) {
                    $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
                    $params = array_merge([$userId], $deleteIds);
                    $stmt = $pdo->prepare("DELETE FROM pantry_item_pan WHERE id_usr_pan = ? AND id_pan IN ($placeholders)");
                    $stmt->execute($params);
                }
            }
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO pantry_item_pan (id_usr_pan, id_ing_pan, quantity_pan, id_uni_pan)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $ingredientId, $qty, $unitId]);
        }

        $pdo->commit();
        return 'Pantry item added.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
