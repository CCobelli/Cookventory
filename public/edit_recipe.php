<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';
require_once '../private/recipe-form-helpers.php';

if (!isset($_GET['id']) || !ctype_digit((string)$_GET['id'])) {
    http_response_code(400);
    die('Invalid recipe id.');
}

$recipeId = (int)$_GET['id'];
$userId = (int)$_SESSION['user_id'];
$hasServingsColumn = ensureRecipeServingsColumn($pdo);

$stmt = $pdo->prepare("SELECT * FROM recipe_rcp WHERE id_rcp = ? AND id_usr_rcp = ? LIMIT 1");
$stmt->execute([$recipeId, $userId]);
$recipe = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$recipe) {
    http_response_code(403);
    die('You can only edit your own recipes.');
}

$stmt = $pdo->query("SELECT id_uni, name_uni FROM unit_uni ORDER BY name_uni");
$unitsList = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->query("
    SELECT t.name_cty, c.id_cat, c.name_cat
    FROM category_type_cty t
    JOIN category_cat c ON c.id_cty_cat = t.id_cty
    ORDER BY t.name_cty, c.name_cat
");
$catRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoriesByType = [];
foreach ($catRows as $row) {
    $categoriesByType[$row['name_cty']][] = [
        'id_cat' => (int)$row['id_cat'],
        'name_cat' => $row['name_cat']
    ];
}
$cuisineCategoryIds = array_map(
    static fn($row) => (int)$row['id_cat'],
    $categoriesByType['cuisine'] ?? []
);
$cuisineCategoryMap = array_fill_keys($cuisineCategoryIds, true);

$stmt = $pdo->prepare("
    SELECT i.id_ing, i.name_ing, ri.quantity_rcping, ri.id_uni_rcping
    FROM recipe_ingredient_rcping ri
    JOIN ingredient_ing i ON i.id_ing = ri.id_ing_rcping
    WHERE ri.id_rcp_rcping = ?
    ORDER BY ri.id_rcping ASC
");
$stmt->execute([$recipeId]);
$dbIngredients = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT instruction_stp FROM recipe_step_stp WHERE id_rcp_stp = ? ORDER BY step_number_stp ASC");
$stmt->execute([$recipeId]);
$dbSteps = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

$stmt = $pdo->prepare("SELECT id_cat_rcpcat FROM recipe_category_rcpcat WHERE id_rcp_rcpcat = ?");
$stmt->execute([$recipeId]);
$dbCategoryIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
$stmt = $pdo->prepare("
    SELECT c.name_cat
    FROM recipe_category_rcpcat rc
    JOIN category_cat c ON c.id_cat = rc.id_cat_rcpcat
    WHERE rc.id_rcp_rcpcat = ? AND c.id_cty_cat = 2
    ORDER BY c.name_cat ASC
    LIMIT 1
");
$stmt->execute([$recipeId]);
$dbCuisineName = (string)($stmt->fetchColumn() ?: '');

$stmt = $pdo->prepare("SELECT id_img, image_path_img FROM recipe_image_img WHERE id_rcp_img = ? ORDER BY id_img ASC LIMIT 1");
$stmt->execute([$recipeId]);
$existingImage = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $prepRaw = trim($_POST['prep'] ?? '');
    $cookRaw = trim($_POST['cook'] ?? '');
    $servingsRaw = trim($_POST['servings'] ?? '');
    $youtube = trim($_POST['youtube'] ?? '');
    $customCuisine = trim($_POST['custom_cuisine'] ?? '');
    $photoFile = $_FILES['recipe_photo'] ?? null;

    $prep = ($prepRaw === '') ? null : (int)$prepRaw;
    $cook = ($cookRaw === '') ? null : (int)$cookRaw;
    $servings = ($servingsRaw === '') ? null : (int)$servingsRaw;

    if ($title === '') $errors[] = 'Title is required.';
    if ($servingsRaw === '' || !ctype_digit($servingsRaw) || (int)$servingsRaw <= 0) {
        $errors[] = 'Serving size must be a whole number greater than 0.';
    }

    $ingIds = $_POST['ingredient_id'] ?? [];
    $ingNames = $_POST['ingredient_name'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $unitIds = $_POST['unit_id'] ?? [];
    $steps = $_POST['step'] ?? [];
    $cats = $_POST['category'] ?? [];

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            if ($hasServingsColumn) {
                $stmt = $pdo->prepare("
                    UPDATE recipe_rcp
                    SET title_rcp = ?, description_rcp = ?, prep_time_minutes_rcp = ?, cook_time_minutes_rcp = ?, servings_rcp = ?, youtube_url_rcp = ?
                    WHERE id_rcp = ? AND id_usr_rcp = ?
                ");
                $stmt->execute([$title, $desc, $prep, $cook, $servings, ($youtube === '' ? null : $youtube), $recipeId, $userId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE recipe_rcp
                    SET title_rcp = ?, description_rcp = ?, prep_time_minutes_rcp = ?, cook_time_minutes_rcp = ?, youtube_url_rcp = ?
                    WHERE id_rcp = ? AND id_usr_rcp = ?
                ");
                $stmt->execute([$title, $desc, $prep, $cook, ($youtube === '' ? null : $youtube), $recipeId, $userId]);
            }

            $pdo->prepare("DELETE FROM recipe_ingredient_rcping WHERE id_rcp_rcping = ?")->execute([$recipeId]);
            $pdo->prepare("DELETE FROM recipe_step_stp WHERE id_rcp_stp = ?")->execute([$recipeId]);
            $pdo->prepare("DELETE FROM recipe_category_rcpcat WHERE id_rcp_rcpcat = ?")->execute([$recipeId]);

            $count = count($ingNames);
            for ($i = 0; $i < $count; $i++) {
                $typedName = isset($ingNames[$i]) ? trim((string)$ingNames[$i]) : '';
                $pickedId = (isset($ingIds[$i]) && ctype_digit((string)$ingIds[$i])) ? (int)$ingIds[$i] : 0;
                $qty = isset($qtys[$i]) ? trim((string)$qtys[$i]) : '';
                $unitId = (isset($unitIds[$i]) && ctype_digit((string)$unitIds[$i])) ? (int)$unitIds[$i] : 0;

                if ($typedName === '' || $qty === '' || $unitId <= 0) continue;

                $finalIngId = $pickedId > 0 ? $pickedId : resolveIngredientId($pdo, $typedName);
                if ($finalIngId <= 0) continue;

                $stmt = $pdo->prepare("INSERT INTO recipe_ingredient_rcping (id_rcp_rcping, id_ing_rcping, quantity_rcping, id_uni_rcping) VALUES (?, ?, ?, ?)");
                $stmt->execute([$recipeId, $finalIngId, $qty, $unitId]);
            }

            $stepNum = 1;
            foreach ($steps as $s) {
                $s = trim((string)$s);
                if ($s === '') continue;
                $stmt = $pdo->prepare("INSERT INTO recipe_step_stp (id_rcp_stp, step_number_stp, instruction_stp) VALUES (?, ?, ?)");
                $stmt->execute([$recipeId, $stepNum, $s]);
                $stepNum++;
            }

            foreach ($cats as $catId) {
                if (!ctype_digit((string)$catId)) continue;
                if ($customCuisine !== '' && isset($cuisineCategoryMap[(int)$catId])) continue;
                $stmt = $pdo->prepare("INSERT INTO recipe_category_rcpcat (id_rcp_rcpcat, id_cat_rcpcat) VALUES (?, ?)");
                $stmt->execute([$recipeId, (int)$catId]);
            }

            if ($customCuisine !== '') {
                $cuisineCatId = resolveCuisineCategoryId($pdo, $customCuisine);
                if ($cuisineCatId > 0) {
                    $stmt = $pdo->prepare("
                        INSERT IGNORE INTO recipe_category_rcpcat (id_rcp_rcpcat, id_cat_rcpcat)
                        VALUES (?, ?)
                    ");
                    $stmt->execute([$recipeId, $cuisineCatId]);
                }
            }

            $photoPath = saveRecipePhotoUpload($photoFile ?? [], $recipeId);
            if ($photoPath !== null) {
                if ($existingImage && !empty($existingImage['image_path_img'])) {
                    $oldFile = __DIR__ . '/' . ltrim((string)$existingImage['image_path_img'], '/');
                    if (is_file($oldFile)) {
                        @unlink($oldFile);
                    }

                    $stmt = $pdo->prepare("UPDATE recipe_image_img SET image_path_img = ? WHERE id_img = ?");
                    $stmt->execute([$photoPath, (int)$existingImage['id_img']]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO recipe_image_img (id_rcp_img, image_path_img) VALUES (?, ?)");
                    $stmt->execute([$recipeId, $photoPath]);
                }
            }

            $pdo->commit();
            header('Location: recipe.php?id=' . $recipeId);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$stickyTitle = h($_POST['title'] ?? $recipe['title_rcp']);
$stickyDesc = h($_POST['description'] ?? $recipe['description_rcp']);
$stickyPrep = h($_POST['prep'] ?? ($recipe['prep_time_minutes_rcp'] ?? ''));
$stickyCook = h($_POST['cook'] ?? ($recipe['cook_time_minutes_rcp'] ?? ''));
$stickyServings = h($_POST['servings'] ?? ($recipe['servings_rcp'] ?? '4'));
$stickyYT = h($_POST['youtube'] ?? ($recipe['youtube_url_rcp'] ?? ''));
$stickyCuisine = h($_POST['custom_cuisine'] ?? $dbCuisineName);

$stickyIngredients = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postNames = $_POST['ingredient_name'] ?? [];
    $postIds = $_POST['ingredient_id'] ?? [];
    $postQtys = $_POST['quantity'] ?? [];
    $postUnitIds = $_POST['unit_id'] ?? [];
    $count = max(count($postNames), count($postQtys), count($postUnitIds));
    for ($i = 0; $i < $count; $i++) {
        $stickyIngredients[] = [
            'ingredient_name' => (string)($postNames[$i] ?? ''),
            'ingredient_id' => (string)($postIds[$i] ?? ''),
            'quantity' => (string)($postQtys[$i] ?? ''),
            'unit_id' => (string)($postUnitIds[$i] ?? '')
        ];
    }
} else {
    foreach ($dbIngredients as $row) {
        $stickyIngredients[] = [
            'ingredient_name' => $row['name_ing'],
            'ingredient_id' => (string)(int)$row['id_ing'],
            'quantity' => (string)$row['quantity_rcping'],
            'unit_id' => (string)(int)$row['id_uni_rcping']
        ];
    }
}
if (!$stickyIngredients) {
    $stickyIngredients[] = ['ingredient_name' => '', 'ingredient_id' => '', 'quantity' => '', 'unit_id' => ''];
}

$stickySteps = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['step'] ?? []) : $dbSteps;
if (!$stickySteps) {
    $stickySteps = [''];
}

$selectedCategories = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? array_map('intval', $_POST['category'] ?? [])
    : $dbCategoryIds;
$selectedCategoryMap = array_fill_keys($selectedCategories, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Edit Recipe - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main class="cv-page cv-page--narrow create-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">Edit Recipe</h1>
    <p class="cv-page-subtitle">Update your recipe details, ingredients, steps, and tags.</p>
  </header>

  <?php if ($errors): ?>
    <div class="cv-alert cv-alert--error cv-stack-sm">
      <?php foreach ($errors as $err): ?>
        <p><?php echo h($err); ?></p>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="POST" action="" class="cv-stack-lg" enctype="multipart/form-data">
    <section class="cv-card cv-panel cv-stack-md">
      <h2 class="cv-card-title">Basic Info</h2>
      <div class="cv-form-grid">
        <div class="cv-field">
          <label for="recipe_title">Title</label>
          <input id="recipe_title" type="text" name="title" required value="<?php echo $stickyTitle; ?>">
        </div>

        <div class="cv-field">
          <label for="recipe_description">Description</label>
          <textarea id="recipe_description" name="description" rows="4"><?php echo $stickyDesc; ?></textarea>
        </div>

        <div class="cv-form-grid-3">
          <div class="cv-field">
            <label for="recipe_prep">Prep Minutes</label>
            <input id="recipe_prep" type="number" name="prep" min="0" value="<?php echo $stickyPrep; ?>">
          </div>

          <div class="cv-field">
            <label for="recipe_cook">Cook Minutes</label>
            <input id="recipe_cook" type="number" name="cook" min="0" value="<?php echo $stickyCook; ?>">
          </div>

          <div class="cv-field">
            <label for="recipe_servings">Serves</label>
            <input id="recipe_servings" type="number" name="servings" min="1" step="1" required value="<?php echo $stickyServings; ?>">
          </div>
        </div>

        <div class="cv-field">
          <label for="recipe_youtube">YouTube Link</label>
          <input id="recipe_youtube" type="text" name="youtube" value="<?php echo $stickyYT; ?>">
        </div>

        <div class="cv-field">
          <label for="recipe_custom_cuisine">Cuisine</label>
          <input
            id="recipe_custom_cuisine"
            type="text"
            name="custom_cuisine"
            value="<?php echo $stickyCuisine; ?>"
            placeholder="Type a cuisine like Italian or Thai"
            autocomplete="off"
            autocapitalize="words"
            spellcheck="true"
          >
          <p class="cv-help-text">We will match close existing cuisines first so small spelling differences do not create duplicates.</p>
        </div>

        <div class="cv-field">
          <label for="recipe_photo">Recipe Photo</label>
          <input id="recipe_photo" type="file" name="recipe_photo" accept="image/jpeg,image/png,image/gif,image/webp">
          <p class="cv-help-text">Optional. Uploading a new image replaces the current one.</p>
          <?php if ($existingImage && !empty($existingImage['image_path_img'])): ?>
            <img src="<?php echo h($existingImage['image_path_img']); ?>" alt="Current recipe photo" class="create-recipe-image-preview">
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="cv-card cv-panel cv-stack-md">
      <div class="cv-actions">
        <h2 class="cv-card-title">Ingredients</h2>
        <button type="button" onclick="addIngredientRow()">Add Ingredient</button>
      </div>

      <div id="ingredients">
        <?php foreach ($stickyIngredients as $item): ?>
          <div class="create-ingredient-row ingredient-row">
            <div class="create-ingredient-name-wrap">
              <input
                class="create-ingredient-input"
                type="text"
                name="ingredient_name[]"
                placeholder="Ingredient (e.g., Garlic)"
                required
                autocomplete="off"
                autocapitalize="words"
                spellcheck="true"
                oninput="ingredientType(this)"
                onfocus="ingredientType(this)"
                value="<?php echo h($item['ingredient_name']); ?>"
              >
              <input type="hidden" name="ingredient_id[]" value="<?php echo h($item['ingredient_id']); ?>">
              <div class="ing-suggest"></div>
            </div>

            <input type="number" step="0.001" min="0" name="quantity[]" placeholder="Qty" required value="<?php echo h($item['quantity']); ?>">

            <select name="unit_id[]" required>
              <option value="">Unit</option>
              <?php foreach ($unitsList as $u): ?>
                <option value="<?php echo (int)$u['id_uni']; ?>" <?php echo ((string)(int)$u['id_uni'] === (string)$item['unit_id']) ? 'selected' : ''; ?>><?php echo h($u['name_uni']); ?></option>
              <?php endforeach; ?>
            </select>

            <button type="button" onclick="removeRow(this)">Remove</button>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="cv-card cv-panel cv-stack-md">
      <div class="cv-actions">
        <h2 class="cv-card-title">Steps</h2>
        <button type="button" onclick="addStepRow()">Add Step</button>
      </div>

      <div id="steps">
        <?php foreach ($stickySteps as $index => $step): ?>
          <div class="create-step-row step-row">
            <textarea name="step[]" rows="2" placeholder="Step <?php echo $index + 1; ?>" required><?php echo h($step); ?></textarea>
            <button type="button" onclick="removeRow(this)">Remove</button>
          </div>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="cv-card cv-panel cv-stack-md">
      <h2 class="cv-card-title">Categories</h2>
      <?php foreach ($categoriesByType as $type => $cats): ?>
        <fieldset class="create-category-fieldset">
          <legend><?php echo h(ucfirst($type)); ?></legend>
          <?php foreach ($cats as $c): ?>
            <label class="create-category-option">
              <input type="checkbox" name="category[]" value="<?php echo (int)$c['id_cat']; ?>" <?php echo isset($selectedCategoryMap[(int)$c['id_cat']]) ? 'checked' : ''; ?>>
              <span><?php echo h($c['name_cat']); ?></span>
            </label>
          <?php endforeach; ?>
        </fieldset>
      <?php endforeach; ?>
    </section>

    <div class="cv-actions">
      <button type="submit">Save Changes</button>
      <a href="recipe.php?id=<?php echo $recipeId; ?>" class="cv-button">Cancel</a>
    </div>
  </form>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>
