<?php
session_start();
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';
require_once '../private/recipe-saves.php';
require_once '../private/role-helpers.php';

ensureRoleLevels($pdo);

$recipePlaceholderImage = 'assets/images/noimg.png';

function parseIntList($value): array {
    $values = is_array($value) ? $value : [$value];
    $ids = [];

    foreach ($values as $item) {
        if (ctype_digit((string)$item)) {
            $ids[] = (int)$item;
        }
    }

    return array_values(array_unique(array_filter($ids, static fn($id) => $id > 0)));
}

function buildFilterSummaryLabel(array $selectedNames, string $defaultLabel, string $pluralLabel): string {
    if (!$selectedNames) {
        return $defaultLabel;
    }

    if (count($selectedNames) <= 2) {
        return implode(', ', $selectedNames);
    }

    return count($selectedNames) . ' ' . $pluralLabel . ' selected';
}

function getCategoriesByType(PDO $pdo, string $type): array {
    $stmt = $pdo->prepare("
        SELECT MIN(c.id_cat) AS id_cat, c.name_cat
        FROM category_cat c
        JOIN category_type_cty t ON c.id_cty_cat = t.id_cty
        WHERE t.name_cty = ?
        GROUP BY LOWER(TRIM(c.name_cat)), c.name_cat
        ORDER BY c.name_cat
    ");
    $stmt->execute([$type]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unique = [];
    $seen = [];
    foreach ($rows as $row) {
        $key = mb_strtolower(trim((string)$row['name_cat']), 'UTF-8');
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $unique[] = $row;
    }

    return $unique;
}

$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLoggedIn) {
    $recipeId = isset($_POST['recipe_id']) && ctype_digit((string)$_POST['recipe_id']) ? (int)$_POST['recipe_id'] : 0;
    if ($recipeId > 0) {
        if (isset($_POST['save_recipe'])) {
            saveRecipeForUser($pdo, $userId, $recipeId);
        } elseif (isset($_POST['unsave_recipe'])) {
            unsaveRecipeForUser($pdo, $userId, $recipeId);
        } elseif (isset($_POST['admin_delete_recipe']) && isAdminUser()) {
            $stmt = $pdo->prepare('SELECT id_usr_rcp FROM recipe_rcp WHERE id_rcp = ? AND is_active_rcp = 1 LIMIT 1');
            $stmt->execute([$recipeId]);
            $ownerId = (int)$stmt->fetchColumn();

            if ($ownerId > 0) {
                $ownerRole = getUserPrimaryRole($pdo, $ownerId);
                $canDeleteRecipe = $ownerId !== $userId && (isSuperAdminUser() || $ownerRole === 'user');

                if ($canDeleteRecipe) {
                    $stmt = $pdo->prepare('UPDATE recipe_rcp SET is_active_rcp = 0 WHERE id_rcp = ?');
                    $stmt->execute([$recipeId]);
                }
            }
        }
    }

    $redirect = 'recipes.php';
    $query = $_SERVER['QUERY_STRING'] ?? '';
    if ($query !== '') {
        $redirect .= '?' . $query;
    }
    header('Location: ' . $redirect);
    exit();
}

$cuisines = getCategoriesByType($pdo, 'cuisine');
$proteins = getCategoriesByType($pdo, 'protein');
$diets = getCategoriesByType($pdo, 'diet');
$courses = getCategoriesByType($pdo, 'course');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$cuisine_ids = parseIntList($_GET['cuisine'] ?? []);
$cuisine_name = isset($_GET['cuisine_name']) ? trim((string)$_GET['cuisine_name']) : '';
$protein_ids = parseIntList($_GET['protein'] ?? []);
$diet_ids = parseIntList($_GET['diet'] ?? []);
$course_ids = parseIntList($_GET['course'] ?? []);
$course_name = isset($_GET['course_name']) ? trim((string)$_GET['course_name']) : '';
$quick = (isset($_GET['quick']) && $_GET['quick'] === '1') ? 1 : 0;
$sort = isset($_GET['sort']) ? trim((string)$_GET['sort']) : '';
if (!in_array($sort, ['time_asc', 'popularity_desc'], true)) {
    $sort = '';
}

if (!$cuisine_ids && $cuisine_name !== '') {
    foreach ($cuisines as $cuisineOption) {
        if (mb_strtolower((string)$cuisineOption['name_cat'], 'UTF-8') === mb_strtolower($cuisine_name, 'UTF-8')) {
            $cuisine_ids = [(int)$cuisineOption['id_cat']];
            break;
        }
    }
}

if (!$course_ids && $course_name !== '') {
    foreach ($courses as $courseOption) {
        if (mb_strtolower((string)$courseOption['name_cat'], 'UTF-8') === mb_strtolower($course_name, 'UTF-8')) {
            $course_ids = [(int)$courseOption['id_cat']];
            break;
        }
    }
}

$sql = "
SELECT DISTINCT
    r.id_rcp,
    r.id_usr_rcp,
    r.title_rcp,
    r.description_rcp,
    r.prep_time_minutes_rcp,
    r.cook_time_minutes_rcp,
    r.created_at_rcp,
    img.image_path_img
FROM recipe_rcp r
LEFT JOIN (
    SELECT ri.id_rcp_img, MIN(ri.image_path_img) AS image_path_img
    FROM recipe_image_img ri
    GROUP BY ri.id_rcp_img
) img ON img.id_rcp_img = r.id_rcp
";

if ($sort === 'popularity_desc') {
    $sql .= "
LEFT JOIN (
    SELECT
        id_rcp_rat,
        COUNT(*) AS rating_count,
        AVG(rating_value_rat) AS avg_rating
    FROM rating_rat
    GROUP BY id_rcp_rat
) rr ON rr.id_rcp_rat = r.id_rcp
";
}

$params = [];

if ($cuisine_ids) $sql .= " JOIN recipe_category_rcpcat rc_cui ON rc_cui.id_rcp_rcpcat = r.id_rcp ";
if ($protein_ids) $sql .= " JOIN recipe_category_rcpcat rc_pro ON rc_pro.id_rcp_rcpcat = r.id_rcp ";
if ($diet_ids) $sql .= " JOIN recipe_category_rcpcat rc_diet ON rc_diet.id_rcp_rcpcat = r.id_rcp ";
if ($course_ids) $sql .= " JOIN recipe_category_rcpcat rc_course ON rc_course.id_rcp_rcpcat = r.id_rcp ";

$sql .= " WHERE r.is_active_rcp = 1 ";

if ($cuisine_ids) {
    $placeholders = implode(',', array_fill(0, count($cuisine_ids), '?'));
    $sql .= " AND rc_cui.id_cat_rcpcat IN ($placeholders) ";
    $params = array_merge($params, $cuisine_ids);
}
if ($protein_ids) {
    $placeholders = implode(',', array_fill(0, count($protein_ids), '?'));
    $sql .= " AND rc_pro.id_cat_rcpcat IN ($placeholders) ";
    $params = array_merge($params, $protein_ids);
}
if ($diet_ids) {
    $placeholders = implode(',', array_fill(0, count($diet_ids), '?'));
    $sql .= " AND rc_diet.id_cat_rcpcat IN ($placeholders) ";
    $params = array_merge($params, $diet_ids);
}
if ($course_ids) {
    $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
    $sql .= " AND rc_course.id_cat_rcpcat IN ($placeholders) ";
    $params = array_merge($params, $course_ids);
}

if ($q !== '') {
    $sql .= " AND (r.title_rcp LIKE ? OR r.description_rcp LIKE ?) ";
    $params[] = "%$q%";
    $params[] = "%$q%";
}

if ($quick === 1) {
    $sql .= " AND (COALESCE(r.prep_time_minutes_rcp,0) + COALESCE(r.cook_time_minutes_rcp,0)) <= 30 ";
}

if ($sort === 'time_asc') {
    $sql .= " ORDER BY (COALESCE(r.prep_time_minutes_rcp,0) + COALESCE(r.cook_time_minutes_rcp,0)) ASC, r.created_at_rcp DESC ";
} elseif ($sort === 'popularity_desc') {
    $sql .= " ORDER BY ((COALESCE(rr.avg_rating, 0) * COALESCE(rr.rating_count, 0)) / (COALESCE(rr.rating_count, 0) + 3)) DESC, COALESCE(rr.avg_rating, 0) DESC, COALESCE(rr.rating_count, 0) DESC, r.created_at_rcp DESC ";
} else {
    $sql .= " ORDER BY r.created_at_rcp DESC ";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$savedRecipeIds = $isLoggedIn ? getSavedRecipeIdsForUser($pdo, $userId) : [];
$savedRecipeMap = array_fill_keys($savedRecipeIds, true);
$selectedCuisineNames = [];
foreach ($cuisines as $option) {
    if (in_array((int)$option['id_cat'], $cuisine_ids, true)) {
        $selectedCuisineNames[] = (string)$option['name_cat'];
    }
}
$selectedProteinNames = [];
foreach ($proteins as $option) {
    if (in_array((int)$option['id_cat'], $protein_ids, true)) {
        $selectedProteinNames[] = (string)$option['name_cat'];
    }
}
$selectedDietNames = [];
foreach ($diets as $option) {
    if (in_array((int)$option['id_cat'], $diet_ids, true)) {
        $selectedDietNames[] = (string)$option['name_cat'];
    }
}
$selectedCourseNames = [];
foreach ($courses as $option) {
    if (in_array((int)$option['id_cat'], $course_ids, true)) {
        $selectedCourseNames[] = (string)$option['name_cat'];
    }
}

$cuisineSummaryLabel = buildFilterSummaryLabel($selectedCuisineNames, 'All cuisines', 'cuisines');
$proteinSummaryLabel = buildFilterSummaryLabel($selectedProteinNames, 'All proteins', 'proteins');
$dietSummaryLabel = buildFilterSummaryLabel($selectedDietNames, 'All diets', 'diets');
$courseSummaryLabel = buildFilterSummaryLabel($selectedCourseNames, 'All courses', 'courses');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Recipes - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main class="cv-page recipes-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">Recipes</h1>
    <p class="cv-page-subtitle">Browse the collection, filter by tags, and find a recipe that matches your time and ingredients.</p>
  </header>

  <form method="GET" action="recipes.php" class="recipes-filter-form cv-card cv-panel">
    <div class="recipes-filter-row recipes-filter-row-search">
      <input type="search" name="q" placeholder="Search recipes..." value="<?php echo h($q); ?>">
    </div>

    <div class="recipes-filter-row recipes-filter-row-dropdowns">
      <details class="recipes-filter-dropdown">
        <summary><?php echo h($cuisineSummaryLabel); ?></summary>
        <div class="recipes-filter-checklist">
          <?php foreach ($cuisines as $c): ?>
            <label class="recipes-filter-check">
              <input type="checkbox" name="cuisine[]" value="<?php echo (int)$c['id_cat']; ?>" <?php echo in_array((int)$c['id_cat'], $cuisine_ids, true) ? 'checked' : ''; ?>>
              <span><?php echo h($c['name_cat']); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </details>

      <details class="recipes-filter-dropdown">
        <summary><?php echo h($proteinSummaryLabel); ?></summary>
        <div class="recipes-filter-checklist">
          <?php foreach ($proteins as $p): ?>
            <label class="recipes-filter-check">
              <input type="checkbox" name="protein[]" value="<?php echo (int)$p['id_cat']; ?>" <?php echo in_array((int)$p['id_cat'], $protein_ids, true) ? 'checked' : ''; ?>>
              <span><?php echo h($p['name_cat']); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </details>

      <details class="recipes-filter-dropdown">
        <summary><?php echo h($dietSummaryLabel); ?></summary>
        <div class="recipes-filter-checklist">
          <?php foreach ($diets as $d): ?>
            <label class="recipes-filter-check">
              <input type="checkbox" name="diet[]" value="<?php echo (int)$d['id_cat']; ?>" <?php echo in_array((int)$d['id_cat'], $diet_ids, true) ? 'checked' : ''; ?>>
              <span><?php echo h($d['name_cat']); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </details>

      <details class="recipes-filter-dropdown">
        <summary><?php echo h($courseSummaryLabel); ?></summary>
        <div class="recipes-filter-checklist">
          <?php foreach ($courses as $c): ?>
            <label class="recipes-filter-check">
              <input type="checkbox" name="course[]" value="<?php echo (int)$c['id_cat']; ?>" <?php echo in_array((int)$c['id_cat'], $course_ids, true) ? 'checked' : ''; ?>>
              <span><?php echo h($c['name_cat']); ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </details>
    </div>

    <div class="recipes-filter-row recipes-filter-row-actions">
      <label class="recipes-sort-label">
        <span>Sort by</span>
        <select name="sort">
          <option value="">Newest first</option>
          <option value="time_asc" <?php echo $sort === 'time_asc' ? 'selected' : ''; ?>>Cook Time</option>
          <option value="popularity_desc" <?php echo $sort === 'popularity_desc' ? 'selected' : ''; ?>>Most popular</option>
        </select>
      </label>

      <button type="submit">Apply</button>
      <a href="recipes.php" class="recipes-reset-link">Reset</a>
    </div>
  </form>

  <?php if (!$recipes): ?>
    <article class="cv-card cv-panel">
      <p class="cv-empty-text">No recipes found.</p>
    </article>
  <?php else: ?>
    <div class="recipes-list">
      <?php foreach ($recipes as $r): ?>
        <?php
          $prep = $r['prep_time_minutes_rcp'];
          $cook = $r['cook_time_minutes_rcp'];
          $total = (int)($prep ?? 0) + (int)($cook ?? 0);
          $isSaved = isset($savedRecipeMap[(int)$r['id_rcp']]);
          $isOwner = $isLoggedIn && (int)$r['id_usr_rcp'] === $userId;
          $ownerRole = getUserPrimaryRole($pdo, (int)$r['id_usr_rcp']);
          $canAdminDeleteRecipe = $isLoggedIn && isAdminUser() && !$isOwner && (isSuperAdminUser() || $ownerRole === 'user');
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

            <?php if ($isOwner): ?>
              <a href="edit_recipe.php?id=<?php echo (int)$r['id_rcp']; ?>" class="recipe-card-link">Edit recipe &rarr;</a>
            <?php endif; ?>

            <?php if ($isLoggedIn): ?>
              <form method="POST" action="recipes.php?<?php echo h(http_build_query($_GET)); ?>" class="cv-inline-form">
                <input type="hidden" name="recipe_id" value="<?php echo (int)$r['id_rcp']; ?>">
                <?php if ($isSaved): ?>
                  <button type="submit" name="unsave_recipe" value="1" class="recipe-save-button recipe-save-button--saved">Saved</button>
                <?php else: ?>
                  <button type="submit" name="save_recipe" value="1" class="recipe-save-button">Save recipe</button>
                <?php endif; ?>
              </form>
            <?php endif; ?>

            <?php if ($canAdminDeleteRecipe): ?>
              <form method="POST" action="recipes.php?<?php echo h(http_build_query($_GET)); ?>" class="cv-inline-form" onsubmit="return confirm('Remove this recipe from Cookventory?');">
                <input type="hidden" name="recipe_id" value="<?php echo (int)$r['id_rcp']; ?>">
                <button type="submit" name="admin_delete_recipe" value="1">Delete recipe</button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>






