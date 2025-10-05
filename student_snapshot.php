<?php
// File: api/student_snapshot.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Basic CORS (optional, keep if your dashboard is on another origin)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../config.php';

function bad_request($msg, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$input  = file_get_contents('php://input');
$body   = json_decode($input, true);
$userId = 0;

if (isset($_GET['user_id'])) {
    $userId = (int)$_GET['user_id'];
} elseif (is_array($body) && isset($body['user_id'])) {
    $userId = (int)$body['user_id'];
}
if ($userId <= 0) bad_request('Missing or invalid user_id');

try {
    // 1) User
    $stmt = $pdo->prepare("
        SELECT user_id, username, email, personality_type, role, status, specialization
        FROM users WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) bad_request('User not found', 404);

    // 2) Goals (motivational_progress)
    $stmt = $pdo->prepare("
        SELECT progress_id, goal, progress_status, last_updated
        FROM motivational_progress
        WHERE user_id = ?
        ORDER BY last_updated DESC
    ");
    $stmt->execute([$userId]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3) Skills
    $stmt = $pdo->prepare("
        SELECT s.skill_name, us.status
        FROM user_skills us
        JOIN skills s ON s.skill_id = us.skill_id
        WHERE us.user_id = ?
        ORDER BY s.skill_name
    ");
    $stmt->execute([$userId]);
    $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4) Mentors
    $stmt = $pdo->prepare("
        SELECT m.mentor_id, u.username AS mentor_name, u.specialization
        FROM mentorships m
        JOIN users u ON u.user_id = m.mentor_id
        WHERE m.user_id = ? AND m.status = 'active'
        ORDER BY u.username
    ");
    $stmt->execute([$userId]);
    $mentors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 5) Upcoming sessions (if you use `sessions` table)
    $stmt = $pdo->prepare("
        SELECT session_id, title, session_date, session_type, notes
        FROM sessions
        WHERE user_id = ? AND session_date >= NOW()
        ORDER BY session_date ASC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $upcoming_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6) Recent activity (from progress + cvs, customize as needed)
    $stmt = $pdo->prepare("
        SELECT * FROM (
          SELECT 'Goal Updated' AS action, goal AS description, last_updated AS event_time
          FROM motivational_progress WHERE user_id = ?
          UNION ALL
          SELECT 'CV Submitted' AS action, content AS description, created_at AS event_time
          FROM cvs WHERE user_id = ?
        ) AS t
        ORDER BY event_time DESC LIMIT 10
    ");
    $stmt->execute([$userId, $userId]);
    $recent_activity = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7) Stats
    $stmt = $pdo->prepare("
        SELECT
          COUNT(*) AS total_goals,
          SUM(CASE WHEN progress_status='completed' THEN 1 ELSE 0 END) AS completed_goals
        FROM motivational_progress WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_goals'=>0,'completed_goals'=>0];
    $goal_completion = ($stats['total_goals'] > 0)
      ? round(($stats['completed_goals'] / $stats['total_goals']) * 100) : 0;

    // 8) Weekly aggregation for last 4 weeks
    $stmt = $pdo->prepare("
        SELECT YEARWEEK(last_updated, 3) AS yw,
               COUNT(*) AS total,
               SUM(CASE WHEN progress_status='completed' THEN 1 ELSE 0 END) AS completed
        FROM motivational_progress
        WHERE user_id = ? AND last_updated >= (CURRENT_DATE - INTERVAL 28 DAY)
        GROUP BY YEARWEEK(last_updated,3)
        ORDER BY yw
    ");
    $stmt->execute([$userId]);
    $weekly = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'user' => $user,
        'goals' => $goals,
        'skills' => $skills,
        'mentors' => $mentors,
        'upcoming_sessions' => $upcoming_sessions,
        'recent_activity' => $recent_activity,
        'stats' => [
            'raw' => $stats,
            'goal_completion' => $goal_completion
        ],
        'weekly' => $weekly
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
