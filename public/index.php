<?php
session_start();
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';


function fetchCategory(PDO $pdo, string $name, ?string $type = null): ?array {
    $sql = "
        SELECT c.id_cat, c.name_cat, t.name_cty
        FROM category_cat c
        JOIN category_type_cty t ON t.id_cty = c.id_cty_cat
        WHERE LOWER(c.name_cat) = LOWER(?)
    ";
    $params = [$name];

    if ($type !== null) {
        $sql .= " AND LOWER(t.name_cty) = LOWER(?) ";
        $params[] = $type;
    }

    $sql .= " LIMIT 1 ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function fetchWeeklyHomepageCategories(PDO $pdo, int $count = 4): array {
    $stmt = $pdo->query("
        SELECT
            c.id_cat,
            c.name_cat,
            t.name_cty
        FROM category_cat c
        JOIN category_type_cty t ON t.id_cty = c.id_cty_cat
        WHERE t.name_cty IN ('cuisine', 'protein')
          AND EXISTS (
              SELECT 1
              FROM recipe_category_rcpcat rc
              JOIN recipe_rcp r ON r.id_rcp = rc.id_rcp_rcpcat
              WHERE rc.id_cat_rcpcat = c.id_cat
                AND r.is_active_rcp = 1
          )
        ORDER BY t.name_cty, c.name_cat
    ");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$categories) {
        return [];
    }

    $seed = (int)date('oW');
    usort($categories, static function (array $a, array $b) use ($seed): int {
        $hashA = sprintf('%u', crc32($seed . '|' . $a['name_cty'] . '|' . $a['id_cat']));
        $hashB = sprintf('%u', crc32($seed . '|' . $b['name_cty'] . '|' . $b['id_cat']));

        if ($hashA === $hashB) {
            return strcmp($a['name_cat'], $b['name_cat']);
        }

        return $hashA <=> $hashB;
    });

    return array_slice($categories, 0, max(1, $count));
}

