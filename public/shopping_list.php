<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';
require_once '../private/unit-helpers.php';

$printMode = isset($_GET['print']) && $_GET['print'] === '1';

function setShoppingListFlash(string $message, string $type = 'success', array $extra = []): void {
    $_SESSION['shopping_list_flash'] = array_merge([
        'message' => $message,
        'type' => $type,
    ], $extra);
}

function consumeShoppingListFlash(): ?array {
    if (!isset($_SESSION['shopping_list_flash']) || !is_array($_SESSION['shopping_list_flash'])) {
        return null;
    }

    $flash = $_SESSION['shopping_list_flash'];
    unset($_SESSION['shopping_list_flash']);
    return $flash;
}

function redirectToShoppingList(): void {
    header('Location: shopping_list.php');
    exit();
}

function normalizeInternalReturnUrl(?string $url): ?string {
    if (!$url) {
        return null;
    }

    $parts = parse_url($url);
    if ($parts === false) {
        return null;
    }

    $path = $parts['path'] ?? '';
    if ($path === '') {
        return null;
    }

    $basename = basename($path);
    if ($basename === '' || strcasecmp($basename, 'shopping_list.php') === 0) {
        return null;
    }

    if (!preg_match('/^[A-Za-z0-9_.-]+\.php$/', $basename)) {
        return null;
    }

    $normalized = $basename;
    if (!empty($parts['query'])) {
        $normalized .= '?' . $parts['query'];
    }

    return $normalized;
}

