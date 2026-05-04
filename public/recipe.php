<?php
session_start();
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';
require_once '../private/recipe-saves.php';
require_once '../private/role-helpers.php';
require_once '../private/unit-helpers.php';

ensureRoleLevels($pdo);

$recipePlaceholderImage = 'assets/images/noimg.png';

function setRecipeFlash(string $message, string $type = 'success', array $extra = []): void {
    $_SESSION['recipe_flash'] = array_merge([
        'message' => $message,
        'type' => $type,
    ], $extra);
}

function consumeRecipeFlash(): ?array {
    if (!isset($_SESSION['recipe_flash']) || !is_array($_SESSION['recipe_flash'])) {
        return null;
    }

    $flash = $_SESSION['recipe_flash'];
    unset($_SESSION['recipe_flash']);
    return $flash;
}

function buildRecipeRedirectUrl(int $recipeId, ?int $activeServings, array $extra = []): string {
    $params = array_merge(['id' => $recipeId], $extra);
    if ($activeServings) {
        $params['servings'] = (int)$activeServings;
    }

    return 'recipe.php?' . http_build_query($params);
}


function getRecipeIngredientsForPantryMath(PDO $pdo, int $recipeId): array {
    $stmt = $pdo->prepare("SELECT ri.id_ing_rcping, ri.quantity_rcping, ri.id_uni_rcping, i.name_ing, u.name_uni, u.unit_type_uni, u.base_unit_uni, u.conversion_to_base_uni
        FROM recipe_ingredient_rcping ri
        INNER JOIN ingredient_ing i ON i.id_ing = ri.id_ing_rcping
        INNER JOIN unit_uni u ON u.id_uni = ri.id_uni_rcping
        WHERE ri.id_rcp_rcping = ?
        ORDER BY i.name_ing ASC");
    $stmt->execute([$recipeId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function getUserPantryBaseTotals(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT p.id_ing_pan, u.unit_type_uni, u.base_unit_uni, SUM(p.quantity_pan * u.conversion_to_base_uni) AS pantry_base_qty
        FROM pantry_item_pan p
        INNER JOIN unit_uni u ON u.id_uni = p.id_uni_pan
        WHERE p.id_usr_pan = ?
        GROUP BY p.id_ing_pan, u.unit_type_uni, u.base_unit_uni");
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $map = [];
    foreach ($rows as $row) {
        $key = (int)$row['id_ing_pan'] . '|' . (string)$row['unit_type_uni'] . '|' . mb_strtolower((string)$row['base_unit_uni'], 'UTF-8');
        $map[$key] = (float)$row['pantry_base_qty'];
    }
    return $map;
}

function subtractUpToAvailableFromPantry(PDO $pdo, int $userId, int $ingredientId, string $unitType, string $baseUnitName, float $requestedBaseQty): float {
    if ($requestedBaseQty <= 0) return 0.0;

    $stmt = $pdo->prepare("SELECT p.id_pan, p.quantity_pan, p.id_uni_pan, u.conversion_to_base_uni
        FROM pantry_item_pan p
        INNER JOIN unit_uni u ON u.id_uni = p.id_uni_pan
        WHERE p.id_usr_pan = ? AND p.id_ing_pan = ? AND u.unit_type_uni = ? AND u.base_unit_uni = ?
        ORDER BY p.id_pan ASC FOR UPDATE");
    $stmt->execute([$userId, $ingredientId, $unitType, $baseUnitName]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remainingBaseQty = $requestedBaseQty;
    $deductedBaseQty = 0.0;

    foreach ($rows as $row) {
        if ($remainingBaseQty <= 0.00001) break;

        $rowId = (int)$row['id_pan'];
        $rowQty = (float)$row['quantity_pan'];
        $rowConv = isset($row['conversion_to_base_uni']) ? (float)$row['conversion_to_base_uni'] : 1.0;
        if ($rowConv <= 0) $rowConv = 1.0;

        $rowBaseQty = $rowQty * $rowConv;
        if ($rowBaseQty <= $remainingBaseQty + 0.00001) {
            $stmtDelete = $pdo->prepare("DELETE FROM pantry_item_pan WHERE id_pan = ? AND id_usr_pan = ?");
            $stmtDelete->execute([$rowId, $userId]);
            $remainingBaseQty -= $rowBaseQty;
            $deductedBaseQty += $rowBaseQty;
        } else {
            $newRowBaseQty = $rowBaseQty - $remainingBaseQty;
            $newRowQtyInStoredUnit = $newRowBaseQty / $rowConv;
            $stmtUpdate = $pdo->prepare("UPDATE pantry_item_pan SET quantity_pan = ? WHERE id_pan = ? AND id_usr_pan = ?");
            $stmtUpdate->execute([$newRowQtyInStoredUnit, $rowId, $userId]);
            $deductedBaseQty += $remainingBaseQty;
            $remainingBaseQty = 0.0;
        }
    }

    return $deductedBaseQty;
}

if (!isset($_GET['id']) || !ctype_digit($_GET['id'])) {
    http_response_code(400);
    die('Invalid recipe id.');
}

$recipe_id = (int)$_GET['id'];
$flash = '';
$flashType = 'success';
$hasServingsColumn = ensureRecipeServingsColumn($pdo);

$stmt = $pdo->prepare("SELECT r.* FROM recipe_rcp r WHERE r.id_rcp = ? AND r.is_active_rcp = 1");
$stmt->execute([$recipe_id]);
$recipe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$recipe) {
    http_response_code(404);
    die('Recipe not found.');
}

$baseServings = null;
if ($hasServingsColumn && isset($recipe['servings_rcp']) && $recipe['servings_rcp'] !== null) {
    $baseServings = max(1, (int)$recipe['servings_rcp']);
}

$requestedServingInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['servings_target'] ?? null)
    : ($_GET['servings'] ?? null);
$activeServings = resolveServingCount($requestedServingInput, $baseServings);
$servingScale = ($baseServings && $activeServings)
    ? ((float)$activeServings / (float)$baseServings)
    : 1.0;
$flashMeta = consumeRecipeFlash();
if ($flashMeta) {
    $flash = (string)($flashMeta['message'] ?? '');
    $flashType = (string)($flashMeta['type'] ?? 'success');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_delete_recipe'])) {
    if (!$canAdminDeleteRecipe) {
        $flash = 'You do not have permission to remove this recipe.';
        $flashType = 'error';
    } else {
        $stmt = $pdo->prepare('UPDATE recipe_rcp SET is_active_rcp = 0 WHERE id_rcp = ?');
        $stmt->execute([$recipe_id]);
        header('Location: admin.php?recipe_removed=1');
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rate_recipe'])) {
    if (!isset($_SESSION['user_id'])) {
        $flash = 'Please log in to rate recipes.';
        $flashType = 'error';
    } else {
        $ratingValueRaw = trim((string)($_POST['rating_value'] ?? ''));
        if (!ctype_digit($ratingValueRaw)) {
            $flash = 'Please choose a rating from 1 to 5 stars.';
            $flashType = 'error';
        } else {
            $ratingValue = (int)$ratingValueRaw;
            if ($ratingValue < 1 || $ratingValue > 5) {
                $flash = 'Please choose a rating from 1 to 5 stars.';
                $flashType = 'error';
            } else {
                $userId = (int)$_SESSION['user_id'];
                $stmt = $pdo->prepare("
                    INSERT INTO rating_rat (id_rcp_rat, id_usr_rat, rating_value_rat)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        rating_value_rat = VALUES(rating_value_rat),
                        created_at_rat = CURRENT_TIMESTAMP
                ");
                $stmt->execute([$recipe_id, $userId, $ratingValue]);

                $redirect = 'recipe.php?id=' . $recipe_id;
                if ($activeServings) {
                    $redirect .= '&servings=' . (int)$activeServings;
                }
                header('Location: ' . $redirect . '&rated=1');
                exit();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['save_recipe']) || isset($_POST['unsave_recipe']))) {
    if (!isset($_SESSION['user_id'])) {
        $flash = 'Please log in to save recipes.';
        $flashType = 'error';
    } else {
        $userId = (int)$_SESSION['user_id'];
        if (isset($_POST['save_recipe'])) {
            saveRecipeForUser($pdo, $userId, $recipe_id);
        } else {
            unsaveRecipeForUser($pdo, $userId, $recipe_id);
        }

        $redirect = 'recipe.php?id=' . $recipe_id;
        if ($activeServings) {
            $redirect .= '&servings=' . (int)$activeServings;
        }
        header('Location: ' . $redirect);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_missing_to_shopping_list'])) {
    if (!isset($_SESSION['user_id'])) {
        setRecipeFlash('Please log in to add items to your shopping list.', 'error');
    } else {
        try {
            ensureShoppingListTable($pdo);
            $userId = (int)$_SESSION['user_id'];
            $recipeIngredientsForShopping = getRecipeIngredientsForPantryMath($pdo, $recipe_id);
            $pantryMap = getUserPantryBaseTotals($pdo, $userId);
            $addedCount = 0;

            foreach ($recipeIngredientsForShopping as $ing) {
                $ingredientId = (int)$ing['id_ing_rcping'];
                $recipeQty = (float)$ing['quantity_rcping'] * $servingScale;
                $recipeUnitId = (int)$ing['id_uni_rcping'];
                $unitType = $ing['unit_type_uni'] ?? null;
                $baseUnitName = $ing['base_unit_uni'] ?? null;
                $conversion = isset($ing['conversion_to_base_uni']) ? (float)$ing['conversion_to_base_uni'] : null;

                if ($unitType && $baseUnitName && $conversion !== null && $conversion > 0) {
                    $requiredBaseQty = $recipeQty * $conversion;
                    $key = $ingredientId . '|' . $unitType . '|' . mb_strtolower($baseUnitName, 'UTF-8');
                    $pantryBaseQty = isset($pantryMap[$key]) ? (float)$pantryMap[$key] : 0.0;
                    $missingBaseQty = $requiredBaseQty - $pantryBaseQty;

                    if ($missingBaseQty > 0.00001) {
                        $missingInRecipeUnit = $missingBaseQty / $conversion;
                        addOrMergeShoppingListItem($pdo, $userId, $ingredientId, $missingInRecipeUnit, $recipeUnitId);
                        $addedCount++;
                    }
                } else {
                    addOrMergeShoppingListItem($pdo, $userId, $ingredientId, $recipeQty, $recipeUnitId);
                    $addedCount++;
                }
            }

            $flashMessage = $addedCount > 0
                ? ($addedCount === 1 ? '1 missing ingredient was added to your shopping list.' : $addedCount . ' missing ingredients were added to your shopping list.')
                : 'You already have enough of every ingredient for this recipe.';
            setRecipeFlash($flashMessage, 'success', ['show_shopping_list_link' => $addedCount > 0]);
        } catch (Throwable $e) {
            setRecipeFlash('Could not update the shopping list right now.', 'error');
        }
    }

    header('Location: ' . buildRecipeRedirectUrl($recipe_id, $activeServings));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cook_recipe_and_subtract_pantry'])) {
    if (!isset($_SESSION['user_id'])) {
        setRecipeFlash('Please log in to remove recipe ingredients from your pantry.', 'error');
    } else {
        try {
            $userId = (int)$_SESSION['user_id'];
            $recipeIngredientsForCooking = getRecipeIngredientsForPantryMath($pdo, $recipe_id);
            if (!$recipeIngredientsForCooking) {
                throw new RuntimeException('This recipe has no ingredients.');
            }

            $isModifiedRecipe = isset($_POST['modified_recipe']) && $_POST['modified_recipe'] === '1';
            $submittedQtyMap = $_POST['modified_qty'] ?? [];

            $pdo->beginTransaction();
            $deductedAny = false;
            $shortItems = [];

            foreach ($recipeIngredientsForCooking as $ing) {
                $ingredientId = (int)$ing['id_ing_rcping'];
                $ingredientName = (string)$ing['name_ing'];
                $unitName = (string)$ing['name_uni'];
                $recipeQty = (float)$ing['quantity_rcping'] * $servingScale;

                if ($isModifiedRecipe && is_array($submittedQtyMap) && array_key_exists((string)$ingredientId, $submittedQtyMap)) {
                    $rawQty = trim((string)$submittedQtyMap[(string)$ingredientId]);
                    $recipeQty = ($rawQty === '' || !is_numeric($rawQty)) ? 0.0 : max(0.0, (float)$rawQty);
                }

                if ($recipeQty <= 0) continue;

                $unitType = $ing['unit_type_uni'] ?? null;
                $baseUnitName = $ing['base_unit_uni'] ?? null;
                $conversion = isset($ing['conversion_to_base_uni']) ? (float)$ing['conversion_to_base_uni'] : null;
                if (!$unitType || !$baseUnitName || $conversion === null || $conversion <= 0) {
                    $shortItems[] = $ingredientName;
                    continue;
                }

                $requestedBaseQty = $recipeQty * $conversion;
                $deductedBaseQty = subtractUpToAvailableFromPantry($pdo, $userId, $ingredientId, (string)$unitType, (string)$baseUnitName, $requestedBaseQty);
                if ($deductedBaseQty > 0.00001) {
                    $deductedAny = true;
                }

                if ($deductedBaseQty + 0.00001 < $requestedBaseQty) {
                    $missingBaseQty = $requestedBaseQty - $deductedBaseQty;
                    $missingInRecipeUnit = $conversion > 0 ? ($missingBaseQty / $conversion) : 0.0;
                    $shortItems[] = $ingredientName . ' (' . formatQty($missingInRecipeUnit) . ' ' . $unitName . ' short)';
                }
            }

            $pdo->commit();

            if ($deductedAny && $shortItems) {
                setRecipeFlash('Available pantry ingredients were deducted. Short or missing: ' . implode(', ', $shortItems) . '.');
            } elseif ($deductedAny) {
                setRecipeFlash('Recipe ingredients were removed from your pantry.');
            } elseif ($shortItems) {
                setRecipeFlash('Nothing was deducted. Missing or unavailable: ' . implode(', ', $shortItems) . '.', 'error');
            } else {
                setRecipeFlash('No pantry changes were needed.');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            setRecipeFlash('Could not remove recipe ingredients from your pantry.', 'error');
        }
    }

    header('Location: ' . buildRecipeRedirectUrl($recipe_id, $activeServings));
    exit();
}

$stmt = $pdo->prepare("SELECT i.id_ing, i.name_ing, ri.quantity_rcping, ri.id_uni_rcping, u.name_uni
    FROM recipe_ingredient_rcping ri
    JOIN ingredient_ing i ON ri.id_ing_rcping = i.id_ing
    JOIN unit_uni u ON ri.id_uni_rcping = u.id_uni
    WHERE ri.id_rcp_rcping = ?
    ORDER BY i.name_ing");
$stmt->execute([$recipe_id]);
$ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT step_number_stp, instruction_stp FROM recipe_step_stp WHERE id_rcp_stp = ? ORDER BY step_number_stp ASC");
$stmt->execute([$recipe_id]);
$steps = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT image_path_img FROM recipe_image_img WHERE id_rcp_img = ?");
$stmt->execute([$recipe_id]);
$images = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT t.name_cty, c.name_cat
    FROM recipe_category_rcpcat rc
    JOIN category_cat c ON rc.id_cat_rcpcat = c.id_cat
    JOIN category_type_cty t ON c.id_cty_cat = t.id_cty
    WHERE rc.id_rcp_rcpcat = ?
    ORDER BY t.name_cty, c.name_cat");
$stmt->execute([$recipe_id]);
$catRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoriesByType = [];
foreach ($catRows as $row) {
    $categoriesByType[$row['name_cty']][] = $row['name_cat'];
}

$stmt = $pdo->prepare("SELECT AVG(rating_value_rat) AS avg_rating, COUNT(*) AS rating_count FROM rating_rat WHERE id_rcp_rat = ?");
$stmt->execute([$recipe_id]);
$rating = $stmt->fetch(PDO::FETCH_ASSOC);

$userRating = 0;
if (isset($_GET['rated']) && $_GET['rated'] === '1' && $flash === '') {
    $flash = 'Your rating was saved.';
}

if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT rating_value_rat FROM rating_rat WHERE id_rcp_rat = ? AND id_usr_rat = ? LIMIT 1");
    $stmt->execute([$recipe_id, (int)$_SESSION['user_id']]);
    $userRating = (int)$stmt->fetchColumn();
}

function toYouTubeEmbedUrl($url) {
    if (!$url) return null;
    if (strpos($url, 'youtube.com/embed/') !== false) return $url;
    if (preg_match('~youtu\.be/([A-Za-z0-9_-]{6,})~', $url, $m)) return 'https://www.youtube.com/embed/' . $m[1];
    if (preg_match('~v=([A-Za-z0-9_-]{6,})~', $url, $m)) return 'https://www.youtube.com/embed/' . $m[1];
    return null;
}

$youtube_embed = toYouTubeEmbedUrl($recipe['youtube_url_rcp']);
$prep = $recipe['prep_time_minutes_rcp'];
$cook = $recipe['cook_time_minutes_rcp'];
$total = (int)($prep ?? 0) + (int)($cook ?? 0);
$servingBadge = $baseServings ? ('Serves ' . $baseServings) : 'Serving size not set';
$isRecipeSaved = false;
$isOwner = isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === (int)$recipe['id_usr_rcp'];
$recipeOwnerRole = getUserPrimaryRole($pdo, (int)$recipe['id_usr_rcp']);
$canAdminDeleteRecipe = isAdminUser() && !$isOwner && (isSuperAdminUser() || $recipeOwnerRole === 'user');
if (isset($_SESSION['user_id'])) {
    $savedRecipeIds = getSavedRecipeIdsForUser($pdo, (int)$_SESSION['user_id']);
    $isRecipeSaved = in_array($recipe_id, $savedRecipeIds, true);
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo h($recipe['title_rcp']); ?> - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main class="cv-page cv-page--narrow recipe-page">
  <div class="recipe-top-actions">
    <a href="recipes.php" class="recipe-back-link">&larr; Back to recipes</a>
    <?php if ($canAdminDeleteRecipe): ?>
      <form method="POST" action="recipe.php?id=<?php echo (int)$recipe_id; ?><?php echo $activeServings ? '&servings=' . (int)$activeServings : ''; ?>" class="recipe-top-admin-form" onsubmit="return confirm('Remove this recipe from Cookventory?');">
        <?php if ($activeServings): ?>
          <input type="hidden" name="servings_target" value="<?php echo (int)$activeServings; ?>">
        <?php endif; ?>
        <button type="submit" name="admin_delete_recipe" value="1">Admin Remove Recipe</button>
      </form>
    <?php endif; ?>
  </div>
  <header class="cv-page-header recipe-header-card cv-card cv-panel">
    <div class="recipe-header-top">
      <div>
        <h1 class="cv-page-title"><?php echo h($recipe['title_rcp']); ?></h1>
        <p class="recipe-card-copy"><?php echo h($recipe['description_rcp']); ?></p>
      </div>
      <div class="recipe-serving-badge"><?php echo h($servingBadge); ?></div>
    </div>

    <p class="recipe-meta cv-muted"><?php if ($prep !== null): ?>Prep: <?php echo (int)$prep; ?>m<?php endif; ?><?php if ($cook !== null): ?> | Cook: <?php echo (int)$cook; ?>m<?php endif; ?><?php if ($prep !== null || $cook !== null): ?> | Total: <?php echo $total; ?>m<?php endif; ?></p>
    <?php if (!empty($rating['rating_count'])): ?><p class="cv-muted">Rating: <?php echo number_format((float)$rating['avg_rating'], 1); ?>/5 (<?php echo (int)$rating['rating_count']; ?>)</p><?php endif; ?>

    <?php if (isset($_SESSION['user_id'])): ?>
      <form method="POST" action="recipe.php?id=<?php echo (int)$recipe_id; ?><?php echo $activeServings ? '&servings=' . (int)$activeServings : ''; ?>" class="recipe-rating-form">
        <?php if ($activeServings): ?>
          <input type="hidden" name="servings_target" value="<?php echo (int)$activeServings; ?>">
        <?php endif; ?>
        <input type="hidden" name="rate_recipe" value="1">
        <div class="recipe-rating-header">
          <span class="recipe-rating-label">Your rating</span>
          <?php if ($userRating > 0): ?>
            <span class="cv-help-text">Current: <?php echo (int)$userRating; ?>/5</span>
          <?php else: ?>
            <span class="cv-help-text">Choose 1 to 5 stars</span>
          <?php endif; ?>
        </div>
        <div class="recipe-rating-stars" role="group" aria-label="Rate this recipe">
          <?php for ($star = 1; $star <= 5; $star++): ?>
            <button
              type="submit"
              name="rating_value"
              value="<?php echo $star; ?>"
              class="recipe-rating-star<?php echo $userRating >= $star ? ' is-selected' : ''; ?>"
              aria-label="Rate <?php echo $star; ?> star<?php echo $star === 1 ? '' : 's'; ?>"
            >
              &#9733;
            </button>
          <?php endfor; ?>
        </div>
      </form>
    <?php else: ?>
      <p class="cv-help-text">Log in to rate this recipe.</p>
    <?php endif; ?>

  </header>

  <?php if ($flash): ?>
    <div class="cv-alert <?php echo $flashType === 'error' ? 'cv-alert--error' : 'cv-alert--success'; ?> cv-stack-sm">
      <p><?php echo h($flash); ?></p>
      <?php if ($flashType !== 'error' && !empty($flashMeta['show_shopping_list_link'])): ?>
        <div class="recipe-flash-link"><a href="shopping_list.php">View shopping list &rarr;</a></div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="recipe-section cv-card cv-panel">
    <div class="recipe-section-header">
      <h2 class="cv-card-title">Ingredients</h2>
      <?php if ($activeServings): ?>
        <p class="cv-muted">Scaled for <?php echo (int)$activeServings; ?> serving<?php echo (int)$activeServings === 1 ? '' : 's'; ?>.</p>
      <?php endif; ?>
    </div>
    <?php if (!$ingredients): ?>
      <p class="cv-empty-text">No ingredients listed.</p>
    <?php else: ?>
      <ul class="recipe-ingredient-list">
        <?php foreach ($ingredients as $ing): ?>
          <li><?php echo h(formatQty((float)$ing['quantity_rcping'] * $servingScale)); ?> <?php echo h($ing['name_uni']); ?> <?php echo h($ing['name_ing']); ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <div class="recipe-section-actions recipe-section-actions--split">
      <div class="recipe-section-actions-main">
        <div class="recipe-serving-tools">
          <?php if ($baseServings): ?>
            <form method="GET" action="recipe.php" class="recipe-serving-form">
              <input type="hidden" name="id" value="<?php echo (int)$recipe_id; ?>">
              <div class="cv-field recipe-serving-field">
                <label for="recipe_servings_target">Adjust servings</label>
                <input id="recipe_servings_target" type="number" name="servings" min="1" step="1" value="<?php echo (int)$activeServings; ?>">
              </div>
              <button type="submit">Update amounts</button>
              <?php if ($activeServings !== null && $activeServings !== $baseServings): ?>
                <a class="cv-button recipe-serving-reset" href="recipe.php?id=<?php echo (int)$recipe_id; ?>">Reset to <?php echo (int)$baseServings; ?></a>
              <?php endif; ?>
            </form>
          <?php else: ?>
            <p class="recipe-serving-copy cv-muted">This recipe does not have a saved serving size yet, so ingredient scaling is unavailable for it.</p>
          <?php endif; ?>
        </div>
      </div>

      <?php if (isset($_SESSION['user_id'])): ?>
        <form method="POST" action="recipe.php?id=<?php echo (int)$recipe_id; ?><?php echo $activeServings ? '&servings=' . (int)$activeServings : ''; ?>" class="recipe-action-form">
          <?php if ($activeServings): ?>
            <input type="hidden" name="servings_target" value="<?php echo (int)$activeServings; ?>">
          <?php endif; ?>
          <button type="submit" name="add_missing_to_shopping_list" value="1">Add to Shopping List</button>
        </form>
      <?php else: ?>
        <p class="cv-help-text recipe-login-note"><a href="login.php">Log in</a> to add missing ingredients to your shopping list.</p>
      <?php endif; ?>
    </div>
  </section>

  <section class="recipe-section cv-card cv-panel">
    <h2 class="cv-card-title">Instructions</h2>
    <?php if (!$steps): ?><p class="cv-empty-text">No steps listed.</p><?php else: ?><ol><?php foreach ($steps as $s): ?><li><?php echo h($s['instruction_stp']); ?></li><?php endforeach; ?></ol><?php endif; ?>

    <?php if (isset($_SESSION['user_id'])): ?>
      <div class="recipe-section-actions">
        <form method="POST" action="recipe.php?id=<?php echo (int)$recipe_id; ?><?php echo $activeServings ? '&servings=' . (int)$activeServings : ''; ?>" class="recipe-action-form" onsubmit="return confirm('This will reduce pantry ingredients based on this recipe. Continue?');">
          <?php if ($activeServings): ?>
            <input type="hidden" name="servings_target" value="<?php echo (int)$activeServings; ?>">
          <?php endif; ?>
          <div class="recipe-action-row recipe-action-row--cook">
            <label class="recipe-toggle-label"><input type="checkbox" name="modified_recipe" id="modified_recipe" value="1"><span>Modified recipe?</span></label>
            <button type="submit" name="cook_recipe_and_subtract_pantry" value="1">Cook This Recipe</button>
          </div>

          <div id="modified-recipe-fields" class="recipe-modified-fields is-hidden">
            <h3>Adjust ingredients used</h3>
            <p class="cv-muted">Set the amount you actually used for each ingredient. Leave an item at 0 to skip deducting it.</p>
            <div class="recipe-adjust-grid">
              <?php foreach ($ingredients as $ing): ?>
                <?php $defaultQty = formatQty((float)$ing['quantity_rcping'] * $servingScale); ?>
                <div class="recipe-adjust-row">
                  <label for="modified_qty_<?php echo (int)$ing['id_ing']; ?>"><?php echo h($ing['name_ing']); ?></label>
                  <input type="number" id="modified_qty_<?php echo (int)$ing['id_ing']; ?>" name="modified_qty[<?php echo (int)$ing['id_ing']; ?>]" step="0.001" min="0" value="<?php echo h($defaultQty); ?>">
                  <span><?php echo h($ing['name_uni']); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </form>
      </div>
    <?php else: ?>
      <p class="cv-help-text recipe-login-note"><a href="login.php">Log in</a> to remove ingredients from your pantry when you cook.</p>
    <?php endif; ?>
  </section>

  <section class="recipe-section cv-card cv-panel recipe-media-section">
    <h2 class="cv-card-title">Images</h2>
    <div class="recipe-media-grid recipe-media-grid--compact">
      <?php if ($images): ?>
        <?php foreach ($images as $img): ?>
          <img src="<?php echo h($img['image_path_img']); ?>" alt="Recipe image" class="recipe-media-image recipe-media-image--compact">
        <?php endforeach; ?>
      <?php else: ?>
        <img src="<?php echo h($recipePlaceholderImage); ?>" alt="No image available" class="recipe-media-image recipe-media-image--compact">
      <?php endif; ?>
    </div>
  </section>

  <?php if ($youtube_embed): ?>
    <section class="recipe-section cv-card cv-panel">
      <h2 class="cv-card-title">Video</h2>
      <iframe class="recipe-video" src="<?php echo h($youtube_embed); ?>" title="YouTube video" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>
    </section>
  <?php endif; ?>

  <?php if (isset($_SESSION['user_id'])): ?>
    <div class="recipe-action-block recipe-action-block--footer">
      <form method="POST" action="recipe.php?id=<?php echo (int)$recipe_id; ?><?php echo $activeServings ? '&servings=' . (int)$activeServings : ''; ?>" class="recipe-action-form recipe-save-form">
        <?php if ($activeServings): ?>
          <input type="hidden" name="servings_target" value="<?php echo (int)$activeServings; ?>">
        <?php endif; ?>
        <?php if ($isRecipeSaved): ?>
          <button type="submit" name="unsave_recipe" value="1" class="recipe-save-button recipe-save-button--saved">Saved</button>
        <?php else: ?>
          <button type="submit" name="save_recipe" value="1" class="recipe-save-button">Save recipe</button>
        <?php endif; ?>
      </form>
    </div>
  <?php else: ?>
    <p class="recipe-section"><a href="login.php">Log in</a> to save recipes.</p>
  <?php endif; ?>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>


