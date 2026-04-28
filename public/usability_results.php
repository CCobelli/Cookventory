<?php
require_once __DIR__ . '/includes/admin-check.php';
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';

function pdfSafeText(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
    $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
    if ($text === false) {
        $text = '';
    }
    $text = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $text);
    return $text;
}

function wrapPdfLine(string $text, int $width = 96): array {
    $wrapped = wordwrap($text, $width, "\n", true);
    return explode("\n", $wrapped);
}

function buildSimplePdf(array $submissions): string {
    $pages = [];
    $currentLines = [];

    foreach ($submissions as $index => $submission) {
        $block = [
            'Submission ' . ($index + 1),
            'Submitted: ' . ($submission['submitted_at'] ?? ''),
            'Name: ' . ($submission['name'] ?? ''),
            'Tech Experience: ' . ($submission['tech_experience'] ?? ''),
            'Impressions',
            '  1. Purpose clear: ' . ($submission['impression_purpose_clear'] ?? ''),
            '  2. Easy to navigate: ' . ($submission['impression_easy_navigate'] ?? ''),
            '  3. Trustworthy/professional: ' . ($submission['impression_trustworthy'] ?? ''),
            '  4. Easy to read: ' . ($submission['impression_easy_read'] ?? ''),
            'Tasks',
            '  1. Login/Create account: ' . ($submission['task_login_account'] ?? ''),
            '  2. Create a recipe: ' . ($submission['task_create_recipe'] ?? ''),
            '  3. Rate a recipe: ' . ($submission['task_rate_recipe'] ?? ''),
            '  4. Add an item to pantry: ' . ($submission['task_add_pantry'] ?? ''),
            'Exploratory Task',
            ($submission['exploratory_task'] ?? ''),
            str_repeat('-', 100),
        ];

        $blockLines = [];
        foreach ($block as $line) {
            foreach (wrapPdfLine((string)$line) as $wrappedLine) {
                $blockLines[] = $wrappedLine;
            }
        }

        if (count($currentLines) + count($blockLines) > 48 && $currentLines) {
            $pages[] = $currentLines;
            $currentLines = [];
        }

        foreach ($blockLines as $line) {
            if (count($currentLines) >= 48) {
                $pages[] = $currentLines;
                $currentLines = [];
            }
            $currentLines[] = $line;
        }
    }

    if ($currentLines || !$pages) {
        $pages[] = $currentLines ?: ['No submissions found.'];
    }

    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';

    $pageCount = count($pages);
    $fontObjectId = 3 + ($pageCount * 2);
    $kids = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $kids[] = (3 + ($i * 2)) . ' 0 R';
    }
    $objects[] = '<< /Type /Pages /Kids [ ' . implode(' ', $kids) . ' ] /Count ' . $pageCount . ' >>';

    foreach ($pages as $pageIndex => $lines) {
        $pageObjectId = 3 + ($pageIndex * 2);
        $contentObjectId = $pageObjectId + 1;

        $content = "BT\n/F1 11 Tf\n50 790 Td\n14 TL\n";
        foreach ($lines as $lineNumber => $line) {
            if ($lineNumber === 0) {
                $content .= '(' . pdfSafeText($line) . ") Tj\n";
            } else {
                $content .= 'T* (' . pdfSafeText($line) . ") Tj\n";
            }
        }
        $content .= "ET";

        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $fontObjectId . ' 0 R >> >> /Contents ' . $contentObjectId . ' 0 R >>';
        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
    }

    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    return $pdf;
}

$storagePath = __DIR__ . '/../private/usability-test-submissions.jsonl';
$submissions = [];

if (is_file($storagePath)) {
    $lines = file($storagePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if (is_array($decoded)) {
            $submissions[] = $decoded;
        }
    }
}

$submissions = array_reverse($submissions);

if (isset($_GET['download']) && $_GET['download'] === 'pdf') {
    $downloadSubmissions = $submissions;
    $fileName = 'cookventory-usability-submissions.pdf';

    if (isset($_GET['submission'])) {
        $submissionIndex = filter_input(INPUT_GET, 'submission', FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0]
        ]);

        if ($submissionIndex !== false && $submissionIndex !== null && isset($submissions[$submissionIndex])) {
            $downloadSubmissions = [$submissions[$submissionIndex]];
            $submissionLabel = $submissionIndex + 1;
            $fileName = 'cookventory-usability-submission-' . $submissionLabel . '.pdf';
        }
    }

    $pdf = buildSimplePdf($downloadSubmissions);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Usability Results - Cookventory</title>
  <link rel="stylesheet" href="assets/CSS/style.css?v=20260311b">