function removeShoppingListItem(PDO $pdo, int $userId, int $shoppingId): void {
    $stmt = $pdo->prepare("
        DELETE FROM shopping_list_item_shli
        WHERE id_shli = ? AND id_usr_shli = ?
    ");
    $stmt->execute([$shoppingId, $userId]);
}

ensureShoppingListTable($pdo);

$userId = (int)$_SESSION['user_id'];
$errors = [];
$success = '';

if (!isset($_SESSION['pantry_display_units']) || !is_array($_SESSION['pantry_display_units'])) {
    $_SESSION['pantry_display_units'] = [];
}

$returnTo = normalizeInternalReturnUrl($_SERVER['HTTP_REFERER'] ?? null);
if ($returnTo !== null) {
    $_SESSION['shopping_list_return_to'] = $returnTo;
}

$backLink = $_SESSION['shopping_list_return_to'] ?? 'recipes.php';

$flash = consumeShoppingListFlash();
$flashMeta = $flash;
if ($flash) {
    if (($flash['type'] ?? 'success') === 'error') {
        $errors[] = (string)($flash['message'] ?? '');
    } else {
        $success = (string)($flash['message'] ?? '');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_missing_items'])) {
    $restorePayload = $_POST['restore_items'] ?? [];

    if (!is_array($restorePayload) || !$restorePayload) {
        setShoppingListFlash('No missing items were provided to restore.', 'error');
        redirectToShoppingList();
    }

    try {
        $pdo->beginTransaction();
        $restoredNames = [];

        foreach ($restorePayload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $ingredientId = isset($item['ingredient_id']) && ctype_digit((string)$item['ingredient_id']) ? (int)$item['ingredient_id'] : 0;
            $unitId = isset($item['unit_id']) && ctype_digit((string)$item['unit_id']) ? (int)$item['unit_id'] : 0;
            $quantity = isset($item['quantity']) && is_numeric((string)$item['quantity']) ? (float)$item['quantity'] : 0.0;
            $name = trim((string)($item['name'] ?? ''));

            if ($ingredientId <= 0 || $unitId <= 0 || $quantity <= 0) {
                continue;
            }

            addOrMergeShoppingListItem($pdo, $userId, $ingredientId, $quantity, $unitId);
            if ($name !== '') {
                $restoredNames[] = $name;
            }
        }

        $pdo->commit();

        if ($restoredNames) {
            setShoppingListFlash('Missing amounts were added back to your shopping list: ' . implode(', ', $restoredNames) . '.');
        } else {
            setShoppingListFlash('Missing amounts were added back to your shopping list.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setShoppingListFlash('Could not add the missing amounts back to your shopping list.', 'error');
    }

    redirectToShoppingList();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_display_unit'])) {
    $shoppingIdRaw = trim((string)($_POST['shopping_id'] ?? ''));
    $displayUnitRaw = trim((string)($_POST['display_unit_id'] ?? ''));

    if ($shoppingIdRaw !== '' && ctype_digit($shoppingIdRaw) && $displayUnitRaw !== '' && ctype_digit($displayUnitRaw)) {
        $shoppingId = (int)$shoppingIdRaw;
        $displayUnitId = (int)$displayUnitRaw;

        $stmt = $pdo->prepare("
            SELECT s.id_shli, s.id_uni_shli, u.unit_type_uni, u.base_unit_uni
            FROM shopping_list_item_shli s
            INNER JOIN unit_uni u ON u.id_uni = s.id_uni_shli
            WHERE s.id_shli = ? AND s.id_usr_shli = ?
            LIMIT 1
        ");
        $stmt->execute([$shoppingId, $userId]);
        $itemRow = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($itemRow) {
            $compatibleUnits = getCompatibleUnits(
                $pdo,
                (string)$itemRow['unit_type_uni'],
                (string)$itemRow['base_unit_uni']
            );

            $allowedIds = array_map(static function ($u) {
                return (int)$u['id_uni'];
            }, $compatibleUnits);

            if (in_array($displayUnitId, $allowedIds, true)) {
                $stmt = $pdo->prepare("
                    UPDATE shopping_list_item_shli
                    SET id_display_uni_shli = ?
                    WHERE id_shli = ? AND id_usr_shli = ?
                ");
                $stmt->execute([$displayUnitId, $shoppingId, $userId]);
            }
        }
    }

    redirectToShoppingList();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_id'])) {
    $removeIdRaw = trim((string)($_POST['remove_id'] ?? ''));

    if ($removeIdRaw !== '' && ctype_digit($removeIdRaw)) {
        $removeId = (int)$removeIdRaw;

        $stmt = $pdo->prepare("
            DELETE FROM shopping_list_item_shli
            WHERE id_shli = ? AND id_usr_shli = ?
        ");
        $stmt->execute([$removeId, $userId]);

        setShoppingListFlash('Shopping list item removed.');
    } else {
        setShoppingListFlash('Could not remove shopping list item.', 'error');
    }

    redirectToShoppingList();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_list'])) {
    $stmt = $pdo->prepare("
        DELETE FROM shopping_list_item_shli
        WHERE id_usr_shli = ?
    ");
    $stmt->execute([$userId]);

    setShoppingListFlash('Shopping list cleared.');
    redirectToShoppingList();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_remove_selected'])) {
    $selectedIds = $_POST['selected_items'] ?? [];

    if (!is_array($selectedIds) || !$selectedIds) {
        setShoppingListFlash('Select at least one shopping list item to remove.', 'error');
    } else {
        $validIds = [];
        foreach ($selectedIds as $idRaw) {
            $idRaw = trim((string)$idRaw);
            if ($idRaw !== '' && ctype_digit($idRaw)) {
                $validIds[] = (int)$idRaw;
            }
        }

        if (!$validIds) {
            setShoppingListFlash('Select at least one valid shopping list item.', 'error');
        } else {
            $placeholders = implode(',', array_fill(0, count($validIds), '?'));
            $params = array_merge([$userId], $validIds);

            $stmt = $pdo->prepare("
                DELETE FROM shopping_list_item_shli
                WHERE id_usr_shli = ?
                  AND id_shli IN ($placeholders)
            ");
            $stmt->execute($params);

            $removedCount = $stmt->rowCount();
            if ($removedCount > 0) {
                $message = $removedCount === 1
                    ? '1 shopping list item was removed.'
                    : $removedCount . ' shopping list items were removed.';
                setShoppingListFlash($message);
            } else {
                setShoppingListFlash('Selected shopping list items were not found.', 'error');
            }
        }
    }

    redirectToShoppingList();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_one_to_pantry'])) {
    $shoppingIdRaw = trim((string)($_POST['shopping_id'] ?? ''));
    $qtyMap = $_POST['qty_to_add'] ?? [];

    if ($shoppingIdRaw === '' || !ctype_digit($shoppingIdRaw)) {
        setShoppingListFlash('Invalid shopping list item.', 'error');
    } else {
        $shoppingId = (int)$shoppingIdRaw;

        $stmt = $pdo->prepare("
            SELECT
                s.id_shli,
                s.id_ing_shli,
                s.quantity_shli,
                s.id_uni_shli,
                s.id_display_uni_shli,
                u.unit_type_uni,
                u.base_unit_uni
            FROM shopping_list_item_shli s
            INNER JOIN unit_uni u ON u.id_uni = s.id_uni_shli
            WHERE s.id_shli = ? AND s.id_usr_shli = ?
            LIMIT 1
        ");
        $stmt->execute([$shoppingId, $userId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            setShoppingListFlash('Shopping list item not found.', 'error');
        } else {
            $storedUnitId = (int)$item['id_uni_shli'];
            $displayUnitId = !empty($item['id_display_uni_shli'])
                ? (int)$item['id_display_uni_shli']
                : $storedUnitId;

            $displayUnit = getUnitById($pdo, $displayUnitId);
            if (!$displayUnit) {
                $displayUnit = getUnitById($pdo, $storedUnitId);
            }

            $qtyToAddRaw = isset($qtyMap[$shoppingId]) ? trim((string)$qtyMap[$shoppingId]) : '';

            if ($qtyToAddRaw === '' || !is_numeric($qtyToAddRaw) || (float)$qtyToAddRaw <= 0) {
                setShoppingListFlash('Quantity to add must be greater than 0.', 'error');
            } else {
                $qtyToAddDisplay = (float)$qtyToAddRaw;
                $qtyToSubtractStored = convertDisplayQtyToStoredBase($qtyToAddDisplay, $displayUnit);

                try {
                    $pdo->beginTransaction();

                    addOrMergePantryItem(
                        $pdo,
                        $userId,
                        (int)$item['id_ing_shli'],
                        $qtyToAddDisplay,
                        (int)$displayUnit['id_uni']
                    );

                    if (!empty($displayUnit['unit_type_uni']) && !empty($displayUnit['base_unit_uni'])) {
                        $pantryKey = pantryDisplayKey(
                            (int)$item['id_ing_shli'],
                            (string)$displayUnit['unit_type_uni'],
                            (string)$displayUnit['base_unit_uni']
                        );
                        $_SESSION['pantry_display_units'][$pantryKey] = (int)$displayUnit['id_uni'];
                    }

                    removeShoppingListItem($pdo, $userId, $shoppingId);

                    $pdo->commit();
                    if ($qtyToAddDisplay + 0.00001 < $displayQty) {
                        $missingQty = max(0, $displayQty - $qtyToAddDisplay);
                        setShoppingListFlash(
                            'Item added to pantry. You may not have enough ' . $item['name_ing'] . ' to make your recipe yet.',
                            'error',
                            [
                                'restore_items' => [[
                                    'ingredient_id' => (int)$item['id_ing_shli'],
                                    'quantity' => $missingQty,
                                    'unit_id' => (int)$displayUnit['id_uni'],
                                    'name' => (string)$item['name_ing'],
                                ]],
                            ]
                        );
                    } else {
                        setShoppingListFlash('Item added to pantry.');
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    setShoppingListFlash('Could not add item to pantry.', 'error');
                }
            }
        }
    }

    redirectToShoppingList();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_add_to_pantry'])) {
    $selectedIds = $_POST['selected_items'] ?? [];
    $qtyMap = $_POST['qty_to_add'] ?? [];

    if (!is_array($selectedIds) || !$selectedIds) {
        setShoppingListFlash('Select at least one shopping list item to bulk add.', 'error');
    } else {
        $validIds = [];
        foreach ($selectedIds as $idRaw) {
            $idRaw = trim((string)$idRaw);
            if ($idRaw !== '' && ctype_digit($idRaw)) {
                $validIds[] = (int)$idRaw;
            }
        }

        if (!$validIds) {
            setShoppingListFlash('Select at least one valid shopping list item.', 'error');
        } else {
            $placeholders = implode(',', array_fill(0, count($validIds), '?'));
            $params = array_merge([$userId], $validIds);

            $stmt = $pdo->prepare("
                SELECT
                    s.id_shli,
                    s.id_ing_shli,
                    i.name_ing,
                    s.quantity_shli,
                    s.id_uni_shli,
                    s.id_display_uni_shli,
                    u.unit_type_uni,
                    u.base_unit_uni
                FROM shopping_list_item_shli s
                INNER JOIN ingredient_ing i ON i.id_ing = s.id_ing_shli
                INNER JOIN unit_uni u ON u.id_uni = s.id_uni_shli
                WHERE s.id_usr_shli = ?
                  AND s.id_shli IN ($placeholders)
                ORDER BY s.id_shli ASC
            ");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!$rows) {
                setShoppingListFlash('Selected shopping list items were not found.', 'error');
            } else {
                try {
                    $pdo->beginTransaction();
                    $addedCount = 0;
                    $partialItems = [];

                    foreach ($rows as $row) {
                        $shoppingId = (int)$row['id_shli'];
                        $storedUnitId = (int)$row['id_uni_shli'];
                        $displayUnitId = !empty($row['id_display_uni_shli'])
                            ? (int)$row['id_display_uni_shli']
                            : $storedUnitId;

                        $displayUnit = getUnitById($pdo, $displayUnitId);
                        if (!$displayUnit) {
                            $displayUnit = getUnitById($pdo, $storedUnitId);
                        }

                        $qtyToAddRaw = isset($qtyMap[$shoppingId]) ? trim((string)$qtyMap[$shoppingId]) : '';

                        if ($qtyToAddRaw === '' || !is_numeric($qtyToAddRaw)) {
                            throw new RuntimeException('One of the bulk quantities is invalid.');
                        }

                        $qtyToAddDisplay = (float)$qtyToAddRaw;
                        if ($qtyToAddDisplay <= 0) {
                            throw new RuntimeException('One of the bulk quantities must be greater than 0.');
                        }

                        $qtyToSubtractStored = convertDisplayQtyToStoredBase($qtyToAddDisplay, $displayUnit);

                        addOrMergePantryItem(
                            $pdo,
                            $userId,
                            (int)$row['id_ing_shli'],
                            $qtyToAddDisplay,
                            (int)$displayUnit['id_uni']
                        );

                        if (!empty($displayUnit['unit_type_uni']) && !empty($displayUnit['base_unit_uni'])) {
                            $pantryKey = pantryDisplayKey(
                                (int)$row['id_ing_shli'],
                                (string)$displayUnit['unit_type_uni'],
                                (string)$displayUnit['base_unit_uni']
                            );
                            $_SESSION['pantry_display_units'][$pantryKey] = (int)$displayUnit['id_uni'];
                        }

                        removeShoppingListItem($pdo, $userId, $shoppingId);

                        $originalDisplayQty = (float)convertFromStoredBaseToDisplayQty((float)$row['quantity_shli'], $displayUnit);
                        if ($qtyToAddDisplay + 0.00001 < $originalDisplayQty) {
                            $partialItems[] = [
                                'ingredient_id' => (int)$row['id_ing_shli'],
                                'quantity' => max(0, $originalDisplayQty - $qtyToAddDisplay),
                                'unit_id' => (int)$displayUnit['id_uni'],
                                'name' => (string)$row['name_ing'],
                            ];
                        }

                        $addedCount++;
                    }

                    $pdo->commit();
                    $message = $addedCount === 1
                        ? '1 shopping list item was added to pantry.'
                        : $addedCount . ' shopping list items were added to pantry.';
                    if ($partialItems) {
                        $partialNames = array_map(static function (array $item): string {
                            return (string)$item['name'];
                        }, $partialItems);
                        if ($partialNames) {
                            $message .= ' You may not have enough of these items to make your recipe yet: ' . implode(', ', $partialNames) . '.';
                        }
                        setShoppingListFlash($message, 'error', ['restore_items' => $partialItems]);
                    } else {
                        setShoppingListFlash($message);
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    setShoppingListFlash('Could not bulk add selected items to pantry.', 'error');
                }
            }
        }
    }

    redirectToShoppingList();
}

$stmt = $pdo->prepare("
    SELECT
        s.id_shli,
        s.id_ing_shli,
        i.name_ing,
        s.quantity_shli,
        s.id_uni_shli,
        s.id_display_uni_shli,
        u.name_uni,
        u.unit_type_uni,
        u.base_unit_uni,
        u.conversion_to_base_uni
    FROM shopping_list_item_shli s
    INNER JOIN ingredient_ing i
        ON i.id_ing = s.id_ing_shli
    INNER JOIN unit_uni u
        ON u.id_uni = s.id_uni_shli
    WHERE s.id_usr_shli = ?
    ORDER BY i.name_ing ASC, s.id_shli ASC
");
$stmt->execute([$userId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($items as &$item) {
    $shoppingId = (int)$item['id_shli'];
    $storedUnitId = (int)$item['id_uni_shli'];

    $compatibleUnits = getCompatibleUnits(
        $pdo,
        (string)$item['unit_type_uni'],
        (string)$item['base_unit_uni']
    );

    $selectedDisplayUnitId = !empty($item['id_display_uni_shli'])
        ? (int)$item['id_display_uni_shli']
        : $storedUnitId;

    $displayUnit = null;
    foreach ($compatibleUnits as $unit) {
        if ((int)$unit['id_uni'] === $selectedDisplayUnitId) {
            $displayUnit = $unit;
            break;
        }
    }

    if (!$displayUnit) {
        $displayUnit = getUnitById($pdo, $storedUnitId);
        $selectedDisplayUnitId = $storedUnitId;
    }

    $displayQty = convertFromStoredBaseToDisplayQty((float)$item['quantity_shli'], $displayUnit);

    $item['compatible_units'] = $compatibleUnits;
    $item['selected_display_unit_id'] = $selectedDisplayUnitId;
    $item['display_unit_name'] = $displayUnit['name_uni'];
    $item['display_qty'] = $displayQty;
}
unset($item);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Shopping List - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body class="<?php echo $printMode ? 'shopping-print-mode' : ''; ?>"<?php echo $printMode ? ' data-auto-print="1"' : ''; ?>>

<?php if (!$printMode): ?>
  <?php include 'includes/navbar.php'; ?>
<?php endif; ?>

<main class="cv-page shopping-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">Your Shopping List</h1>
    <p class="cv-page-subtitle">Add missing ingredients from recipes, then move them into your pantry one at a time or in bulk.</p>
    <?php if ($printMode): ?>
      <p class="shopping-print-note">Use your browser's destination options to Save as PDF or print this shopping list.</p>
    <?php else: ?>
      <p><a href="<?php echo h($backLink); ?>" class="recipe-back-link">&larr; Back</a></p>
    <?php endif; ?>
  </header>

  <?php if ($success): ?>
    <div class="cv-alert cv-alert--success"><p><?php echo h($success); ?></p></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="cv-alert cv-alert--error cv-stack-sm">
      <?php foreach ($errors as $e): ?>
        <p><?php echo h($e); ?></p>
      <?php endforeach; ?>
      <?php if (!empty($flashMeta['restore_items']) && is_array($flashMeta['restore_items'])): ?>
        <form method="POST" action="" class="cv-inline-form">
          <?php foreach ($flashMeta['restore_items'] as $index => $item): ?>
            <input type="hidden" name="restore_items[<?php echo $index; ?>][ingredient_id]" value="<?php echo (int)($item['ingredient_id'] ?? 0); ?>">
            <input type="hidden" name="restore_items[<?php echo $index; ?>][quantity]" value="<?php echo h((string)($item['quantity'] ?? '0')); ?>">
            <input type="hidden" name="restore_items[<?php echo $index; ?>][unit_id]" value="<?php echo (int)($item['unit_id'] ?? 0); ?>">
            <input type="hidden" name="restore_items[<?php echo $index; ?>][name]" value="<?php echo h((string)($item['name'] ?? '')); ?>">
          <?php endforeach; ?>
          <button type="submit" name="restore_missing_items" value="1">Add Missing Amounts Back to Shopping List</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$items): ?>
    <article class="cv-card cv-panel">
      <p class="recipe-card-copy">Your shopping list is empty.</p>
      <a href="recipes.php" class="cv-cta-link">Browse recipes &rarr;</a>
    </article>
  <?php else: ?>
    <section class="cv-card cv-panel shopping-toolbar">
      <?php if (!$printMode): ?>
        <form method="POST" action="" class="cv-inline-form">
          <button type="submit" name="clear_list" value="1" onclick="return confirm('Clear your whole shopping list?');">Clear Shopping List</button>
        </form>
      <?php endif; ?>

      <a href="shopping_list.php?print=1" class="cv-button shopping-print-button" target="_blank" rel="noopener">Print / Save PDF</a>
    </section>

    <form id="shopping-bulk-form" method="POST" action="" class="is-hidden">
      <input type="hidden" id="single-shopping-id" name="shopping_id" value="">
    </form>

    <div class="cv-table-wrap">
      <table class="cv-table<?php echo $printMode ? ' shopping-print-table' : ''; ?>">
        <thead>
          <tr>
            <?php if (!$printMode): ?>
              <th>
                <label class="shopping-select-all">
                  <input type="checkbox" id="shopping-select-all" aria-label="Select all shopping list items">
                  <span>Select All</span>
                </label>
              </th>
            <?php endif; ?>
            <th>Ingredient</th>
            <th>Shopping List Qty</th>
            <?php if (!$printMode): ?>
              <th>Display Unit</th>
              <th>Qty To Add To Pantry</th>
              <th>Single Add</th>
              <th>Remove</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <?php $shoppingId = (int)$item['id_shli']; $displayQty = (float)$item['display_qty']; $displayQtyFormatted = formatQty($displayQty); ?>
            <tr>
              <?php if (!$printMode): ?>
                <td data-label="Select">
                  <input form="shopping-bulk-form" type="checkbox" name="selected_items[]" value="<?php echo $shoppingId; ?>">
                </td>
              <?php endif; ?>
              <td data-label="Ingredient"><?php echo h($item['name_ing']); ?></td>
              <td data-label="Shopping List Qty"><?php echo h($displayQtyFormatted); ?> <?php echo h($item['display_unit_name']); ?></td>
              <?php if (!$printMode): ?>
                <td data-label="Display Unit">
                  <form method="POST" action="" class="cv-inline-form">
                    <input type="hidden" name="shopping_id" value="<?php echo $shoppingId; ?>">
                    <select name="display_unit_id" onchange="this.form.submit()">
                      <?php foreach ($item['compatible_units'] as $unit): ?>
                        <option value="<?php echo (int)$unit['id_uni']; ?>" <?php echo ((int)$unit['id_uni'] === (int)$item['selected_display_unit_id']) ? 'selected' : ''; ?>><?php echo h($unit['name_uni']); ?></option>
                      <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="change_display_unit" value="1">
                  </form>
                </td>
                <td data-label="Qty To Add To Pantry">
                  <input form="shopping-bulk-form" class="shopping-qty-input" type="number" name="qty_to_add[<?php echo $shoppingId; ?>]" step="0.001" min="0.001" value="<?php echo h($displayQtyFormatted); ?>">
                  <span class="pantry-display-label"><?php echo h($item['display_unit_name']); ?></span>
                </td>
                <td data-label="Single Add">
                  <button form="shopping-bulk-form" type="submit" name="add_one_to_pantry" value="1" onclick="document.getElementById('single-shopping-id').value='<?php echo $shoppingId; ?>';">Add This Item</button>
                </td>
                <td data-label="Remove">
                  <form method="POST" action="" class="cv-inline-form">
                    <button type="submit" name="remove_id" value="<?php echo $shoppingId; ?>" onclick="return confirm('Remove this shopping list item?');">Remove</button>
                  </form>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!$printMode): ?>
      <div class="shopping-bulk-actions">
        <button form="shopping-bulk-form" type="submit" name="bulk_add_to_pantry" value="1">Bulk Add Selected to Pantry</button>
        <button form="shopping-bulk-form" type="submit" name="bulk_remove_selected" value="1" onclick="return confirm('Remove all selected shopping list items?');">Remove Selected</button>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</main>

<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>














