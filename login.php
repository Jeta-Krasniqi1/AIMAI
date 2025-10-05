<?php
session_start();
require_once 'config.php';

// Handle Signup
if (isset($_POST['signup'])) {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $personality_type = $_POST['personality_type'] ?? null;
    $role = $_POST['role'] ?? '';

    if (empty($username) || empty($email) || empty($password) || empty($role)) {
        $_SESSION['signup_error'] = "All fields including role are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['signup_error'] = "Invalid email format.";
    } elseif (strlen($password) < 8) {
        $_SESSION['signup_error'] = "Password must be at least 8 characters.";
    } else {
        try {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $_SESSION['signup_error'] = "Email already exists.";
            } else {
                // Create new user
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, personality_type, role) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$username, $email, $password_hash, $personality_type, $role]);

                $_SESSION['signup_success'] = "Registration successful! Please log in.";
                header("Location: ".$_SERVER['PHP_SELF']."?form=login");
                exit();
            }
        } catch (PDOException $e) {
            $_SESSION['signup_error'] = "Registration error: ".$e->getMessage();
        }
    }
}

// Handle Login
if (isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $_SESSION['login_error'] = "Email and password are required.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT user_id, username, password_hash, personality_type, role FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                // Set user session data
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['personality'] = $user['personality_type'];

                // Redirect based on role
                switch ($user['role']) {
                    case 'admin':
                        header("Location: admin_dashboard.php");
                        break;
                    case 'company':
                        header("Location: company_dashboard.php");
                        break;
                    case 'mentor':
                        header("Location: mentor_dashboard.php");
                        break;
                    default:
                        header("Location: student_dashboard.php");
                        break;
                }
                exit();
            } else {
                $_SESSION['login_error'] = "Invalid email or password.";
            }
        } catch (PDOException $e) {
            $_SESSION['login_error'] = "Login error: ".$e->getMessage();
        }
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: ".$_SERVER['PHP_SELF']);
    exit();
}

// Determine which form to show (default: login)
$form = $_GET['form'] ?? 'login';
$show_login = ($form === 'login');

