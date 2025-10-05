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

// Helper function to call AI proxy
function callAiProxy($data) {
    $url = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . '/ai_proxy.php';
    
    $ch = curl_init();
    $jsonData = json_encode($data);
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: AimAI-Client/1.0'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $jsonData,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_VERBOSE => false
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Debug logging
    error_log("AI Proxy Call - URL: $url, HTTP Code: $httpCode, Error: $error");
    error_log("AI Proxy Response: " . substr($result, 0, 200));
    
    if ($error) {
        error_log("AI Proxy call error: $error");
        return null;
    }
    
    if ($httpCode !== 200) {
        error_log("AI Proxy HTTP error: $httpCode, Response: $result");
        return null;
    }
    
    $decoded = json_decode($result, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("AI Proxy JSON decode error: " . json_last_error_msg());
        return null;
    }
    
    return $decoded;
}

try {
    // Fetch user data
    $stmt = $pdo->prepare("SELECT username, personality_type, specialization FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        header("Location: login.php");
        exit;
    }

    // Fetch recent goals
    $stmt = $pdo->prepare("SELECT goal, progress_status, last_updated FROM motivational_progress WHERE user_id = ? ORDER BY last_updated DESC LIMIT 5");
    $stmt->execute([$_SESSION['user_id']]);
    $goals = $stmt->fetchAll();

    // Fetch progress stats
    $stmt = $pdo->prepare("
        SELECT 
            (SELECT COUNT(*) FROM motivational_progress WHERE user_id = ? AND progress_status = 'completed') AS goals_completed,
            (SELECT COUNT(*) FROM motivational_progress WHERE user_id = ?) AS total_goals
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $stats = $stmt->fetch();
    $completion_percentage = $stats['total_goals'] > 0 ? round(($stats['goals_completed'] / $stats['total_goals']) * 100) : 0;

    // Fetch recommendations
    $stmt = $pdo->prepare("SELECT recommendation_id, recommendation, created_at FROM recommendations WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
    $stmt->execute([$_SESSION['user_id']]);
    $recommendations = $stmt->fetchAll();

    // Fetch recommended jobs based on user skills (MariaDB compatible)
    $stmt = $pdo->prepare("
        SELECT DISTINCT j.job_id, j.title, j.description, j.region, c.name AS company_name 
        FROM jobs j 
        LEFT JOIN job_skills js ON j.job_id = js.job_id 
        LEFT JOIN skills s ON js.skill_id = s.skill_id 
        LEFT JOIN companies c ON j.company_id = c.company_id
        LEFT JOIN user_skills us ON s.skill_id = us.skill_id AND us.user_id = ?
        WHERE us.user_id IS NOT NULL
        ORDER BY j.posted_at DESC 
        LIMIT 3
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $jobs = $stmt->fetchAll();
    
    // If no skill-based jobs found, get recent jobs
    if (empty($jobs)) {
        $stmt = $pdo->prepare("
            SELECT j.job_id, j.title, j.description, j.region, c.name AS company_name 
            FROM jobs j 
            LEFT JOIN companies c ON j.company_id = c.company_id
            ORDER BY j.posted_at DESC 
            LIMIT 3
        ");
        $stmt->execute();
        $jobs = $stmt->fetchAll();
    }

    // Fetch mentor suggestions
    $stmt = $pdo->prepare("
        SELECT m.mentor_id, m.name, m.profession, m.company 
        FROM mentors m 
        WHERE m.status = 'active' 
        AND (m.profession LIKE ? OR m.profession LIKE '%Data Science%' OR m.profession LIKE '%Technology%') 
        ORDER BY RAND()
        LIMIT 3
    ");
    $stmt->execute(['%' . ($user['specialization'] ?: 'Technology') . '%']);
    $mentors = $stmt->fetchAll();

    // Handle new recommendation request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_recommendation']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die("Invalid CSRF token");
        }
        
        $data = [
            'intent' => 'generate_recommendation',
            'user' => [
                'id' => $_SESSION['user_id'],
                'username' => $user['username'],
                'personality_type' => $user['personality_type'] ?: 'Not set'
            ],
            'goals' => $goals,
            'stats' => ['goal_completion' => $completion_percentage],
            'specialization' => $user['specialization'] ?: 'Not set',
            'source' => 'ai_coach.php'
        ];
        
        $response = callAiProxy($data);
        
        // Process response and save to database
        if ($response && $response['ok'] && isset($response['data']['recommendation'])) {
            $recommendation_text = $response['data']['recommendation'];
            $stmt = $pdo->prepare("INSERT INTO recommendations (user_id, recommendation, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$_SESSION['user_id'], $recommendation_text]);
            $_SESSION['recommendation_success'] = "New recommendation generated successfully!";
        } else {
            $_SESSION['recommendation_error'] = "Failed to generate recommendation. Please try again.";
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: ai_coach.php");
        exit;
    }

    // Handle CV generation request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_cv']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die("Invalid CSRF token");
        }
        
        $data = [
            'intent' => 'generate_cv',
            'user' => [
                'id' => $_SESSION['user_id'],
                'username' => $user['username'],
                'personality_type' => $user['personality_type'] ?: 'Not set',
                'specialization' => $user['specialization'] ?: 'Not set'
            ],
            'goals' => $goals,
            'stats' => ['goal_completion' => $completion_percentage],
            'source' => 'ai_coach.php'
        ];
        
        $response = callAiProxy($data);
        
        if ($response && $response['ok']) {
            $_SESSION['cv_success'] = "CV generation request submitted successfully!";
        } else {
            $_SESSION['cv_error'] = "Failed to process CV generation request. Please try again.";
        }
        
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        header("Location: ai_coach.php");
        exit;
    }

    // Get flash messages
    $recommendation_success = $_SESSION['recommendation_success'] ?? '';
    $recommendation_error = $_SESSION['recommendation_error'] ?? '';
    $cv_success = $_SESSION['cv_success'] ?? '';
    $cv_error = $_SESSION['cv_error'] ?? '';
    unset($_SESSION['recommendation_success'], $_SESSION['recommendation_error'], $_SESSION['cv_success'], $_SESSION['cv_error']);

    // Generate avatar initials
    $nameParts = explode(' ', $user['username']);
    $initials = '';
    foreach ($nameParts as $part) {
        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - AI Coach Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #1a2a6c;
            --secondary: #4db8ff;
            --accent: #ff6b6b;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --sidebar-width: 280px;
            --header-height: 80px;
            --card-radius: 12px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f0f4f8, #e6f0ff);
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
        }

        .logo i {
            font-size: 32px;
            color: var(--secondary);
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
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
            background: linear-gradient(45deg, var(--primary), var(--secondary));
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
            transition: var(--transition);
            text-decoration: none;
        }

        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        /* Dashboard Layout */
        .dashboard-container {
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
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: var(--card-radius);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            padding: 25px 0;
            height: fit-content;
            position: sticky;
            top: calc(var(--header-height) + 20px);
        }

        .nav-title {
            padding: 0 25px 15px;
            font-size: 14px;
            color: #6c757d;
            font-weight: 600;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            margin-bottom: 15px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 25px;
            color: #495057;
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
            border-radius: 0 30px 30px 0;
            margin: 0 10px;
        }

        .nav-item:hover, .nav-item.active {
            background: rgba(77, 184, 255, 0.1);
            color: var(--primary);
        }

        .nav-item i {
            width: 20px;
            text-align: center;
            font-size: 18px;
        }

        .dashboard-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            grid-auto-rows: minmax(100px, auto);
            gap: 25px;
        }

        /* Flash Messages */
        .flash-message {
            padding: 12px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .flash-success {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
            border: 1px solid rgba(46, 204, 113, 0.2);
        }

        .flash-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 1px solid rgba(231, 76, 60, 0.2);
        }

        /* Card Styles */
        .card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: var(--card-radius);
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }

        .card-header {
            padding: 20px;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h3 {
            font-size: 18px;
            color: var(--primary);
            font-weight: 600;
        }

        .card-header i {
            font-size: 20px;
            color: var(--secondary);
            background: rgba(77, 184, 255, 0.1);
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .card-body {
            padding: 20px;
        }

        /* Grid Layout */
        .profile-summary {
            grid-column: span 6;
        }

        .goals-progress {
            grid-column: span 6;
        }

        .career-roadmap {
            grid-column: span 8;
        }

        .recommended-jobs {
            grid-column: span 4;
        }

        .mentor-suggestions {
            grid-column: span 4;
        }

        .ai-coach-chat {
            grid-column: span 8;
            height: 500px;
        }

        .personalized-recommendations {
            grid-column: span 12;
        }

        /* Profile Summary */
        .profile-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: rgba(77, 184, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
            text-align: center;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            color: #6c757d;
        }

        .progress-ring {
            width: 120px;
            height: 120px;
            margin: 0 auto;
            position: relative;
        }

        .progress-circle {
            transform: rotate(-90deg);
            transform-origin: 50% 50%;
        }

        .progress-bg {
            fill: none;
            stroke: #e6e6e6;
            stroke-width: 8;
        }

        .progress {
            fill: none;
            stroke: var(--secondary);
            stroke-width: 8;
            stroke-dasharray: 314;
            stroke-dashoffset: calc(314 - (314 * <?php echo $completion_percentage; ?>) / 100);
            stroke-linecap: round;
            transition: stroke-dashoffset 0.5s ease;
        }

        .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }

        .progress-text span {
            font-size: 14px;
            display: block;
            color: #6c757d;
        }

        /* Goals List */
        .goals-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .goal-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            border-radius: 10px;
            background: rgba(77, 184, 255, 0.05);
            transition: var(--transition);
        }

        .goal-item:hover {
            background: rgba(77, 184, 255, 0.1);
            transform: translateX(5px);
        }

        .goal-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 18px;
        }

        .goal-content {
            flex: 1;
        }

        .goal-title {
            font-weight: 600;
            margin-bottom: 5px;
        }

        .goal-status {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
            color: #6c757d;
        }

        .status-badge {
            padding: 3px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }

        .status-in-progress {
            background: rgba(243, 156, 18, 0.1);
            color: var(--warning);
        }

        .status-completed {
            background: rgba(46, 204, 113, 0.1);
            color: var(--success);
        }

        .status-not-started {
            background: rgba(108, 117, 125, 0.1);
            color: #6c757d;
        }

        /* Roadmap Styles */
        .roadmap-container {
            position: relative;
            padding-left: 30px;
            margin-top: 20px;
        }

        .roadmap-line {
            position: absolute;
            left: 15px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: rgba(77, 184, 255, 0.3);
        }

        .roadmap-item {
            position: relative;
            padding: 0 0 30px 30px;
        }

        .roadmap-item:last-child {
            padding-bottom: 0;
        }

        .roadmap-dot {
            position: absolute;
            left: 0;
            top: 5px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--secondary);
            border: 4px solid white;
            box-shadow: 0 0 0 2px var(--secondary);
        }

        .roadmap-content {
            background: rgba(77, 184, 255, 0.05);
            border-radius: 10px;
            padding: 15px;
        }

        .roadmap-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--primary);
        }

        .roadmap-date {
            font-size: 12px;
            color: #6c757d;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Job List */
        .job-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .job-item {
            background: rgba(46, 204, 113, 0.05);
            border-radius: 10px;
            padding: 15px;
            transition: var(--transition);
        }

        .job-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .job-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--primary);
        }

        .job-company {
            font-size: 14px;
            color: #6c757d;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .job-description {
            font-size: 14px;
            color: #333;
            margin-bottom: 10px;
            line-height: 1.5;
        }

        .job-meta {
            display: flex;
            gap: 10px;
            font-size: 12px;
            color: #6c757d;
        }

        .job-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .action-btn {
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 15px;
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 10px;
            font-size: 14px;
            text-decoration: none;
        }

        .action-btn:hover {
            background: #3aa0e0;
            transform: translateY(-2px);
        }

        /* Mentor List */
        .mentor-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .mentor-item {
            background: rgba(255, 107, 107, 0.05);
            border-radius: 10px;
            padding: 15px;
            transition: var(--transition);
        }

        .mentor-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .mentor-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 10px;
        }

        .mentor-avatar {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--accent), #ff9e9e);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }

        .mentor-info {
            flex: 1;
        }

        .mentor-name {
            font-weight: 600;
            color: var(--primary);
        }

        .mentor-profession {
            font-size: 14px;
            color: #6c757d;
        }

        .mentor-company {
            font-size: 14px;
            color: #333;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Recommendations */
        .recommendation-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        .recommendation-item {
            background: rgba(156, 39, 176, 0.05);
            border-radius: 10px;
            padding: 20px;
            transition: var(--transition);
        }

        .recommendation-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .recommendation-content {
            font-size: 14px;
            color: #333;
            line-height: 1.6;
            margin-bottom: 15px;
        }

        .recommendation-meta {
            font-size: 12px;
            color: #6c757d;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .request-btn {
            background: linear-gradient(45deg, var(--primary), var(--secondary));
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 25px;
            cursor: pointer;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            margin: 10px 5px;
        }

        .request-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(26, 42, 108, 0.2);
        }

        .request-btn.cv-btn {
            background: linear-gradient(45deg, #ff6b6b, #ff9e9e);
        }

        /* Chat Styles */
        .chat-container {
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .chat-header {
            background: linear-gradient(135deg, var(--primary), #0d1b4b);
            color: white;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .chat-header-left {
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

        .chat-status {
            font-size: 12px;
            color: #4db8ff;
        }

        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #f8f9fa;
            display: flex;
            flex-direction: column;
            gap: 15px;
            max-height: 350px;
        }

        .message {
            max-width: 80%;
            padding: 12px 16px;
            border-radius: 18px;
            font-size: 14px;
            line-height: 1.4;
            word-wrap: break-word;
            position: relative;
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
            color: rgba(255, 255, 255, 0.7);
            margin-top: 5px;
            text-align: right;
        }

        .message.ai .message-time {
            color: rgba(0, 0, 0, 0.5);
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
            transition: var(--transition);
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
            transition: var(--transition);
            font-size: 16px;
        }

        .send-btn:hover:not(:disabled) {
            background: #3aa0e0;
            transform: scale(1.05);
        }

        .send-btn:disabled {
            background: #dee2e6;
            cursor: not-allowed;
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

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
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
            transition: var(--transition);
        }

        .footer-links a:hover {
            color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .dashboard-container {
                padding: 0 15px;
                gap: 20px;
            }
            
            .dashboard-grid {
                gap: 20px;
            }
            
            .profile-summary,
            .goals-progress {
                grid-column: span 12;
            }
            
            .career-roadmap,
            .ai-coach-chat {
                grid-column: span 12;
            }
            
            .recommended-jobs,
            .mentor-suggestions {
                grid-column: span 6;
            }
        }

        @media (max-width: 768px) {
            .dashboard-grid {
                gap: 15px;
            }
            
            .recommended-jobs,
            .mentor-suggestions {
                grid-column: span 12;
            }
            
            .profile-stats {
                grid-template-columns: 1fr;
            }
            
            .recommendation-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .dashboard-container {
                padding: 0 10px;
                gap: 15px;
            }
            
            .card-header {
                padding: 15px;
            }
            
            .card-body {
                padding: 15px;
            }
            
            .request-btn {
                padding: 10px 20px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-container">
            <div class="logo">
                AimAI
            </div>
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-role">Student <?php echo $user['personality_type'] ? '- ' . htmlspecialchars($user['personality_type']) : ''; ?></div>
                </div>
                <div class="user-avatar">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <div class="dashboard-container">
        <div class="sidebar">
            <div class="nav-title">MAIN NAVIGATION</div>
            <a href="student_dashboard.php" class="nav-item">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="goals.php" class="nav-item">
                <i class="fas fa-bullseye"></i> Goals
            </a>
            <a href="progress.php" class="nav-item">
                <i class="fas fa-chart-line"></i> Progress
            </a>
            <a href="personality.php" class="nav-item">
                <i class="fas fa-brain"></i> Personality
            </a>
            <a href="ai_coach.php" class="nav-item active">
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

        <div class="dashboard-grid">
            <!-- Flash Messages -->
            <?php if ($recommendation_success): ?>
                <div class="flash-message flash-success" style="grid-column: span 12;">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($recommendation_success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($recommendation_error): ?>
                <div class="flash-message flash-error" style="grid-column: span 12;">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($recommendation_error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($cv_success): ?>
                <div class="flash-message flash-success" style="grid-column: span 12;">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($cv_success); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($cv_error): ?>
                <div class="flash-message flash-error" style="grid-column: span 12;">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($cv_error); ?>
                </div>
            <?php endif; ?>

            <!-- Profile Summary -->
            <div class="card profile-summary">
                <div class="card-header">
                    <h3>Profile Summary</h3>
                    <i class="fas fa-user"></i>
                </div>
                <div class="card-body">
                    <div class="profile-stats">
                        <div class="stat-card">
                            <div class="stat-value"><?php echo htmlspecialchars($user['personality_type'] ?: 'Not set'); ?></div>
                            <div class="stat-label">Personality Type</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?php echo htmlspecialchars($user['specialization'] ?: 'Not set'); ?></div>
                            <div class="stat-label">Specialization</div>
                        </div>
                    </div>
                    
                    <div class="progress-ring">
                        <svg class="progress-circle" width="120" height="120" viewBox="0 0 120 120">
                            <circle class="progress-bg" cx="60" cy="60" r="50"></circle>
                            <circle class="progress" cx="60" cy="60" r="50"></circle>
                        </svg>
                        <div class="progress-text"><?php echo $completion_percentage; ?>%<span>Completion</span></div>
                    </div>
                    
                    <h4 style="margin-top: 20px; margin-bottom: 10px;">Recent Goals</h4>
                    <?php if (empty($goals)): ?>
                        <p>No recent goals found.</p>
                    <?php else: ?>
                        <ul style="padding-left: 20px;">
                            <?php foreach (array_slice($goals, 0, 3) as $goal): ?>
                                <li style="margin-bottom: 8px;">
                                    <?php echo htmlspecialchars($goal['goal']); ?>
                                    (<?php echo ucfirst($goal['progress_status']); ?>, 
                                    Updated: <?php echo date('M d, Y', strtotime($goal['last_updated'])); ?>)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Goals Progress -->
            <div class="card goals-progress">
                <div class="card-header">
                    <h3>Goals Progress</h3>
                    <i class="fas fa-tasks"></i>
                </div>
                <div class="card-body">
                    <div class="goals-list">
                        <?php if (empty($goals)): ?>
                            <p>No goals found. Add goals to get started!</p>
                        <?php else: ?>
                            <?php foreach (array_slice($goals, 0, 4) as $goal): ?>
                                <div class="goal-item">
                                    <div class="goal-icon">
                                        <i class="fas fa-bullseye"></i>
                                    </div>
                                    <div class="goal-content">
                                        <div class="goal-title"><?php echo htmlspecialchars($goal['goal']); ?></div>
                                        <div class="goal-status">
                                            <?php
                                            $statusClass = '';
                                            switch ($goal['progress_status']) {
                                                case 'completed': $statusClass = 'status-completed'; break;
                                                case 'in_progress': $statusClass = 'status-in-progress'; break;
                                                default: $statusClass = 'status-not-started';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $statusClass; ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $goal['progress_status'])); ?>
                                            </span>
                                            <span>Updated: <?php echo date('M d, Y', strtotime($goal['last_updated'])); ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Career Roadmap -->
            <div class="card career-roadmap">
                <div class="card-header">
                    <h3>Career Roadmap</h3>
                    <i class="fas fa-road"></i>
                </div>
                <div class="card-body">
                    <div class="roadmap-container">
                        <div class="roadmap-line"></div>
                        
                        <?php if (empty($goals)): ?>
                            <p>No goals found. Add goals to build your career roadmap.</p>
                        <?php else: ?>
                            <?php foreach (array_slice($goals, 0, 3) as $index => $goal): ?>
                                <div class="roadmap-item">
                                    <div class="roadmap-dot"></div>
                                    <div class="roadmap-content">
                                        <div class="roadmap-title">Step <?php echo $index + 1; ?>: <?php echo htmlspecialchars($goal['goal']); ?></div>
                                        <div class="roadmap-date">
                                            <i class="far fa-calendar"></i> 
                                            Last Updated: <?php echo date('M d, Y', strtotime($goal['last_updated'])); ?>
                                        </div>
                                        <p>
                                            Status: <strong><?php echo ucfirst(str_replace('_', ' ', $goal['progress_status'])); ?></strong>
                                            <?php if ($goal['progress_status'] === 'in_progress'): ?>
                                                <br>Focus on completing this goal to advance your career.
                                            <?php else: ?>
                                                <br>Great progress! Consider setting your next goal.
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recommended Jobs -->
            <div class="card recommended-jobs">
                <div class="card-header">
                    <h3>Recommended Jobs</h3>
                    <i class="fas fa-briefcase"></i>
                </div>
                <div class="card-body">
                    <div class="job-list">
                        <?php if (empty($jobs)): ?>
                            <p>No job recommendations available. Add skills to your profile.</p>
                        <?php else: ?>
                            <?php foreach ($jobs as $job): ?>
                                <div class="job-item">
                                    <div class="job-title"><?php echo htmlspecialchars($job['title']); ?></div>
                                    <?php if ($job['company_name']): ?>
                                        <div class="job-company"><i class="fas fa-building"></i> <?php echo htmlspecialchars($job['company_name']); ?></div>
                                    <?php endif; ?>
                                    <div class="job-description"><?php echo htmlspecialchars(substr($job['description'], 0, 100) . '...'); ?></div>
                                    <div class="job-meta">
                                        <?php if ($job['region']): ?>
                                            <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['region']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <a href="job_details.php?id=<?php echo $job['job_id']; ?>" class="action-btn"><i class="fas fa-eye"></i> View Details</a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Mentor Suggestions -->
            <div class="card mentor-suggestions">
                <div class="card-header">
                    <h3>Mentor Suggestions</h3>
                    <i class="fas fa-user-graduate"></i>
                </div>
                <div class="card-body">
                    <div class="mentor-list">
                        <?php if (empty($mentors)): ?>
                            <p>No mentors available. Check back later!</p>
                        <?php else: ?>
                            <?php foreach ($mentors as $mentor): ?>
                                <div class="mentor-item">
                                    <div class="mentor-header">
                                        <div class="mentor-avatar">
                                            <?php 
                                            $mentorInitials = '';
                                            $mentorNameParts = explode(' ', $mentor['name']);
                                            foreach ($mentorNameParts as $part) {
                                                $mentorInitials .= strtoupper(substr($part, 0, 1));
                                                if (strlen($mentorInitials) >= 2) break;
                                            }
                                            echo $mentorInitials;
                                            ?>
                                        </div>
                                        <div class="mentor-info">
                                            <div class="mentor-name"><?php echo htmlspecialchars($mentor['name']); ?></div>
                                            <div class="mentor-profession"><?php echo htmlspecialchars($mentor['profession']); ?></div>
                                        </div>
                                    </div>
                                    <?php if ($mentor['company']): ?>
                                        <div class="mentor-company"><i class="fas fa-building"></i> <?php echo htmlspecialchars($mentor['company']); ?></div>
                                    <?php endif; ?>
                                    <a href="mentor_connect.php?id=<?php echo $mentor['mentor_id']; ?>" class="action-btn"><i class="fas fa-handshake"></i> Connect</a>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- AI Coach Chat -->
            <div class="card ai-coach-chat">
                <div class="chat-container">
                    <div class="chat-header">
                        <div class="chat-header-left">
                            <i class="fas fa-robot"></i>
                            <div>
                                <h3>Chat with AI Coach</h3>
                                <div class="chat-status">● Online</div>
                            </div>
                        </div>
                    </div>
                    <div class="chat-messages" id="chatMessages">
                        <div class="message ai">
                            <div>Hello <?php echo htmlspecialchars($user['username']); ?>! I'm your AI Coach. I'm here to help you with your goals, career paths, and mentorship connections. How can I assist you today?</div>
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
            
            <!-- Personalized Recommendations -->
            <div class="card personalized-recommendations">
                <div class="card-header">
                    <h3>Personalized Recommendations</h3>
                    <i class="fas fa-lightbulb"></i>
                </div>
                <div class="card-body">
                    <div class="recommendation-list">
                        <?php if (empty($recommendations)): ?>
                            <div class="recommendation-item">
                                <div class="recommendation-content">
                                    No recommendations yet. Click below to request your first personalized recommendation based on your goals and personality type!
                                </div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recommendations as $recommendation): ?>
                                <div class="recommendation-item">
                                    <div class="recommendation-content">
                                        <?php echo htmlspecialchars($recommendation['recommendation']); ?>
                                    </div>
                                    <div class="recommendation-meta">
                                        <span><i class="far fa-calendar"></i> <?php echo date('F d, Y', strtotime($recommendation['created_at'])); ?></span>
                                        <span><i class="fas fa-robot"></i> AI Coach</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    
                    <div style="text-align: center; margin-top: 20px;">
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <button type="submit" name="request_recommendation" class="request-btn">
                                <i class="fas fa-robot"></i> Request New Recommendation
                            </button>
                        </form>
                        
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                            <button type="submit" name="generate_cv" class="request-btn cv-btn">
                                <i class="fas fa-file-alt"></i> Generate Professional CV
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

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
            const chatMessages = document.getElementById('chatMessages');
            const chatInput = document.getElementById('chatInput');
            const sendBtn = document.getElementById('sendBtn');
            const typingIndicator = document.getElementById('typingIndicator');

            chatInput.addEventListener('input', function() {
                sendBtn.disabled = this.value.trim() === '';
            });

            chatInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey && !sendBtn.disabled) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            sendBtn.addEventListener('click', sendMessage);

            async function sendMessage() {
                const message = chatInput.value.trim();
                if (!message) return;

                addMessage(message, 'user');
                chatInput.value = '';
                sendBtn.disabled = true;

                typingIndicator.style.display = 'block';
                scrollToBottom();

                try {
                    const response = await fetch('ai_proxy.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify({
                            intent: 'chat_message',
                            message: message,
                            user: {
                                id: <?php echo $_SESSION['user_id']; ?>,
                                username: '<?php echo addslashes($user['username']); ?>',
                                personality_type: '<?php echo addslashes($user['personality_type'] ?: ''); ?>'
                            }
                        })
                    });

                    const data = await response.json();
                    
                    typingIndicator.style.display = 'none';
                    
                    if (data.ok && data.data && data.data.response) {
                        addMessage(data.data.response, 'ai');
                    } else {
                        addMessage("I'm having trouble connecting right now. Please try again later.", 'ai');
                    }
                } catch (error) {
                    typingIndicator.style.display = 'none';
                    addMessage("Sorry, I'm experiencing technical difficulties. Please try again.", 'ai');
                    console.error('Chat error:', error);
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

            // Auto-remove flash messages after 5 seconds
            const flashMessages = document.querySelectorAll('.flash-message');
            flashMessages.forEach(function(message) {
                setTimeout(function() {
                    message.style.transition = 'opacity 0.5s ease';
                    message.style.opacity = '0';
                    setTimeout(function() {
                        message.remove();
                    }, 500);
                }, 5000);
            });
        });
    </script>
</body>
</html>