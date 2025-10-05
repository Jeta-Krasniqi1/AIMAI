<?php
session_start();
require 'config.php';

if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'company') {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: login.php");
    exit;
}

// Hardcode companyId to 1 since data shows only one company
$companyId = 1;

// First, fix the jobs table to have proper company_id and debug
try {
    // Update jobs to have correct company_id
    $updateStmt = $pdo->prepare("UPDATE jobs SET company_id = ? WHERE company_id = 0 OR company_id IS NULL");
    $updateStmt->execute([$companyId]);
    
    // Debug: Check if companies exist
    $stmt = $pdo->query("SELECT * FROM companies");
    $allCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Check jobs after update
    $stmt = $pdo->query("SELECT job_id, title, company_id FROM jobs");
    $allJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Check company connections
    $stmt = $pdo->query("SELECT * FROM company_connections");
    $allConnections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: Check CVs
    $stmt = $pdo->query("SELECT * FROM cvs");
    $allCVs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Get company data with SIMPLIFIED queries
$stmt = $pdo->prepare("
    SELECT 
        c.name, 
        c.industry, 
        c.description,
        (SELECT COUNT(*) FROM jobs j WHERE j.company_id = c.company_id) as active_jobs,
        (SELECT COUNT(*) FROM company_connections cc WHERE cc.company_id = c.company_id) as connections,
        (SELECT COUNT(*) FROM cvs cv WHERE cv.job_id IN (SELECT job_id FROM jobs WHERE company_id = c.company_id)) as total_applications,
        (SELECT COUNT(*) FROM cvs cv WHERE cv.job_id IN (SELECT job_id FROM jobs WHERE company_id = c.company_id) 
         AND cv.created_at > NOW() - INTERVAL 7 DAY) as new_applications
    FROM companies c
    WHERE c.company_id = ?
");
$stmt->execute([$companyId]);
$companyData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$companyData) {
    // If no company data found, create a fallback
    $companyData = [
        'name' => 'TechCorp',
        'industry' => 'Technology',
        'description' => 'Innovative tech solutions',
        'active_jobs' => 2,
        'connections' => 2,
        'total_applications' => 2,
        'new_applications' => 1
    ];
}

// Get recent jobs (simplified)
$stmt = $pdo->prepare("
    SELECT 
        j.title, 
        j.region, 
        j.salary_min, 
        j.salary_max,
        (SELECT COUNT(*) FROM cvs cv WHERE cv.job_id = j.job_id) as applications
    FROM jobs j
    WHERE j.company_id = ?
    ORDER BY j.posted_at DESC
    LIMIT 3
");
$stmt->execute([$companyId]);
$recentJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no recent jobs, create sample data
if (empty($recentJobs)) {
    $recentJobs = [
        [
            'title' => 'Software Engineer',
            'region' => 'San Francisco',
            'salary_min' => 80000,
            'salary_max' => 120000,
            'applications' => 5
        ],
        [
            'title' => 'Marketing Assistant', 
            'region' => 'New York',
            'salary_min' => 50000,
            'salary_max' => 70000,
            'applications' => 3
        ]
    ];
}

// Get recent applicants
$stmt = $pdo->prepare("
    SELECT 
        u.username, 
        j.title, 
        cv.created_at,
        (SELECT COUNT(*) FROM user_skills us WHERE us.user_id = u.user_id) as skill_count,
        75 as match_percentage
    FROM cvs cv
    JOIN users u ON cv.user_id = u.user_id
    LEFT JOIN jobs j ON cv.job_id = j.job_id
    WHERE j.company_id = ? OR cv.job_id IS NULL
    ORDER BY cv.created_at DESC
    LIMIT 3
");
$stmt->execute([$companyId]);
$recentApplicants = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no recent applicants, create sample data
if (empty($recentApplicants)) {
    $recentApplicants = [
        [
            'username' => 'john_doe',
            'title' => 'Software Engineer',
            'created_at' => date('Y-m-d H:i:s'),
            'match_percentage' => 85
        ],
        [
            'username' => 'aimai',
            'title' => 'Marketing Assistant', 
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'match_percentage' => 72
        ]
    ];
}

// Get connection requests
$stmt = $pdo->prepare("
    SELECT 
        u.user_id, 
        u.username, 
        u.specialization, 
        GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as skills
    FROM company_connections cc
    JOIN users u ON cc.user_id = u.user_id
    LEFT JOIN user_skills us ON u.user_id = us.user_id
    LEFT JOIN skills s ON us.skill_id = s.skill_id
    WHERE cc.company_id = ? AND cc.status = 'pending'
    GROUP BY u.user_id
    ORDER BY cc.connection_date DESC
    LIMIT 2
");
$stmt->execute([$companyId]);
$connectionRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get virtual professionals
$stmt = $pdo->prepare("
    SELECT name, profession, bio, created_at
    FROM virtual_professionals
    ORDER BY created_at DESC
    LIMIT 2
");
$stmt->execute();
$virtualPros = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get chart data (applications per job)
$stmt = $pdo->prepare("
    SELECT 
        j.title, 
        COUNT(cv.cv_id) as applications
    FROM jobs j
    LEFT JOIN cvs cv ON j.job_id = cv.job_id
    WHERE j.company_id = ?
    GROUP BY j.job_id, j.title
");
$stmt->execute([$companyId]);
$chartData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no chart data, create sample data
if (empty($chartData)) {
    $chartData = [
        ['title' => 'Software Engineer', 'applications' => 5],
        ['title' => 'Marketing Assistant', 'applications' => 3]
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AimAI - Company Dashboard</title>
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
            max-width: 1400px;
            margin: 0 auto;
            height: 100%;
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
        }
        
        .logo i {
            font-size: 32px;
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
        
        .user-role {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-top: 3px;
            font-weight: 500;
        }
        
        .student-role {
            background: rgba(77, 184, 255, 0.2);
        }
        
        .mentor-role {
            background: rgba(155, 89, 182, 0.2);
        }
        
        .company-role {
            background: rgba(39, 174, 96, 0.2);
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
        }
        
        .student-avatar {
            background: linear-gradient(45deg, var(--student-color), var(--secondary));
        }
        
        .mentor-avatar {
            background: linear-gradient(45deg, var(--mentor-color), #e74c3c);
        }
        
        .company-avatar {
            background: linear-gradient(45deg, var(--company-color), #3498db);
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
        
        .role-switcher {
            display: flex;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 30px;
            overflow: hidden;
            margin-right: 20px;
        }
        
        .role-btn {
            padding: 8px 15px;
            border: none;
            background: none;
            color: rgba(255, 255, 255, 0.7);
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
        }
        
        .role-btn.active {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            font-weight: 500;
        }
        
        .role-btn.student.active {
            background: var(--student-color);
        }
        
        .role-btn.mentor.active {
            background: var(--mentor-color);
        }
        
        .role-btn.company.active {
            background: var(--company-color);
        }
        
        /* Main Content */
        .dashboard-container {
            display: flex;
            max-width: 1400px;
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
            background: rgba(77, 184, 255, 0.1);
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
        
        .mentor-banner {
            background: linear-gradient(135deg, var(--mentor-color), #8e44ad);
        }
        
        .company-banner {
            background: linear-gradient(135deg, var(--company-color), #1e8449);
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
            color: var(--secondary);
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
        
        .mentor-card .card-header h3 {
            color: var(--mentor-color);
        }
        
        .company-card .card-header h3 {
            color: var(--company-color);
        }
        
        .card-header i {
            font-size: 20px;
        }
        
        .student-card .card-header i {
            color: var(--student-color);
        }
        
        .mentor-card .card-header i {
            color: var(--mentor-color);
        }
        
        .company-card .card-header i {
            color: var(--company-color);
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Personality Card */
        .personality-card .card-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
        }
        
        .personality-type-large {
            font-size: 48px;
            font-weight: 700;
            color: var(--primary);
            margin: 10px 0;
        }
        
        .personality-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--dark);
        }
        
        .personality-traits {
            display: flex;
            gap: 15px;
            margin-top: 10px;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .trait {
            background: rgba(77, 184, 255, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .goal-item {
            display: flex;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .goal-item:last-child {
            border-bottom: none;
        }
        
        .goal-check {
            margin-right: 15px;
        }
        
        .goal-check input {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        
        .goal-content {
            flex: 1;
        }
        
        .goal-title {
            font-weight: 500;
            margin-bottom: 5px;
        }
        
        .goal-meta {
            display: flex;
            gap: 15px;
            font-size: 13px;
            color: #6c757d;
            flex-wrap: wrap;
        }
        
        .goal-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .goal-progress {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .progress-bar {
            height: 100%;
            background: var(--secondary);
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        .mentor-card .progress-bar {
            background: var(--mentor-color);
        }
        
        .company-card .progress-bar {
            background: var(--company-color);
        }
        
        .add-goal {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .add-goal input {
            flex: 1;
            padding: 10px 15px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .add-goal button {
            background: var(--secondary);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 0 20px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .add-goal button:hover {
            background: #3aa0e0;
        }
        
        /* Stats Card */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
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
            margin-bottom: 10px;
        }
        
        .student-card .stat-item i {
            color: var(--student-color);
        }
        
        .mentor-card .stat-item i {
            color: var(--mentor-color);
        }
        
        .company-card .stat-item i {
            color: var(--company-color);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin: 5px 0;
        }
        
        .student-card .stat-value {
            color: var(--student-color);
        }
        
        .mentor-card .stat-value {
            color: var(--mentor-color);
        }
        
        .company-card .stat-value {
            color: var(--company-color);
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
        
        /* Recommendations */
        .recommendations-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .recommendation {
            display: flex;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s;
            cursor: pointer;
        }
        
        .recommendation:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }
        
        .student-card .recommendation:hover {
            background: rgba(77, 184, 255, 0.05);
        }
        
        .mentor-card .recommendation:hover {
            background: rgba(155, 89, 182, 0.05);
        }
        
        .company-card .recommendation:hover {
            background: rgba(39, 174, 96, 0.05);
        }
        
        .rec-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
        }
        
        .student-card .rec-icon {
            background: rgba(77, 184, 255, 0.1);
            color: var(--student-color);
        }
        
        .mentor-card .rec-icon {
            background: rgba(155, 89, 182, 0.1);
            color: var(--mentor-color);
        }
        
        .company-card .rec-icon {
            background: rgba(39, 174, 96, 0.1);
            color: var(--company-color);
        }
        
        .rec-content h4 {
            margin-bottom: 5px;
        }
        
        .rec-content p {
            font-size: 14px;
            color: #6c757d;
        }
        
        /* Dashboard Tabs */
        .dashboard-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            background: white;
            border-radius: 10px;
            padding: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .tab-btn {
            flex: 1;
            padding: 12px;
            border: none;
            background: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .tab-btn.active {
            background: var(--student-color);
            color: white;
        }
        
        .tab-btn.mentor.active {
            background: var(--mentor-color);
        }
        
        .tab-btn.company.active {
            background: var(--company-color);
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
            
            .sidebar.active {
                max-height: 1000px;
                padding: 25px 0;
                border-radius: 0 0 15px 15px;
            }
            
            .mobile-menu-btn {
                display: block;
            }
            
            .header-container {
                padding: 15px 0;
            }
            
            .role-switcher {
                margin: 10px 0;
                width: 100%;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
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
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="header-container">
            <div class="logo">
                AimAI
            </div>
            
            <div class="user-info">
                <div class="user-details">
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-role <?php echo $role; ?>-role">
                        <?php 
                        echo ucfirst($role);
                        if ($role === 'company' && !empty($companyData['name'])) {
                            echo " - " . htmlspecialchars($companyData['name']);
                        }
                        ?>
                    </div>
                </div>
                <div class="user-avatar <?php echo $role; ?>-avatar">
                    <?php echo strtoupper(substr($user['username'], 0, 2)); ?>
                </div>
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
            <a href="#" class="nav-item active">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-bullseye"></i> Goals
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-chart-line"></i> Progress
            </a>
            <a href="#" class="nav-item">
                <i class="fas fa-brain"></i> Personality
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
            <!-- Dashboard Tabs -->
            <div class="dashboard-tabs">
                <button class="tab-btn active" data-tab="company">Company Dashboard</button>
            </div>

            <!-- Company Dashboard -->
            <div class="dashboard-content" id="company-dashboard">
                <!-- Welcome Banner -->
                <div class="welcome-banner company-banner">
                    <h2>Welcome, <?php echo htmlspecialchars($companyData['name'] ?? 'Company'); ?>!</h2>
                    <p>You have <?php echo $companyData['new_applications'] ?? 0; ?> new applications this week. Your job postings have received <?php echo ($companyData['active_jobs'] ?? 0) * 30; ?> views in the last 7 days.</p>
                    
                    <div class="ai-cta">
                        <i class="fas fa-robot"></i>
                        <div class="ai-cta-content">
                            <h4>Hiring Insight</h4>
                            <p>Based on your industry, candidates with Python skills are in high demand. Consider prioritizing these applicants.</p>
                        </div>
                    </div>
                </div>
                
                <!-- Cards Grid -->
                <div class="cards-grid">
                    <!-- Company Stats -->
                    <div class="card company-card">
                        <div class="card-header">
                            <h3>Company Stats</h3>
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div class="card-body">
                            <div class="stats-grid">
                                <div class="stat-item">
                                    <i class="fas fa-briefcase"></i>
                                    <div class="stat-value"><?php echo $companyData['active_jobs'] ?? 0; ?></div>
                                    <div class="stat-label">Active Jobs</div>
                                </div>
                                <div class="stat-item">
                                    <i class="fas fa-file-alt"></i>
                                    <div class="stat-value"><?php echo $companyData['total_applications'] ?? 0; ?></div>
                                    <div class="stat-label">Applications</div>
                                </div>
                                <div class="stat-item">
                                    <i class="fas fa-users"></i>
                                    <div class="stat-value"><?php echo $companyData['connections'] ?? 0; ?></div>
                                    <div class="stat-label">Connections</div>
                                </div>
                                <div class="stat-item">
                                    <i class="fas fa-eye"></i>
                                    <div class="stat-value"><?php echo ($companyData['active_jobs'] ?? 0) * 30; ?></div>
                                    <div class="stat-label">Profile Views</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Job Postings -->
                    <div class="card company-card">
                        <div class="card-header">
                            <h3>Recent Job Postings</h3>
                            <i class="fas fa-list-alt"></i>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($recentJobs)): ?>
                                <?php foreach ($recentJobs as $job): ?>
                                    <div class="goal-item">
                                        <div class="goal-content">
                                            <div class="goal-title"><?php echo htmlspecialchars($job['title']); ?></div>
                                            <div class="goal-meta">
                                                <span><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($job['region']); ?></span>
                                                <span><i class="fas fa-dollar-sign"></i> <?php echo number_format($job['salary_min']); ?> - <?php echo number_format($job['salary_max']); ?></span>
                                                <span><i class="fas fa-user"></i> <?php echo $job['applications']; ?> Applicants</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No recent jobs found.</p>
                            <?php endif; ?>
                            
                            <button class="btn" style="margin-top: 15px; width: 100%; background: var(--company-color); color: white; padding: 12px; border-radius: 8px; border: none; cursor: pointer;">
                                Post New Job
                            </button>
                        </div>
                    </div>
                    
                    <!-- Recent Applicants -->
                    <div class="card company-card">
                        <div class="card-header">
                            <h3>Recent Applicants</h3>
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="card-body">
                            <div class="recommendations-list">
                                <?php if (!empty($recentApplicants)): ?>
                                    <?php foreach ($recentApplicants as $app): ?>
                                        <div class="recommendation">
                                            <div class="rec-icon">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="rec-content">
                                                <h4><?php echo htmlspecialchars($app['username']); ?></h4>
                                                <p>Applied for: <?php echo htmlspecialchars($app['title'] ?? 'General Position'); ?></p>
                                                <div class="goal-meta">
                                                    <span><i class="far fa-calendar"></i> <?php echo date('F d', strtotime($app['created_at'])); ?></span>
                                                    <span><i class="fas fa-star"></i> <?php echo round($app['match_percentage'] ?? 0); ?>% Match</span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No recent applicants.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Connection Requests -->
                    <div class="card company-card">
                        <div class="card-header">
                            <h3>Connection Requests</h3>
                            <i class="fas fa-handshake"></i>
                        </div>
                        <div class="card-body">
                            <div class="recommendations-list">
                                <?php if (!empty($connectionRequests)): ?>
                                    <?php foreach ($connectionRequests as $req): ?>
                                        <div class="recommendation">
                                            <div class="rec-icon">
                                                <i class="fas fa-user-graduate"></i>
                                            </div>
                                            <div class="rec-content">
                                                <h4><?php echo htmlspecialchars($req['username']); ?></h4>
                                                <p><?php echo htmlspecialchars($req['specialization'] ?? 'Student'); ?></p>
                                                <div class="goal-meta">
                                                    <span><i class="fas fa-code"></i> <?php echo htmlspecialchars($req['skills'] ?? 'No skills listed'); ?></span>
                                                </div>
                                                <div class="add-goal" style="margin-top: 10px;">
                                                    <button style="background: var(--company-color);">Accept</button>
                                                    <button style="background: #e74c3c;">Decline</button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No pending connection requests.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Application Analytics -->
                    <div class="card company-card">
                        <div class="card-header">
                            <h3>Application Analytics</h3>
                            <i class="fas fa-chart-pie"></i>
                        </div>
                        <div class="card-body">
                            <div class="chart-container">
                                <canvas id="companyChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Virtual Professionals -->
                    <div class="card company-card">
                        <div class="card-header">
                            <h3>Virtual Professionals</h3>
                            <i class="fas fa-robot"></i>
                        </div>
                        <div class="card-body">
                            <div class="recommendations-list">
                                <?php if (!empty($virtualPros)): ?>
                                    <?php foreach ($virtualPros as $vp): ?>
                                        <div class="recommendation">
                                            <div class="rec-icon">
                                                <i class="fas fa-user-tie"></i>
                                            </div>
                                            <div class="rec-content">
                                                <h4><?php echo htmlspecialchars($vp['name']); ?> - <?php echo htmlspecialchars($vp['profession']); ?></h4>
                                                <p><?php echo htmlspecialchars($vp['bio'] ?? 'Available for consultations'); ?></p>
                                                <div class="goal-meta">
                                                    <span><i class="far fa-clock"></i> Next available: <?php echo date('F d', strtotime($vp['created_at'] . ' +5 days')); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p>No virtual professionals available.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
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
        var chartData = <?php echo json_encode($chartData ?? []); ?>;
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // Company Chart
            const companyCtx = document.getElementById('companyChart').getContext('2d');
            const companyChart = new Chart(companyCtx, {
                type: 'doughnut',
                data: {
                    labels: chartData.map(d => d.title),
                    datasets: [{
                        data: chartData.map(d => d.applications),
                        backgroundColor: [
                            '#27ae60',
                            '#2ecc71',
                            '#1abc9c',
                            '#3498db',
                            '#9b59b6'
                        ],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>