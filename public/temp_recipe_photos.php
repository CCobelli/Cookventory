<?php
require_once __DIR__ . '/includes/auth-check.php';
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';
require_once '../private/recipe-form-helpers.php';

$recipePlaceholderImage = 'assets/images/noimg.png';

function setTempPhotoFlash(string $message, string $type = 'success'): void {
    $_SESSION['temp_recipe_photos_flash'] = [
        'message' => $message,
        'type' => $type,
    ];
}

function consumeTempPhotoFlash(): ?array {
    if (!isset($_SESSION['temp_recipe_photos_flash']) || !is_array($_SESSION['temp_recipe_photos_flash'])) {
        return null;
    }

    $flash = $_SESSION['temp_recipe_photos_flash'];
    unset($_SESSION['temp_recipe_photos_flash']);
    return $flash;
}

function redirectToTempRecipePhotos(): void {
    header('Location: temp_recipe_photos.php');
    exit();
}

$userId = (int)$_SESSION['user_id'];
$flash = consumeTempPhotoFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_recipe_photo'])) {
    $recipeId = isset($_POST['recipe_id']) && ctype_digit((string)$_POST['recipe_id']) ? (int)$_POST['recipe_id'] : 0;

    if ($recipeId <= 0) {
        setTempPhotoFlash('Invalid recipe selected.', 'error');
        redirectToTempRecipePhotos();
    }

    $stmt = $pdo->prepare('SELECT id_rcp, title_rcp FROM recipe_rcp WHERE id_rcp = ? AND id_usr_rcp = ? AND is_active_rcp = 1 LIMIT 1');
    $stmt->execute([$recipeId, $userId]);
    $recipe = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recipe) {
        setTempPhotoFlash('You can only update photos for your own active recipes.', 'error');
        redirectToTempRecipePhotos();
    }

    try {
        $photoPath = saveRecipePhotoUpload($_FILES['recipe_photo'] ?? [], $recipeId);
        if ($photoPath === null) {
            throw new RuntimeException('Choose a photo before uploading.');
        }

        $stmt = $pdo->prepare('SELECT id_img, image_path_img FROM recipe_image_img WHERE id_rcp_img = ? ORDER BY id_img ASC LIMIT 1');
        $stmt->execute([$recipeId]);
        $existingImage = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($existingImage && !empty($existingImage['image_path_img'])) {
            $oldFile = __DIR__ . '/' . ltrim((string)$existingImage['image_path_img'], '/');
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }

            $stmt = $pdo->prepare('UPDATE recipe_image_img SET image_path_img = ? WHERE id_img = ?');
            $stmt->execute([$photoPath, (int)$existingImage['id_img']]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO recipe_image_img (id_rcp_img, image_path_img) VALUES (?, ?)');
            $stmt->execute([$recipeId, $photoPath]);
        }

        setTempPhotoFlash('Photo updated for "' . $recipe['title_rcp'] . '".');
    } catch (Throwable $e) {
        setTempPhotoFlash($e->getMessage(), 'error');
    }

    redirectToTempRecipePhotos();
}

$stmt = $pdo->prepare("
    SELECT
        r.id_rcp,
        r.title_rcp,
        r.description_rcp,
        r.created_at_rcp,
        img.image_path_img
    FROM recipe_rcp r
    LEFT JOIN (
        SELECT id_rcp_img, MIN(image_path_img) AS image_path_img
        FROM recipe_image_img
        GROUP BY id_rcp_img
    ) img ON img.id_rcp_img = r.id_rcp
    WHERE r.id_usr_rcp = ?
      AND r.is_active_rcp = 1
    ORDER BY r.created_at_rcp DESC, r.title_rcp ASC
");
$stmt->execute([$userId]);
$recipes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Temp Recipe Photos - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main class="cv-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">Temporary Recipe Photo Uploader</h1>
    <p class="cv-page-subtitle">Quick utility page for uploading or replacing photos on your recipes.</p>
  </header>

  <?php if ($flash): ?>
    <div class="cv-alert <?php echo ($flash['type'] ?? 'success') === 'error' ? 'cv-alert--error' : 'cv-alert--success'; ?>">
      <p><?php echo h($flash['message'] ?? ''); ?></p>
    </div>
  <?php endif; ?>

  <?php if (!$recipes): ?>
    <section class="cv-card cv-panel">
      <p class="cv-empty-text">You do not have any active recipes yet.</p>
    </section>
  <?php else: ?>
    <section class="cv-stack-lg">
      <?php foreach ($recipes as $recipe): ?>
        <article class="cv-card cv-panel cv-stack-md">
          <div class="cv-actions">
            <div>
              <h2 class="cv-card-title"><?php echo h($recipe['title_rcp']); ?></h2>
              <p class="cv-muted"><?php echo h($recipe['created_at_rcp']); ?></p>
            </div>
            <a href="recipe.php?id=<?php echo (int)$recipe['id_rcp']; ?>" class="cv-button">View Recipe</a>
          </div>

          <?php if (!empty($recipe['description_rcp'])): ?>
            <p class="recipe-card-copy"><?php echo h($recipe['description_rcp']); ?></p>
          <?php endif; ?>

          <img
            src="<?php echo h(!empty($recipe['image_path_img']) ? (string)$recipe['image_path_img'] : $recipePlaceholderImage); ?>"
            alt="<?php echo h($recipe['title_rcp']); ?>"
            class="create-recipe-image-preview"
          >

          <form method="POST" action="" enctype="multipart/form-data" class="cv-actions">
            <input type="hidden" name="recipe_id" value="<?php echo (int)$recipe['id_rcp']; ?>">
            <input type="file" name="recipe_photo" accept="image/jpeg,image/png,image/gif,image/webp" required>
            <button type="submit" name="upload_recipe_photo" value="1">Add Photo</button>
          </form>
        </article>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>
