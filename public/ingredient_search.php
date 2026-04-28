<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../private/db-connect.php';
require_once '../private/recipe-form-helpers.php';

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$q = preg_replace('/\s+/', ' ', $q);

if ($q === '' || strlen($q) < 1) {
    echo json_encode(['suggestions' => [], 'best_guess' => null]);
    exit();
}

$needleLower = mb_strtolower($q, 'UTF-8');

// 1) Prefix matches
$stmt = $pdo->prepare("
    SELECT id_ing, name_ing
    FROM ingredient_ing
    WHERE LOWER(name_ing) LIKE ?
    ORDER BY name_ing
    LIMIT 8
");
$stmt->execute([$needleLower . '%']);
$suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2) Contains matches (fill remaining)
if (count($suggestions) < 8) {
    $stmt = $pdo->prepare("
        SELECT id_ing, name_ing
        FROM ingredient_ing
        WHERE LOWER(name_ing) LIKE ?
        ORDER BY name_ing
        LIMIT 12
    ");
    $stmt->execute(['%' . $needleLower . '%']);
    $more = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $seen = [];
    foreach ($suggestions as $s) $seen[$s['id_ing']] = true;

    foreach ($more as $m) {
        if (!isset($seen[$m['id_ing']])) {
            $suggestions[] = $m;
            $seen[$m['id_ing']] = true;
        }
        if (count($suggestions) >= 8) break;
    }
}

// 3) Strict "Did you mean" suggestion (soundex + very close levenshtein)
$best_guess = null;

$norm = normalizeIngredientName($q);
$normLower = mb_strtolower($norm, 'UTF-8');
$normFirst = mb_substr($normLower, 0, 1, 'UTF-8');
$normLen = strlen($normLower);

if ($normLen >= 4) {

    $stmt = $pdo->prepare("
        SELECT id_ing, name_ing
        FROM ingredient_ing
        WHERE SOUNDEX(name_ing) = SOUNDEX(?)
        LIMIT 30
    ");
    $stmt->execute([$norm]);
    $cands = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $bestId = 0;
    $bestName = '';
    $bestDist = 999;

    foreach ($cands as $c) {
        $candName = (string)$c['name_ing'];
        $candLower = mb_strtolower($candName, 'UTF-8');
        $candFirst = mb_substr($candLower, 0, 1, 'UTF-8');

        // strict: must start with same first letter
        if ($candFirst !== $normFirst) continue;

        $dist = levenshtein($normLower, $candLower);
        if ($dist < $bestDist) {
            $bestDist = $dist;
            $bestId = (int)$c['id_ing'];
            $bestName = $candName;
        }
    }

    // strict: only if extremely close
    if ($bestId && $bestDist <= 1) {
        $best_guess = ['id_ing' => $bestId, 'name_ing' => $bestName];
    }
}

echo json_encode([
    'suggestions' => $suggestions,
    'best_guess' => $best_guess
]);
