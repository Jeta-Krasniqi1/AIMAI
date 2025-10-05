<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

// Get user info
$stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Generate avatar initials
$nameParts = explode(' ', $user['username']);
$initials = '';
foreach ($nameParts as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
    if (strlen($initials) >= 2) break;
}

// Get all CVs
$stmt = $pdo->prepare("
    SELECT cv_id, LEFT(content, 200) as preview, created_at
    FROM cvs
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$user_id]);
$cvs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My CVs - AIMAI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            background: linear-gradient(135deg, #f0f4f8, #e6f0ff);
            min-height: 100vh;
        }
        
        header {
            background: linear-gradient(135deg, #1a2a6c, #0d1b4b);
            color: white;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            font-size: 1.8rem;
            font-weight: 800;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 16px;
            color: white;
            background: linear-gradient(45deg, #1a2a6c, #4db8ff);
        }
        
        .container { max-width: 1200px; margin: 30px auto; padding: 0 20px; }
        
        h1 { 
            color: #1a2a6c; 
            margin-bottom: 30px;
            font-size: 2rem;
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
        
        .cv-list { 
            display: grid; 
            gap: 25px; 
        }
        
        .cv-card { 
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
        }
        
        .cv-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
        }
        
        .cv-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .cv-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #1a2a6c;
        }
        
        .cv-preview { 
            font-family: monospace;
            font-size: 13px;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            white-space: pre-wrap;
            color: #495057;
            max-height: 150px;
            overflow: hidden;
            position: relative;
        }
        
        .cv-preview::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 40px;
            background: linear-gradient(transparent, #f8f9fa);
        }
        
        .cv-meta { 
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .cv-actions { 
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .btn { 
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .btn-view {
            background: linear-gradient(45deg, #1a2a6c, #4db8ff);
            color: white;
        }
        
        .btn-view:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(26, 42, 108, 0.3);
        }
        
        .btn-download {
            background: #28a745;
            color: white;
        }
        
        .btn-download:hover {
            background: #218838;
            transform: translateY(-2px);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        }
        
        .empty-state i {
            font-size: 64px;
            color: #dee2e6;
            margin-bottom: 20px;
        }
        
        .empty-state h2 {
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: #adb5bd;
            margin-bottom: 25px;
        }
        
        .btn-generate {
            background: linear-gradient(45deg, #ff6b6b, #ff9e9e);
            color: white;
            padding: 12px 30px;
            font-size: 16px;
        }
        
        .btn-generate:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.3);
        }
        
        @media (max-width: 768px) {
            .cv-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .cv-actions {
                width: 100%;
            }
            
            .btn {
                flex: 1;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="header-container">
            <div class="logo">AimAI</div>
            <div class="user-info">
                <div class="user-avatar"><?php echo htmlspecialchars($initials); ?></div>
            </div>
        </div>
    </header>

    <div class="container">
        <a href="ai_coach.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <h1><i class="fas fa-file-alt"></i> My CVs</h1>
        
        <?php if (empty($cvs)): ?>
            <div class="empty-state">
                <i class="fas fa-file-alt"></i>
                <h2>No CVs Generated Yet</h2>
                <p>Create your first professional CV based on your skills and achievements</p>
                <a href="ai_coach.php" class="btn btn-generate">
                    <i class="fas fa-plus"></i> Generate Your First CV
                </a>
            </div>
        <?php else: ?>
            <div class="cv-list">
                <?php foreach ($cvs as $cv): ?>
                    <div class="cv-card">
                        <div class="cv-header">
                            <div class="cv-title">
                                <i class="fas fa-file-alt"></i> CV - <?php echo date('F d, Y', strtotime($cv['created_at'])); ?>
                            </div>
                        </div>
                        
                        <div class="cv-meta">
                            <i class="far fa-calendar"></i>
                            Generated: <?php echo date('M d, Y \a\t g:i A', strtotime($cv['created_at'])); ?>
                        </div>
                        
                        <div class="cv-preview"><?php echo htmlspecialchars($cv['preview']); ?>...</div>
                        
                        <div class="cv-actions">
                            <a href="view_cv.php?id=<?php echo $cv['cv_id']; ?>" class="btn btn-view">
                                <i class="fas fa-eye"></i> View Full CV
                            </a>
                            <a href="download_cv.php?id=<?php echo $cv['cv_id']; ?>" class="btn btn-download">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>