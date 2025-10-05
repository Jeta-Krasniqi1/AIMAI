<?php
session_start();
require 'config.php';

if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentor') {
    header("Location: login.php");
    exit;
}

try {
    // Fetch mentor details
    $stmt = $pdo->prepare("SELECT username, specialization FROM users WHERE user_id = :user_id AND role = 'mentor'");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $mentor = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mentor) {
        throw new Exception("Mentor not found");
    }

    // Generate avatar initials
    $nameParts = explode(' ', $mentor['username']);
    $initials = '';
    foreach ($nameParts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }

    // Handle form submission for new session
    $errors = [];
    $success = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['schedule_session'])) {
        $mentee_id = filter_input(INPUT_POST, 'mentee_id', FILTER_VALIDATE_INT);
        $session_title = trim($_POST['session_title']);
        $session_date = $_POST['session_date'];
        $session_type = trim($_POST['session_type']);

        // Validate inputs
        if (!$mentee_id) {
            $errors[] = "Please select a mentee.";
        }
        if (empty($session_title)) {
            $errors[] = "Session title is required.";
        }
        if (empty($session_date) || strtotime($session_date) < time()) {
            $errors[] = "Please select a valid future date.";
        }
        if (empty($session_type)) {
            $errors[] = "Session type is required.";
        }

        // Verify mentorship exists
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT mentorship_id FROM mentorships WHERE mentor_id = :mentor_id AND user_id = :user_id AND status = 'active'");
            $stmt->execute([':mentor_id' => $_SESSION['user_id'], ':user_id' => $mentee_id]);
            $mentorship = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$mentorship) {
                $errors[] = "Invalid mentee selected.";
            }
        }

        // Insert session and notification if no errors
        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO sessions (mentorship_id, session_title, session_date, session_type)
                VALUES (:mentorship_id, :session_title, :session_date, :session_type)
            ");
            $stmt->execute([
                ':mentorship_id' => $mentorship['mentorship_id'],
                ':session_title' => $session_title,
                ':session_date' => $session_date,
                ':session_type' => $session_type
            ]);

            // Add notification for mentee
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message)
                VALUES (:user_id, :message)
            ");
            $stmt->execute([
                ':user_id' => $mentee_id,
                ':message' => "New session scheduled: $session_title on $session_date"
            ]);

            $success = "Session scheduled successfully!";
        }
    }

    // Handle session cancellation
    if (isset($_GET['cancel_session'])) {
        $session_id = filter_input(INPUT_GET, 'cancel_session', FILTER_VALIDATE_INT);
        if ($session_id) {
            // Fetch mentee ID for notification
            $stmt = $pdo->prepare("
                SELECT m.user_id, s.session_title, s.session_date
                FROM sessions s
                JOIN mentorships m ON s.mentorship_id = m.mentorship_id
                WHERE s.session_id = :session_id AND m.mentor_id = :mentor_id
            ");
            $stmt->execute([':session_id' => $session_id, ':mentor_id' => $_SESSION['user_id']]);
            $session = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($session) {
                // Delete session
                $stmt = $pdo->prepare("
                    DELETE FROM sessions 
                    WHERE session_id = :session_id 
                    AND mentorship_id IN (SELECT mentorship_id FROM mentorships WHERE mentor_id = :mentor_id)
                ");
                $stmt->execute([':session_id' => $session_id, ':mentor_id' => $_SESSION['user_id']]);

                // Add cancellation notification
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, message)
                    VALUES (:user_id, :message)
                ");
                $stmt->execute([
                    ':user_id' => $session['user_id'],
                    ':message' => "Session canceled: {$session['session_title']} on {$session['session_date']}"
                ]);

                $success = "Session canceled successfully!";
            }
        }
    }

    // Handle search and pagination
    $search_mentee = isset($_GET['search_mentee']) ? trim($_GET['search_mentee']) : '';
    $date_filter = isset($_GET['date_filter']) ? trim($_GET['date_filter']) : 'upcoming';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    // Build session query
    $search_query = $search_mentee ? "AND u.username LIKE :search_mentee" : "";
    $date_query = $date_filter === 'past' ? "AND s.session_date < NOW()" : "AND s.session_date >= NOW()";
    $stmt = $pdo->prepare("
        SELECT s.session_id, s.mentorship_id, s.session_title, s.session_date, s.session_type, u.username
        FROM sessions s
        JOIN mentorships m ON s.mentorship_id = m.mentorship_id
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = :mentor_id $search_query $date_query
        ORDER BY s.session_date ASC
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':mentor_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    if ($search_mentee) {
        $stmt->bindValue(':search_mentee', "%$search_mentee%", PDO::PARAM_STR);
    }
    $stmt->execute();
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total sessions for pagination
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM sessions s
        JOIN mentorships m ON s.mentorship_id = m.mentorship_id
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = :mentor_id $search_query $date_query
    ");
    $stmt->bindValue(':mentor_id', $_SESSION['user_id'], PDO::PARAM_INT);
    if ($search_mentee) {
        $stmt->bindValue(':search_mentee', "%$search_mentee%", PDO::PARAM_STR);
    }
    $stmt->execute();
    $total_sessions = $stmt->fetchColumn();
    $total_pages = ceil($total_sessions / $limit);

    // Fetch active mentees for session form
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username
        FROM mentorships m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = :mentor_id AND m.status = 'active'
        ORDER BY u.username
    ");
    $stmt->execute([':mentor_id' => $_SESSION['user_id']]);
    $mentees = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Sessions</title>
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
            --mentor-color: #9b59b6;
            --company-color: #27ae60;
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
        
        /* Header Styles */
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
            background-clip: text;
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
            background: rgba(155, 89, 182, 0.2);
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
            background: linear-gradient(45deg, var(--mentor-color), #e74c3c);
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
        
        /* Main Content */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
            margin: 20px auto;
            padding: 0 20px;
            gap: 25px;
            flex: 1;
            width: 100%;
        }
        
        /* Sidebar */
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
            background: rgba(155, 89, 182, 0.1);
            color: var(--mentor-color);
            border-left: 3px solid var(--mentor-color);
        }
        
        .nav-item i {
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 25px;
            min-width: 0;
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, var(--mentor-color), #8e44ad);
            color: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 20px rgba(155, 89, 182, 0.2);
            display: flex;
            flex-direction: column;
            gap: 15px;
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: "";
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200px;
            height: 200px;
            background: radial-gradient(rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 0;
        }
        
        .welcome-banner::after {
            content: "";
            position: absolute;
            bottom: -30%;
            right: 10%;
            width: 150px;
            height: 150px;
            background: radial-gradient(rgba(255,255,255,0.1) 0%, transparent 70%);
            border-radius: 50%;
            z-index: 0;
        }
        
        .welcome-banner h2 {
            font-size: 28px;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }
        
        .welcome-banner p {
            max-width: 700px;
            opacity: 0.9;
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }
        
        /* Search and Filter Bar */
        .search-bar {
            display: flex;
            gap: 10px;
            background: white;
            border-radius: 10px;
            padding: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            flex-wrap: wrap;
        }
        
        .search-form {
            display: flex;
            flex: 1;
            gap: 10px;
            min-width: 0;
        }
        
        .search-bar input, .search-bar select {
            padding: 10px 15px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
            flex: 1;
            min-width: 0;
        }
        
        .search-bar button {
            background: var(--mentor-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            cursor: pointer;
            transition: all 0.3s;
            flex-shrink: 0;
        }
        
        .search-bar button:hover {
            background: #8e44ad;
        }
        
        /* Session Form */
        .session-form-container {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 8px;
            color: #555;
        }
        
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-submit {
            background: var(--success);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .form-submit:hover {
            background: #27ae60;
        }
        
        .error-message {
            color: var(--danger);
            font-size: 13px;
            margin-bottom: 10px;
        }
        
        .success-message {
            color: var(--success);
            font-size: 13px;
            margin-bottom: 10px;
        }
        
        /* Cards Grid */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: fadeIn 0.5s ease-out;
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
        }
        
        .card-header h3 {
            font-size: 18px;
            color: var(--mentor-color);
        }
        
        .card-header i {
            font-size: 20px;
            color: var(--mentor-color);
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Session Item */
        .session-item {
            display: flex;
            flex-direction: column;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .session-item:last-child {
            border-bottom: none;
        }
        
        .session-content {
            flex: 1;
            min-width: 0;
        }
        
        .session-title {
            font-weight: 500;
            margin-bottom: 5px;
            word-wrap: break-word;
        }
        
        .session-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: #6c757d;
            flex-wrap: wrap;
        }
        
        .session-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .session-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 8px 15px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .edit-btn {
            background: var(--warning);
            color: white;
        }
        
        .edit-btn:hover {
            background: #e08e0b;
        }
        
        .cancel-btn {
            background: var(--danger);
            color: white;
        }
        
        .cancel-btn:hover {
            background: #c0392b;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination a {
            padding: 10px 15px;
            border-radius: 8px;
            background: #f8f9fa;
            color: #495057;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background: var(--mentor-color);
            color: white;
        }
        
        .pagination a.active {
            background: var(--mentor-color);
            color: white;
        }
        
        /* Footer */
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
        
        /* Sidebar Overlay for Mobile */
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
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-container {
                padding: 0 15px;
                gap: 20px;
            }
            
            .cards-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 20px;
            }
        }
        
        @media (max-width: 992px) {
            :root {
                --sidebar-width: 100%;
                --header-height: 70px;
            }
            
            .dashboard-container {
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
            
            .welcome-banner {
                padding: 25px 20px;
            }
            
            .welcome-banner h2 {
                font-size: 24px;
            }
            
            .cards-grid {
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 15px;
            }
            
            .search-bar {
                flex-direction: column;
                gap: 8px;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .search-bar input, .search-bar select, .search-bar button {
                width: 100%;
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
            
            .welcome-banner {
                padding: 20px 15px;
            }
            
            .welcome-banner h2 {
                font-size: 22px;
            }
            
            .welcome-banner p {
                font-size: 14px;
            }
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                padding: 15px;
            }
            
            .card-body {
                padding: 15px;
            }
            
            .session-meta {
                gap: 10px;
                font-size: 12px;
            }
            
            .session-actions {
                flex-direction: column;
                gap: 8px;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
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
            .dashboard-container {
                padding: 0 10px;
                margin: 10px auto;
            }
            
            .welcome-banner {
                padding: 15px;
            }
            
            .welcome-banner h2 {
                font-size: 20px;
            }
            
            .cards-grid {
                gap: 10px;
            }
            
            .card-header {
                padding: 12px;
            }
            
            .card-body {
                padding: 12px;
            }
            
            .session-item {
                padding: 12px 0;
            }
            
            .sidebar {
                width: 100%;
            }
            
            .nav-item {
                padding: 15px 20px;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card:nth-child(1) { animation-delay: 0.1s; }
        .card:nth-child(2) { animation-delay: 0.2s; }
        .card:nth-child(3) { animation-delay: 0.3s; }
    </style>
</head>
<body>
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Header -->
    <header>
        <div class="header-container">
            <div class="logo">AimAI <i class="fas fa-robot"></i></div>
            <button class="mobile-menu-btn" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($mentor['username']); ?></div>
                    <div class="user-role mentor-role">Mentor - <?php echo htmlspecialchars($mentor['specialization'] ?: 'N/A'); ?></div>
                </div>
                <div class="user-avatar mentor-avatar"><?php echo htmlspecialchars($initials); ?></div>
                <a href="logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>
    </header>
    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <div class="sidebar" id="sidebar">
            <div class="nav-title">MAIN NAVIGATION</div>
            <a href="mentor_dashboard.php" class="nav-item">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="mentees.php" class="nav-item">
                <i class="fas fa-user-graduate"></i> Mentees
            </a>
            <a href="sessions.php" class="nav-item active">
                <i class="fas fa-calendar-alt"></i> Sessions
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-robot"></i> AI Coach
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-book"></i> Resources
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-cog"></i> Settings
            </a>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Welcome Banner -->
            <div class="welcome-banner mentor-banner">
                <h2>Manage Your Sessions</h2>
                <p>Schedule and track mentoring sessions with your mentees.</p>
            </div>
            
            <!-- Session Form -->
            <div class="cards-grid">
                <div class="card mentor-card session-form-container">
                    <div class="card-header">
                        <h3>Schedule New Session</h3>
                        <i class="fas fa-calendar-plus"></i>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($errors)): ?>
                            <div class="error-message">
                                <?php foreach ($errors as $error): ?>
                                    <p><?php echo htmlspecialchars($error); ?></p>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($success): ?>
                            <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
                        <?php endif; ?>
                        <form action="sessions.php" method="POST">
                            <div class="form-group">
                                <label for="mentee_id">Select Mentee</label>
                                <select name="mentee_id" id="mentee_id" required>
                                    <option value="">Choose a mentee...</option>
                                    <?php foreach ($mentees as $mentee): ?>
                                        <option value="<?php echo $mentee['user_id']; ?>">
                                            <?php echo htmlspecialchars($mentee['username']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="session_title">Session Title</label>
                                <input type="text" name="session_title" id="session_title" placeholder="e.g., Goal Setting Review" required>
                            </div>
                            <div class="form-group">
                                <label for="session_date">Session Date & Time</label>
                                <input type="datetime-local" name="session_date" id="session_date" required>
                            </div>
                            <div class="form-group">
                                <label for="session_type">Session Type</label>
                                <select name="session_type" id="session_type" required>
                                    <option value="">Choose a type...</option>
                                    <option value="Video Call">Video Call</option>
                                    <option value="In-Person">In-Person</option>
                                    <option value="Chat">Chat</option>
                                </select>
                            </div>
                            <button type="submit" name="schedule_session" class="form-submit">
                                <i class="fas fa-calendar-check"></i> Schedule Session
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Search and Filter Bar -->
            <div class="search-bar">
                <form action="sessions.php" method="GET" class="search-form">
                    <input type="text" name="search_mentee" placeholder="Search by mentee name..." value="<?php echo htmlspecialchars($search_mentee); ?>">
                    <select name="date_filter">
                        <option value="upcoming" <?php echo $date_filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming Sessions</option>
                        <option value="past" <?php echo $date_filter === 'past' ? 'selected' : ''; ?>>Past Sessions</option>
                    </select>
                    <button type="submit"><i class="fas fa-search"></i> Filter</button>
                </form>
            </div>
            
            <!-- Sessions List -->
            <div class="cards-grid">
                <div class="card mentor-card">
                    <div class="card-header">
                        <h3>Your Sessions (<?php echo $total_sessions; ?>)</h3>
                        <i class="fas fa-calendar-alt"></i>
                    </div>
                    <div class="card-body">
                        <?php if (empty($sessions)): ?>
                            <p>No sessions found. <?php echo $search_mentee || $date_filter ? 'Try adjusting your filters.' : 'Schedule a new session above.'; ?></p>
                        <?php else: ?>
                            <?php foreach ($sessions as $session): ?>
                                <div class="session-item">
                                    <div class="session-content">
                                        <div class="session-title"><?php echo htmlspecialchars($session['session_title']); ?></div>
                                        <div class="session-meta">
                                            <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($session['username']); ?></span>
                                            <span><i class="far fa-calendar"></i> <?php echo date('M d, Y H:i', strtotime($session['session_date'])); ?></span>
                                            <span><i class="fas fa-video"></i> <?php echo htmlspecialchars($session['session_type']); ?></span>
                                        </div>
                                    </div>
                                    <div class="session-actions">
                                        <a href="edit_session.php?id=<?php echo $session['session_id']; ?>" class="action-btn edit-btn">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                        <a href="sessions.php?cancel_session=<?php echo $session['session_id']; ?>" class="action-btn cancel-btn" onclick="return confirm('Are you sure you want to cancel this session?');">
                                            <i class="fas fa-trash"></i> Cancel
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="sessions.php?page=<?php echo $i; ?><?php echo $search_mentee ? '&search_mentee=' . urlencode($search_mentee) : ''; ?>&date_filter=<?php echo urlencode($date_filter); ?>" class="<?php echo $page == $i ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Footer -->
    <footer>
        <div class="footer-container">
            <div class="copyright">© 2025 AimAI. All rights reserved.</div>
            <div class="footer-links">
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
                <a href="#">Contact Us</a>
                <a href="#">Help Center</a>
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
                if (sidebar.classList.contains('active')) {
                    icon.classList.remove('fa-bars');
                    icon.classList.add('fa-times');
                } else {
                    icon.classList.remove('fa-times');
                    icon.classList.add('fa-bars');
                }
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
            
            // Set minimum date for session_date input
            const sessionDateInput = document.getElementById('session_date');
            if (sessionDateInput) {
                const now = new Date();
                const minDate = now.toISOString().slice(0, 16); // Format: YYYY-MM-DDTHH:MM
                sessionDateInput.setAttribute('min', minDate);
            }
        });
    </script>
</body>
</html>