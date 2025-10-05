<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];

try {
    // Gather user data
    $stmt = $pdo->prepare("SELECT username, email, personality_type FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found");
    }
    
    // Get skills
    $stmt = $pdo->prepare("
        SELECT s.name, us.status, us.acquired_at
        FROM user_skills us
        JOIN skills s ON us.skill_id = s.skill_id
        WHERE us.user_id = ?
        ORDER BY us.acquired_at DESC
    ");
    $stmt->execute([$user_id]);
    $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get completed goals
    $stmt = $pdo->prepare("
        SELECT goal, last_updated
        FROM motivational_progress
        WHERE user_id = ? AND progress_status = 'completed'
        ORDER BY last_updated DESC
        LIMIT 10
    ");
    $stmt->execute([$user_id]);
    $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all goals for context
    $stmt = $pdo->prepare("
        SELECT goal, progress_status
        FROM motivational_progress
        WHERE user_id = ?
        ORDER BY last_updated DESC
    ");
    $stmt->execute([$user_id]);
    $allGoals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate CV content (simple version without AI for now)
    $cvContent = generateSimpleCV($user, $skills, $achievements, $allGoals);
    
    // Store CV in database
    $stmt = $pdo->prepare("
        INSERT INTO cvs (user_id, job_id, content, created_at)
        VALUES (?, NULL, ?, NOW())
    ");
    $stmt->execute([$user_id, $cvContent]);
    
    $cv_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'cv_id' => $cv_id,
        'content' => $cvContent,
        'message' => 'CV generated successfully!'
    ]);
    
} catch (Exception $e) {
    error_log("CV Generation Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function generateSimpleCV($user, $skills, $achievements, $allGoals) {
    $cv = "=================================\n";
    $cv .= "       CURRICULUM VITAE\n";
    $cv .= "=================================\n\n";
    
    // Personal Information
    $cv .= "PERSONAL INFORMATION\n";
    $cv .= "--------------------\n";
    $cv .= "Name: " . $user['username'] . "\n";
    $cv .= "Email: " . $user['email'] . "\n";
    if ($user['personality_type']) {
        $cv .= "Personality Type: " . $user['personality_type'] . "\n";
    }
    $cv .= "\n";
    
    // Professional Summary
    $cv .= "PROFESSIONAL SUMMARY\n";
    $cv .= "--------------------\n";
    $cv .= "Motivated " . $user['personality_type'] . " professional with demonstrated commitment to ";
    $cv .= "continuous learning and personal development. Actively pursuing goals in ";
    
    $goalAreas = array_unique(array_map(function($g) {
        if (stripos($g['goal'], 'python') !== false) return 'Python Development';
        if (stripos($g['goal'], 'javascript') !== false) return 'JavaScript Development';
        if (stripos($g['goal'], 'data') !== false) return 'Data Analysis';
        if (stripos($g['goal'], 'career') !== false) return 'Career Development';
        return 'Professional Growth';
    }, $allGoals));
    
    $cv .= implode(', ', array_slice($goalAreas, 0, 3)) . ".\n\n";
    
    // Skills Section
    if (!empty($skills)) {
        $cv .= "SKILLS\n";
        $cv .= "------\n";
        
        $acquiredSkills = array_filter($skills, fn($s) => $s['status'] === 'acquired');
        $learningSkills = array_filter($skills, fn($s) => $s['status'] === 'learning');
        
        if (!empty($acquiredSkills)) {
            $cv .= "Proficient in: ";
            $cv .= implode(', ', array_column($acquiredSkills, 'name')) . "\n";
        }
        
        if (!empty($learningSkills)) {
            $cv .= "Currently learning: ";
            $cv .= implode(', ', array_column($learningSkills, 'name')) . "\n";
        }
        $cv .= "\n";
    }
    
    // Achievements Section
    if (!empty($achievements)) {
        $cv .= "KEY ACHIEVEMENTS\n";
        $cv .= "----------------\n";
        foreach (array_slice($achievements, 0, 5) as $achievement) {
            $cv .= "• " . $achievement['goal'];
            $cv .= " (Completed: " . date('M Y', strtotime($achievement['last_updated'])) . ")\n";
        }
        $cv .= "\n";
    }
    
    // Current Goals
    $inProgressGoals = array_filter($allGoals, fn($g) => $g['progress_status'] === 'in_progress');
    if (!empty($inProgressGoals)) {
        $cv .= "CURRENT OBJECTIVES\n";
        $cv .= "------------------\n";
        foreach (array_slice($inProgressGoals, 0, 3) as $goal) {
            $cv .= "• " . $goal['goal'] . "\n";
        }
        $cv .= "\n";
    }
    
    $cv .= "Generated via AIMAI Career Platform on " . date('F d, Y') . "\n";
    
    return $cv;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Generate CV - AIMAI</title>
</head>
<body>
    <div class="cv-generator">
        <h1>AI-Powered CV Generator</h1>
        
        <div class="generator-section">
            <p>Generate a professional CV based on your skills, projects, and achievements.</p>
            
            <form method="POST">
                <button type="submit" name="generate_cv" class="btn-primary">
                    <i class="fas fa-file-alt"></i> Generate My CV
                </button>
            </form>
        </div>
        
        <?php if ($cvGenerated): ?>
            <div class="cv-preview">
                <h2>Your Generated CV</h2>
                <div class="cv-content">
                    <?php echo nl2br(htmlspecialchars($cvContent)); ?>
                </div>
                <button onclick="downloadCV()" class="btn-success">
                    <i class="fas fa-download"></i> Download PDF
                </button>
            </div>
        <?php endif; ?>
        
        <div class="existing-cvs">
            <h2>Your Previous CVs</h2>
            <?php foreach ($existingCVs as $cv): ?>
                <div class="cv-card">
                    <p><?php echo htmlspecialchars($cv['preview']); ?>...</p>
                    <small>Generated: <?php echo date('M d, Y', strtotime($cv['created_at'])); ?></small>
                    <a href="view_cv.php?id=<?php echo $cv['cv_id']; ?>" class="btn-small">View</a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>