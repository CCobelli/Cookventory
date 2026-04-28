<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';
require_once '../private/recipe-form-helpers.php';
require_once '../private/unit-helpers.php';

function setPantryFlash(string $message, string $type = 'success'): void {
    $_SESSION['pantry_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumePantryFlash(): ?array {
    if (!isset($_SESSION['pantry_flash']) || !is_array($_SESSION['pantry_flash'])) {
        return null;
    }

    $flash = $_SESSION['pantry_flash'];
    unset($_SESSION['pantry_flash']);
    return $flash;
}

function redirectToPantry(): void {
    header('Location: pantry.php');
    exit();
}

function updateCompatiblePantryGroup(PDO $pdo, int $userId, int $ingredientId, string $unitType, string $baseUnitName, float $newBaseQty): void {
    $baseUnit = getBaseUnitByName($pdo, $baseUnitName);
    if (!$baseUnit) {
        throw new RuntimeException('Base unit not found.');
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT p.id_pan
            FROM pantry_item_pan p
            INNER JOIN unit_uni u
                ON u.id_uni = p.id_uni_pan
            WHERE p.id_usr_pan = ?
              AND p.id_ing_pan = ?
              AND u.unit_type_uni = ?
              AND u.base_unit_uni = ?
            ORDER BY p.id_pan ASC
            FOR UPDATE
        ");
        $stmt->execute([$userId, $ingredientId, $unitType, $baseUnitName]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            throw new RuntimeException('Pantry item not found.');
        }

        $keepId = (int)$rows[0]['id_pan'];

        $stmt = $pdo->prepare("
            UPDATE pantry_item_pan
            SET quantity_pan = ?, id_uni_pan = ?
            WHERE id_pan = ? AND id_usr_pan = ?
        ");
        $stmt->execute([$newBaseQty, (int)$baseUnit['id_uni'], $keepId, $userId]);

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

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function updateExactPantryGroup(PDO $pdo, int $userId, int $ingredientId, int $unitId, float $newQty): void {
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT id_pan
            FROM pantry_item_pan
            WHERE id_usr_pan = ?
              AND id_ing_pan = ?
              AND id_uni_pan = ?
            ORDER BY id_pan ASC
            FOR UPDATE
        ");
        $stmt->execute([$userId, $ingredientId, $unitId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$rows) {
            throw new RuntimeException('Pantry item not found.');
        }

        $keepId = (int)$rows[0]['id_pan'];

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

                $stmt = $pdo->prepare("
                    DELETE FROM pantry_item_pan
                    WHERE id_usr_pan = ?
                      AND id_pan IN ($placeholders)
                ");
                $stmt->execute($params);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function deleteCompatiblePantryGroup(PDO $pdo, int $userId, int $ingredientId, string $unitType, string $baseUnitName): void {
    $stmt = $pdo->prepare("
        DELETE p
        FROM pantry_item_pan p
        INNER JOIN unit_uni u
            ON u.id_uni = p.id_uni_pan
        WHERE p.id_usr_pan = ?
          AND p.id_ing_pan = ?
          AND u.unit_type_uni = ?
          AND u.base_unit_uni = ?
    ");
    $stmt->execute([$userId, $ingredientId, $unitType, $baseUnitName]);
}

function deleteExactPantryGroup(PDO $pdo, int $userId, int $ingredientId, int $unitId): void {
    $stmt = $pdo->prepare("
        DELETE FROM pantry_item_pan
        WHERE id_usr_pan = ?
          AND id_ing_pan = ?
          AND id_uni_pan = ?
    ");
    $stmt->execute([$userId, $ingredientId, $unitId]);
}

$userId = (int)$_SESSION['user_id'];
$errors = [];
$success = '';

$flash = consumePantryFlash();
if ($flash) {
    if (($flash['type'] ?? 'success') === 'error') {
        $errors[] = (string)($flash['message'] ?? '');
    } else {
        $success = (string)($flash['message'] ?? '');
    }
}

if (!isset($_SESSION['pantry_display_units']) || !is_array($_SESSION['pantry_display_units'])) {
    $_SESSION['pantry_display_units'] = [];
}

$formIngredientName = '';
$formIngredientId = '';
$formQuantity = '';
$formUnitId = '';

$stmt = $pdo->query("
    SELECT id_uni, name_uni, unit_type_uni
    FROM unit_uni
    ORDER BY
        CASE unit_type_uni
            WHEN 'volume' THEN 1
            WHEN 'weight' THEN 2
            WHEN 'count' THEN 3
            ELSE 4
        END,
        conversion_to_base_uni DESC,
        name_uni
");
$units = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Change display unit only */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_display_unit'])) {
    $displayIngIdRaw = trim((string)($_POST['display_ing_id'] ?? ''));
    $displayUnitType = trim((string)($_POST['display_unit_type'] ?? ''));
    $displayBaseUnit = trim((string)($_POST['display_base_unit'] ?? ''));
    $displayUnitIdRaw = trim((string)($_POST['display_unit_id'] ?? ''));

    if (
        $displayIngIdRaw !== '' && ctype_digit($displayIngIdRaw) &&
        $displayUnitType !== '' &&
        $displayBaseUnit !== '' &&
        $displayUnitIdRaw !== '' && ctype_digit($displayUnitIdRaw)
    ) {
        $chosenUnit = getUnitById($pdo, (int)$displayUnitIdRaw);

        if (
            $chosenUnit &&
            ($chosenUnit['unit_type_uni'] ?? '') === $displayUnitType &&
            mb_strtolower((string)($chosenUnit['base_unit_uni'] ?? ''), 'UTF-8') === mb_strtolower($displayBaseUnit, 'UTF-8')
        ) {
            $key = pantryDisplayKey((int)$displayIngIdRaw, $displayUnitType, $displayBaseUnit);
            $_SESSION['pantry_display_units'][$key] = (int)$displayUnitIdRaw;
            setPantryFlash('Display unit updated.');
        } else {
            setPantryFlash('Could not change display unit.', 'error');
        }
    } else {
        setPantryFlash('Could not change display unit.', 'error');
    }

    redirectToPantry();
}

/* Handle add item */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_pantry'])) {
    $formIngredientName = trim($_POST['ingredient_name'] ?? '');
    $formIngredientId   = trim((string)($_POST['ingredient_id'] ?? ''));
    $formQuantity       = trim((string)($_POST['quantity'] ?? ''));
    $formUnitId         = trim((string)($_POST['unit_id'] ?? ''));

    $ingredientName = $formIngredientName;
    $ingredientId   = ($formIngredientId !== '' && ctype_digit($formIngredientId)) ? (int)$formIngredientId : 0;
    $qtyRaw         = $formQuantity;
    $unitId         = ($formUnitId !== '' && ctype_digit($formUnitId)) ? (int)$formUnitId : 0;

    if ($ingredientName === '') $errors[] = 'Ingredient is required.';
    if ($qtyRaw === '' || !is_numeric($qtyRaw)) $errors[] = 'Quantity must be a number.';
    if ($qtyRaw !== '' && is_numeric($qtyRaw) && (float)$qtyRaw <= 0) $errors[] = 'Quantity must be greater than 0.';
    if ($unitId <= 0) $errors[] = 'Unit is required.';

    if (!$errors) {
        $finalIngId = $ingredientId > 0 ? $ingredientId : resolveIngredientId($pdo, $ingredientName);

        if ($finalIngId <= 0) {
            $errors[] = 'Could not resolve ingredient.';
        } else {
            try {
                $success = addOrMergePantryItem($pdo, $userId, $finalIngId, (float)$qtyRaw, $unitId);

                $addedUnit = getUnitById($pdo, $unitId);
                if ($addedUnit && !empty($addedUnit['unit_type_uni']) && !empty($addedUnit['base_unit_uni'])) {
                    $key = pantryDisplayKey($finalIngId, $addedUnit['unit_type_uni'], $addedUnit['base_unit_uni']);
                    $_SESSION['pantry_display_units'][$key] = $unitId;
                }

                setPantryFlash($success);
                redirectToPantry();
            } catch (Throwable $e) {
                $errors[] = 'Could not save pantry item.';
            }
        }
    }
}

/* Handle update quantity */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pan'])) {
    $editMode      = trim((string)($_POST['edit_mode'] ?? ''));
    $editIngIdRaw  = trim((string)($_POST['edit_ing_id'] ?? ''));
    $editUnitIdRaw = trim((string)($_POST['edit_unit_id'] ?? ''));
    $editUnitType  = trim((string)($_POST['edit_unit_type'] ?? ''));
    $editBaseUnit  = trim((string)($_POST['edit_base_unit'] ?? ''));
    $editQtyRaw    = trim((string)($_POST['edit_quantity'] ?? ''));
    $editDisplayUnitIdRaw = trim((string)($_POST['edit_display_unit_id'] ?? ''));

    if ($editIngIdRaw === '' || !ctype_digit($editIngIdRaw)) $errors[] = 'Invalid ingredient.';
    if ($editQtyRaw === '' || !is_numeric($editQtyRaw)) $errors[] = 'Updated quantity must be a number.';
    if ($editQtyRaw !== '' && is_numeric($editQtyRaw) && (float)$editQtyRaw <= 0) $errors[] = 'Updated quantity must be greater than 0.';

    if ($editMode === 'exact' && ($editUnitIdRaw === '' || !ctype_digit($editUnitIdRaw))) {
        $errors[] = 'Invalid unit.';
    }

    if ($editMode === 'compatible' && ($editUnitType === '' || $editBaseUnit === '')) {
        $errors[] = 'Invalid unit group.';
    }

    if (!$errors) {
        $editIngId = (int)$editIngIdRaw;
        $editQty = (float)$editQtyRaw;

        try {
            if ($editMode === 'compatible') {
                $displayUnitId = 0;
                $displayUnit = null;

                if ($editDisplayUnitIdRaw !== '' && ctype_digit($editDisplayUnitIdRaw)) {
                    $displayUnitId = (int)$editDisplayUnitIdRaw;
                    $displayUnit = getUnitById($pdo, $displayUnitId);
                }

                if (
                    !$displayUnit ||
                    ($displayUnit['unit_type_uni'] ?? '') !== $editUnitType ||
                    mb_strtolower((string)($displayUnit['base_unit_uni'] ?? ''), 'UTF-8') !== mb_strtolower($editBaseUnit, 'UTF-8') ||
                    (float)($displayUnit['conversion_to_base_uni'] ?? 0) <= 0
                ) {
                    $displayUnit = getBaseUnitByName($pdo, $editBaseUnit);
                }

                if (!$displayUnit || (float)($displayUnit['conversion_to_base_uni'] ?? 0) <= 0) {
                    throw new RuntimeException('Display unit not found.');
                }

                $newBaseQty = $editQty * (float)$displayUnit['conversion_to_base_uni'];
                updateCompatiblePantryGroup($pdo, $userId, $editIngId, $editUnitType, $editBaseUnit, $newBaseQty);

                $key = pantryDisplayKey($editIngId, $editUnitType, $editBaseUnit);
                $_SESSION['pantry_display_units'][$key] = (int)$displayUnit['id_uni'];
            } else {
                updateExactPantryGroup($pdo, $userId, $editIngId, (int)$editUnitIdRaw, $editQty);
            }

            $success = 'Pantry item updated.';
        } catch (Throwable $e) {
            $errors[] = 'Could not update pantry item.';
        }
    }

    if (!$errors) {
        setPantryFlash($success);
    } else {
        setPantryFlash($errors[0], 'error');
    }
    redirectToPantry();
}

/* Handle delete group */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_pan_group'])) {
    $deleteMode      = trim((string)($_POST['delete_mode'] ?? ''));
    $deleteIngIdRaw  = trim((string)($_POST['delete_ing_id'] ?? ''));
    $deleteUnitIdRaw = trim((string)($_POST['delete_unit_id'] ?? ''));
    $deleteUnitType  = trim((string)($_POST['delete_unit_type'] ?? ''));
    $deleteBaseUnit  = trim((string)($_POST['delete_base_unit'] ?? ''));

    if ($deleteIngIdRaw === '' || !ctype_digit($deleteIngIdRaw)) {
        $errors[] = 'Could not remove pantry item.';
    } else {
        try {
            if ($deleteMode === 'compatible' && $deleteUnitType !== '' && $deleteBaseUnit !== '') {
                deleteCompatiblePantryGroup($pdo, $userId, (int)$deleteIngIdRaw, $deleteUnitType, $deleteBaseUnit);

                $key = pantryDisplayKey((int)$deleteIngIdRaw, $deleteUnitType, $deleteBaseUnit);
                unset($_SESSION['pantry_display_units'][$key]);

                setPantryFlash('Pantry item removed.');
            } elseif ($deleteMode === 'exact' && $deleteUnitIdRaw !== '' && ctype_digit($deleteUnitIdRaw)) {
                deleteExactPantryGroup($pdo, $userId, (int)$deleteIngIdRaw, (int)$deleteUnitIdRaw);
                setPantryFlash('Pantry item removed.');
            } else {
                setPantryFlash('Could not remove pantry item.', 'error');
            }
        } catch (Throwable $e) {
            setPantryFlash('Could not remove pantry item.', 'error');
        }
    }

    redirectToPantry();
}

$stmt = $pdo->prepare("
    SELECT
        p.id_ing_pan,
        i.name_ing,

        CASE
            WHEN u.unit_type_uni IS NOT NULL
             AND u.base_unit_uni IS NOT NULL
             AND u.conversion_to_base_uni IS NOT NULL
            THEN 'compatible'
            ELSE 'exact'
        END AS row_mode,

        u.unit_type_uni,
        u.base_unit_uni,

        CASE
            WHEN u.unit_type_uni IS NOT NULL
             AND u.base_unit_uni IS NOT NULL
             AND u.conversion_to_base_uni IS NOT NULL
            THEN SUM(p.quantity_pan * u.conversion_to_base_uni)
            ELSE SUM(p.quantity_pan)
        END AS stored_total_qty,

        MIN(u.id_uni) AS fallback_unit_id,
        MIN(u.name_uni) AS fallback_unit_name
    FROM pantry_item_pan p
    INNER JOIN ingredient_ing i
        ON p.id_ing_pan = i.id_ing
    INNER JOIN unit_uni u
        ON p.id_uni_pan = u.id_uni
    WHERE p.id_usr_pan = ?
    GROUP BY
        p.id_ing_pan,
        i.name_ing,
        row_mode,
        u.unit_type_uni,
        u.base_unit_uni
    ORDER BY
        i.name_ing,
        row_mode,
        u.base_unit_uni,
        fallback_unit_name
");
$stmt->execute([$userId]);
$rawPantry = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pantry = [];

foreach ($rawPantry as $row) {
    $rowMode = (string)$row['row_mode'];

    if ($rowMode === 'compatible') {
        $ingredientId = (int)$row['id_ing_pan'];
        $unitType = (string)$row['unit_type_uni'];
        $baseUnitName = (string)$row['base_unit_uni'];
        $storedTotalQty = (float)$row['stored_total_qty'];

        $displayOptions = getCompatibleDisplayUnits($pdo, $unitType, $baseUnitName);

        $selectedUnit = null;
        $key = pantryDisplayKey($ingredientId, $unitType, $baseUnitName);

        if (isset($_SESSION['pantry_display_units'][$key])) {
            $prefUnitId = (int)$_SESSION['pantry_display_units'][$key];
            foreach ($displayOptions as $option) {
                if ((int)$option['id_uni'] === $prefUnitId) {
                    $selectedUnit = $option;
                    break;
                }
            }
        }

        if (!$selectedUnit) {
            foreach ($displayOptions as $option) {
                if (mb_strtolower((string)$option['name_uni'], 'UTF-8') === mb_strtolower($baseUnitName, 'UTF-8')) {
                    $selectedUnit = $option;
                    break;
                }
            }
        }

        if (!$selectedUnit && !empty($displayOptions)) {
            $selectedUnit = $displayOptions[0];
        }

        if (!$selectedUnit) {
            $selectedUnit = [
                'id_uni' => 0,
                'name_uni' => $baseUnitName,
                'conversion_to_base_uni' => 1
            ];
        }

        $selectedConv = (float)($selectedUnit['conversion_to_base_uni'] ?? 1);
        if ($selectedConv <= 0) $selectedConv = 1;

        $displayQty = $storedTotalQty / $selectedConv;

        $pantry[] = [
            'id_ing_pan' => $ingredientId,
            'name_ing' => $row['name_ing'],
            'row_mode' => 'compatible',
            'unit_type_uni' => $unitType,
            'base_unit_uni' => $baseUnitName,
            'stored_total_qty' => $storedTotalQty,
            'display_quantity' => $displayQty,
            'display_unit_id' => (int)$selectedUnit['id_uni'],
            'display_unit_name' => $selectedUnit['name_uni'],
            'display_options' => $displayOptions
        ];
    } else {
        $pantry[] = [
            'id_ing_pan' => (int)$row['id_ing_pan'],
            'name_ing' => $row['name_ing'],
            'row_mode' => 'exact',
            'unit_type_uni' => null,
            'base_unit_uni' => null,
            'stored_total_qty' => (float)$row['stored_total_qty'],
            'display_quantity' => (float)$row['stored_total_qty'],
            'display_unit_id' => (int)$row['fallback_unit_id'],
            'display_unit_name' => $row['fallback_unit_name'],
            'display_options' => []
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Pantry - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<main class="cv-page pantry-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">Your Pantry</h1>
    <p class="cv-page-subtitle">Add ingredients you already have so Cookventory can recommend recipes you can actually make.</p>
  </header>

  <?php if ($success): ?>
    <div class="cv-alert cv-alert--success"><p><?php echo h($success); ?></p></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="cv-alert cv-alert--error cv-stack-sm">
      <?php foreach ($errors as $e): ?>
        <p><?php echo h($e); ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="cv-card cv-panel cv-stack-md">
    <h2 class="cv-card-title">Add Item</h2>

    <form method="POST" action="" class="pantry-add-form">
      <div class="pantry-ingredient-wrap">
        <input
          type="text"
          name="ingredient_name"
          placeholder="Ingredient (e.g., Garlic)"
          required
          autocomplete="off"
          autocapitalize="words"
          spellcheck="true"
          oninput="pantryIngredientType(this)"
          onfocus="pantryIngredientType(this)"
          value="<?php echo h($formIngredientName); ?>"
        >
        <input type="hidden" name="ingredient_id" value="<?php echo h($formIngredientId); ?>">
        <div class="ing-suggest"></div>
      </div>

      <input type="number" step="0.001" min="0.001" name="quantity" placeholder="Qty" required value="<?php echo h($formQuantity); ?>">

      <select name="unit_id" required>
        <option value="">Unit</option>
        <?php
        $currentGroup = '';
        foreach ($units as $u):
            $groupLabel = $u['unit_type_uni'] ? ucfirst($u['unit_type_uni']) : 'Other';
            if ($groupLabel !== $currentGroup):
                if ($currentGroup !== '') echo '</optgroup>';
                $currentGroup = $groupLabel;
                echo '<optgroup label="' . h($groupLabel) . '">';
            endif;
        ?>
          <option value="<?php echo (int)$u['id_uni']; ?>" <?php echo ((string)$u['id_uni'] === (string)$formUnitId) ? 'selected' : ''; ?>><?php echo h($u['name_uni']); ?></option>
        <?php endforeach; if ($currentGroup !== '') echo '</optgroup>'; ?>
      </select>

      <button type="submit" name="add_pantry">Add</button>
    </form>

    <p class="pantry-unit-note cv-help-text">Compatible units are normalized behind the scenes, but you can choose how they are displayed in your pantry.</p>
  </section>

  <section class="pantry-list-section cv-stack-md">
    <h2 class="cv-card-title">Current Pantry</h2>

    <?php if (!$pantry): ?>
      <article class="cv-card cv-panel">
        <p class="cv-empty-text">No pantry items yet.</p>
      </article>
    <?php else: ?>
      <div class="cv-table-wrap">
        <table class="cv-table">
          <thead>
            <tr>
              <th>Ingredient</th>
              <th>Quantity</th>
              <th>Display Unit</th>
              <th>Change Display</th>
              <th>Update Quantity</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($pantry as $p): ?>
              <tr>
                <td data-label="Ingredient"><?php echo h($p['name_ing']); ?></td>
                <td data-label="Quantity"><?php echo h(formatQty($p['display_quantity'])); ?></td>
                <td data-label="Display Unit"><?php echo h($p['display_unit_name']); ?></td>
                <td data-label="Change Display">
                  <?php if ($p['row_mode'] === 'compatible' && !empty($p['display_options'])): ?>
                    <form method="POST" action="" class="cv-inline-form">
                      <input type="hidden" name="display_ing_id" value="<?php echo (int)$p['id_ing_pan']; ?>">
                      <input type="hidden" name="display_unit_type" value="<?php echo h($p['unit_type_uni']); ?>">
                      <input type="hidden" name="display_base_unit" value="<?php echo h($p['base_unit_uni']); ?>">
                      <select name="display_unit_id" required onchange="this.form.submit()">
                        <?php foreach ($p['display_options'] as $opt): ?>
                          <option value="<?php echo (int)$opt['id_uni']; ?>" <?php echo ((int)$opt['id_uni'] === (int)$p['display_unit_id']) ? 'selected' : ''; ?>><?php echo h($opt['name_uni']); ?></option>
                        <?php endforeach; ?>
                      </select>
                      <input type="hidden" name="change_display_unit" value="1">
                    </form>
                  <?php else: ?>
                    <span class="pantry-fixed-label">Fixed</span>
                  <?php endif; ?>
                </td>
                <td data-label="Update Quantity">
                  <form method="POST" action="" class="pantry-inline-form">
                    <input type="hidden" name="edit_mode" value="<?php echo h($p['row_mode']); ?>">
                    <input type="hidden" name="edit_ing_id" value="<?php echo (int)$p['id_ing_pan']; ?>">
                    <input type="hidden" name="edit_unit_id" value="<?php echo (int)$p['display_unit_id']; ?>">
                    <input type="hidden" name="edit_unit_type" value="<?php echo h($p['unit_type_uni'] ?? ''); ?>">
                    <input type="hidden" name="edit_base_unit" value="<?php echo h($p['base_unit_uni'] ?? ''); ?>">
                    <input type="hidden" name="edit_display_unit_id" value="<?php echo (int)$p['display_unit_id']; ?>">
                    <input class="pantry-qty-input" type="number" name="edit_quantity" step="0.001" min="0.001" value="<?php echo h(formatQty($p['display_quantity'])); ?>" required>
                    <span class="pantry-display-label"><?php echo h($p['display_unit_name']); ?></span>
                    <button type="submit" name="update_pan">Save</button>
                  </form>
                </td>
                <td data-label="Action">
                  <form method="POST" action="" class="cv-inline-form">
                    <input type="hidden" name="delete_mode" value="<?php echo h($p['row_mode']); ?>">
                    <input type="hidden" name="delete_ing_id" value="<?php echo (int)$p['id_ing_pan']; ?>">
                    <input type="hidden" name="delete_unit_id" value="<?php echo (int)$p['display_unit_id']; ?>">
                    <input type="hidden" name="delete_unit_type" value="<?php echo h($p['unit_type_uni'] ?? ''); ?>">
                    <input type="hidden" name="delete_base_unit" value="<?php echo h($p['base_unit_uni'] ?? ''); ?>">
                    <button type="submit" name="delete_pan_group" value="1">Delete</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</main>

<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>








