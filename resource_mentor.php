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

    // Fetch all mentees for notification purposes
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username
        FROM mentorships m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = :mentor_id AND m.status = 'active'
        ORDER BY u.username
    ");
    $stmt->execute([':mentor_id' => $_SESSION['user_id']]);
    $mentees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch distinct categories
    $stmt = $pdo->prepare("SELECT DISTINCT category FROM resources ORDER BY category");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Handle form submission
    $errors = [];
    $success = '';
    $title = $description = $category = $url = $selected_category = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_resource'])) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $category = trim($_POST['category']);
        $url = trim($_POST['url']);
        $notify_mentees = isset($_POST['notify_mentees']) ? $_POST['notify_mentees'] : [];

        // Validate inputs
        if (empty($title)) {
            $errors[] = "Resource title is required.";
        }
        if (empty($description)) {
            $errors[] = "Description is required.";
        }
        if (empty($category)) {
            $errors[] = "Category is required.";
        }
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = "Valid URL is required.";
        }

        // Save resource
        if (empty($errors)) {
            $stmt = $pdo->prepare("
                INSERT INTO resources (user_id, title, description, category, url)
                VALUES (:user_id, :title, :description, :category, :url)
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':title' => $title,
                ':description' => $description,
                ':category' => $category,
                ':url' => $url
            ]);

            // Send notifications to selected mentees
            if (!empty($notify_mentees)) {
                $stmt = $pdo->prepare("
                    INSERT INTO notifications (user_id, message)
                    VALUES (:user_id, :message)
                ");
                foreach ($notify_mentees as $mentee_id) {
                    $stmt->execute([
                        ':user_id' => $mentee_id,
                        ':message' => "New resource added by your mentor: $title"
                    ]);
                }
            }

            $success = "Resource added successfully!";
            $title = $description = $category = $url = ''; // Clear form
        }
    }

    // Fetch resources with optional category filter
    $selected_category = isset($_GET['category']) ? trim($_GET['category']) : '';
    $query = "SELECT resource_id, title, description, category, url, created_at FROM resources";
    $params = [];
    if ($selected_category) {
        $query .= " WHERE category = :category";
        $params[':category'] = $selected_category;
    }
    $query .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $resources = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Mentor Resources</title>
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

        /* Form and Resources Sections */
        .resources-form-container, .resources-container {
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

        .form-group input, .form-group select, .form-group textarea {
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

        .form-group.checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
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

        /* Category Filter */
        .category-filter {
            margin-bottom: 20px;
        }

        .category-filter select {
            padding: 8px;
            border-radius: 8px;
            border: 1px solid #dee2e6;
            font-size: 14px;
        }

        /* Resources Table */
        .resources-table {
            width: 100%;
            border-collapse: collapse;
        }

        .resources-table th, .resources-table td {
            padding: 12px;
            border-bottom: 1px solid #e9ecef;
            text-align: left;
        }

        .resources-table th {
            background: #f8f9fa;
            font-weight: 600;
        }

        .resources-table td {
            font-size: 14px;
        }

        .resources-table a {
            color: var(--mentor-color);
            text-decoration: none;
        }

        .resources-table a:hover {
            text-decoration: underline;
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

            .resources-form-container, .resources-container {
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

            .resources-table th, .resources-table td {
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
        }

        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .resources-form-container, .resources-container {
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
            <a href="aicoachmentor.php" class="nav-item">
                <i class="fas fa-robot"></i> AI Coach
            </a>
            <a href="resources.php" class="nav-item active">
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
                <h2>Manage Resources</h2>
                <p>Add and view resources to support your mentees' learning and career goals.</p>
            </div>

            <!-- Add Resource Form -->
            <div class="resources-form-container">
                <h3>Add New Resource</h3>
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
                <form action="resources.php" method="POST">
                    <div class="form-group">
                        <label for="title">Resource Title</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($title); ?>" placeholder="e.g., Learn Python the Hard Way" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" placeholder="e.g., A comprehensive book for beginners" required><?php echo htmlspecialchars($description); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <input type="text" name="category" id="category" value="<?php echo htmlspecialchars($category); ?>" placeholder="e.g., Programming" required>
                    </div>
                    <div class="form-group">
                        <label for="url">URL</label>
                        <input type="url" name="url" id="url" value="<?php echo htmlspecialchars($url); ?>" placeholder="e.g., https://example.com" required>
                    </div>
                    <div class="form-group checkbox-group">
                        <label>Notify Mentees</label>
                        <?php foreach ($mentees as $mentee): ?>
                            <label>
                                <input type="checkbox" name="notify_mentees[]" value="<?php echo $mentee['user_id']; ?>">
                                <?php echo htmlspecialchars($mentee['username']); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="add_resource" class="form-submit">
                        <i class="fas fa-plus"></i> Add Resource
                    </button>
                </form>
            </div>

            <!-- Resources List -->
            <div class="resources-container">
                <h3>Resources</h3>
                <div class="category-filter">
                    <form action="resources.php" method="GET">
                        <select name="category" onchange="this.form.submit()">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($cat === $selected_category) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                <?php if (empty($resources)): ?>
                    <p>No resources found. Add a resource above.</p>
                <?php else: ?>
                    <table class="resources-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Description</th>
                                <th>Category</th>
                                <th>URL</th>
                                <th>Added</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resources as $resource): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($resource['title']); ?></td>
                                    <td><?php echo htmlspecialchars($resource['description']); ?></td>
                                    <td><?php echo htmlspecialchars($resource['category']); ?></td>
                                    <td><a href="<?php echo htmlspecialchars($resource['url']); ?>" target="_blank">Link</a></td>
                                    <td><?php echo date('M d, Y H:i', strtotime($resource['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
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
        });
    </script>
</body>
</html>