// Get and clear session messages
$signup_error = $_SESSION['signup_error'] ?? null;
$signup_success = $_SESSION['signup_success'] ?? null;
$login_error = $_SESSION['login_error'] ?? null;
unset($_SESSION['signup_error'], $_SESSION['signup_success'], $_SESSION['login_error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Personal Growth Platform</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(135deg, #0f0f23 0%, #1a1a2e 50%, #16213e 100%);
            color: #ffffff;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow-x: hidden;
        }

        .container {
            width: 100%;
            max-width: 1100px;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(20px);
            border-radius: 25px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.4);
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.1);
            position: relative;
        }

        .container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
        }

        .content {
            display: flex;
            min-height: 600px;
        }

        .left-panel {
            flex: 1;
            padding: 50px;
            background: rgba(15, 15, 35, 0.8);
            backdrop-filter: blur(10px);
            display: flex;
            flex-direction: column;
            justify-content: center;
            border-right: 1px solid rgba(255, 255, 255, 0.1);
        }

        .right-panel {
            flex: 1;
            background: linear-gradient(135deg, #00d4ff, #7c3aed);
            padding: 50px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: white;
            position: relative;
            overflow: hidden;
        }

        .right-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }

        .form-header h2 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(45deg, #ffffff, #00d4ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }

        .form-header p {
            color: #b0b0b0;
            font-size: 1.1rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #ffffff;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .input-with-icon {
            position: relative;
        }

        .input-with-icon i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #00d4ff;
            font-size: 1.1rem;
        }

        .input-with-icon input,
        .input-with-icon select {
            width: 100%;
            padding: 16px 16px 16px 50px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 15px;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }

        .input-with-icon input::placeholder {
            color: #b0b0b0;
        }

        .input-with-icon input:focus,
        .input-with-icon select:focus {
            border-color: #00d4ff;
            outline: none;
            box-shadow: 0 0 0 3px rgba(0, 212, 255, 0.2);
            background: rgba(255, 255, 255, 0.08);
        }

        .input-with-icon select {
            cursor: pointer;
        }

        .input-with-icon select option {
            background: #1a1a2e;
            color: #ffffff;
        }

        .btn {
            width: 100%;
            padding: 16px;
            border: none;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .btn-primary {
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            color: white;
            margin-top: 1rem;
            box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.5);
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s;
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .toggle-form {
            text-align: center;
            margin-top: 2rem;
            color: #b0b0b0;
        }

        .toggle-form a {
            color: #00d4ff;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .toggle-form a:hover {
            color: #ffffff;
            text-shadow: 0 0 10px rgba(0, 212, 255, 0.5);
        }

        .alert {
            padding: 16px 20px;
            border-radius: 15px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .alert-success {
            background: rgba(46, 204, 113, 0.2);
            border-color: #2ecc71;
            color: #2ecc71;
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.2);
            border-color: #e74c3c;
            color: #ff6b6b;
        }

        .alert i {
            font-size: 1.2rem;
        }

        .personality-info {
            background: rgba(0, 212, 255, 0.1);
            border: 1px solid rgba(0, 212, 255, 0.3);
            border-radius: 15px;
            padding: 1rem;
            margin-top: 10px;
            display: none;
            backdrop-filter: blur(10px);
        }

        .personality-info.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .personality-info h4 {
            color: #00d4ff;
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .personality-info p {
            color: #b0b0b0;
            font-size: 0.9rem;
            line-height: 1.5;
        }

        .right-panel-content {
            position: relative;
            z-index: 2;
        }

        .right-panel h2 {
            font-size: 2.5rem;
            font-weight: 800;
            margin-bottom: 1.5rem;
            color: white;
        }

        .right-panel p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            color: rgba(255, 255, 255, 0.9);
            line-height: 1.6;
        }

        .features {
            margin-top: 2rem;
        }

        .feature {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            gap: 1rem;
            background: rgba(255, 255, 255, 0.1);
            padding: 1rem;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .feature:hover {
            transform: translateX(10px);
            background: rgba(255, 255, 255, 0.15);
        }

        .feature i {
            font-size: 1.5rem;
            width: 40px;
            text-align: center;
            color: rgba(255, 255, 255, 0.8);
        }

        .feature-content h3 {
            margin-bottom: 0.3rem;
            font-size: 1.1rem;
            color: white;
        }

        .feature-content p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
            margin: 0;
        }

        footer {
            background: rgba(15, 15, 35, 0.9);
            color: rgba(255, 255, 255, 0.7);
            text-align: center;
            padding: 1.5rem;
            font-size: 0.9rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Animations */
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.8; }
            50% { transform: scale(1.05); opacity: 1; }
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(5deg); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive Design Enhancements */
        @media (max-width: 1024px) {
            .form-header h2 {
                font-size: 2.2rem;
            }
            
            .right-panel h2 {
                font-size: 2.2rem;
            }
            
            .right-panel p {
                font-size: 1.1rem;
            }
            
            .feature-content h3 {
                font-size: 1.05rem;
            }
            
            .feature-content p {
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 900px) {
            .content {
                flex-direction: column;
            }
            
            .left-panel,
            .right-panel {
                padding: 40px 35px;
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .right-panel {
                padding: 40px 35px;
            }
            
            .form-header h2 {
                font-size: 2rem;
            }
            
            .right-panel h2 {
                font-size: 2rem;
            }
            
            .ai-visual {
                display: none;
            }
        }
        
        @media (max-width: 768px) {
            .container {
                border-radius: 20px;
            }
            
            .left-panel,
            .right-panel {
                padding: 35px 30px;
            }
            
            .form-header h2 {
                font-size: 1.9rem;
            }
            
            .form-header p {
                font-size: 1rem;
            }
            
            .right-panel h2 {
                font-size: 1.9rem;
            }
            
            .right-panel p {
                font-size: 1rem;
            }
            
            .feature {
                padding: 0.8rem;
            }
        }
        
        @media (max-width: 576px) {
            body {
                padding: 15px;
            }
            
            .container {
                border-radius: 18px;
            }
            
            .left-panel,
            .right-panel {
                padding: 30px 25px;
            }
            
            .form-header h2 {
                font-size: 1.7rem;
            }
            
            .form-header p {
                font-size: 0.95rem;
            }
            
            .right-panel h2 {
                font-size: 1.7rem;
            }
            
            .input-with-icon input,
            .input-with-icon select {
                padding: 14px 14px 14px 45px;
                font-size: 0.95rem;
            }
            
            .input-with-icon i {
                font-size: 1rem;
                left: 15px;
            }
            
            .btn {
                padding: 14px;
                font-size: 1rem;
            }
            
            .feature {
                margin-bottom: 1.2rem;
            }
            
            .feature i {
                font-size: 1.3rem;
                width: 35px;
            }
            
            .feature-content h3 {
                font-size: 1rem;
            }
            
            .feature-content p {
                font-size: 0.82rem;
            }
            
            footer {
                padding: 1.2rem;
            }
        }
        
        @media (max-width: 480px) {
            .left-panel,
            .right-panel {
                padding: 25px 20px;
            }
            
            .form-header h2 {
                font-size: 1.6rem;
            }
            
            .form-header p {
                font-size: 0.92rem;
            }
            
            .right-panel h2 {
                font-size: 1.6rem;
            }
            
            .input-with-icon input,
            .input-with-icon select {
                padding: 12px 12px 12px 40px;
                font-size: 0.92rem;
            }
            
            .input-with-icon i {
                font-size: 0.95rem;
                left: 13px;
            }
            
            .btn {
                padding: 13px;
                font-size: 0.95rem;
            }
            
            .toggle-form {
                font-size: 0.95rem;
            }
            
            .alert {
                padding: 14px 18px;
                font-size: 0.95rem;
            }
            
            .personality-info {
                padding: 0.8rem;
            }
            
            .personality-info h4 {
                font-size: 0.95rem;
            }
            
            .personality-info p {
                font-size: 0.85rem;
            }
            
            .right-panel p {
                font-size: 0.95rem;
            }
            
            .feature {
                padding: 0.7rem;
            }
            
            footer {
                padding: 1rem;
                font-size: 0.85rem;
            }
        }
        
        @media (max-width: 360px) {
            .form-header h2 {
                font-size: 1.5rem;
            }
            
            .right-panel h2 {
                font-size: 1.5rem;
            }
            
            .input-with-icon input,
            .input-with-icon select {
                padding: 11px 11px 11px 38px;
                font-size: 0.9rem;
            }
            
            .input-with-icon i {
                font-size: 0.9rem;
            }
            
            .btn {
                padding: 12px;
            }
            
            .toggle-form {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="content">
            <div class="left-panel">
                <!-- SIGNUP FORM -->
                <div class="form-container" id="signup-form" <?= $show_login ? 'style="display:none;"' : '' ?>>
                    <div class="form-header">
                        <h2>Create Your Account</h2>
                        <p>Join our AI-powered platform and unlock your potential</p>
                    </div>

                    <?php if ($signup_error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <div><?= htmlspecialchars($signup_error) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($signup_success): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <div><?= htmlspecialchars($signup_success) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="form-group">
                            <label>Username</label>
                            <div class="input-with-icon">
                                <i class="fas fa-user"></i>
                                <input type="text" name="username" placeholder="Enter your username" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Email</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope"></i>
                                <input type="email" name="email" placeholder="Enter your email" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Password</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" name="password" placeholder="Create a password (min 8 chars)" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Personality Type (Optional)</label>
                            <div class="input-with-icon">
                                <i class="fas fa-brain"></i>
                                <select name="personality_type" id="personality_type">
                                    <option value="">Select your personality type</option>
                                    <option value="INTJ">INTJ - Architect</option>
                                    <option value="INTP">INTP - Thinker</option>
                                    <option value="ENTJ">ENTJ - Commander</option>
                                    <option value="ENTP">ENTP - Debater</option>
                                    <option value="INFJ">INFJ - Advocate</option>
                                    <option value="INFP">INFP - Mediator</option>
                                    <option value="ENFJ">ENFJ - Protagonist</option>
                                    <option value="ENFP">ENFP - Campaigner</option>
                                    <option value="ISTJ">ISTJ - Logistician</option>
                                    <option value="ISFJ">ISFJ - Protector</option>
                                    <option value="ESTJ">ESTJ - Executive</option>
                                    <option value="ESFJ">ESFJ - Consul</option>
                                    <option value="ISTP">ISTP - Virtuoso</option>
                                    <option value="ISFP">ISFP - Adventurer</option>
                                    <option value="ESTP">ESTP - Entrepreneur</option>
                                    <option value="ESFP">ESFP - Entertainer</option>
                                </select>
                            </div>
                            <div class="personality-info" id="personality-info">
                                <h4>Why share your personality type?</h4>
                                <p>This helps AimAI personalize your experience and tailor content to your unique traits and preferences.</p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Register As</label>
                            <div class="input-with-icon">
                                <i class="fas fa-user-tag"></i>
                                <select name="role" required>
                                    <option value="">Select your role</option>
                                    <option value="student">Student</option>
                                    <option value="mentor">Mentor</option>
                                    <option value="company">Company</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit" name="signup" class="btn btn-primary">
                            <i class="fas fa-user-plus"></i> Create Account
                        </button>

                        <div class="toggle-form">
                            Already have an account? <a href="?form=login">Log In</a>
                        </div>
                    </form>
                </div>

                <!-- LOGIN FORM -->
                <div class="form-container" id="login-form" <?= !$show_login ? 'style="display:none;"' : '' ?>>
                    <div class="form-header">
                        <h2>Welcome Back</h2>
                        <p>Sign in to continue your journey with AimAI</p>
                    </div>

                    <?php if ($login_error): ?>
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <div><?= htmlspecialchars($login_error) ?></div>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <div class="form-group">
                            <label>Email</label>
                            <div class="input-with-icon">
                                <i class="fas fa-envelope"></i>
                                <input type="email" name="email" placeholder="Enter your email" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Password</label>
                            <div class="input-with-icon">
                                <i class="fas fa-lock"></i>
                                <input type="password" name="password" placeholder="Enter your password" required>
                            </div>
                        </div>

                        <button type="submit" name="login" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt"></i> Sign In
                        </button>

                        <div class="toggle-form">
                            Don't have an account? <a href="?form=signup">Sign Up</a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Right Panel with Features -->
            <div class="right-panel">
                <div class="right-panel-content">
                    <h2>Unlock Your Potential with AI</h2>
                    <p>AimAI helps you set goals, track progress, and achieve more with personalized AI recommendations.</p>
                    
                    <div class="features">
                        <div class="feature">
                            <i class="fas fa-bullseye"></i>
                            <div class="feature-content">
                                <h3>Smart Goal Setting</h3>
                                <p>AI-powered goal recommendations based on your personality</p>
                            </div>
                        </div>
                        <div class="feature">
                            <i class="fas fa-chart-line"></i>
                            <div class="feature-content">
                                <h3>Progress Analytics</h3>
                                <p>Advanced tracking and insights to optimize your growth</p>
                            </div>
                        </div>
                        <div class="feature">
                            <i class="fas fa-robot"></i>
                            <div class="feature-content">
                                <h3>Personalized AI Coach</h3>
                                <p>Get tailored advice and motivation 24/7</p>
                            </div>
                        </div>
                        <div class="feature">
                            <i class="fas fa-users"></i>
                            <div class="feature-content">
                                <h3>Community & Mentorship</h3>
                                <p>Connect with mentors and like-minded individuals</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <footer>
            <p>&copy; 2025 AimAI. All rights reserved.</p>
        </footer>
    </div>

    <script>
        // Personality info toggle
        const personalitySelect = document.getElementById('personality_type');
        const personalityInfo = document.getElementById('personality-info');
        
        if (personalitySelect) {
            personalitySelect.addEventListener('change', function() {
                if (this.value) {
                    personalityInfo.classList.add('active');
                } else {
                    personalityInfo.classList.remove('active');
                }
            });
        }
    </script>
</body>
</html>