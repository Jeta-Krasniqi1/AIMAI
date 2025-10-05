<?php


session_start();
require 'config.php';

// ✅ Dashboard Stats
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_mentors = $pdo->query("SELECT COUNT(*) FROM mentors")->fetchColumn();
$total_companies = $pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
$total_jobs = $pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();

// ✅ Recent Users (latest 10)
$stmt = $pdo->query("
    SELECT user_id, username, email, role, created_at 
    FROM users ORDER BY created_at DESC LIMIT 10
");
$recent_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ User Growth (last 6 months)
$stmt = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%b') AS month, COUNT(*) AS total
    FROM users 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY MONTH(created_at)
    ORDER BY MONTH(created_at)
");
$user_growth_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
$months = json_encode(array_column($user_growth_data, 'month'));
$user_counts = json_encode(array_column($user_growth_data, 'total'));

// ✅ Platform Activity (role distribution)
$stmt = $pdo->query("
    SELECT role, COUNT(*) as total FROM users GROUP BY role
");
$roles_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$role_labels = json_encode(array_keys($roles_data));
$role_counts = json_encode(array_values($roles_data));
?>






<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        :root {
            --primary: #1a237e;
            --secondary: #5c6bc0;
            --accent: #ff4081;
            --light: #f8f9fa;
            --dark: #212529;
            --success: #2ecc71;
            --warning: #f39c12;
            --danger: #e74c3c;
            --sidebar-width: 250px;
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
            z-index: 100;
            height: var(--header-height);
        }
        
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1800px;
            margin: 0 auto;
            height: 100%;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .logo {
            font-size: 2rem;
            font-weight: 800;
            background: linear-gradient(45deg, #00d4ff, #7c3aed);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .logo i {
            font-size: 32px;
            color: var(--secondary);
        }
        
        .logo h1 {
            font-size: 24px;
            font-weight: 700;
        }
        
        .logo span {
            color: var(--secondary);
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
        
        .admin-badge {
            background: rgba(255, 64, 129, 0.2);
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 3px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(45deg, var(--accent), var(--primary));
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 18px;
            color: white;
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
            padding: 5px 10px;
            border-radius: 4px;
            transition: all 0.3s;
        }
        
        .logout-btn:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        
        /* Main Content */
        .dashboard-container {
            display: flex;
            max-width: 1800px;
            margin: 20px auto;
            padding: 0 20px;
            gap: 25px;
            flex: 1;
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
            background: rgba(92, 107, 192, 0.1);
            color: var(--primary);
            border-left: 3px solid var(--secondary);
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
        }
        
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary), #0d1b4b);
            color: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 20px rgba(26, 42, 108, 0.2);
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
        
        .ai-cta {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 10px;
            padding: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
            margin-top: 10px;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            z-index: 1;
            backdrop-filter: blur(5px);
        }
        
        .ai-cta:hover {
            background: rgba(255, 255, 255, 0.25);
        }
        
        .ai-cta i {
            font-size: 24px;
            color: var(--accent);
        }
        
        .ai-cta-content h4 {
            margin-bottom: 5px;
        }
        
        .ai-cta-content p {
            font-size: 14px;
            opacity: 0.8;
        }
        
        /* Cards Grid */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
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
            color: var(--secondary);
            font-size: 20px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Stats Card */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }
        
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .stat-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            text-align: center;
            transition: transform 0.3s ease;
        }
        
        .stat-item:hover {
            transform: translateY(-3px);
        }
        
        .stat-item i {
            font-size: 24px;
            color: var(--secondary);
            margin-bottom: 10px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin: 5px 0;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6c757d;
        }
        
        /* Chart Container */
        .chart-container {
            height: 250px;
            position: relative;
            margin-top: 15px;
        }
        
        /* Table Styles */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        
        .data-table th {
            background-color: #f1f5f9;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: var(--primary);
            border-bottom: 2px solid #e2e8f0;
        }
        
        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .data-table tr:hover {
            background-color: #f8fafc;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active {
            background: rgba(46, 204, 113, 0.1);
            color: #27ae60;
        }
        
        .status-pending {
            background: rgba(243, 156, 18, 0.1);
            color: #f39c12;
        }
        
        .status-inactive {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }
        
        .action-btn {
            padding: 6px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .btn-view {
            background: rgba(92, 107, 192, 0.1);
            color: var(--secondary);
        }
        
        .btn-edit {
            background: rgba(46, 204, 113, 0.1);
            color: #2ecc71;
        }
        
        .btn-delete {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
        }
        
        .action-btn:hover {
            opacity: 0.8;
        }
        
        /* Footer */
        footer {
            background: white;
            padding: 25px 20px;
            margin-top: 40px;
            border-top: 1px solid #e9ecef;
        }
        
        .footer-container {
            max-width: 1800px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .copyright {
            color: #6c757d;
            font-size: 14px;
        }
        
        .footer-links {
            display: flex;
            gap: 20px;
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
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .tab-btn {
            padding: 10px 20px;
            background: #f1f5f9;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .tab-btn.active {
            background: var(--primary);
            color: white;
        }
        
        /* Responsive Design */
        @media (max-width: 1100px) {
            .cards-grid {
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            }
        }
        
        @media (max-width: 992px) {
            .dashboard-container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                max-height: 0;
                overflow: hidden;
                padding: 0;
                border-radius: 0;
            }
            
            .header-container {
                padding: 15px 0;
            }
        }
        
        @media (max-width: 768px) {
            .header-container {
                flex-wrap: wrap;
                gap: 15px;
            }
            
            .user-info {
                margin-left: auto;
            }
            
            .welcome-banner h2 {
                font-size: 24px;
            }
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            .footer-container {
                flex-direction: column;
                gap: 15px;
                text-align: center;
            }
            
            .footer-links {
                flex-wrap: wrap;
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .user-info {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }
            
            .user-details {
                text-align: center;
            }
            
            .personality-type-large {
                font-size: 36px;
            }
            
            .welcome-banner {
                padding: 20px;
            }
        }
        
        /* Animation */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card {
            animation: fadeIn 0.5s ease-out;
        }
        
        .card:nth-child(1) { animation-delay: 0.1s; }
        .card:nth-child(2) { animation-delay: 0.2s; }
        .card:nth-child(3) { animation-delay: 0.3s; }
        .card:nth-child(4) { animation-delay: 0.4s; }
        .card:nth-child(5) { animation-delay: 0.5s; }
        
        .search-bar {
            display: flex;
            margin-bottom: 20px;
            gap: 10px;
        }
        
        .search-bar input {
            flex: 1;
            padding: 12px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .search-bar button {
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .search-bar button:hover {
            background: var(--primary);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="header-container">
            <div class="logo">
                AimAI Admin
            </div>
            
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name">Admin User</div>
                    <div class="admin-badge">Administrator</div>
                </div>
                <div class="user-avatar">AU</div>
                <button class="logout-btn" onclick="window.location='logout.php'">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </button>
            </div>
        </div>
    </header>
    
    <!-- Dashboard Content -->
    <div class="dashboard-container">
        <!-- Sidebar -->
        <div class="sidebar">
            <div class="nav-title">MAIN NAVIGATION</div>
            <a href="#" class="nav-item active"><i class="fas fa-home"></i> Dashboard</a>
            <a href="#" class="nav-item"><i class="fas fa-users"></i> Users</a>
            <a href="#" class="nav-item"><i class="fas fa-chalkboard-teacher"></i> Mentors</a>
            <a href="#" class="nav-item"><i class="fas fa-building"></i> Companies</a>
            <a href="#" class="nav-item"><i class="fas fa-briefcase"></i> Jobs</a>
            <a href="#" class="nav-item"><i class="fas fa-brain"></i> Skills</a>
            <a href="#" class="nav-item"><i class="fas fa-chart-line"></i> Analytics</a>
            <a href="#" class="nav-item"><i class="fas fa-cog"></i> Settings</a>
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            
            <!-- Welcome Banner -->
            <div class="welcome-banner">
                <h2>Admin Dashboard</h2>
                <p>Manage all aspects of the AimAI platform. Monitor system performance, user activities, and platform analytics.</p>
                <div class="ai-cta">
                    <i class="fas fa-robot"></i>
                    <div class="ai-cta-content">
                        <h4>System Status: All Systems Operational</h4>
                        <p>Last updated: <?= date("F d, Y H:i") ?></p>
                    </div>
                </div>
            </div>
            
            <!-- Stats Grid -->
            <div class="card">
                <div class="card-header">
                    <h3>Platform Overview</h3>
                    <i class="fas fa-chart-bar"></i>
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <div class="stat-item">
                            <i class="fas fa-users"></i>
                            <div class="stat-value"><?= $total_users ?></div>
                            <div class="stat-label">Total Users</div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-chalkboard-teacher"></i>
                            <div class="stat-value"><?= $total_mentors ?></div>
                            <div class="stat-label">Mentors</div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-building"></i>
                            <div class="stat-value"><?= $total_companies ?></div>
                            <div class="stat-label">Companies</div>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-briefcase"></i>
                            <div class="stat-value"><?= $total_jobs ?></div>
                            <div class="stat-label">Job Listings</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Charts Row -->
            <div class="cards-grid">
                <div class="card">
                    <div class="card-header">
                        <h3>User Growth</h3>
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="userChart"></canvas></div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h3>Platform Activity</h3>
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="card-body">
                        <div class="chart-container"><canvas id="activityChart"></canvas></div>
                    </div>
                </div>
            </div>
            
            <!-- Recent Users -->
            <div class="card">
                <div class="card-header">
                    <h3>Recent Users</h3>
                    <i class="fas fa-users"></i>
                </div>
                <div class="card-body">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th><th>Name</th><th>Email</th><th>Type</th><th>Status</th><th>Joined</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_users as $u): ?>
                            <?php
                                $badgeClass = 'status-active';
                                if ($u['role'] === 'company') $badgeClass = 'status-pending';
                                if ($u['role'] === 'mentor') $badgeClass = 'status-active';
                            ?>
                            <tr>
                                <td><?= $u['user_id'] ?></td>
                                <td><?= htmlspecialchars($u['username']) ?></td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td><?= $u['role'] ?></td>
                                <td><span class="status-badge <?= $badgeClass ?>"><?= ucfirst($u['role']) ?></span></td>
                                <td><?= date("Y-m-d", strtotime($u['created_at'])) ?></td>
                                <td>
                                    <button class="action-btn btn-view"><i class="fas fa-eye"></i></button>
                                    <button class="action-btn btn-edit"><i class="fas fa-edit"></i></button>
                                    <button class="action-btn btn-delete"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
        </div>
    </div>
    
    <!-- Footer -->
    <footer>
        <div class="footer-container">
            <div class="copyright">© 2025 AimAI. All rights reserved. Admin Dashboard v2.3.1</div>
            <div class="footer-links">
                <a href="#">Privacy Policy</a>
                <a href="#">Terms of Service</a>
                <a href="#">Help Center</a>
            </div>
        </div>
    </footer>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // ✅ User Growth Chart
        const months = <?= $months ?: '[]' ?>;
        const userCounts = <?= $user_counts ?: '[]' ?>;
        new Chart(document.getElementById('userChart').getContext('2d'), {
            type: 'line',
            data: {
                labels: months,
                datasets: [{
                    label: 'New Users',
                    data: userCounts,
                    borderColor: '#5c6bc0',
                    backgroundColor: 'rgba(92, 107, 192, 0.1)',
                    tension: 0.3,
                    fill: true
                }]
            }
        });

        // ✅ Platform Activity Chart
        const roleLabels = <?= $role_labels ?: '[]' ?>;
        const roleCounts = <?= $role_counts ?: '[]' ?>;
        new Chart(document.getElementById('activityChart').getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: roleLabels,
                datasets: [{
                    data: roleCounts,
                    backgroundColor: ['#5c6bc0', '#ff4081', '#1a237e', '#26a69a'],
                    borderWidth: 0
                }]
            },
            options: { responsive: true, maintainAspectRatio: false }
        });
    });
    </script>
</body>
</html>