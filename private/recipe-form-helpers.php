<?php

function saveRecipePhotoUpload(array $file, int $recipeId): ?string {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed.');
    }

    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Photo must be 5MB or smaller.');
    }

    $tmpName = $file['tmp_name'] ?? '';
    $imageInfo = @getimagesize($tmpName);
    if (!$imageInfo || empty($imageInfo['mime'])) {
        throw new RuntimeException('Uploaded file must be an image.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $mime = $imageInfo['mime'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, GIF, and WEBP images are allowed.');
    }

    $uploadDir = dirname(__DIR__) . '/public/uploads/recipes';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Could not create recipe upload folder.');
    }

    $fileName = 'recipe_' . $recipeId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $destination = $uploadDir . '/' . $fileName;

    if ($mime === 'image/gif' || !function_exists('imagecreatefromstring')) {
        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Could not save uploaded photo.');
        }
        return 'uploads/recipes/' . $fileName;
    }

    $imageData = @file_get_contents($tmpName);
    $sourceImage = $imageData !== false ? @imagecreatefromstring($imageData) : false;
    if (!$sourceImage) {
        throw new RuntimeException('Could not process uploaded photo.');
    }

    $sourceWidth = imagesx($sourceImage);
    $sourceHeight = imagesy($sourceImage);
    $maxDimension = 1600;
    $scale = min(
        1,
        $maxDimension / max(1, $sourceWidth),
        $maxDimension / max(1, $sourceHeight)
    );

    $targetWidth = max(1, (int)round($sourceWidth * $scale));
    $targetHeight = max(1, (int)round($sourceHeight * $scale));
    $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

    if (in_array($mime, ['image/png', 'image/webp'], true)) {
        imagealphablending($targetImage, false);
        imagesavealpha($targetImage, true);
        $transparent = imagecolorallocatealpha($targetImage, 0, 0, 0, 127);
        imagefilledrectangle($targetImage, 0, 0, $targetWidth, $targetHeight, $transparent);
    }

    imagecopyresampled(
        $targetImage,
        $sourceImage,
        0,
        0,
        0,
        0,
        $targetWidth,
        $targetHeight,
        $sourceWidth,
        $sourceHeight
    );

    $saved = false;
    if ($mime === 'image/jpeg') {
        $saved = imagejpeg($targetImage, $destination, 78);
    } elseif ($mime === 'image/png') {
        $saved = imagepng($targetImage, $destination, 7);
    } elseif ($mime === 'image/webp' && function_exists('imagewebp')) {
        $saved = imagewebp($targetImage, $destination, 78);
    }

    imagedestroy($sourceImage);
    imagedestroy($targetImage);

    if (!$saved) {
        throw new RuntimeException('Could not save uploaded photo.');
    }

    return 'uploads/recipes/' . $fileName;
}

function normalizeIngredientName(string $raw): string {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    if ($raw === '') {
        return '';
    }

    $raw = mb_strtolower($raw, 'UTF-8');
    return mb_convert_case($raw, MB_CASE_TITLE, 'UTF-8');
}

function resolveIngredientId(PDO $pdo, string $rawName): int {
    $name = normalizeIngredientName($rawName);
    if ($name === '') {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT id_ing FROM ingredient_ing WHERE name_ing = ? LIMIT 1");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id_ing'];
    }

    $stmt = $pdo->prepare("
        SELECT id_ing, name_ing
        FROM ingredient_ing
        WHERE SOUNDEX(name_ing) = SOUNDEX(?)
        LIMIT 30
    ");
    $stmt->execute([$name]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $needle = mb_strtolower($name, 'UTF-8');
    $needleFirst = mb_substr($needle, 0, 1, 'UTF-8');
    $length = strlen($needle);
    $bestId = 0;
    $bestDistance = 999;

    foreach ($candidates as $candidate) {
        $candidateName = mb_strtolower((string)$candidate['name_ing'], 'UTF-8');
        if (mb_substr($candidateName, 0, 1, 'UTF-8') !== $needleFirst) {
            continue;
        }

        $distance = levenshtein($needle, $candidateName);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestId = (int)$candidate['id_ing'];
        }
    }

    if ($bestId && $length >= 4 && $bestDistance <= 2) {
        return $bestId;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO ingredient_ing (name_ing) VALUES (?)");
        $stmt->execute([$name]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("SELECT id_ing FROM ingredient_ing WHERE name_ing = ? LIMIT 1");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id_ing'] : 0;
    }
}

function normalizeCategoryName(string $raw): string {
    $raw = trim(preg_replace('/\s+/', ' ', $raw));
    if ($raw === '') {
        return '';
    }

    $raw = mb_strtolower($raw, 'UTF-8');
    return mb_convert_case($raw, MB_CASE_TITLE, 'UTF-8');
}

function canonicalizeCategoryKey(string $raw): string {
    $raw = normalizeCategoryName($raw);
    if ($raw === '') {
        return '';
    }

    $raw = mb_strtolower($raw, 'UTF-8');
    $raw = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $raw);
    return trim(preg_replace('/\s+/', ' ', (string)$raw));
}

function resolveCuisineCategoryId(PDO $pdo, string $rawName): int {
    $name = normalizeCategoryName($rawName);
    if ($name === '') {
        return 0;
    }

    $canonicalName = canonicalizeCategoryKey($rawName);

    $stmt = $pdo->prepare("
        SELECT id_cat
        FROM category_cat
        WHERE id_cty_cat = 2 AND name_cat = ?
        LIMIT 1
    ");
    $stmt->execute([$name]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return (int)$row['id_cat'];
    }

    $stmt = $pdo->prepare("
        SELECT id_cat, name_cat
        FROM category_cat
        WHERE id_cty_cat = 2
    ");
    $stmt->execute();
    $allCuisineRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allCuisineRows as $cuisineRow) {
        if (canonicalizeCategoryKey((string)$cuisineRow['name_cat']) === $canonicalName) {
            return (int)$cuisineRow['id_cat'];
        }
    }

    $stmt = $pdo->prepare("
        SELECT id_cat, name_cat
        FROM category_cat
        WHERE id_cty_cat = 2 AND SOUNDEX(name_cat) = SOUNDEX(?)
        LIMIT 30
    ");
    $stmt->execute([$name]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $needle = canonicalizeCategoryKey($name);
    $needleFirst = mb_substr($needle, 0, 1, 'UTF-8');
    $length = strlen($needle);
    $bestId = 0;
    $bestDistance = 999;

    foreach ($candidates as $candidate) {
        $candidateKey = canonicalizeCategoryKey((string)$candidate['name_cat']);
        if ($candidateKey === '' || mb_substr($candidateKey, 0, 1, 'UTF-8') !== $needleFirst) {
            continue;
        }

        $distance = levenshtein($needle, $candidateKey);
        if ($distance < $bestDistance) {
            $bestDistance = $distance;
            $bestId = (int)$candidate['id_cat'];
        }
    }

    if ($bestId && $length >= 4 && $bestDistance <= 1) {
        return $bestId;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO category_cat (name_cat, id_cty_cat) VALUES (?, 2)");
        $stmt->execute([$name]);
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        $stmt = $pdo->prepare("
            SELECT id_cat
            FROM category_cat
            WHERE id_cty_cat = 2 AND name_cat = ?
            LIMIT 1
        ");
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id_cat'] : 0;
    }
}
