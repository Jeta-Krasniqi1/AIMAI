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

try {
    // Fetch user data
    $stmt = $pdo->prepare("SELECT username, personality_type FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        header("Location: login.php");
        exit;
    }

    // Handle personality update
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['personality_type']) && isset($_POST['csrf_token'])) {
        if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            die("Invalid CSRF token");
        }
        $personality_type = trim($_POST['personality_type']);
        $valid_types = ['INTJ', 'INTP', 'ENTJ', 'ENTP', 'INFJ', 'INFP', 'ENFJ', 'ENFP', 
                        'ISTJ', 'ISFJ', 'ESTJ', 'ESFJ', 'ISTP', 'ISFP', 'ESTP', 'ESFP'];
        if (in_array($personality_type, $valid_types)) {
            $stmt = $pdo->prepare("UPDATE users SET personality_type = ? WHERE user_id = ?");
            $stmt->execute([$personality_type, $_SESSION['user_id']]);

            // Send personality type to n8n webhook
            $webhook_url = 'https://n8n.yourdomain.com/webhook/personality'; // Replace with your n8n webhook URL
            $data = [
                'user_id' => $_SESSION['user_id'],
                'personality_type' => $personality_type,
                'username' => $user['username']
            ];
            $options = [
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($data)
                ]
            ];
            $context = stream_context_create($options);
            file_get_contents($webhook_url, false, $context);

            // Regenerate CSRF token
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header("Location: personality.php");
            exit;
        } else {
            $error = "Invalid personality type selected.";
        }
    }

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
    <title>AimAI - Personality</title>
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

        .personality-info {
            margin-bottom: 20px;
        }

        .personality-info h4 {
            font-size: 16px;
            color: #333;
            margin-bottom: 10px;
        }

        .personality-info p {
            font-size: 14px;
            color: #6c757d;
            line-height: 1.6;
        }

        .quiz-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .quiz-question {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
        }

        .quiz-question h4 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #333;
        }

        .quiz-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .quiz-options label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            cursor: pointer;
        }

        .quiz-options input[type="radio"] {
            width: 20px;
            height: 20px;
        }

        .update-form {
            margin-top: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .update-form select {
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
            width: 100%;
            max-width: 300px;
        }

        .update-form button {
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            width: fit-content;
        }

        .update-form button:hover {
            background: #3aa0e0;
        }

        .error-message {
            color: var(--danger);
            font-size: 14px;
            margin-bottom: 10px;
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

            .card-header {
                padding: 15px;
            }

            .card-body {
                padding: 15px;
            }

            .quiz-question {
                padding: 12px;
            }

            .quiz-question h4 {
                font-size: 14px;
            }

            .quiz-options label {
                font-size: 13px;
            }

            .update-form select {
                padding: 8px;
            }

            .update-form button {
                padding: 8px 15px;
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

            .quiz-options {
                gap: 8px;
            }

            .quiz-options input[type="radio"] {
                width: 18px;
                height: 18px;
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
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-role">
                        Student <?php echo $user['personality_type'] ? '- ' . htmlspecialchars($user['personality_type']) : ''; ?>
                    </div>
                </div>
                <div class="user-avatar">
                    <?php echo htmlspecialchars($initials); ?>
                </div>
                <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container">
      <div class="sidebar">
            <div class="nav-title">MAIN NAVIGATION</div>
            <a href="student_dashboard.php" class="nav-item ">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="goals.php" class="nav-item">
                <i class="fas fa-bullseye"></i> Goals
            </a>
            <a href="progress.php" class="nav-item">
                <i class="fas fa-chart-line"></i> Progress
            </a>
            <a href="personality.php" class="nav-item active">
                <i class="fas fa-brain"></i> Personality
            </a>
            <a href="ai_coach.php" class="nav-item ">
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

        <!-- Personality Content -->
        <div class="main-content">
            <div class="card">
                <div class="card-header">
                    <h3>Your Personality Profile</h3>
                    <i class="fas fa-brain"></i>
                </div>
                <div class="card-body">
                    <?php if (isset($error)): ?>
                        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    <div class="personality-info">
                        <h4>Current Personality Type</h4>
                        <p>
                            <?php if ($user['personality_type']): ?>
                                Your current personality type is <strong><?php echo htmlspecialchars($user['personality_type']); ?></strong>.
                                This is based on the Myers-Briggs Type Indicator (MBTI). Take the quiz below to update or confirm your personality type.
                            <?php else: ?>
                                You haven't set a personality type yet. Take the quiz below to discover your type!
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="quiz-container">
                        <h3>Personality Assessment</h3>
                        <p>Answer the following questions to determine your MBTI personality type:</p>
                        <form id="personalityQuiz" onsubmit="calculatePersonality(event)">
                            <!-- Question 1: Introversion vs. Extraversion -->
                            <div class="quiz-question">
                                <h4>1. How do you prefer to spend your time?</h4>
                                <div class="quiz-options">
                                    <label><input type="radio" name="q1" value="I" required> I enjoy solitary activities and need time alone to recharge.</label>
                                    <label><input type="radio" name="q1" value="E"> I enjoy social activities and feel energized by being around others.</label>
                                </div>
                            </div>
                            <!-- Question 2: Sensing vs. Intuition -->
                            <div class="quiz-question">
                                <h4>2. How do you process information?</h4>
                                <div class="quiz-options">
                                    <label><input type="radio" name="q2" value="S" required> I focus on facts and details, preferring concrete information.</label>
                                    <label><input type="radio" name="q2" value="N"> I focus on possibilities and patterns, preferring abstract ideas.</label>
                                </div>
                            </div>
                            <!-- Question 3: Thinking vs. Feeling -->
                            <div class="quiz-question">
                                <h4>3. How do you make decisions?</h4>
                                <div class="quiz-options">
                                    <label><input type="radio" name="q3" value="T" required> I rely on logical analysis and objective criteria.</label>
                                    <label><input type="radio" name="q3" value="F"> I consider my values and emotions in decision-making.</label>
                                </div>
                            </div>
                            <!-- Question 4: Judging vs. Perceiving -->
                            <div class="quiz-question">
                                <h4>4. How do you approach planning?</h4>
                                <div class="quiz-options">
                                    <label><input type="radio" name="q4" value="J" required> I prefer structured plans and organized schedules.</label>
                                    <label><input type="radio" name="q4" value="P"> I prefer flexibility and keeping my options open.</label>
                                </div>
                            </div>
                            <button type="submit" class="update-form button">Calculate Personality Type</button>
                        </form>
                    </div>
                    <form method="POST" class="update-form" id="updatePersonalityForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <label for="personality_type">Select or Confirm Your Personality Type:</label>
                        <select name="personality_type" id="personality_type" required>
                            <option value="" disabled selected>Select a personality type</option>
                            <option value="INTJ">INTJ - The Architect</option>
                            <option value="INTP">INTP - The Thinker</option>
                            <option value="ENTJ">ENTJ - The Commander</option>
                            <option value="ENTP">ENTP - The Debater</option>
                            <option value="INFJ">INFJ - The Advocate</option>
                            <option value="INFP">INFP - The Mediator</option>
                            <option value="ENFJ">ENFJ - The Protagonist</option>
                            <option value="ENFP">ENFP - The Campaigner</option>
                            <option value="ISTJ">ISTJ - The Logistician</option>
                            <option value="ISFJ">ISFJ - The Defender</option>
                            <option value="ESTJ">ESTJ - The Executive</option>
                            <option value="ESFJ">ESFJ - The Consul</option>
                            <option value="ISTP">ISTP - The Virtuoso</option>
                            <option value="ISFP">ISFP - The Adventurer</option>
                            <option value="ESTP">ESTP - The Entrepreneur</option>
                            <option value="ESFP">ESFP - The Entertainer</option>
                        </select>
                        <button type="submit">Update Personality Type</button>
                    </form>
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
                icon.classList.toggle('fa-bars');
                icon.classList.toggle('fa-times');
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

            // Personality quiz calculation
            window.calculatePersonality = function(event) {
                event.preventDefault();
                const form = document.getElementById('personalityQuiz');
                const formData = new FormData(form);
                const answers = {
                    q1: formData.get('q1'),
                    q2: formData.get('q2'),
                    q3: formData.get('q3'),
                    q4: formData.get('q4')
                };

                if (!answers.q1 || !answers.q2 || !answers.q3 || !answers.q4) {
                    alert('Please answer all questions.');
                    return;
                }

                const personalityType = `${answers.q1}${answers.q2}${answers.q3}${answers.q4}`;
                const select = document.getElementById('personality_type');
                select.value = personalityType;
            };
        });
    </script>
</body>
</html>
