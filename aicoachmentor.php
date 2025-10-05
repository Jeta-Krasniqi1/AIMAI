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

    // Fetch active mentees
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username
        FROM mentorships m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = :mentor_id AND m.status = 'active'
        ORDER BY u.username
    ");
    $stmt->execute([':mentor_id' => $_SESSION['user_id']]);
    $mentees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle form submission
    $errors = [];
    $success = '';
    $selected_mentee_id = $goal = $progress = $ai_advice = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_advice'])) {
        $mentee_id = filter_input(INPUT_POST, 'mentee_id', FILTER_VALIDATE_INT);
        $goal = trim($_POST['goal']);
        $progress = trim($_POST['progress']);

        // Validate inputs
        if (!$mentee_id) {
            $errors[] = "Please select a mentee.";
        }
        if (empty($goal)) {
            $errors[] = "Goal is required.";
        }
        if (empty($progress)) {
            $errors[] = "Progress update is required.";
        }

        // Verify mentorship exists
        if (empty($errors)) {
            $stmt = $pdo->prepare("SELECT mentorship_id FROM mentorships WHERE mentor_id = :mentor_id AND user_id = :user_id AND status = 'active'");
            $stmt->execute([':mentor_id' => $_SESSION['user_id'], ':user_id' => $mentee_id]);
            if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
                $errors[] = "Invalid mentee selected.";
            }
        }

        // Generate AI advice and save session
        if (empty($errors)) {
            // Generate AI advice with resource suggestions
            $ai_advice = generateAICoachingAdvice($pdo, $goal, $progress);

            // Save coaching session
            $stmt = $pdo->prepare("
                INSERT INTO coaching_sessions (mentor_id, user_id, goal, progress, ai_advice)
                VALUES (:mentor_id, :user_id, :goal, :progress, :ai_advice)
            ");
            $stmt->execute([
                ':mentor_id' => $_SESSION['user_id'],
                ':user_id' => $mentee_id,
                ':goal' => $goal,
                ':progress' => $progress,
                ':ai_advice' => $ai_advice
            ]);

            // Add notification for mentee
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message)
                VALUES (:user_id, :message)
            ");
            $stmt->execute([
                ':user_id' => $mentee_id,
                ':message' => "New AI coaching advice available for your goal: $goal"
            ]);

            $success = "Coaching advice generated and saved successfully!";
            $selected_mentee_id = $mentee_id;
        }
    }

    // Fetch mentee progress for selected mentee
    $mentee_progress = [];
    if (isset($_GET['mentee_id']) && filter_input(INPUT_GET, 'mentee_id', FILTER_VALIDATE_INT)) {
        $mentee_id = filter_input(INPUT_GET, 'mentee_id', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare("
            SELECT progress_id, goal, progress_status, last_updated
            FROM motivational_progress
            WHERE user_id = :user_id
            ORDER BY last_updated DESC
        ");
        $stmt->execute([':user_id' => $mentee_id]);
        $mentee_progress = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch recent coaching sessions
    $stmt = $pdo->prepare("
        SELECT cs.coaching_id, cs.user_id, cs.goal, cs.progress, cs.ai_advice, cs.created_at, u.username
        FROM coaching_sessions cs
        JOIN users u ON cs.user_id = u.user_id
        WHERE cs.mentor_id = :mentor_id
        ORDER BY cs.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([':mentor_id' => $_SESSION['user_id']]);
    $coaching_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}

// Updated AI coaching logic with resource suggestions
function generateAICoachingAdvice($pdo, $goal, $progress) {
    $advice = "Based on the goal '$goal' and progress '$progress', consider the following: ";
    
    // Determine resource category based on goal
    $category = (strpos(strtolower($goal), 'programming') !== false || strpos(strtolower($goal), 'skill') !== false || strpos(strtolower($goal), 'learn') !== false)
        ? 'Programming'
        : 'Career Development';

    // Fetch relevant resources
    $stmt = $pdo->prepare("SELECT title, url FROM resources WHERE category = :category ORDER BY created_at DESC LIMIT 2");
    $stmt->execute([':category' => $category]);
    $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate advice based on goal
    if (strpos(strtolower($goal), 'learn') !== false || strpos(strtolower($goal), 'skill') !== false) {
        $advice .= "Encourage consistent practice with structured learning resources. Set weekly checkpoints to review progress.";
    } else if (strpos(strtolower($goal), 'career') !== false) {
        $advice .= "Recommend networking with industry professionals and updating their CV (check cvs table). Suggest relevant job postings from the jobs table.";
    } else {
        $advice .= "Break the goal into smaller milestones and track progress weekly. Provide positive reinforcement to maintain motivation.";
    }

    if (strlen($progress) < 50) {
        $advice .= " The progress update is brief; request more detailed updates to tailor future advice.";
    }

    // Append resource suggestions
    if ($resources) {
        $advice .= " Recommended resources: ";
        foreach ($resources as $resource) {
            $title = htmlspecialchars($resource['title']);
            $url = htmlspecialchars($resource['url']);
            $advice .= "<a href='$url' target='_blank'>$title</a>, ";
        }
        $advice = rtrim($advice, ', ') . ".";
    } else {
        $advice .= " No specific resources found for this category. Visit the Resources page to add relevant materials.";
    }

    return $advice;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - AI Coach for Mentors</title>
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
        
        /* Form and Progress Sections */
        .coaching-form-container, .progress-container, .sessions-container {
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
        
        .form-group select, .form-group textarea {
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

        /* Chat Box Styles */
        .chat-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-top: 25px;
            height: 500px;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            background: linear-gradient(135deg, var(--primary), #0d1b4b);
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chat-header h3 {
            margin: 0;
            font-size: 16px;
        }

        .chat-header i {
            font-size: 18px;
        }

        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f8f9fa;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .message {
            max-width: 80%;
            padding: 12px 16px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.4;
            word-wrap: break-word;
        }

        .message.user {
            background: var(--secondary);
            color: white;
            align-self: flex-end;
            border-bottom-right-radius: 6px;
        }

        .message.ai {
            background: white;
            color: #333;
            align-self: flex-start;
            border-bottom-left-radius: 6px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .message-time {
            font-size: 11px;
            color: #6c757d;
            margin-top: 5px;
            text-align: right;
        }

        .message.ai .message-time {
            text-align: left;
        }

        .chat-input-container {
            padding: 15px 20px;
            background: white;
            border-top: 1px solid #e9ecef;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .chat-input {
            flex: 1;
            border: 1px solid #dee2e6;
            border-radius: 25px;
            padding: 12px 20px;
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }

        .chat-input:focus {
            border-color: var(--secondary);
        }

        .send-btn {
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 50%;
            width: 45px;
            height: 45px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 16px;
        }

        .send-btn:hover {
            background: #3aa0e0;
            transform: scale(1.05);
        }

        .send-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
            transform: none;
        }

        .typing-indicator {
            display: none;
            align-self: flex-start;
            background: white;
            padding: 12px 16px;
            border-radius: 18px;
            border-bottom-left-radius: 6px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .typing-dots {
            display: flex;
            gap: 4px;
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            background: #6c757d;
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }

        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }

        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        
        /* Progress Table */
        .progress-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .progress-table th, .progress-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
        }
        
        .progress-table th {
            background: #f8f9fa;
            font-weight: 600;
        }
        
        .progress-table td {
            font-size: 14px;
        }
        
        /* Coaching Sessions */
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
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .dashboard-container {
                padding: 0 15px;
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
            
            .coaching-form-container, .progress-container, .sessions-container {
                padding: 15px;
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
            
            .progress-table th, .progress-table td {
                padding: 10px;
                font-size: 13px;
            }
            
            .form-submit {
                width: 100%;
                text-align: center;
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
            
            .sidebar {
                width: 100%;
            }
            
            .nav-item {
                padding: 15px 20px;
            }
            
            .session-meta {
                flex-direction: column;
                gap: 8px;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .coaching-form-container, .progress-container, .sessions-container {
            animation: fadeIn 0.5s ease-out;
        }
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
            <a href="sessions.php" class="nav-item">
                <i class="fas fa-calendar-alt"></i> Sessions
            </a>
            <a href="aicoachmentor.php" class="nav-item active">
                <i class="fas fa-robot"></i> AI Coach
            </a>
            <a href="resources.php" class="nav-item">
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
                <h2>AI Coach for Mentors</h2>
                <p>Generate personalized coaching advice for your mentees using AI insights.</p>
            </div>
            
            <!-- Coaching Form -->
            <div class="coaching-form-container">
                <h3>Generate AI Coaching Advice</h3>
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
                <form action="aicoachmentor.php" method="POST">
                    <div class="form-group">
                        <label for="mentee_id">Select Mentee</label>
                        <select name="mentee_id" id="mentee_id" required onchange="window.location.href='aicoachmentor.php?mentee_id=' + this.value;">
                            <option value="">Choose a mentee...</option>
                            <?php foreach ($mentees as $mentee): ?>
                                <option value="<?php echo $mentee['user_id']; ?>" <?php echo ($mentee['user_id'] == $selected_mentee_id) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($mentee['username']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="goal">Mentee Goal</label>
                        <textarea name="goal" id="goal" placeholder="e.g., Learn Python programming" required><?php echo htmlspecialchars($goal); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="progress">Progress Update</label>
                        <textarea name="progress" id="progress" placeholder="e.g., Completed first module" required><?php echo htmlspecialchars($progress); ?></textarea>
                    </div>
                    <button type="submit" name="generate_advice" class="form-submit">
                        <i class="fas fa-robot"></i> Generate Advice
                    </button>
                </form>
                <?php if ($ai_advice): ?>
                    <div class="form-group" style="margin-top: 20px;">
                        <label>AI-Generated Advice</label>
                        <p style="background: #f8f9fa; padding: 15px; border-radius: 8px;"><?php echo $ai_advice; ?></p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Mentee Progress -->
            <?php if (!empty($mentee_progress)): ?>
                <div class="progress-container">
                    <h3>Mentee Progress</h3>
                    <table class="progress-table">
                        <thead>
                            <tr>
                                <th>Goal</th>
                                <th>Status</th>
                                <th>Last Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($mentee_progress as $progress): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($progress['goal']); ?></td>
                                    <td><?php echo htmlspecialchars($progress['progress_status']); ?></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($progress['last_updated'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
            
            <!-- Recent Coaching Sessions -->
            <div class="sessions-container">
                <h3>Recent Coaching Sessions</h3>
                <?php if (empty($coaching_sessions)): ?>
                    <p>No coaching sessions found. Start by generating advice above.</p>
                <?php else: ?>
                    <?php foreach ($coaching_sessions as $session): ?>
                        <div class="session-item">
                            <div class="session-content">
                                <div class="session-title"><?php echo htmlspecialchars($session['goal']); ?></div>
                                <div class="session-meta">
                                    <span><i class="fas fa-user"></i> <?php echo htmlspecialchars($session['username']); ?></span>
                                    <span><i class="far fa-calendar"></i> <?php echo date('M d, Y H:i', strtotime($session['created_at'])); ?></span>
                                </div>
                                <p><strong>Progress:</strong> <?php echo htmlspecialchars($session['progress']); ?></p>
                                <p><strong>AI Advice:</strong> <?php echo $session['ai_advice']; ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Chat Box -->
            <div class="chat-container">
                <div class="chat-header">
                    <i class="fas fa-robot"></i>
                    <h3>Chat with AI Coach</h3>
                </div>
                <div class="chat-messages" id="chatMessages">
                    <div class="message ai">
                        <div>Hello! I'm your AI Coach assistant. I'm here to help you provide better coaching advice to your mentees. How can I assist you today?</div>
                        <div class="message-time"><?php echo date('h:i A'); ?></div>
                    </div>
                </div>
                <div class="typing-indicator" id="typingIndicator">
                    <div class="typing-dots">
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                    </div>
                </div>
                <div class="chat-input-container">
                    <input type="text" class="chat-input" id="chatInput" placeholder="Type your message here..." maxlength="500">
                    <button class="send-btn" id="sendBtn" disabled>
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Footer -->
    <footer>
        <div class="footer-container">
            <div class="copyright">Â© 2025 AimAI. All rights reserved.</div>
            <div class="footer-links">
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
                <a href="#">Contact Us</a>
                <a href="#">Help Center</a>
            </div>
        </div>
    </footer>
    
    <style>
        .footer-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            padding: 25px 20px;
            background: white;
            border-top: 1px solid #e9ecef;
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
    </style>
    
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

            // Chat functionality
            const chatMessages = document.getElementById('chatMessages');
            const chatInput = document.getElementById('chatInput');
            const sendBtn = document.getElementById('sendBtn');
            const typingIndicator = document.getElementById('typingIndicator');

            // Enable/disable send button based on input
            chatInput.addEventListener('input', function() {
                sendBtn.disabled = this.value.trim() === '';
            });

            // Handle Enter key
            chatInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey && !sendBtn.disabled) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            // Handle send button click
            sendBtn.addEventListener('click', sendMessage);

            function sendMessage() {
                const message = chatInput.value.trim();
                if (!message) return;

                // Add user message
                addMessage(message, 'user');
                chatInput.value = '';
                sendBtn.disabled = true;

                // Show typing indicator
                typingIndicator.style.display = 'block';
                scrollToBottom();

                // Send HTTP request to AI agent
                sendToAIAgent(message);
            }

            async function sendToAIAgent(userMessage) {
                try {
                    const response = await fetch('http://localhost:5678/webhook-test/ai-agent', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            message: userMessage,
                            user_id: '<?php echo $_SESSION['user_id'] ?? 'unknown'; ?>',
                            user_type: 'mentor',
                            timestamp: new Date().toISOString()
                        })
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const data = await response.json();
                    
                    // Hide typing indicator
                    typingIndicator.style.display = 'none';
                    
                    // Display AI response
                    const aiResponse = data.response || data.message || 'I apologize, but I couldn\'t process your request at the moment.';
                    addMessage(aiResponse, 'ai');
                    
                } catch (error) {
                    console.error('Error sending message to AI agent:', error);
                    
                    // Hide typing indicator
                    typingIndicator.style.display = 'none';
                    
                    // Show error message to user
                    addMessage('I apologize, but I\'m having trouble connecting right now. Please try again in a moment.', 'ai');
                }
            }

            function addMessage(text, sender) {
                const messageDiv = document.createElement('div');
                messageDiv.className = `message ${sender}`;
                
                const messageContent = document.createElement('div');
                messageContent.textContent = text;
                
                const messageTime = document.createElement('div');
                messageTime.className = 'message-time';
                messageTime.textContent = new Date().toLocaleTimeString('en-US', { 
                    hour: 'numeric', 
                    minute: '2-digit',
                    hour12: true 
                });
                
                messageDiv.appendChild(messageContent);
                messageDiv.appendChild(messageTime);
                
                chatMessages.appendChild(messageDiv);
                scrollToBottom();
            }

            function scrollToBottom() {
                chatMessages.scrollTop = chatMessages.scrollHeight;
            }

            function generateAIResponse(userMessage) {
                // Placeholder AI responses for mentors - replace with actual AI integration
                const responses = [
                    "As a mentor, I can help you provide better guidance to your mentees. What specific area do you need assistance with?",
                    "I understand you're looking for coaching advice. Let me help you develop better mentoring strategies.",
                    "That's a great question about mentoring! Here's what I recommend based on best practices...",
                    "I can help you improve your coaching approach. What specific challenge are you facing with your mentee?",
                    "As an AI coach for mentors, I'm here to enhance your mentoring skills. How can I assist you today?",
                    "I appreciate you seeking guidance. Let me share some effective mentoring techniques with you.",
                    "That's an interesting mentoring challenge. Here's my advice on how to approach this situation.",
                    "I'm here to support your mentoring journey. What would you like to work on improving?"
                ];
                
                // Simple keyword-based responses for mentors (replace with actual AI logic)
                const lowerMessage = userMessage.toLowerCase();
                
                if (lowerMessage.includes('mentee') || lowerMessage.includes('student')) {
                    return "When working with mentees, it's important to understand their individual needs and learning styles. Try asking open-ended questions to better understand their goals and challenges. What specific aspect of mentoring would you like to improve?";
                } else if (lowerMessage.includes('advice') || lowerMessage.includes('guidance')) {
                    return "Providing effective advice involves active listening and asking the right questions. Instead of giving direct answers, try guiding your mentee to discover solutions themselves. What type of guidance are you looking to provide?";
                } else if (lowerMessage.includes('motivation') || lowerMessage.includes('encourage')) {
                    return "Motivating mentees requires understanding their intrinsic and extrinsic motivators. Help them connect their goals to their values and celebrate their progress. How do you currently approach motivation in your mentoring?";
                } else if (lowerMessage.includes('goal') || lowerMessage.includes('objective')) {
                    return "Goal setting with mentees should be collaborative and SMART (Specific, Measurable, Achievable, Relevant, Time-bound). Help them break down large goals into smaller, manageable steps. What goal-setting challenges are you facing?";
                } else if (lowerMessage.includes('communication') || lowerMessage.includes('feedback')) {
                    return "Effective communication in mentoring involves active listening, providing constructive feedback, and maintaining regular check-ins. How do you currently structure your communication with mentees?";
                } else if (lowerMessage.includes('thank') || lowerMessage.includes('thanks')) {
                    return "You're very welcome! I'm here to support your mentoring journey. Is there anything else you'd like to discuss about your mentoring practice?";
                } else {
                    return responses[Math.floor(Math.random() * responses.length)];
                }
            }

            // Auto-scroll to bottom on page load
            scrollToBottom();
        });
    </script>
</body>
</html>