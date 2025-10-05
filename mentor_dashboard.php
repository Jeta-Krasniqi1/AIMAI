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
    $stmt = $pdo->prepare("SELECT username, specialization FROM users WHERE user_id = ? AND role = 'mentor'");
    $stmt->execute([$_SESSION['user_id']]);
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

    // Fetch number of active mentees
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) as active_mentees FROM mentorships WHERE mentor_id = ? AND status = 'active'");
    $stmt->execute([$_SESSION['user_id']]);
    $active_mentees = $stmt->fetchColumn();

    // Fetch average rating from reviews
    $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating FROM reviews WHERE user_id IN (SELECT user_id FROM mentorships WHERE mentor_id = ?)");
    $stmt->execute([$_SESSION['user_id']]);
    $avg_rating = round($stmt->fetchColumn(), 1);

    // Fetch current mentees
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.personality_type
        FROM mentorships m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = ? AND m.status = 'active'
        ORDER BY u.username
        LIMIT 3
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $mentees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch recent feedback
    $stmt = $pdo->prepare("
        SELECT u.username, r.rating, r.comment, r.created_at
        FROM reviews r
        JOIN users u ON r.user_id = u.user_id
        WHERE r.user_id IN (SELECT user_id FROM mentorships WHERE mentor_id = ?)
        ORDER BY r.created_at DESC
        LIMIT 2
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch pending mentee requests
    $stmt = $pdo->prepare("
        SELECT m.mentorship_id, u.user_id, u.username, u.personality_type, m.start_date
        FROM mentorships m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = ? AND m.status = 'pending'
        ORDER BY m.start_date DESC
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $pending_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle success/error messages
    $mentee_request_errors = [];
    $mentee_request_success = [];
    if (isset($_SESSION['mentee_request_success'])) {
        $mentee_request_success = $_SESSION['mentee_request_success'];
        unset($_SESSION['mentee_request_success']);
    }
    if (isset($_SESSION['mentee_request_errors'])) {
        $mentee_request_errors = $_SESSION['mentee_request_errors'];
        unset($_SESSION['mentee_request_errors']);
    }

} catch (Exception $e) {
    error_log("Error in mentor_dashboard.php: " . $e->getMessage());
    http_response_code(500);
    die("An error occurred. Please try again later or contact support.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Mentor Dashboard</title>
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
        
        .ai-cta {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
            backdrop-filter: blur(5px);
        }
        
        .ai-cta:hover {
            background: rgba(255, 255, 255, 0.25);
        }
        
        .ai-cta i {
            font-size: 24px;
            color: var(--mentor-color);
            flex-shrink: 0;
        }
        
        .ai-cta-content h4 {
            margin-bottom: 5px;
        }
        
        .ai-cta-content p {
            font-size: 14px;
            opacity: 0.8;
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
        
        /* Stats Card */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 15px;
        }
        
        .stat-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-item:hover {
            transform: translateY(-3px);
        }
        
        .stat-item i {
            font-size: 24px;
            margin-bottom: 10px;
            color: var(--mentor-color);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin: 5px 0;
            color: var(--mentor-color);
        }
        
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            line-height: 1.3;
        }
        
        /* Recommendations */
        .recommendations-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .recommendation {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .recommendation:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            background: rgba(155, 89, 182, 0.05);
        }
        
        .rec-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            background: rgba(155, 89, 182, 0.1);
            color: var(--mentor-color);
        }
        
        .rec-content {
            flex: 1;
            min-width: 0;
        }
        
        .rec-content h4 {
            margin-bottom: 5px;
            word-wrap: break-word;
        }
        
        .rec-content p {
            font-size: 14px;
            color: #6c757d;
            word-wrap: break-word;
        }
        
        .goal-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: #6c757d;
            flex-wrap: wrap;
        }
        
        .goal-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Request Actions */
        .request-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .request-actions button {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .accept-btn {
            background: var(--success);
            color: white;
        }
        
        .accept-btn:hover {
            background: #27ae60;
        }
        
        .reject-btn {
            background: var(--danger);
            color: white;
        }
        
        .reject-btn:hover {
            background: #c0392b;
        }
        
        /* Error/Success Messages */
        .error-message, .success-message {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        
        .error-message {
            background: #ffebee;
            color: #c62828;
        }
        
        .success-message {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        /* Dashboard Tabs */
        .dashboard-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background: white;
            border-radius: 10px;
            padding: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .tab-btn {
            flex: 1;
            padding: 12px;
            border: none;
            background: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        .tab-btn.active {
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
            
            .ai-cta {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .ai-cta i {
                font-size: 20px;
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
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .recommendation {
                padding: 12px;
                gap: 12px;
            }
            
            .rec-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
            
            .rec-content h4 {
                font-size: 16px;
            }
            
            .rec-content p {
                font-size: 13px;
            }
            
            .goal-meta {
                gap: 10px;
                font-size: 12px;
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
            
            .ai-cta {
                padding: 12px;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .stat-item {
                padding: 12px;
            }
            
            .stat-value {
                font-size: 20px;
            }
            
            .recommendation {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .sidebar {
                width: 100%;
            }
            
            .nav-item {
                padding: 15px 20px;
            }
            
            .tab-btn {
                padding: 10px;
                font-size: 13px;
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
        .card:nth-child(4) { animation-delay: 0.4s; }
        .card:nth-child(5) { animation-delay: 0.5s; }
    </style>
</head>
<body>
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Header -->
    <header>
        <div class="header-container">
            <div class="logo">AimAI</i></div>
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
            <a href="#" class="nav-item active">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="mentees.php" class="nav-item">
                <i class="fas fa-user-graduate"></i> Mentees
            </a>
            <a href="sessions.php" class="nav-item">
                <i class="fas fa-calendar-alt"></i> Sessions
            </a>
            <a href="aicoachmentor.php" class="nav-item">
                <i class="fas fa-robot"></i> AI Coach
            </a>
            <a href="resource_mentor.php" class="nav-item">
                <i class="fas fa-book"></i> Resources
            </a>
            <a href="settings_mentor.php" class="nav-item">
                <i class="fas fa-cog"></i> Settings
            </a>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Dashboard Tabs -->
            <div class="dashboard-tabs">
                <button class="tab-btn active mentor" data-tab="mentor">Mentor Dashboard</button>
            </div>

            <!-- Mentor Dashboard -->
            <div class="dashboard-content" id="mentor-dashboard" style="display: block;">
                <!-- Success/Error Messages -->
                <?php if (!empty($mentee_request_success)): ?>
                    <div class="success-message">
                        <?php foreach ($mentee_request_success as $msg): ?>
                            <p><?php echo htmlspecialchars($msg); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($mentee_request_errors)): ?>
                    <div class="error-message">
                        <?php foreach ($mentee_request_errors as $error): ?>
                            <p><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Welcome Banner -->
                <div class="welcome-banner mentor-banner">
                    <h2>Welcome, <?php echo htmlspecialchars($mentor['username']); ?>!</h2>
                    <p>You're currently mentoring <?php echo $active_mentees; ?> students.</p>
                    <div class="ai-cta">
                        <i class="fas fa-robot"></i>
                        <div class="ai-cta-content">
                            <h4>Mentorship Tip</h4>
                            <p>Encourage your mentees to set SMART goals to enhance their progress tracking.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Cards Grid -->
                <div class="cards-grid">
                    <!-- Mentorship Stats -->
                    <div class="card mentor-card">
                        <div class="card-header">
                            <h3>Mentorship Stats</h3>
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <i class="fas fa-users"></i>
                                    <div class="stat-value"><?php echo $active_mentees; ?></div>
                                    <div class="stat-label">Active Mentees</div>
                                </div>
                                <div class="stat-item">
                                    <i class="fas fa-star"></i>
                                    <div class="stat-value"><?php echo $avg_rating ?: 'N/A'; ?></div>
                                    <div class="stat-label">Avg. Rating</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Current Mentees -->
                    <div class="card mentor-card">
                        <div class="card-header">
                            <h3>Current Mentees</h3>
                            <i class="fas fa-user-graduate"></i>
                        </div>
                        <div class="card-body">
                            <div class="recommendations-list">
                                <?php foreach ($mentees as $mentee): ?>
                                    <div class="recommendation">
                                        <div class="rec-icon">
                                            <i class="fas fa-user"></i>
                                        </div>
                                        <div class="rec-content">
                                            <h4><?php echo htmlspecialchars($mentee['username']); ?></h4>
                                            <p><?php echo htmlspecialchars($mentee['personality_type'] ?: 'N/A'); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Mentee Requests -->
                    <div class="card mentor-card">
                        <div class="card-header">
                            <h3>Mentee Requests</h3>
                            <i class="fas fa-user-plus"></i>
                        </div>
                        <div class="card-body">
                            <div class="recommendations-list">
                                <?php if (empty($pending_requests)): ?>
                                    <p>No pending mentee requests.</p>
                                <?php else: ?>
                                    <?php foreach ($pending_requests as $request): ?>
                                        <div class="recommendation">
                                            <div class="rec-icon">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="rec-content">
                                                <h4><?php echo htmlspecialchars($request['username']); ?></h4>
                                                <p><?php echo htmlspecialchars($request['personality_type'] ?: 'N/A'); ?></p>
                                                <div class="goal-meta">
                                                    <span><i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($request['start_date'])); ?></span>
                                                </div>
                                                <form action="handle_mentee_request.php" method="POST" class="request-actions">
                                                    <input type="hidden" name="mentorship_id" value="<?php echo htmlspecialchars($request['mentorship_id']); ?>">
                                                    <button type="submit" name="action" value="accept" class="accept-btn">Accept</button>
                                                    <button type="submit" name="action" value="reject" class="reject-btn">Reject</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Resources for Mentors -->
                    <div class="card mentor-card">
                        <div class="card-header">
                            <h3>Mentor Resources</h3>
                            <i class="fas fa-book"></i>
                        </div>
                        <div class="card-body">
                            <div class="recommendations-list">
                                <div class="recommendation">
                                    <div class="rec-icon">
                                        <i class="fas fa-file-pdf"></i>
                                    </div>
                                    <div class="rec-content">
                                        <h4>Effective Mentoring Guide</h4>
                                        <p>Strategies for successful mentorship relationships</p>
                                    </div>
                                </div>
                                <div class="recommendation">
                                    <div class="rec-icon">
                                        <i class="fas fa-video"></i>
                                    </div>
                                    <div class="rec-content">
                                        <h4>Motivating Different Personalities</h4>
                                        <p>Video series on adapting to mentee personality types</p>
                                    </div>
                                </div>
                                <div class="recommendation">
                                    <div class="rec-icon">
                                        <i class="fas fa-chalkboard-teacher"></i>
                                    </div>
                                    <div class="rec-content">
                                        <h4>Mentor Community Forum</h4>
                                        <p>Connect with other mentors to share experiences</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Feedback -->
                    <div class="card mentor-card">
                        <div class="card-header">
                            <h3>Recent Feedback</h3>
                            <i class="fas fa-comments"></i>
                        </div>
                        <div class="card-body">
                            <div class="recommendations-list">
                                <?php foreach ($feedback as $fb): ?>
                                    <div class="recommendation">
                                        <div class="rec-icon" style="background: rgba(46, 204, 113, 0.1); color: #2ecc71;">
                                            <i class="fas fa-star"></i>
                                        </div>
                                        <div class="rec-content">
                                            <h4><?php echo htmlspecialchars($fb['username']); ?></h4>
                                            <p>"<?php echo htmlspecialchars($fb['comment'] ?: 'No comment provided'); ?>"</p>
                                            <div class="goal-meta">
                                                <span><i class="fas fa-star"></i> <?php echo $fb['rating']; ?></span>
                                                <span><i class="far fa-calendar"></i> <?php echo date('M d, Y', strtotime($fb['created_at'])); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
            
            // AI CTA interaction
            const aiCta = document.querySelector('.ai-cta');
            if (aiCta) {
                aiCta.addEventListener('click', function() {
                    alert('Opening mentorship tips...');
                });
            }
            
            // Recommendation clicks
            const recommendations = document.querySelectorAll('.recommendation:not(.mentee-request)');
            recommendations.forEach(rec => {
                rec.addEventListener('click', function(e) {
                    if (!e.target.closest('.request-actions')) {
                        const title = this.querySelector('h4').textContent;
                        alert(`Opening: ${title}`);
                    }
                });
            });
        });
    </script>
</body>
</html>