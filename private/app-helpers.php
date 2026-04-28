<?php

function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatQty($value): string {
    $formatted = number_format((float)$value, 3, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function ensureRecipeServingsColumn(PDO $pdo): bool {
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM recipe_rcp LIKE 'servings_rcp'");
        $checked = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        if ($checked) {
            return true;
        }

        try {
            $pdo->exec("ALTER TABLE recipe_rcp ADD COLUMN servings_rcp INT UNSIGNED NULL DEFAULT NULL AFTER cook_time_minutes_rcp");
        } catch (Throwable $e) {
            // Ignore if schema updates are not available here.
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM recipe_rcp LIKE 'servings_rcp'");
        $checked = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
        return $checked;
    } catch (Throwable $e) {
        $checked = false;
        return false;
    }
}

function resolveServingCount($rawValue, ?int $fallback): ?int {
    if ($rawValue === null) {
        return $fallback;
    }

    $raw = trim((string)$rawValue);
    if ($raw === '' || !ctype_digit($raw)) {
        return $fallback;
    }

    $value = (int)$raw;
    return $value > 0 ? $value : $fallback;
}
