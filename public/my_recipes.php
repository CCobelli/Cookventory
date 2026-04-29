<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';

$recipePlaceholderImage = 'assets/images/noimg.png';
$userId = (int)$_SESSION['user_id'];
$flash = null;

if (isset($_SESSION['my_recipes_flash']) && is_array($_SESSION['my_recipes_flash'])) {
    $flash = $_SESSION['my_recipes_flash'];
    unset($_SESSION['my_recipes_flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_recipe'])) {
    $recipeId = isset($_POST['recipe_id']) && ctype_digit((string)$_POST['recipe_id'])
        ? (int)$_POST['recipe_id']
        : 0;

    if ($recipeId <= 0) {
        $_SESSION['my_recipes_flash'] = [
            'type' => 'error',
            'message' => 'Invalid recipe selected for deletion.',
        ];
    } else {
        $stmt = $pdo->prepare("
            UPDATE recipe_rcp
            SET is_active_rcp = 0
            WHERE id_rcp = ? AND id_usr_rcp = ? AND is_active_rcp = 1
        ");
        $stmt->execute([$recipeId, $userId]);

        $_SESSION['my_recipes_flash'] = $stmt->rowCount() > 0
            ? [
                'type' => 'success',
                'message' => 'Recipe deleted.',
            ]
            : [
                'type' => 'error',
                'message' => 'Could not delete that recipe.',
            ];
    }

    header('Location: my_recipes.php');
    exit();
}

$stmt = $pdo->prepare("
    SELECT
        id_rcp,
        title_rcp,
        description_rcp,
        prep_time_minutes_rcp,
        cook_time_minutes_rcp,
        created_at_rcp,
        img.image_path_img
    FROM recipe_rcp r
    LEFT JOIN (
        SELECT ri.id_rcp_img, MIN(ri.image_path_img) AS image_path_img
        FROM recipe_image_img ri
        GROUP BY ri.id_rcp_img
    ) img ON img.id_rcp_img = r.id_rcp
    WHERE id_usr_rcp = ?
      AND is_active_rcp = 1
    ORDER BY created_at_rcp DESC
");
$stmt->execute([$userId]);
$recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>My Recipes - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main class="cv-page saved-recipes-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">My Recipes</h1>
    <p class="cv-page-subtitle">These are the recipes you have created in Cookventory.</p>
  </header>

  <?php if ($flash): ?>
    <div class="cv-alert <?php echo ($flash['type'] ?? 'success') === 'error' ? 'cv-alert--error' : 'cv-alert--success'; ?>">
      <p><?php echo h((string)($flash['message'] ?? '')); ?></p>
    </div>
  <?php endif; ?>

  <?php if (!$recipes): ?>
    <article class="cv-card cv-panel saved-recipes-empty">
      <p class="cv-empty-text">You have not created any recipes yet.</p>
      <a href="create_recipe.php" class="recipe-card-link">Create a recipe &rarr;</a>
    </article>
  <?php else: ?>
    <div class="recipes-list saved-recipes-list">
      <?php foreach ($recipes as $r): ?>
        <?php
          $prep = $r['prep_time_minutes_rcp'];
          $cook = $r['cook_time_minutes_rcp'];
          $total = (int)($prep ?? 0) + (int)($cook ?? 0);
        ?>
        <?php
          $hasRecipeImage = !empty($r['image_path_img']);
          $cardImagePath = $hasRecipeImage ? (string)$r['image_path_img'] : $recipePlaceholderImage;
        ?>
        <article class="recipe-card cv-card">
          <a href="recipe.php?id=<?php echo (int)$r['id_rcp']; ?>" class="recipe-card-image-link<?php echo $hasRecipeImage ? '' : ' recipe-card-image-link--placeholder'; ?>">
            <img src="<?php echo h($cardImagePath); ?>" alt="<?php echo h($r['title_rcp']); ?>" class="recipe-card-image<?php echo $hasRecipeImage ? '' : ' recipe-card-image--placeholder'; ?>">
          </a>

          <h2 class="recipe-card-title">
            <a href="recipe.php?id=<?php echo (int)$r['id_rcp']; ?>" class="recipe-card-link-reset"><?php echo h($r['title_rcp']); ?></a>
          </h2>

          <p class="recipe-card-copy"><?php echo h($r['description_rcp']); ?></p>

          <p class="recipe-card-meta">
            <?php if ($prep !== null): ?>Prep: <?php echo (int)$prep; ?>m<?php endif; ?>
            <?php if ($cook !== null): ?> | Cook: <?php echo (int)$cook; ?>m<?php endif; ?>
            <?php if ($prep !== null || $cook !== null): ?> | Total: <?php echo $total; ?>m<?php endif; ?>
          </p>

          <div class="recipe-card-actions">
            <a href="recipe.php?id=<?php echo (int)$r['id_rcp']; ?>" class="recipe-card-link">View recipe &rarr;</a>
            <a href="edit_recipe.php?id=<?php echo (int)$r['id_rcp']; ?>" class="recipe-card-link">Edit recipe &rarr;</a>
            <form method="POST" action="my_recipes.php" class="cv-inline-form" onsubmit="return confirm('Delete this recipe?');">
              <input type="hidden" name="recipe_id" value="<?php echo (int)$r['id_rcp']; ?>">
              <button type="submit" name="delete_recipe" value="1">Delete recipe</button>
            </form>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>

