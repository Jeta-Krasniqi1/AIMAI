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

    // Fetch session details
    $session_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$session_id) {
        throw new Exception("Invalid session ID");
    }

    $stmt = $pdo->prepare("
        SELECT s.session_id, s.mentorship_id, s.session_title, s.session_date, s.session_type, u.username, u.user_id
        FROM sessions s
        JOIN mentorships m ON s.mentorship_id = m.mentorship_id
        JOIN users u ON m.user_id = u.user_id
        WHERE s.session_id = :session_id AND m.mentor_id = :mentor_id
    ");
    $stmt->execute([':session_id' => $session_id, ':mentor_id' => $_SESSION['user_id']]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) {
        throw new Exception("Session not found or you don't have permission to edit it");
    }

    // Handle form submission
    $errors = [];
    $success = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $session_title = trim($_POST['session_title']);
        $session_date = $_POST['session_date'];
        $session_type = trim($_POST['session_type']);

        // Validate inputs
        if (empty($session_title)) {
            $errors[] = "Session title is required.";
        }
        if (empty($session_date) || strtotime($session_date) < time()) {
            $errors[] = "Please select a valid future date.";
        }
        if (empty($session_type)) {
            $errors[] = "Session type is required.";
        }

        // Update session if no errors
        if (empty($errors)) {
            $stmt = $pdo->prepare("
                UPDATE sessions 
                SET session_title = :title, session_date = :date, session_type = :type
                WHERE session_id = :session_id AND mentorship_id IN (
                    SELECT mentorship_id FROM mentorships WHERE mentor_id = :mentor_id
                )
            ");
            $stmt->execute([
                ':title' => $session_title,
                ':date' => $session_date,
                ':type' => $session_type,
                ':session_id' => $session_id,
                ':mentor_id' => $_SESSION['user_id']
            ]);

            // Add notification for mentee
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, message)
                VALUES (:user_id, :message)
            ");
            $stmt->execute([
                ':user_id' => $session['user_id'],
                ':message' => "Session updated: $session_title on $session_date"
            ]);

            $success = "Session updated successfully!";
        }
    }

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Edit Session</title>
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
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
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
        
        /* Back Button */
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 10px 20px;
            background: var(--mentor-color);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s;
            font-weight: 500;
        }
        
        .back-btn:hover {
            background: #8e44ad;
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
            
            .session-form-container {
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
            
            .session-form-container {
                padding: 12px;
            }
            
            .form-group {
                margin-bottom: 12px;
            }
            
            .form-submit, .back-btn {
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
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .session-form-container {
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
                <h2>Edit Session</h2>
                <p>Update the details of the mentoring session.</p>
            </div>
            
            <!-- Session Form -->
            <div class="session-form-container">
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
                <form action="edit_session.php?id=<?php echo $session_id; ?>" method="POST">
                    <div class="form-group">
                        <label>Mentee</label>
                        <p><strong><?php echo htmlspecialchars($session['username']); ?></strong></p>
                    </div>
                    <div class="form-group">
                        <label for="session_title">Session Title</label>
                        <input type="text" name="session_title" id="session_title" value="<?php echo htmlspecialchars($session['session_title']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="session_date">Session Date & Time</label>
                        <input type="datetime-local" name="session_date" id="session_date" value="<?php echo date('Y-m-d\TH:i', strtotime($session['session_date'])); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="session_type">Session Type</label>
                        <select name="session_type" id="session_type" required>
                            <option value="Video Call" <?php echo $session['session_type'] === 'Video Call' ? 'selected' : ''; ?>>Video Call</option>
                            <option value="In-Person" <?php echo $session['session_type'] === 'In-Person' ? 'selected' : ''; ?>>In-Person</option>
                            <option value="Chat" <?php echo $session['session_type'] === 'Chat' ? 'selected' : ''; ?>>Chat</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="form-submit">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="sessions.php" class="back-btn">
                            <i class="fas fa-arrow-left"></i> Back to Sessions
                        </a>
                    </div>
                </form>
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
            
            // Set minimum date for session_date input
            const sessionDateInput = document.getElementById('session_date');
            if (sessionDateInput) {
                const now = new Date();
                const minDate = now.toISOString().slice(0, 16);
                sessionDateInput.setAttribute('min', minDate);
            }
        });
    </script>
</body>
</html>