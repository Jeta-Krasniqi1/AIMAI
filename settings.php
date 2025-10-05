<?php
session_start();
require 'config.php';

if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check session timeout (1 hour)
if (time() - $_SESSION['login_time'] > 3600) {
    session_destroy();
    header("Location: login.php");
    exit;
}

try {
    // Fetch user data
    $stmt = $pdo->prepare("SELECT username, personality_type FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        header("Location: login.php");
        exit;
    }

    // Fetch user skills
    $stmt = $pdo->prepare("SELECT s.skill_id, s.name FROM skills s JOIN user_skills us ON s.skill_id = us.skill_id WHERE us.user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user_skills = $stmt->fetchAll();

    // Fetch all available skills for suggestions
    $stmt = $pdo->prepare("SELECT skill_id, name FROM skills ORDER BY name");
    $stmt->execute();
    $all_skills = $stmt->fetchAll();

    $errors = [];
    $success = '';

    // Handle username update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_username']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $errors[] = "Invalid CSRF token";
        } else {
            $new_username = trim($_POST['username'] ?? '');
            if (empty($new_username)) {
                $errors[] = "Username is required";
            } elseif (strlen($new_username) < 3 || strlen($new_username) > 50) {
                $errors[] = "Username must be between 3 and 50 characters";
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
                $stmt->execute([$new_username, $_SESSION['user_id']]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = "Username already taken";
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE user_id = ?");
                    $stmt->execute([$new_username, $_SESSION['user_id']]);
                    $user['username'] = $new_username;
                    $success = "Username updated successfully";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
            }
        }
    }

    // Handle password update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $errors[] = "Invalid CSRF token";
        } else {
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            if (empty($new_password) || empty($confirm_password)) {
                $errors[] = "Both password fields are required";
            } elseif ($new_password !== $confirm_password) {
                $errors[] = "Passwords do not match";
            } elseif (strlen($new_password) < 8) {
                $errors[] = "Password must be at least 8 characters";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $stmt->execute([$hashed_password, $_SESSION['user_id']]);
                $success = "Password updated successfully";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        }
    }

    // Handle personality type update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_personality']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $errors[] = "Invalid CSRF token";
        } else {
            $personality_type = trim($_POST['personality_type'] ?? '');
            if (empty($personality_type)) {
                $errors[] = "Personality type is required";
            } elseif (!in_array($personality_type, ['INTJ', 'INTP', 'ENTJ', 'ENTP', 'INFJ', 'INFP', 'ENFJ', 'ENFP', 'ISTJ', 'ISFJ', 'ESTJ', 'ESFJ', 'ISTP', 'ISFP', 'ESTP', 'ESFP'])) {
                $errors[] = "Invalid personality type";
            } else {
                $stmt = $pdo->prepare("UPDATE users SET personality_type = ? WHERE user_id = ?");
                $stmt->execute([$personality_type, $_SESSION['user_id']]);
                $user['personality_type'] = $personality_type;
                $success = "Personality type updated successfully";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        }
    }

    // Handle skill addition
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_skill']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $errors[] = "Invalid CSRF token";
        } else {
            $skill_id = $_POST['skill_id'] ?? '';
            if (empty($skill_id)) {
                $errors[] = "Please select a skill";
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_skills WHERE user_id = ? AND skill_id = ?");
                $stmt->execute([$_SESSION['user_id'], $skill_id]);
                if ($stmt->fetchColumn() > 0) {
                    $errors[] = "This skill is already added";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO user_skills (user_id, skill_id) VALUES (?, ?)");
                    $stmt->execute([$_SESSION['user_id'], $skill_id]);
                    $success = "Skill added successfully";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $stmt = $pdo->prepare("SELECT s.skill_id, s.name FROM skills s JOIN user_skills us ON s.skill_id = us.skill_id WHERE us.user_id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $user_skills = $stmt->fetchAll();
                }
            }
        }
    }

    // Handle skill deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_skill']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $errors[] = "Invalid CSRF token";
        } else {
            $skill_id = $_POST['skill_id'] ?? '';
            $stmt = $pdo->prepare("DELETE FROM user_skills WHERE user_id = ? AND skill_id = ?");
            $stmt->execute([$_SESSION['user_id'], $skill_id]);
            $success = "Skill removed successfully";
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare("SELECT s.skill_id, s.name FROM skills s JOIN user_skills us ON s.skill_id = us.skill_id WHERE us.user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user_skills = $stmt->fetchAll();
        }
    }

    // Handle temporary data deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_temp_data']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $errors[] = "Invalid CSRF token";
        } else {
            // Delete temporary files
            $user_tmp_dir = "tmp/uploads/user_{$_SESSION['user_id']}/";
            if (is_dir($user_tmp_dir)) {
                $files = glob($user_tmp_dir . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($user_tmp_dir);
            }

            // Clear non-critical session data
            $keep = ['user_id', 'role', 'login_time', 'csrf_token'];
            foreach ($_SESSION as $key => $value) {
                if (!in_array($key, $keep)) {
                    unset($_SESSION[$key]);
                }
            }

            $success = "Temporary data deleted successfully";
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    // Handle account deletion
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $errors[] = "Invalid CSRF token";
        } else {
            // Delete temporary files
            $user_tmp_dir = "tmp/uploads/user_{$_SESSION['user_id']}/";
            if (is_dir($user_tmp_dir)) {
                $files = glob($user_tmp_dir . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
                rmdir($user_tmp_dir);
            }

            // Delete database records
            $stmt = $pdo->prepare("DELETE FROM user_skills WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            session_destroy();
            header("Location: login.php?status=account_deleted");
            exit;
        }
    }

    // Generate avatar initials
    $nameParts = explode(' ', $user['username']);
    $initials = '';
    foreach ($nameParts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
} catch (PDOException $e) {
    $errors[] = "Database error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Settings</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        :root {
            --primary: #1a2a6c;
            --secondary: #4db8ff;
            --accent: #ff6b6b;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --student-color: #1a2a6c;
            --sidebar-width: 280px;
            --header-height: 80px;
        }

        body {
            background-color: #f0f4f8;
            color: #333;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background: linear-gradient(135deg, var(--primary), #0d1b4b);
            color: white;
            padding: 0 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 1000;
            height: var(--header-height);
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
            height: 100%;
            position: relative;
        }

        .logo {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 1001;
        }

        .logo i {
            font-size: 32px;
            color: var(--secondary);
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 10px;
            border-radius: 5px;
            transition: background 0.3s;
            z-index: 1001;
        }

        .mobile-menu-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .user-details {
            text-align: right;
        }

        .user-name {
            font-weight: 600;
            font-size: 16px;
        }

        .user-role {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 3px;
            font-weight: 500;
            background: rgba(77, 184, 255, 0.2);
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            color: white;
            background: linear-gradient(45deg, var(--student-color), var(--secondary));
        }

        .logout-btn {
            background: transparent;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
            padding: 8px 12px;
            border-radius: 6px;
            transition: all 0.3s;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .logout-btn:focus {
            outline: 2px solid var(--secondary);
            outline-offset: 2px;
        }

        .container {
            display: flex;
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
            gap: 25px;
            flex: 1;
            width: 100%;
        }

        .sidebar {
            width: var(--sidebar-width);
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            padding: 25px 0;
            height: fit-content;
            transition: all 0.3s ease;
            position: sticky;
            top: calc(var(--header-height) + 20px);
        }

        .nav-title {
            padding: 0 25px 15px;
            font-size: 14px;
            color: #6c757d;
            font-weight: 600;
            border-bottom: 1px solid #e9ecef;
            margin-bottom: 15px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 25px;
            color: #495057;
            text-decoration: none;
            transition: all 0.3s;
            font-weight: 500;
        }

        .nav-item:hover, .nav-item.active {
            background: rgba(77, 184, 255, 0.1);
            color: var(--primary);
            border-left: 3px solid var(--secondary);
        }

        .nav-item i {
            width: 20px;
            text-align: center;
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 25px;
            min-width: 0;
        }

        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
        }

        .card-header h3, .card-header h4 {
            font-size: 18px;
            color: var(--primary);
        }

        .card-header h4 {
            font-size: 16px;
        }

        .card-header i {
            font-size: 20px;
            color: var(--student-color);
            transition: transform 0.3s;
        }

        .card-header i.rotate {
            transform: rotate(180deg);
        }

        .card-body {
            padding: 20px;
            display: none;
        }

        .card-body.active {
            display: block;
        }

        .form-section {
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            color: #333;
            margin-bottom: 5px;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

        .form-group input:focus, .form-group select:focus {
            border-color: var(--secondary);
            outline: 2px solid var(--secondary);
            outline-offset: 2px;
        }

        .form-group select {
            appearance: none;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="5" viewBox="0 0 10 5"><path fill="%23333" d="M0 0h10L5 5z"/></svg>') no-repeat right 10px center;
            background-size: 10px;
        }

        .error, .success {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .error {
            background: var(--danger);
            color: white;
        }

        .success {
            background: var(--success);
            color: white;
        }

        .submit-btn, .delete-btn {
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .submit-btn:hover {
            background: #3aa0e0;
        }

        .delete-btn {
            background: var(--danger);
        }

        .delete-btn:hover {
            background: #c0392b;
        }

        .submit-btn:focus, .delete-btn:focus {
            outline: 2px solid var(--secondary);
            outline-offset: 2px;
        }

        .submit-btn.loading::after, .delete-btn.loading::after {
            content: '';
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid white;
            border-top-color: transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .skill-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 15px;
        }

        .skill-tag {
            background: rgba(77, 184, 255, 0.2);
            color: var(--primary);
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .skill-tag .remove-skill {
            background: transparent;
            border: none;
            color: var(--danger);
            font-size: 14px;
            cursor: pointer;
            transition: color 0.3s;
        }

        .skill-tag .remove-skill:hover {
            color: #c0392b;
        }

        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltip-text {
            visibility: hidden;
            width: 120px;
            background: var(--dark);
            color: white;
            text-align: center;
            border-radius: 6px;
            padding: 6px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            transform: translateX(-50%);
            font-size: 12px;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .tooltip:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }

        .skill-search {
            margin-bottom: 15px;
        }

        .skill-search input {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

        .skill-search input:focus {
            border-color: var(--secondary);
            outline: 2px solid var(--secondary);
            outline-offset: 2px;
        }

        footer {
            background: white;
            padding: 25px 20px;
            margin-top: 40px;
            border-top: 1px solid #e9ecef;
        }

        .footer-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .copyright {
            color: #6c757d;
            font-size: 14px;
        }

        .footer-links {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .footer-links a {
            color: #6c757d;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        .footer-links a:focus {
            outline: 2px solid var(--secondary);
            outline-offset: 2px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
        }

        .sidebar-overlay.active {
            display: block;
        }

        @media (max-width: 1200px) {
            .container {
                padding: 0 15px;
                gap: 20px;
            }
        }

        @media (max-width: 992px) {
            :root {
                --sidebar-width: 100%;
                --header-height: 70px;
            }

            .container {
                flex-direction: column;
                margin: 15px auto;
                gap: 15px;
            }

            .sidebar {
                position: fixed;
                top: var(--header-height);
                left: -100%;
                width: 280px;
                height: calc(100vh - var(--header-height));
                z-index: 999;
                overflow-y: auto;
                transition: left 0.3s ease;
                border-radius: 0;
            }

            .sidebar.active {
                left: 0;
            }

            .mobile-menu-btn {
                display: block;
            }

            .header-container {
                padding: 15px 0;
            }
        }

        @media (max-width: 768px) {
            .header-container {
                flex-wrap: wrap;
                gap: 10px;
            }

            .logo {
                font-size: 1.5rem;
            }

            .logo i {
                font-size: 24px;
            }

            .user-info {
                gap: 15px;
            }

            .user-details {
                text-align: left;
            }

            .user-name {
                font-size: 14px;
            }

            .user-avatar {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .card-header {
                padding: 15px;
            }

            .card-body {
                padding: 15px;
            }

            .form-section h4 {
                font-size: 14px;
            }

            .form-group label {
                font-size: 13px;
            }

            .form-group input, .form-group select {
                font-size: 13px;
            }

            .submit-btn, .delete-btn {
                padding: 8px 15px;
            }

            .skill-tag {
                font-size: 11px;
                padding: 3px 10px;
            }

            .footer-container {
                flex-direction: column;
                text-align: center;
            }

            .footer-links {
                justify-content: center;
                gap: 15px;
            }
        }

        @media (max-width: 480px) {
            .container {
                padding: 0 10px;
                margin: 10px auto;
            }

            .card-header {
                padding: 12px;
            }

            .card-body {
                padding: 12px;
            }

            .sidebar {
                width: 100%;
            }

            .nav-item {
                padding: 15px 20px;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <header>
        <div class="header-container">
            <div class="logo"> AimAI</div>
            <button class="mobile-menu-btn" id="mobileMenuToggle" aria-label="Toggle menu"><i class="fas fa-bars"></i></button>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-role">
                        Student <?php echo $user['personality_type'] ? '- ' . htmlspecialchars($user['personality_type']) : ''; ?>
                    </div>
                </div>
                <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
                <a href="logout.php" class="logout-btn" aria-label="Logout"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="container">
        <div class="sidebar">
            <div class="nav-title">MAIN NAVIGATION</div>
            <a href="student_dashboard.php" class="nav-item ">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="goals.php" class="nav-item ">
                <i class="fas fa-bullseye"></i> Goals
            </a>
            <a href="progress.php" class="nav-item ">
                <i class="fas fa-chart-line"></i> Progress
            </a>
            <a href="personality.php" class="nav-item">
                <i class="fas fa-brain"></i> Personality
            </a>
            <a href="ai_coach.php" class="nav-item ">
                <i class="fas fa-robot"></i> AI Coach
            </a>
            <a href="view_cvs.php" class="nav-item">
        <i class="fas fa-file-alt"></i> My CVs
    </a>
            <a href="resources.php" class="nav-item">
                <i class="fas fa-book"></i> Resources
            </a>
            <a href="settings.php" class="nav-item active">
                <i class="fas fa-cog"></i> Settings
            </a>
        </div>

        <div class="main-content">
            <div class="card">
                <div class="card-header" role="button" aria-expanded="true" aria-controls="settings-body">
                    <h3>Account Settings</h3>
                    <i class="fas fa-chevron-down"></i>
                </div>
                <div class="card-body active" id="settings-body">
                    <?php if (!empty($errors)): ?>
                        <?php foreach ($errors as $error): ?>
                            <div class="error"><?php echo htmlspecialchars($error); ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="success"><?php echo htmlspecialchars($success); ?></div>
                    <?php endif; ?>

                    <div class="form-section" data-section="username">
                        <div class="card-header" role="button" aria-expanded="true" aria-controls="username-body">
                            <h4>Update Username</h4>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="card-body active" id="username-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <div class="form-group">
                                    <label for="username">New Username</label>
                                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" required aria-label="New username">
                                </div>
                                <button type="submit" name="update_username" class="submit-btn tooltip" aria-label="Update username">
                                    Update Username
                                    <span class="tooltip-text">Save your new username</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="form-section" data-section="password">
                        <div class="card-header" role="button" aria-expanded="false" aria-controls="password-body">
                            <h4>Update Password</h4>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="card-body" id="password-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <input type="password" id="new_password" name="new_password" required aria-label="New password">
                                </div>
                                <div class="form-group">
                                    <label for="confirm_password">Confirm Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" required aria-label="Confirm new password">
                                </div>
                                <button type="submit" name="update_password" class="submit-btn tooltip" aria-label="Update password">
                                    Update Password
                                    <span class="tooltip-text">Save your new password</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="form-section" data-section="personality">
                        <div class="card-header" role="button" aria-expanded="false" aria-controls="personality-body">
                            <h4>Update Personality Type</h4>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="card-body" id="personality-body">
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <div class="form-group">
                                    <label for="personality_type">Personality Type</label>
                                    <select id="personality_type" name="personality_type" required aria-label="Personality type">
                                        <option value="" disabled <?php echo !$user['personality_type'] ? 'selected' : ''; ?>>Select Personality Type</option>
                                        <?php
                                        $personality_types = ['INTJ', 'INTP', 'ENTJ', 'ENTP', 'INFJ', 'INFP', 'ENFJ', 'ENFP', 'ISTJ', 'ISFJ', 'ESTJ', 'ESFJ', 'ISTP', 'ISFP', 'ESTP', 'ESFP'];
                                        foreach ($personality_types as $type) {
                                            echo '<option value="' . $type . '"' . ($user['personality_type'] === $type ? ' selected' : '') . '>' . $type . '</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <button type="submit" name="update_personality" class="submit-btn tooltip" aria-label="Update personality type">
                                    Update Personality Type
                                    <span class="tooltip-text">Save your personality type</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="form-section" data-section="skills">
                        <div class="card-header" role="button" aria-expanded="true" aria-controls="skills-body">
                            <h4>Manage Skills</h4>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="card-body active" id="skills-body">
                            <div class="skill-tags">
                                <?php if (empty($user_skills)): ?>
                                    <p>No skills added yet. Select a skill below to add.</p>
                                <?php else: ?>
                                    <?php foreach ($user_skills as $skill): ?>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                            <input type="hidden" name="skill_id" value="<?php echo $skill['skill_id']; ?>">
                                            <span class="skill-tag">
                                                <?php echo htmlspecialchars($skill['name']); ?>
                                                <button type="submit" name="delete_skill" class="remove-skill tooltip" aria-label="Remove skill <?php echo htmlspecialchars($skill['name']); ?>">
                                                    <i class="fas fa-times"></i>
                                                    <span class="tooltip-text">Remove this skill</span>
                                                </button>
                                            </span>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <div class="form-group">
                                    <label for="skill_search">Search Skills</label>
                                    <input type="text" id="skill_search" class="skill-search" placeholder="Type to search skills..." aria-label="Search skills">
                                    <select id="skill_id" name="skill_id" required aria-label="Add new skill" style="margin-top: 10px;">
                                        <option value="" disabled selected>Select a skill</option>
                                        <?php foreach ($all_skills as $skill): ?>
                                            <option value="<?php echo $skill['skill_id']; ?>" data-name="<?php echo htmlspecialchars($skill['name']); ?>">
                                                <?php echo htmlspecialchars($skill['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" name="add_skill" class="submit-btn tooltip" aria-label="Add skill">
                                    Add Skill
                                    <span class="tooltip-text">Add this skill to your profile</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="form-section" data-section="temp-data">
                        <div class="card-header" role="button" aria-expanded="false" aria-controls="temp-data-body">
                            <h4>Clear Temporary Data</h4>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="card-body" id="temp-data-body">
                            <p style="color: #e74c3c; font-size: 14px;">This will delete temporary files (e.g., uploaded documents) and clear non-critical session data. This action cannot be undone.</p>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete temporary data? This action cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" name="delete_temp_data" class="delete-btn tooltip" aria-label="Delete temporary data">
                                    Delete Temporary Data
                                    <span class="tooltip-text">Clear temporary files and session data</span>
                                </button>
                            </form>
                        </div>
                    </div>

                    <div class="form-section" data-section="delete-account">
                        <div class="card-header" role="button" aria-expanded="false" aria-controls="delete-account-body">
                            <h4>Delete Account</h4>
                            <i class="fas fa-chevron-down"></i>
                        </div>
                        <div class="card-body" id="delete-account-body">
                            <p style="color: #e74c3c; font-size: 14px;">Warning: This action is irreversible and will delete all your data, including goals, progress, and recommendations.</p>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to delete your account? This action cannot be undone.');">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <button type="submit" name="delete_account" class="delete-btn tooltip" aria-label="Delete account">
                                    Delete My Account
                                    <span class="tooltip-text">Permanently delete your account</span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <footer>
        <div class="footer-container">
            <div class="copyright">© 2025 AimAI. All rights reserved.</div>
            <div class="footer-links">
                <a href="#" aria-label="Privacy Policy">Privacy Policy</a>
                <a href="#" aria-label="Terms of Service">Terms of Service</a>
                <a href="#" aria-label="Contact Us">Contact Us</a>
                <a href="#" aria-label="Help Center">Help Center</a>
            </div>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Mobile menu functionality
            const mobileMenuToggle = document.querySelector('.mobile-menu-btn');
            const sidebar = document.getElementById('sidebar');
            const sidebarOverlay = document.getElementById('sidebarOverlay');

            function toggleMobileMenu() {
                sidebar.classList.toggle('active');
                sidebarOverlay.classList.toggle('active');
                const icon = mobileMenuToggle.querySelector('i');
                icon.classList.toggle('fa-bars');
                icon.classList.toggle('fa-times');
            }

            mobileMenuToggle.addEventListener('click', toggleMobileMenu);
            sidebarOverlay.addEventListener('click', toggleMobileMenu);

            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth <= 992) {
                        toggleMobileMenu();
                    }
                });
            });

            window.addEventListener('resize', function() {
                if (window.innerWidth > 992) {
                    sidebar.classList.remove('active');
                    sidebarOverlay.classList.remove('active');
                    const icon = mobileMenuToggle.querySelector('i');
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
            });

            // Collapsible form sections
            const formHeaders = document.querySelectorAll('.form-section .card-header');
            formHeaders.forEach(header => {
                header.addEventListener('click', function() {
                    const cardBody = header.nextElementSibling;
                    const icon = header.querySelector('i');
                    cardBody.classList.toggle('active');
                    icon.classList.toggle('rotate');
                    header.setAttribute('aria-expanded', cardBody.classList.contains('active'));
                });
            });

            // Skill search functionality
            const skillSearch = document.getElementById('skill_search');
            const skillSelect = document.getElementById('skill_id');
            const options = skillSelect.querySelectorAll('option[data-name]');

            skillSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                options.forEach(option => {
                    const skillName = option.getAttribute('data-name').toLowerCase();
                    option.style.display = skillName.includes(searchTerm) ? '' : 'none';
                });
                skillSelect.value = ''; // Reset selection
            });

            // Button loading states
            const buttons = document.querySelectorAll('.submit-btn, .delete-btn');
            buttons.forEach(button => {
                button.addEventListener('click', function() {
                    if (!this.classList.contains('loading')) {
                        this.classList.add('loading');
                        setTimeout(() => this.classList.remove('loading'), 2000);
                    }
                });
            });
        });
    </script>
</body>
</html>