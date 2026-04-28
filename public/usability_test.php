<?php
require_once '../private/db-connect.php';
require_once '../private/app-helpers.php';

$errors = [];
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = [
        'submitted_at' => date('c'),
        'name' => trim($_POST['name'] ?? ''),
        'tech_experience' => trim($_POST['tech_experience'] ?? ''),
        'impression_purpose_clear' => trim($_POST['impression_purpose_clear'] ?? ''),
        'impression_easy_navigate' => trim($_POST['impression_easy_navigate'] ?? ''),
        'impression_trustworthy' => trim($_POST['impression_trustworthy'] ?? ''),
        'impression_easy_read' => trim($_POST['impression_easy_read'] ?? ''),
        'task_login_account' => trim($_POST['task_login_account'] ?? ''),
        'task_create_recipe' => trim($_POST['task_create_recipe'] ?? ''),
        'task_rate_recipe' => trim($_POST['task_rate_recipe'] ?? ''),
        'task_add_pantry' => trim($_POST['task_add_pantry'] ?? ''),
        'exploratory_task' => trim($_POST['exploratory_task'] ?? ''),
    ];

    if ($payload['name'] === '') {
        $errors[] = 'Name is required.';
    }

    if ($payload['tech_experience'] === '') {
        $errors[] = 'Tech experience is required.';
    }

    foreach ([
        'impression_purpose_clear',
        'impression_easy_navigate',
        'impression_trustworthy',
        'impression_easy_read',
    ] as $field) {
        if ($payload[$field] === '') {
            $errors[] = 'Please answer all impression questions.';
            break;
        }
    }

    foreach ([
        'task_login_account',
        'task_create_recipe',
        'task_rate_recipe',
        'task_add_pantry',
        'exploratory_task',
    ] as $field) {
        if ($payload[$field] === '') {
            $errors[] = 'Please complete all task response fields.';
            break;
        }
    }

    if (!$errors) {
        $storagePath = __DIR__ . '/../private/usability-test-submissions.jsonl';
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false || file_put_contents($storagePath, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            $errors[] = 'Could not save your response. Please try again.';
        } else {
            $submitted = true;
            $_POST = [];
        }
    }
}

function sticky(string $key): string {
    return h($_POST[$key] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cookventory Usability Test</title>
    <link rel="stylesheet" href="assets/CSS/usability-test.css">
</head>
<body>
<main class="usability-page">
    <section class="usability-shell">
        <header class="usability-hero">
            <p class="usability-eyebrow">Cookventory</p>
            <h1>Usability Testing Form</h1>
            <p>
                Thanks for helping test the site. Please answer each section as honestly as you can.
            </p>
        </header>

        <?php if ($submitted): ?>
            <section class="usability-message usability-message--success">
                <h2>Response submitted</h2>
                <p>Your feedback was saved successfully. Thank you for testing Cookventory.</p>
            </section>
        <?php endif; ?>

        <?php if ($errors): ?>
            <section class="usability-message usability-message--error">
                <h2>Please fix the following</h2>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo h($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <form method="post" action="" class="usability-form">
            <section class="usability-card">
                <h2>Participant Info</h2>
                <div class="usability-grid">
                    <label class="usability-field">
                        <span>Name</span>
                        <input type="text" name="name" value="<?php echo sticky('name'); ?>" required>
                    </label>

                    <label class="usability-field">
                        <span>Tech Experience</span>
                        <textarea name="tech_experience" rows="3" required><?php echo sticky('tech_experience'); ?></textarea>
                    </label>
                </div>
            </section>

            <section class="usability-card">
                <h2>Impressions</h2>
                <p class="usability-section-copy">Rate each statement from 1 to 5.</p>

                <?php
                $impressionQuestions = [
                    'impression_purpose_clear' => '1. Is the purpose of this site clear?',
                    'impression_easy_navigate' => '2. Is it easy to navigate?',
                    'impression_trustworthy' => '3. Does this look trustworthy/professional?',
                    'impression_easy_read' => '4. Is it easy to read?',
                ];
                foreach ($impressionQuestions as $name => $label):
                ?>
                    <fieldset class="usability-rating">
                        <legend><?php echo h($label); ?></legend>
                        <div class="usability-rating-options">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <label>
                                    <input
                                        type="radio"
                                        name="<?php echo h($name); ?>"
                                        value="<?php echo $i; ?>"
                                        <?php echo (sticky($name) === (string)$i) ? 'checked' : ''; ?>
                                        required
                                    >
                                    <span><?php echo $i; ?></span>
                                </label>
                            <?php endfor; ?>
                        </div>
                        <div class="usability-rating-scale">
                            <span>1 = Strongly disagree</span>
                            <span>5 = Strongly agree</span>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
            </section>

            <section class="usability-card">
                <h2>Task Section</h2>
                <p class="usability-section-copy">Please describe how each task went and note any issues or confusion.</p>

                <label class="usability-field">
                    <span>1. Login / Create account</span>
                    <textarea name="task_login_account" rows="4" required><?php echo sticky('task_login_account'); ?></textarea>
                </label>

                <label class="usability-field">
                    <span>2. Create a Recipe</span>
                    <textarea name="task_create_recipe" rows="4" required><?php echo sticky('task_create_recipe'); ?></textarea>
                </label>

                <label class="usability-field">
                    <span>3. Rate a Recipe</span>
                    <textarea name="task_rate_recipe" rows="4" required><?php echo sticky('task_rate_recipe'); ?></textarea>
                </label>

                <label class="usability-field">
                    <span>4. Add an item to pantry</span>
                    <textarea name="task_add_pantry" rows="4" required><?php echo sticky('task_add_pantry'); ?></textarea>
                </label>
            </section>

            <section class="usability-card">
                <h2>Exploratory Task</h2>
                <label class="usability-field">
                    <span>Select a recipe that you would make, add all those items to your pantry, then cook the recipe. Please describe this process and any difficulties involved.</span>
                    <textarea name="exploratory_task" rows="7" required><?php echo sticky('exploratory_task'); ?></textarea>
                </label>
            </section>

            <div class="usability-actions">
                <button type="submit">Submit Feedback</button>
                <a href="index.php">Back to Cookventory</a>
            </div>
        </form>
    </section>
</main>
</body>
</html>