</head>
<body>
<?php include 'includes/navbar.php'; ?>
<main class="cv-page admin-page">
  <header class="cv-page-header">
    <h1 class="cv-page-title">Usability Results</h1>
    <p class="cv-page-subtitle">Review tester feedback and export every submitted form as one PDF.</p>
  </header>

  <section class="cv-card cv-panel cv-stack-md">
    <div class="cv-actions">
      <a href="usability_results.php?download=pdf" class="cv-button">Download All as PDF</a>
      <span class="cv-help-text"><?php echo count($submissions); ?> submission<?php echo count($submissions) === 1 ? '' : 's'; ?></span>
    </div>
  </section>

  <?php if (!$submissions): ?>
    <section class="cv-card cv-panel">
      <p class="cv-empty-text">No usability form submissions have been saved yet.</p>
    </section>
  <?php else: ?>
    <?php foreach ($submissions as $index => $submission): ?>
      <section class="cv-card cv-panel cv-stack-md">
        <div class="cv-actions">
          <h2 class="cv-card-title">Submission <?php echo count($submissions) - $index; ?></h2>
          <span class="cv-help-text"><?php echo h($submission['submitted_at'] ?? ''); ?></span>
          <a href="usability_results.php?download=pdf&amp;submission=<?php echo $index; ?>" class="cv-button">Download PDF</a>
        </div>

        <div class="cv-form-grid-2">
          <div class="cv-field">
            <label>Name</label>
            <div class="cv-muted"><?php echo h($submission['name'] ?? ''); ?></div>
          </div>
          <div class="cv-field">
            <label>Tech Experience</label>
            <div class="cv-muted"><?php echo nl2br(h($submission['tech_experience'] ?? '')); ?></div>
          </div>
        </div>

        <div class="cv-table-wrap">
          <table class="cv-table">
            <thead>
              <tr>
                <th>Impressions</th>
                <th>Response</th>
              </tr>
            </thead>
            <tbody>
              <tr><td data-label="Impression">Purpose of the site is clear</td><td data-label="Response"><?php echo h($submission['impression_purpose_clear'] ?? ''); ?></td></tr>
              <tr><td data-label="Impression">Easy to navigate</td><td data-label="Response"><?php echo h($submission['impression_easy_navigate'] ?? ''); ?></td></tr>
              <tr><td data-label="Impression">Looks trustworthy/professional</td><td data-label="Response"><?php echo h($submission['impression_trustworthy'] ?? ''); ?></td></tr>
              <tr><td data-label="Impression">Easy to read</td><td data-label="Response"><?php echo h($submission['impression_easy_read'] ?? ''); ?></td></tr>
            </tbody>
          </table>
        </div>

        <div class="cv-stack-sm">
          <div class="cv-field">
            <label>1. Login / Create account</label>
            <div class="cv-muted"><?php echo nl2br(h($submission['task_login_account'] ?? '')); ?></div>
          </div>
          <div class="cv-field">
            <label>2. Create a Recipe</label>
            <div class="cv-muted"><?php echo nl2br(h($submission['task_create_recipe'] ?? '')); ?></div>
          </div>
          <div class="cv-field">
            <label>3. Rate a Recipe</label>
            <div class="cv-muted"><?php echo nl2br(h($submission['task_rate_recipe'] ?? '')); ?></div>
          </div>
          <div class="cv-field">
            <label>4. Add an item to pantry</label>
            <div class="cv-muted"><?php echo nl2br(h($submission['task_add_pantry'] ?? '')); ?></div>
          </div>
          <div class="cv-field">
            <label>Exploratory Task</label>
            <div class="cv-muted"><?php echo nl2br(h($submission['exploratory_task'] ?? '')); ?></div>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>
<script src="assets/JS/script.js?v=20260311b"></script>
</body>
</html>
