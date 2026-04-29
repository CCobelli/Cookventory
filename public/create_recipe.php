<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';
require_once '../private/recipe-form-helpers.php';

$hasServingsColumn = ensureRecipeServingsColumn($pdo);

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

$errors = [];

function queueRecipeFlash(string $message, string $type = 'success'): void
{
    $_SESSION['recipe_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $prepRaw = trim($_POST['prep'] ?? '');
    $cookRaw = trim($_POST['cook'] ?? '');
    $servingsRaw = trim($_POST['servings'] ?? '');
    $youtube = trim($_POST['youtube'] ?? '');
    $useCustomCuisine = isset($_POST['use_custom_cuisine']) && $_POST['use_custom_cuisine'] === '1';
    $customCuisine = $useCustomCuisine ? trim($_POST['custom_cuisine'] ?? '') : '';
    $photoFile = $_FILES['recipe_photo'] ?? null;

    $prep = ($prepRaw === '') ? null : (int)$prepRaw;
    $cook = ($cookRaw === '') ? null : (int)$cookRaw;
    $servings = ($servingsRaw === '') ? null : (int)$servingsRaw;

    if ($title === '') $errors[] = "Title is required.";
    if ($servingsRaw === '' || !ctype_digit($servingsRaw) || (int)$servingsRaw <= 0) {
        $errors[] = "Serving size must be a whole number greater than 0.";
    }

    $ingIds = $_POST['ingredient_id'] ?? [];
    $ingNames = $_POST['ingredient_name'] ?? [];
    $qtys = $_POST['quantity'] ?? [];
    $unitIds = $_POST['unit_id'] ?? [];
    $steps = $_POST['step'] ?? [];
    $cats = $_POST['category'] ?? [];

    if (!$errors) {
        try {
            $ingredientRows = [];
            $count = count($ingNames);
            for ($i = 0; $i < $count; $i++) {
                $typedName = isset($ingNames[$i]) ? trim((string)$ingNames[$i]) : '';
                $pickedId = (isset($ingIds[$i]) && ctype_digit((string)$ingIds[$i])) ? (int)$ingIds[$i] : 0;
                $qty = isset($qtys[$i]) ? trim((string)$qtys[$i]) : '';
                $unitId = (isset($unitIds[$i]) && ctype_digit((string)$unitIds[$i])) ? (int)$unitIds[$i] : 0;

                if ($typedName === '' || $qty === '' || $unitId <= 0) {
                    continue;
                }

                $finalIngId = $pickedId > 0 ? $pickedId : resolveIngredientId($pdo, $typedName);
                if ($finalIngId <= 0) {
                    continue;
                }

                $ingredientRows[] = [
                    'ingredient_id' => $finalIngId,
                    'quantity' => $qty,
                    'unit_id' => $unitId,
                ];
            }

            $stepRows = [];
            $stepNum = 1;
            foreach ($steps as $s) {
                $s = trim((string)$s);
                if ($s === '') {
                    continue;
                }

                $stepRows[] = [
                    'step_number' => $stepNum,
                    'instruction' => $s,
                ];
                $stepNum++;
            }

            $categoryIds = [];
            foreach ($cats as $catId) {
                if (!ctype_digit((string)$catId)) {
                    continue;
                }
                $catId = (int)$catId;
                if ($customCuisine !== '' && isset($cuisineCategoryMap[$catId])) {
                    continue;
                }
                $categoryIds[$catId] = true;
            }

            $customCuisineCatId = 0;
            if ($customCuisine !== '') {
                $customCuisineCatId = resolveCuisineCategoryId($pdo, $customCuisine);
            }

            $pdo->beginTransaction();

            if ($hasServingsColumn) {
                $stmt = $pdo->prepare("
                    INSERT INTO recipe_rcp
                    (id_usr_rcp, title_rcp, description_rcp, prep_time_minutes_rcp, cook_time_minutes_rcp, servings_rcp, youtube_url_rcp)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $title,
                    $desc,
                    $prep,
                    $cook,
                    $servings,
                    ($youtube === '' ? null : $youtube)
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO recipe_rcp
                    (id_usr_rcp, title_rcp, description_rcp, prep_time_minutes_rcp, cook_time_minutes_rcp, youtube_url_rcp)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $title,
                    $desc,
                    $prep,
                    $cook,
                    ($youtube === '' ? null : $youtube)
                ]);
            }

            $recipe_id = (int)$pdo->lastInsertId();

            foreach ($ingredientRows as $ingredientRow) {
                $stmt = $pdo->prepare("
                    INSERT INTO recipe_ingredient_rcping
                    (id_rcp_rcping, id_ing_rcping, quantity_rcping, id_uni_rcping)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $recipe_id,
                    (int)$ingredientRow['ingredient_id'],
                    $ingredientRow['quantity'],
                    (int)$ingredientRow['unit_id'],
                ]);
            }

            foreach ($stepRows as $stepRow) {
                $stmt = $pdo->prepare("
                    INSERT INTO recipe_step_stp
                    (id_rcp_stp, step_number_stp, instruction_stp)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([
                    $recipe_id,
                    (int)$stepRow['step_number'],
                    $stepRow['instruction'],
                ]);
            }

            foreach (array_keys($categoryIds) as $catId) {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO recipe_category_rcpcat (id_rcp_rcpcat, id_cat_rcpcat)
                    VALUES (?, ?)
                ");
                $stmt->execute([$recipe_id, $catId]);
            }

            if ($customCuisineCatId > 0) {
                $stmt = $pdo->prepare("
                    INSERT IGNORE INTO recipe_category_rcpcat (id_rcp_rcpcat, id_cat_rcpcat)
                    VALUES (?, ?)
                ");
                $stmt->execute([$recipe_id, $customCuisineCatId]);
            }

            $pdo->commit();

            try {
                $photoPath = saveRecipePhotoUpload($photoFile ?? [], $recipe_id);
                if ($photoPath !== null) {
                    $stmt = $pdo->prepare("INSERT INTO recipe_image_img (id_rcp_img, image_path_img) VALUES (?, ?)");
                    $stmt->execute([$recipe_id, $photoPath]);
                }
            } catch (Throwable $photoError) {
                queueRecipeFlash('Recipe was created, but the photo could not be saved.', 'error');
            }

            header("Location: recipe.php?id=" . $recipe_id);
            exit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}

$stickyTitle = h($_POST['title'] ?? '');
$stickyDesc = h($_POST['description'] ?? '');
$stickyPrep = h($_POST['prep'] ?? '');
$stickyCook = h($_POST['cook'] ?? '');
$stickyServings = h($_POST['servings'] ?? '4');
$stickyYT = h($_POST['youtube'] ?? '');
$stickyCuisine = h($_POST['custom_cuisine'] ?? '');
$stickyUseCustomCuisine = isset($_POST['use_custom_cuisine']) && $_POST['use_custom_cuisine'] === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Create Recipe - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main class="cv-page cv-page--narrow create-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">Create Recipe</h1>
    <p class="cv-page-subtitle">Build a recipe with structured ingredients, steps, tags, and a base serving size so it can power pantry matches and scaled cooking.</p>
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
          <label for="recipe_photo">Recipe Photo</label>
          <input id="recipe_photo" type="file" name="recipe_photo" accept="image/jpeg,image/png,image/gif,image/webp">
          <p class="cv-help-text">Optional. JPG, PNG, GIF, or WEBP up to 5MB.</p>
        </div>
      </div>
    </section>

    <section class="cv-card cv-panel cv-stack-md">
      <div class="cv-actions">
        <h2 class="cv-card-title">Ingredients</h2>
        <button type="button" onclick="addIngredientRow()">Add Ingredient</button>
      </div>

      <div id="ingredients">
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
            >
            <input type="hidden" name="ingredient_id[]" value="">
            <div class="ing-suggest"></div>
          </div>

          <input type="number" step="0.001" min="0" name="quantity[]" placeholder="Qty" required>

          <select name="unit_id[]" required>
            <option value="">Unit</option>
            <?php foreach ($unitsList as $u): ?>
              <option value="<?php echo (int)$u['id_uni']; ?>"><?php echo h($u['name_uni']); ?></option>
            <?php endforeach; ?>
          </select>

          <button type="button" onclick="removeRow(this)">Remove</button>
        </div>
      </div>
    </section>

    <section class="cv-card cv-panel cv-stack-md">
      <div class="cv-actions">
        <h2 class="cv-card-title">Steps</h2>
        <button type="button" onclick="addStepRow()">Add Step</button>
      </div>

      <div id="steps">
        <div class="create-step-row step-row">
          <textarea name="step[]" rows="2" placeholder="Step 1" required></textarea>
          <button type="button" onclick="removeRow(this)">Remove</button>
        </div>
      </div>
    </section>

    <section class="cv-card cv-panel cv-stack-md">
      <h2 class="cv-card-title">Categories</h2>
      <?php foreach ($categoriesByType as $type => $cats): ?>
        <fieldset class="create-category-fieldset">
          <legend><?php echo h(ucfirst($type)); ?></legend>
          <?php foreach ($cats as $c): ?>
            <label class="create-category-option">
              <input type="checkbox" name="category[]" value="<?php echo (int)$c['id_cat']; ?>" <?php echo in_array((string)$c['id_cat'], $_POST['category'] ?? [], true) ? 'checked' : ''; ?>>
              <span><?php echo h($c['name_cat']); ?></span>
            </label>
          <?php endforeach; ?>

          <?php if ($type === 'cuisine'): ?>
            <label class="create-category-option create-category-option--other">
              <input type="checkbox" id="use_custom_cuisine" name="use_custom_cuisine" value="1" <?php echo $stickyUseCustomCuisine ? 'checked' : ''; ?>>
              <span>Other</span>
            </label>

            <div id="create-custom-cuisine-field" class="cv-field create-custom-cuisine-field<?php echo $stickyUseCustomCuisine ? '' : ' is-hidden'; ?>">
              <label for="recipe_custom_cuisine">New Cuisine</label>
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
          <?php endif; ?>
        </fieldset>
      <?php endforeach; ?>
    </section>

    <div class="cv-actions">
      <button type="submit">Create Recipe</button>
    </div>
  </form>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>
