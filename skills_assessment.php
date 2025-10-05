<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user's skills
$stmt = $pdo->prepare("
    SELECT us.*, s.name as skill_name, s.description
    FROM user_skills us
    JOIN skills s ON us.skill_id = s.skill_id
    WHERE us.user_id = ?
    ORDER BY us.progress_percentage DESC, us.acquired_at DESC
");
$stmt->execute([$user_id]);
$user_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available skills to add
$stmt = $pdo->prepare("
    SELECT s.skill_id, s.name, s.description
    FROM skills s
    WHERE s.skill_id NOT IN (
        SELECT skill_id FROM user_skills WHERE user_id = ?
    )
    ORDER BY s.name
");
$stmt->execute([$user_id]);
$available_skills = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle skill updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_skill'])) {
        $skill_id = (int)$_POST['skill_id'];
        $proficiency = $_POST['proficiency_level'];
        $progress = (int)$_POST['progress_percentage'];
        
        $stmt = $pdo->prepare("
            UPDATE user_skills 
            SET proficiency_level = ?, 
                progress_percentage = ?,
                last_practiced_at = NOW()
            WHERE user_id = ? AND skill_id = ?
        ");
        $stmt->execute([$proficiency, $progress, $user_id, $skill_id]);
        
        $_SESSION['success'] = "Skill updated successfully!";
        header("Location: skills_assessment.php");
        exit;
    }
    
    if (isset($_POST['add_skill'])) {
        $skill_id = (int)$_POST['skill_id'];
        
        $stmt = $pdo->prepare("
            INSERT INTO user_skills (user_id, skill_id, status, proficiency_level, progress_percentage)
            VALUES (?, ?, 'learning', 'beginner', 0)
        ");
        $stmt->execute([$user_id, $skill_id]);
        
        $_SESSION['success'] = "Skill added successfully!";
        header("Location: skills_assessment.php");
        exit;
    }
}

$success = $_SESSION['success'] ?? '';
unset($_SESSION['success']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Skills Assessment - AIMAI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f0f4f8, #e6f0ff);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            color: #1a2a6c;
            margin-bottom: 30px;
            font-size: 2.5rem;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #1a2a6c;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 25px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .back-btn:hover {
            background: #0d1b4b;
            transform: translateY(-2px);
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #c3e6cb;
        }
        
        .skills-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .skill-card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
        }
        
        .skill-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .skill-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .skill-name {
            font-size: 1.3rem;
            font-weight: 600;
            color: #1a2a6c;
        }
        
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-learning {
            background: rgba(243, 156, 18, 0.1);
            color: #f39c12;
        }
        
        .status-acquired {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }
        
        .proficiency-level {
            margin: 15px 0;
        }
        
        .proficiency-level label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
        }
        
        .proficiency-select {
            width: 100%;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .progress-section {
            margin: 15px 0;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 500;
            color: #495057;
        }
        
        .progress-bar-container {
            background: #e9ecef;
            height: 10px;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: linear-gradient(45deg, #1a2a6c, #4db8ff);
            border-radius: 5px;
            transition: width 0.3s;
        }
        
        .progress-input {
            width: 100%;
            margin-top: 10px;
        }
        
        .last-practiced {
            font-size: 12px;
            color: #6c757d;
            margin-top: 10px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .update-btn {
            width: 100%;
            padding: 10px;
            background: linear-gradient(45deg, #1a2a6c, #4db8ff);
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            margin-top: 15px;
            transition: all 0.3s;
        }
        
        .update-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(26, 42, 108, 0.3);
        }
        
        .add-skill-section {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        }
        
        .add-skill-section h2 {
            color: #1a2a6c;
            margin-bottom: 20px;
        }
        
        .add-skill-form {
            display: flex;
            gap: 15px;
            align-items: flex-end;
        }
        
        .form-group {
            flex: 1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #495057;
        }
        
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .add-btn {
            padding: 12px 30px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .add-btn:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        @media (max-width: 768px) {
            .skills-grid {
                grid-template-columns: 1fr;
            }
            
            .add-skill-form {
                flex-direction: column;
            }
            
            .add-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="student_dashboard.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1><i class="fas fa-graduation-cap"></i> Skills Assessment</h1>
        
        <?php if ($success): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <div class="skills-grid">
            <?php foreach ($user_skills as $skill): ?>
                <div class="skill-card">
                    <form method="POST">
                        <input type="hidden" name="skill_id" value="<?php echo $skill['skill_id']; ?>">
                        
                        <div class="skill-header">
                            <div class="skill-name"><?php echo htmlspecialchars($skill['skill_name']); ?></div>
                            <span class="status-badge status-<?php echo $skill['status']; ?>">
                                <?php echo ucfirst($skill['status']); ?>
                            </span>
                        </div>
                        
                        <div class="proficiency-level">
                            <label>Proficiency Level</label>
                            <select name="proficiency_level" class="proficiency-select">
                                <option value="beginner" <?php echo $skill['proficiency_level'] === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                                <option value="intermediate" <?php echo $skill['proficiency_level'] === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                <option value="advanced" <?php echo $skill['proficiency_level'] === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                                <option value="expert" <?php echo $skill['proficiency_level'] === 'expert' ? 'selected' : ''; ?>>Expert</option>
                            </select>
                        </div>
                        
                        <div class="progress-section">
                            <div class="progress-label">
                                <span>Progress</span>
                                <span id="progress-value-<?php echo $skill['skill_id']; ?>"><?php echo $skill['progress_percentage']; ?>%</span>
                            </div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width: <?php echo $skill['progress_percentage']; ?>%"></div>
                            </div>
                            <input 
                                type="range" 
                                name="progress_percentage" 
                                class="progress-input" 
                                min="0" 
                                max="100" 
                                value="<?php echo $skill['progress_percentage']; ?>"
                                oninput="updateProgress(<?php echo $skill['skill_id']; ?>, this.value)"
                            >
                        </div>
                        
                        <?php if ($skill['last_practiced_at']): ?>
                            <div class="last-practiced">
                                <i class="far fa-clock"></i>
                                Last practiced: <?php echo date('M d, Y', strtotime($skill['last_practiced_at'])); ?>
                            </div>
                        <?php endif; ?>
                        
                        <button type="submit" name="update_skill" class="update-btn">
                            <i class="fas fa-save"></i> Update Skill
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php if (!empty($available_skills)): ?>
            <div class="add-skill-section">
                <h2><i class="fas fa-plus-circle"></i> Add New Skill</h2>
                <form method="POST" class="add-skill-form">
                    <div class="form-group">
                        <label>Select Skill</label>
                        <select name="skill_id" required>
                            <option value="">Choose a skill...</option>
                            <?php foreach ($available_skills as $skill): ?>
                                <option value="<?php echo $skill['skill_id']; ?>">
                                    <?php echo htmlspecialchars($skill['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="add_skill" class="add-btn">
                        <i class="fas fa-plus"></i> Add Skill
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function updateProgress(skillId, value) {
            document.getElementById('progress-value-' + skillId).textContent = value + '%';
            document.querySelector(`#progress-value-${skillId}`).closest('.progress-section').querySelector('.progress-bar').style.width = value + '%';
        }
    </script>
</body>
</html>