function fetchRecipes(PDO $pdo, array $options = []): array {
    $limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 12;
    $excludeIds = $options['exclude_ids'] ?? [];
    $categoryName = $options['category_name'] ?? null;
    $categoryType = $options['category_type'] ?? null;
    $keywords = $options['keywords'] ?? [];
    $popular = !empty($options['popular']);

    $sql = "
        SELECT DISTINCT
            r.id_rcp,
            r.title_rcp,
            r.description_rcp,
            r.prep_time_minutes_rcp,
            r.cook_time_minutes_rcp,
            r.created_at_rcp,
            img.image_path_img
    ";

    if ($popular) {
        $sql .= ",
            COALESCE(rr.rating_count, 0) AS rating_count,
            COALESCE(rr.avg_rating, 0) AS avg_rating,
            (
                (COALESCE(rr.avg_rating, 0) * COALESCE(rr.rating_count, 0))
                /
                (COALESCE(rr.rating_count, 0) + 3)
            ) AS popularity_score
        ";
    }

    $sql .= "
        FROM recipe_rcp r
        LEFT JOIN (
            SELECT ri.id_rcp_img, MIN(ri.image_path_img) AS image_path_img
            FROM recipe_image_img ri
            GROUP BY ri.id_rcp_img
        ) img ON img.id_rcp_img = r.id_rcp
    ";

    if ($popular) {
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

    $sql .= " WHERE r.is_active_rcp = 1 ";
    $params = [];

    if ($popular) {
        $sql .= " AND COALESCE(rr.rating_count, 0) > 0 ";
    }

    if (!empty($excludeIds)) {
        $excludeIds = array_values(array_unique(array_map('intval', $excludeIds)));
        $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
        $sql .= " AND r.id_rcp NOT IN ($placeholders) ";
        $params = array_merge($params, $excludeIds);
    }

    if ($categoryName !== null) {
        $sql .= "
            AND EXISTS (
                SELECT 1
                FROM recipe_category_rcpcat rc
                JOIN category_cat c ON c.id_cat = rc.id_cat_rcpcat
                JOIN category_type_cty t ON t.id_cty = c.id_cty_cat
                WHERE rc.id_rcp_rcpcat = r.id_rcp
                  AND LOWER(c.name_cat) = LOWER(?)
        ";
        $params[] = $categoryName;

        if ($categoryType !== null) {
            $sql .= " AND LOWER(t.name_cty) = LOWER(?) ";
            $params[] = $categoryType;
        }

        $sql .= "
            )
        ";
    }

    if (!empty($keywords)) {
        $keywordParts = [];

        foreach ($keywords as $keyword) {
            $keywordParts[] = "(
                r.title_rcp LIKE ?
                OR r.description_rcp LIKE ?
            )";
            $like = '%' . $keyword . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $sql .= " AND (" . implode(' OR ', $keywordParts) . ") ";
    }

    if ($popular) {
        $sql .= "
            ORDER BY
                popularity_score DESC,
                COALESCE(rr.avg_rating, 0) DESC,
                COALESCE(rr.rating_count, 0) DESC,
                r.created_at_rcp DESC
        ";
    } else {
        $sql .= " ORDER BY r.created_at_rcp DESC ";
    }

    $sql .= " LIMIT " . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function addRecipeIds(array &$ids, array $recipes): void {
    foreach ($recipes as $recipe) {
        $ids[] = (int)$recipe['id_rcp'];
    }

    $ids = array_values(array_unique($ids));
}

function renderRecipeGrid(array $recipes, ?string $badge = null): void {
    $recipePlaceholderImage = 'assets/images/noimg.png';
    ?>
    <div class="recipe-carousel-shell">
        <button type="button" class="recipe-carousel-btn recipe-carousel-btn-prev" data-direction="prev" aria-label="Scroll recipes left">
            <span aria-hidden="true">&larr;</span>
        </button>

        <div class="recipe-carousel" aria-label="Recipe carousel" tabindex="0">
            <?php foreach ($recipes as $r): ?>
                <?php
                    $prep = $r['prep_time_minutes_rcp'];
                    $cook = $r['cook_time_minutes_rcp'];
                    $total = (int)($prep ?? 0) + (int)($cook ?? 0);
                    $recipeHref = 'recipe.php?id=' . (int)$r['id_rcp'];
                    if (!empty($r['recommended_servings'])) {
                        $recipeHref .= '&servings=' . (int)$r['recommended_servings'];
                    }
                ?>
                <?php $cardImagePath = !empty($r['image_path_img']) ? (string)$r['image_path_img'] : $recipePlaceholderImage; ?>
                <article class="recipe-card recipe-carousel-card">
                    <a href="<?php echo h($recipeHref); ?>" class="recipe-card-image-link">
                        <img src="<?php echo h($cardImagePath); ?>" alt="<?php echo h($r['title_rcp']); ?>" class="recipe-card-image">
                    </a>

                    <?php
                        $cardBadge = $badge;
                        if (!empty($r['recommended_servings'])) {
                            $cardBadge = 'Can make ' . (int)$r['recommended_servings'] . ' serving' . ((int)$r['recommended_servings'] === 1 ? '' : 's');
                        }
                    ?>
                    <?php if ($cardBadge !== null): ?>
                        <p class="index-recipe-badge"><?php echo h($cardBadge); ?></p>
                    <?php endif; ?>

                    <h3 class="index-recipe-title">
                        <a href="<?php echo h($recipeHref); ?>" class="index-link-reset">
                            <?php echo h($r['title_rcp']); ?>
                        </a>
                    </h3>

                    <p class="index-recipe-copy"><?php echo h($r['description_rcp']); ?></p>

                    <p class="index-recipe-meta">
                        <?php if ($prep !== null): ?>Prep: <?php echo (int)$prep; ?>m<?php endif; ?>
                        <?php if ($cook !== null): ?> | Cook: <?php echo (int)$cook; ?>m<?php endif; ?>
                        <?php if ($prep !== null || $cook !== null): ?> | Total: <?php echo $total; ?>m<?php endif; ?>
                    </p>

                    <a href="<?php echo h($recipeHref); ?>" class="index-cta-link">
                        View recipe &rarr;
                    </a>
                </article>
            <?php endforeach; ?>
        </div>

        <button type="button" class="recipe-carousel-btn recipe-carousel-btn-next" data-direction="next" aria-label="Scroll recipes right">
            <span aria-hidden="true">&rarr;</span>
        </button>
    </div>
    <?php
}

function renderRecipeSection(string $title, string $description, array $recipes, string $emptyMessage, ?string $linkHref = null, ?string $linkLabel = null, ?string $badge = null): void {
    ?>
    <section class="index-section">
        <div class="index-section-head">
            <div>
                <h2 class="index-section-title"><?php echo h($title); ?></h2>
                <p class="index-section-copy"><?php echo h($description); ?></p>
            </div>

            <?php if ($linkHref !== null && $linkLabel !== null): ?>
                <a href="<?php echo h($linkHref); ?>" class="index-cta-link"><?php echo h($linkLabel); ?> &rarr;</a>
            <?php endif; ?>
        </div>

        <?php if (!$recipes): ?>
            <article class="index-empty-card">
                <p class="index-empty-copy"><?php echo h($emptyMessage); ?></p>
            </article>
        <?php else: ?>
            <?php renderRecipeGrid($recipes, $badge); ?>
        <?php endif; ?>
    </section>
    <?php
}

$isLoggedIn = isset($_SESSION['user_id']);
$userId = $isLoggedIn ? (int)$_SESSION['user_id'] : 0;

$recommendedRecipes = [];
$recommendedIds = [];
$latestRecipes = [];
$pantryItemCount = 0;
$displayedRecipeIds = [];
$hasServingsColumn = ensureRecipeServingsColumn($pdo);

/* Pantry-based recommendations */
if ($isLoggedIn) {
    $stmt = $pdo->prepare(" 
        SELECT COUNT(*) AS pantry_count
        FROM pantry_item_pan
        WHERE id_usr_pan = ?
    ");
    $stmt->execute([$userId]);
    $pantryItemCount = (int)$stmt->fetchColumn();

    if ($pantryItemCount > 0) {
        $sql = "
            SELECT
                r.id_rcp,
                r.title_rcp,
                r.description_rcp,
                r.prep_time_minutes_rcp,
                r.cook_time_minutes_rcp,
                r.created_at_rcp,
                img.image_path_img,
                " . ($hasServingsColumn ? "COALESCE(NULLIF(r.servings_rcp, 0), 1)" : "1") . " AS base_servings,
                COUNT(*) AS total_ingredients,
                MIN(
                    CASE
                        WHEN pt.pantry_base_qty IS NULL
                             OR pt.pantry_base_qty <= 0
                             OR ru.conversion_to_base_uni IS NULL
                             OR ru.conversion_to_base_uni <= 0
                             OR ri.quantity_rcping IS NULL
                             OR ri.quantity_rcping <= 0
                        THEN 0
                        ELSE FLOOR(
                            pt.pantry_base_qty
                            /
                            (
                                (ri.quantity_rcping * ru.conversion_to_base_uni)
                                /
                                (" . ($hasServingsColumn ? "COALESCE(NULLIF(r.servings_rcp, 0), 1)" : "1") . ")
                            )
                        )
                    END
                ) AS makeable_servings
            FROM recipe_rcp r
            LEFT JOIN (
                SELECT ri_img.id_rcp_img, MIN(ri_img.image_path_img) AS image_path_img
                FROM recipe_image_img ri_img
                GROUP BY ri_img.id_rcp_img
            ) img
                ON img.id_rcp_img = r.id_rcp
            INNER JOIN recipe_ingredient_rcping ri
                ON ri.id_rcp_rcping = r.id_rcp
            INNER JOIN unit_uni ru
                ON ru.id_uni = ri.id_uni_rcping
            LEFT JOIN (
                SELECT
                    p.id_ing_pan,
                    u.unit_type_uni,
                    u.base_unit_uni,
                    SUM(p.quantity_pan * u.conversion_to_base_uni) AS pantry_base_qty
                FROM pantry_item_pan p
                INNER JOIN unit_uni u
                    ON u.id_uni = p.id_uni_pan
                WHERE p.id_usr_pan = ?
                GROUP BY
                    p.id_ing_pan,
                    u.unit_type_uni,
                    u.base_unit_uni
            ) pt
                ON pt.id_ing_pan = ri.id_ing_rcping
               AND pt.unit_type_uni = ru.unit_type_uni
               AND pt.base_unit_uni = ru.base_unit_uni
            WHERE r.is_active_rcp = 1
              AND ru.conversion_to_base_uni IS NOT NULL
              AND ru.base_unit_uni IS NOT NULL
              AND ru.unit_type_uni IS NOT NULL
            GROUP BY
                r.id_rcp,
                r.title_rcp,
                r.description_rcp,
                r.prep_time_minutes_rcp,
                r.cook_time_minutes_rcp,
                r.created_at_rcp,
                img.image_path_img" . ($hasServingsColumn ? ",
                r.servings_rcp" : "") . "
            HAVING total_ingredients > 0
               AND makeable_servings >= 1
            ORDER BY
                makeable_servings DESC,
                total_ingredients DESC,
                r.created_at_rcp DESC
            LIMIT 12
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $recommendedRecipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($recommendedRecipes as &$recipe) {
            $recommendedServings = max(1, (int)($recipe['makeable_servings'] ?? 1));
            $baseServings = max(1, (int)($recipe['base_servings'] ?? 1));
            $recipe['recommended_servings'] = min($recommendedServings, $baseServings);
        }
        unset($recipe);

        foreach ($recommendedRecipes as $recipe) {
            $recommendedIds[] = (int)$recipe['id_rcp'];
        }
    }
}

addRecipeIds($displayedRecipeIds, $recommendedRecipes);

$latestRecipes = fetchRecipes($pdo, [
    'limit' => 12,
    'exclude_ids' => $displayedRecipeIds,
]);
addRecipeIds($displayedRecipeIds, $latestRecipes);

$popularRecipes = fetchRecipes($pdo, [
    'limit' => 12,
    'popular' => true,
]);
addRecipeIds($displayedRecipeIds, $popularRecipes);
$weeklyCategories = fetchWeeklyHomepageCategories($pdo, 4);
$weeklySections = [];

foreach ($weeklyCategories as $category) {
    $sectionRecipes = fetchRecipes($pdo, [
        'limit' => 12,
        'category_name' => $category['name_cat'],
        'category_type' => $category['name_cty'],
    ]);

    addRecipeIds($displayedRecipeIds, $sectionRecipes);

    $isCuisine = strtolower((string)$category['name_cty']) === 'cuisine';
    $weeklySections[] = [
        'title' => $category['name_cat'],
        'description' => $isCuisine
            ? 'A weekly featured cuisine pulled from our recipe collection.'
            : 'A weekly featured protein pulled from our recipe collection.',
        'recipes' => $sectionRecipes,
        'empty' => 'No recipes have been added for this category yet.',
        'href' => $isCuisine
            ? 'recipes.php?cuisine=' . (int)$category['id_cat']
            : 'recipes.php?protein=' . (int)$category['id_cat'],
        'linkLabel' => $isCuisine
            ? 'Browse this cuisine'
            : 'Browse this protein'
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cookventory</title>
    <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<main class="page index-page">
    <section class="index-hero">
        <h1 class="index-hero-title">Cookventory</h1>
        <p class="index-hero-copy">
            Discover recipes, track your pantry, and find meals you can make with what you already have.
        </p>
    </section>

    <?php if ($isLoggedIn): ?>
        <section class="index-section">
            <div class="index-section-head index-section-head-tight">
                <div>
                    <h2 class="index-section-title">Recommended Based on Your Pantry</h2>
                    <p class="index-section-copy">
                        <?php if ($pantryItemCount > 0): ?>
                            These recipes are fully covered by the ingredients currently in your pantry.
                        <?php else: ?>
                            Add pantry items to start getting personalized recipe recommendations.
                        <?php endif; ?>
                    </p>
                </div>

                <?php if ($pantryItemCount > 0): ?>
                    <a href="pantry.php" class="index-cta-link">Manage pantry &rarr;</a>
                <?php endif; ?>
            </div>

            <div class="index-stack-top">
                <?php if ($pantryItemCount === 0): ?>
                    <article class="index-empty-card">
                        <p class="index-recipe-copy">Your pantry is empty right now.</p>
                        <a href="pantry.php" class="index-cta-link">Add pantry items &rarr;</a>
                    </article>
                <?php elseif (!$recommendedRecipes): ?>
                    <article class="index-empty-card">
                        <p class="index-recipe-copy">
                            No full matches yet. Add more pantry items or create recipes using the same units as your pantry items.
                        </p>
                        <a href="pantry.php" class="index-cta-link">Update pantry &rarr;</a>
                    </article>
                <?php else: ?>
                    <?php renderRecipeGrid($recommendedRecipes, 'Pantry Match'); ?>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php
        renderRecipeSection(
            'Latest Recipes',
            'Browse the newest recipes added to Cookventory.',
            $latestRecipes,
            'No recipes have been added yet.',
            'recipes.php',
            'Browse all recipes'
        );

        renderRecipeSection(
            'Popular',
            'See the recipes people are rating the highest right now.',
            $popularRecipes,
            'No popular recipes to show yet.',
            'recipes.php',
            'See more recipes'
        );

        foreach ($weeklySections as $section) {
            renderRecipeSection(
                $section['title'],
                $section['description'],
                $section['recipes'],
                $section['empty'],
                $section['href'],
                $section['linkLabel']
            );
        }
    ?>
</main>

<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>




