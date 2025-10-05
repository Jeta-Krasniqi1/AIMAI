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

    // Handle search and pagination
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $limit = 10;
    $offset = ($page - 1) * $limit;

    // Fetch mentees with search
    $search_query = $search ? "AND u.username LIKE :search" : "";
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.username, u.personality_type
        FROM mentorships m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = :mentor_id AND m.status = 'active' $search_query
        ORDER BY u.username
        LIMIT :limit OFFSET :offset
    ");
    $stmt->bindValue(':mentor_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    if ($search) {
        $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
    }
    $stmt->execute();
    $mentees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Count total mentees for pagination
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM mentorships m
        JOIN users u ON m.user_id = u.user_id
        WHERE m.mentor_id = :mentor_id AND m.status = 'active' $search_query
    ");
    $stmt->bindValue(':mentor_id', $_SESSION['user_id'], PDO::PARAM_INT);
    if ($search) {
        $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
    }
    $stmt->execute();
    $total_mentees = $stmt->fetchColumn();
    $total_pages = ceil($total_mentees / $limit);

    // Fetch last review for each mentee
    $mentee_reviews = [];
    foreach ($mentees as $mentee) {
        $stmt = $pdo->prepare("
            SELECT rating, comment, created_at
            FROM reviews
            WHERE user_id = :user_id
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $mentee['user_id']]);
        $mentee_reviews[$mentee['user_id']] = $stmt->fetch(PDO::FETCH_ASSOC);
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
    <title>AimAI - Mentees</title>
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
        
        /* Search Bar */
        .search-bar {
            display: flex;
            gap: 10px;
            background: white;
            border-radius: 10px;
            padding: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .search-bar input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
            min-width: 0;
        }
        
        .search-bar button {
            background: var(--mentor-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 10px 20px;
            cursor: pointer;
            transition: all 0.3s;
            flex-shrink: 0;
        }
        
        .search-bar button:hover {
            background: #8e44ad;
        }
        
        /* Cards Grid */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
        }
        
        .card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            animation: fadeIn 0.5s ease-out;
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
            color: var(--mentor-color);
        }
        
        .card-header i {
            font-size: 20px;
            color: var(--mentor-color);
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Mentee Item */
        .mentee-item {
            display: flex;
            flex-direction: column;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .mentee-item:last-child {
            border-bottom: none;
        }
        
        .mentee-content {
            flex: 1;
            min-width: 0;
        }
        
        .mentee-title {
            font-weight: 500;
            margin-bottom: 5px;
            word-wrap: break-word;
        }
        
        .mentee-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: #6c757d;
            flex-wrap: wrap;
        }
        
        .mentee-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .mentee-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 8px 15px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .view-btn {
            background: var(--mentor-color);
            color: white;
        }
        
        .view-btn:hover {
            background: #8e44ad;
        }
        
        .message-btn {
            background: var(--secondary);
            color: white;
        }
        
        .message-btn:hover {
            background: #3aa0e0;
        }
        
        .session-btn {
            background: var(--success);
            color: white;
        }
        
        .session-btn:hover {
            background: #27ae60;
        }
        
        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination a {
            padding: 10px 15px;
            border-radius: 8px;
            background: #f8f9fa;
            color: #495057;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background: var(--mentor-color);
            color: white;
        }
        
        .pagination a.active {
            background: var(--mentor-color);
            color: white;
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
        
        /* Sidebar Overlay for Mobile */
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
            .dashboard-container {
                padding: 0 15px;
                gap: 20px;
            }
            
            .cards-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
            
            .cards-grid {
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 15px;
            }
            
            .search-bar {
                flex-direction: column;
                gap: 8px;
            }
            
            .search-bar input, .search-bar button {
                width: 100%;
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
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            .card-header {
                padding: 15px;
            }
            
            .card-body {
                padding: 15px;
            }
            
            .mentee-meta {
                gap: 10px;
                font-size: 12px;
            }
            
            .mentee-actions {
                flex-direction: column;
                gap: 8px;
            }
            
            .action-btn {
                width: 100%;
                justify-content: center;
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
            
            .cards-grid {
                gap: 10px;
            }
            
            .card-header {
                padding: 12px;
            }
            
            .card-body {
                padding: 12px;
            }
            
            .mentee-item {
                padding: 12px 0;
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
        
        .card:nth-child(1) { animation-delay: 0.1s; }
        .card:nth-child(2) { animation-delay: 0.2s; }
        .card:nth-child(3) { animation-delay: 0.3s; }
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
            <a href="mentees.php" class="nav-item active">
                <i class="fas fa-user-graduate"></i> Mentees
            </a>
            <a href="sessions.php" class="nav-item">
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
                <h2>Manage Your Mentees</h2>
                <p>View and interact with your active mentees to guide their growth.</p>
            </div>
            
            <!-- Search Bar -->
            <div class="search-bar">
                <form action="mentees.php" method="GET" style="display: flex; width: 100%; gap: 10px;">
                    <input type="text" name="search" placeholder="Search mentees by name..." value="<?php echo htmlspecialchars($search); ?>">
                    <button type="submit"><i class="fas fa-search"></i> Search</button>
                </form>
            </div>
            
            <!-- Mentees List -->
            <div class="cards-grid">
                <div class="card mentor-card">
                    <div class="card-header">
                        <h3>Your Mentees (<?php echo $total_mentees; ?>)</h3>
                        <i class="fas fa-user-graduate"></i>
                    </div>
                    <div class="card-body">
                        <?php if (empty($mentees)): ?>
                            <p>No mentees found. <?php echo $search ? 'Try adjusting your search.' : ''; ?></p>
                        <?php else: ?>
                            <?php foreach ($mentees as $mentee): ?>
                                <div class="mentee-item">
                                    <div class="mentee-content">
                                        <div class="mentee-title"><?php echo htmlspecialchars($mentee['username']); ?></div>
                                        <div class="mentee-meta">
                                            <span><i class="fas fa-brain"></i> <?php echo htmlspecialchars($mentee['personality_type'] ?: 'N/A'); ?></span>
                                            <?php if (isset($mentee_reviews[$mentee['user_id']])): ?>
                                                <span><i class="fas fa-star"></i> Last Review: <?php echo date('M d, Y', strtotime($mentee_reviews[$mentee['user_id']]['created_at'])); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="mentee-actions">
                                        <button class="action-btn view-btn" data-mentee-id="<?php echo $mentee['user_id']; ?>">
                                            <i class="fas fa-eye"></i> View Profile
                                        </button>
                                        <button class="action-btn message-btn" data-mentee-id="<?php echo $mentee['user_id']; ?>">
                                            <i class="fas fa-envelope"></i> Message
                                        </button>
                                        <button class="action-btn session-btn" data-mentee-id="<?php echo $mentee['user_id']; ?>">
                                            <i class="fas fa-calendar-alt"></i> Schedule Session
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="mentees.php?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="<?php echo $page == $i ? 'active' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
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
            
            // Action button handlers
            const viewButtons = document.querySelectorAll('.view-btn');
            viewButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const menteeId = this.getAttribute('data-mentee-id');
                    alert(`Viewing profile for mentee ID: ${menteeId}`);
                    // Future: Redirect to profile page, e.g., window.location.href = `profile.php?id=${menteeId}`;
                });
            });
            
            const messageButtons = document.querySelectorAll('.message-btn');
            messageButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const menteeId = this.getAttribute('data-mentee-id');
                    alert(`Opening messaging for mentee ID: ${menteeId}`);
                    // Future: Open messaging interface
                });
            });
            
            const sessionButtons = document.querySelectorAll('.session-btn');
            sessionButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    const menteeId = this.getAttribute('data-mentee-id');
                    alert(`Scheduling session for mentee ID: ${menteeId}`);
                    // Future: Redirect to sessions.php or open scheduling modal
                });
            });
        });
    </script>
</body>
</html>