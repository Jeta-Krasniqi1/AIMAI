<?php
session_start();
require 'config.php';

// --- Sliding session timeout (1 hour) ---
if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
} elseif (time() - $_SESSION['login_time'] > 3600) {
    session_destroy();
    header("Location: login.php");
    exit;
} else {
    $_SESSION['login_time'] = time(); // refresh activity
}

// Guard: student only
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'student') {
    header("Location: login.php");
    exit;
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    // Fetch user data
    $stmt = $pdo->prepare("SELECT username, personality_type FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        header("Location: login.php");
        exit;
    }

    // Progress stats
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM motivational_progress WHERE user_id = ? AND progress_status = 'completed') AS goals_completed,
            (SELECT COUNT(*) FROM motivational_progress WHERE user_id = ?) AS total_goals,
            (SELECT COUNT(*) FROM cvs WHERE user_id = ?) AS cvs_submitted,
            (SELECT COUNT(*) FROM company_connections WHERE user_id = ?) AS connections_made,
            (SELECT COUNT(*) FROM mentorships WHERE user_id = ? AND status = 'active') AS mentorships_active
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'goals_completed'=>0,'total_goals'=>0,'cvs_submitted'=>0,'connections_made'=>0,'mentorships_active'=>0
    ];

    $completion_percentage = ($stats['total_goals'] ?? 0) > 0
        ? (int) round(($stats['goals_completed'] / $stats['total_goals']) * 100)
        : 0;

    // Recent activities
    $stmt = $pdo->prepare("
        SELECT 'CV Submitted' AS activity, created_at AS activity_date FROM cvs WHERE user_id = ?
        UNION
        SELECT 'Connected with Company' AS activity, connection_date AS activity_date FROM company_connections WHERE user_id = ?
        UNION
        SELECT 'Started Mentorship' AS activity, start_date AS activity_date FROM mentorships WHERE user_id = ?
        ORDER BY activity_date DESC LIMIT 5
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id'], $_SESSION['user_id']]);
    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Progress data for last 4 weeks
    $progress_data = [];
    $labels = [];
    for ($i = 3; $i >= 0; $i--) {
        $start_date = date('Y-m-d 00:00:00', strtotime("-$i weeks"));
        $end_date   = date('Y-m-d 23:59:59', strtotime("-$i weeks +6 days"));

        $stmt = $pdo->prepare("
            SELECT 
                SUM(progress_status='completed') AS completed,
                COUNT(*) AS total
            FROM motivational_progress
            WHERE user_id = ? AND last_updated BETWEEN ? AND ?
        ");
        $stmt->execute([$_SESSION['user_id'], $start_date, $end_date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['completed'=>0,'total'=>0];

        $percentage = ((int)$row['total'] > 0) ? (int) round(((int)$row['completed'] / (int)$row['total']) * 100) : 0;
        $progress_data[] = $percentage;
        $labels[] = "Week " . (4 - $i);
    }

    // Fetch mentors who can help improve progress
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.specialization, u.personality_type,
               m.profession, m.company, m.bio,
               COUNT(DISTINCT ma.availability_id) as availability_slots,
               COALESCE(AVG(r.rating), 0) as avg_rating
        FROM users u
        LEFT JOIN mentors m ON u.user_id = m.mentor_id
        LEFT JOIN mentor_availability ma ON u.user_id = ma.mentor_id AND ma.is_available = 1
        LEFT JOIN mentorships ms ON u.user_id = ms.mentor_id
        LEFT JOIN reviews r ON r.user_id = ms.user_id
        WHERE u.role = 'mentor' AND u.status = 'active'
        AND u.user_id NOT IN (
            SELECT mentor_id FROM mentorships 
            WHERE user_id = ? AND status IN ('active', 'pending')
        )
        GROUP BY u.user_id
        ORDER BY avg_rating DESC, availability_slots DESC
        LIMIT 6
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $recommendedMentors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Avatar initials
    $initials = '';
    foreach (explode(' ', (string)$user['username']) as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    die("Error: " . htmlspecialchars($e->getMessage()));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Progress & Mentors</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 1001;
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

        /* Main Content */
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
        }

        .card-header h3 {
            font-size: 18px;
            color: var(--primary);
        }

        .card-header i {
            font-size: 20px;
            color: var(--student-color);
        }

        .card-body {
            padding: 20px;
        }

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
            color: var(--student-color);
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin: 5px 0;
            color: var(--student-color);
        }

        .stat-label {
            font-size: 12px;
            color: #6c757d;
            line-height: 1.3;
        }

        .chart-container {
            height: 300px;
            position: relative;
            margin-top: 15px;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .activity-item {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s;
        }

        .activity-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            background: rgba(77, 184, 255, 0.05);
        }

        .activity-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }

        .activity-content {
            flex: 1;
            min-width: 0;
        }

        .activity-content h4 {
            font-size: 16px;
            margin-bottom: 5px;
            color: #333;
        }

        .activity-content p {
            font-size: 13px;
            color: #6c757d;
        }

        /* Mentor Styles */
        .mentors-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-top: 15px;
        }

        .mentor-compact-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s;
            border: 2px solid transparent;
        }

        .mentor-compact-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            border-color: var(--secondary);
        }

        .mentor-avatar-small {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--mentor-color), #e74c3c);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: bold;
            margin: 0 auto 12px;
            border: 3px solid white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .mentor-compact-card h4 {
            font-size: 16px;
            margin-bottom: 8px;
            color: var(--dark);
        }

        .mentor-compact-info {
            font-size: 13px;
            color: #6c757d;
            margin-bottom: 12px;
            min-height: 40px;
        }

        .mentor-rating {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: rgba(255, 193, 7, 0.1);
            padding: 4px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
            color: #f39c12;
            margin-bottom: 12px;
        }

        .btn-request-compact {
            width: 100%;
            background: var(--secondary);
            color: white;
            border: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }

        .btn-request-compact:hover {
            background: #3aa0e0;
            transform: translateY(-2px);
        }

        .progress-insight {
            background: linear-gradient(135deg, rgba(77, 184, 255, 0.1), rgba(124, 58, 237, 0.1));
            border-left: 4px solid var(--secondary);
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            font-size: 14px;
        }

        .progress-insight strong {
            color: var(--primary);
            display: block;
            margin-bottom: 8px;
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

        /* Sidebar Overlay */
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
            .container {
                padding: 0 15px;
                gap: 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            }

            .mentors-grid {
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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

            .chart-container {
                height: 250px;
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

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }

            .stat-item {
                padding: 12px;
            }

            .stat-value {
                font-size: 20px;
            }

            .activity-item {
                padding: 12px;
                gap: 12px;
            }

            .activity-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }

            .activity-content h4 {
                font-size: 14px;
            }

            .activity-content p {
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

            .mentors-grid {
                grid-template-columns: 1fr;
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

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .chart-container {
                height: 200px;
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
    <!-- Sidebar Overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Header -->
    <header>
        <div class="header-container">
            <div class="logo">AimAI</div>
            <button class="mobile-menu-btn" id="mobileMenuToggle"><i class="fas fa-bars"></i></button>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
                    <div class="user-role">
                        Student <?= $user['personality_type'] ? '- ' . htmlspecialchars($user['personality_type']) : '' ?>
                    </div>
                </div>
                <div class="user-avatar"><?= htmlspecialchars($initials) ?></div>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container">
        <!-- Sidebar Navigation -->
        <div class="sidebar" id="sidebar">
            <div class="nav-title">MAIN NAVIGATION</div>
            <a href="student_dashboard.php" class="nav-item">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="goals.php" class="nav-item">
                <i class="fas fa-bullseye"></i> Goals
            </a>
            <a href="progress.php" class="nav-item active">
                <i class="fas fa-chart-line"></i> Progress
            </a>
            <a href="personality.php" class="nav-item">
                <i class="fas fa-brain"></i> Personality
            </a>
            <a href="ai_coach.php" class="nav-item">
                <i class="fas fa-robot"></i> AI Coach
            </a>
            <a href="view_cvs.php" class="nav-item">
                <i class="fas fa-file-alt"></i> My CVs
            </a>
            <a href="resources.php" class="nav-item">
                <i class="fas fa-book"></i> Resources
            </a>
            <a href="settings.php" class="nav-item">
                <i class="fas fa-cog"></i> Settings
            </a>
        </div>

        <!-- Progress Content -->
        <main class="main-content">
            <!-- Progress Chart -->
            <section class="card">
                <div class="card-header">
                    <h3>Goal Completion Trend</h3>
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="progressChart"></canvas>
                    </div>
                </div>
            </section>

            <!-- Progress Stats -->
            <section class="card">
                <div class="card-header">
                    <h3>Progress Statistics</h3>
                    <i class="fas fa-chart-pie"></i>
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <i class="fas fa-check-circle"></i>
                            <div class="stat-value"><?= (int)$completion_percentage ?>%</div>
                            <div class="stat-label">Goal Completion</div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-file-alt"></i>
                            <div class="stat-value"><?= (int)$stats['cvs_submitted'] ?></div>
                            <div class="stat-label">CVs Submitted</div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-handshake"></i>
                            <div class="stat-value"><?= (int)$stats['connections_made'] ?></div>
                            <div class="stat-label">Connections Made</div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-star"></i>
                            <div class="stat-value"><?= (int)$stats['mentorships_active'] ?></div>
                            <div class="stat-label">Active Mentorships</div>
                        </div>
                    </div>
                    
                    <?php if ($completion_percentage < 50): ?>
                        <div class="progress-insight">
                            <strong><i class="fas fa-lightbulb"></i> Progress Insight:</strong>
                            Your goal completion is at <?= $completion_percentage ?>%. Consider connecting with a mentor below to help accelerate your progress!
                        </div>
                    <?php elseif ($completion_percentage >= 80): ?>
                        <div class="progress-insight">
                            <strong><i class="fas fa-trophy"></i> Excellent Progress!</strong>
                            You're at <?= $completion_percentage ?>% completion! Keep up the amazing work. A mentor can help you maintain this momentum!
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Recommended Mentors to Boost Progress -->
            <?php if (!empty($recommendedMentors)): ?>
            <section class="card">
                <div class="card-header">
                    <h3>Mentors Who Can Help You Progress</h3>
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-body">
                    <p style="margin-bottom: 15px; color: #6c757d;">Connect with experienced mentors to accelerate your goal achievement and career growth.</p>
                    <div class="mentors-grid">
                        <?php foreach ($recommendedMentors as $mentor): ?>
                            <div class="mentor-compact-card">
                                <div class="mentor-avatar-small">
                                    <?= strtoupper(substr($mentor['username'], 0, 2)) ?>
                                </div>
                                <h4><?= htmlspecialchars($mentor['username']) ?></h4>
                                <div class="mentor-compact-info">
                                    <div><i class="fas fa-briefcase"></i> <?= htmlspecialchars($mentor['specialization'] ?: 'General') ?></div>
                                    <?php if ($mentor['company']): ?>
                                        <div><i class="fas fa-building"></i> <?= htmlspecialchars($mentor['company']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($mentor['avg_rating'] > 0): ?>
                                    <div class="mentor-rating">
                                        <i class="fas fa-star"></i> <?= number_format($mentor['avg_rating'], 1) ?>/5.0
                                        </i>
                                        </div>
                                <?php endif; ?>
                                <form method="POST" action="request_mentor.php">
                                    <input type="hidden" name="mentor_id" value="<?= $mentor['user_id'] ?>">
                                    <button type="submit" class="btn-request-compact">
                                        <i class="fas fa-user-plus"></i> Request Mentor
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <!-- Recent Activities -->
            <section class="card">
                <div class="card-header">
                    <h3>Recent Activities</h3>
                    <i class="fas fa-history"></i>
                </div>
                <div class="card-body">
                    <div class="activity-list">
                        <?php if (empty($recent_activities)): ?>
                            <p>No recent activities. Start setting goals to track your progress!</p>
                        <?php else: ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <article class="activity-item">
                                    <div class="activity-icon">
                                        <i class="fas fa-check"></i>
                                    </div>
                                    <div class="activity-content">
                                        <h4><?= htmlspecialchars($activity['activity']) ?></h4>
                                        <p><?= htmlspecialchars(date('F d \a\t h:i A', strtotime($activity['activity_date']))) ?></p>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </main>
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
        // Chart
        const progressCtx = document.getElementById('progressChart').getContext('2d');
        new Chart(progressCtx, {
            type: 'line',
            data: {
                labels: <?= json_encode($labels) ?>,
                datasets: [{
                    label: 'Goal Completion',
                    data: <?= json_encode($progress_data) ?>,
                    borderColor: '#1a2a6c',
                    backgroundColor: 'rgba(26,42,108,0.1)',
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: v => v + '%' }
                    }
                }
            }
        });

        // Mobile menu
        const mobileMenuToggle = document.getElementById('mobileMenuToggle');
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        function toggle() {
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            const icon = mobileMenuToggle.querySelector('i');
            icon.classList.toggle('fa-bars');
            icon.classList.toggle('fa-times');
        }
        
        mobileMenuToggle.addEventListener('click', toggle);
        overlay.addEventListener('click', toggle);

        document.querySelectorAll('.nav-item').forEach(a => {
            a.addEventListener('click', () => {
                if (window.innerWidth <= 992 && sidebar.classList.contains('active')) {
                    toggle();
                }
            });
        });
        
        window.addEventListener('resize', () => {
            if (window.innerWidth > 992) {
                sidebar.classList.remove('active');
                overlay.classList.remove('active');
                const icon = mobileMenuToggle.querySelector('i');
                icon.classList.remove('fa-times');
                icon.classList.add('fa-bars');
            }
        });
    });
    </script>
</body>
</html>