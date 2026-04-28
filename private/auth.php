<?php
session_start();
require_once __DIR__ . '/db-connect.php';
require_once __DIR__ . '/role-helpers.php';

ensureRoleLevels($pdo);

$errors = [];
$username = "";
$email = "";

/* ===============================
   SIGNUP
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signup'])) {

    $username = trim($_POST['signup_username']);
    $email = trim($_POST['signup_email']);
    $password = $_POST['signup_password'];
    $confirm = $_POST['confirm_signup_password'];

    if (empty($username) || empty($email) || empty($password)) {
        $errors[] = "All fields are required.";
    }

    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }

    $stmt = $pdo->prepare("SELECT id_usr FROM user_usr WHERE username_usr = ?");
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        $errors[] = "Username already exists.";
    }

    if (empty($errors)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("
            INSERT INTO user_usr (username_usr, email_usr, password_hash_usr)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$username, $email, $hash]);

        $userId = (int)$pdo->lastInsertId();
        setUserPrimaryRole($pdo, $userId, 'user');

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['username'] = $username;
        $_SESSION['role'] = 'user';

        header("Location: index.php");
        exit();
    }
}

/* ===============================
   LOGIN
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {

    $username = trim($_POST['login_username']);
    $password = $_POST['login_password'];

    if (empty($username) || empty($password)) {
        $errors[] = "Username and password required.";
    }

    if (empty($errors)) {
        $stmt = $pdo->prepare("
            SELECT u.*
            FROM user_usr u
            WHERE u.username_usr = ?
            LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash_usr'])) {
            $errors[] = "Invalid credentials.";
        } elseif (!(int)$user['is_active_usr']) {
            $errors[] = "This account has been removed or deactivated.";
        } else {
            $role = getUserPrimaryRole($pdo, (int)$user['id_usr']);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id_usr'];
            $_SESSION['username'] = $user['username_usr'];
            $_SESSION['role'] = $role;

            header("Location: index.php");
            exit();
        }
    }
}
?>
