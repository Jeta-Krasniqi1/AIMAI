<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    header("Location: student_dashboard.php");
    exit;
}

$cv_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Get CV
    $stmt = $pdo->prepare("SELECT content, created_at FROM cvs WHERE cv_id = ? AND user_id = ?");
    $stmt->execute([$cv_id, $user_id]);
    $cv = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cv) {
        die("CV not found or access denied");
    }
} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Generate avatar initials
$stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$nameParts = explode(' ', $user['username']);
$initials = '';
foreach ($nameParts as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
    if (strlen($initials) >= 2) break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View CV - AIMAI</title>
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
        
        .container { 
            max-width: 1200px; 
            margin: 30px auto; 
            padding: 0 20px; 
        }
        
        .actions-bar {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }
        
        .btn-back {
            background: #6c757d;
            color: white;
        }
        
        .btn-back:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .btn-download {
            background: linear-gradient(45deg, #1a2a6c, #4db8ff);
            color: white;
        }
        
        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(26, 42, 108, 0.3);
        }
        
        .cv-container { 
            background: white; 
            padding: 50px; 
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.05);
        }
        
        pre { 
            white-space: pre-wrap; 
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            color: #333;
        }
        
        .cv-meta {
            text-align: center;
            color: #6c757d;
            font-size: 14px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }
        
        @media (max-width: 768px) {
            .cv-container { 
                padding: 30px 20px; 
            }
            
            pre {
                font-size: 12px;
            }
            
            .actions-bar {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
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
        <div class="actions-bar">
            <a href="ai_coach.php" class="btn btn-back">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
            <a href="download_cv.php?id=<?php echo $cv_id; ?>" class="btn btn-download">
                <i class="fas fa-download"></i> Download CV
            </a>
        </div>
        
        <div class="cv-container">
            <pre><?php echo htmlspecialchars($cv['content']); ?></pre>
            
            <div class="cv-meta">
                <p><i class="far fa-calendar"></i> Generated on: <?php echo date('F d, Y \a\t g:i A', strtotime($cv['created_at'])); ?></p>
            </div>
        </div>
    </div>
</body>
</html>