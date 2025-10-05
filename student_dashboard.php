<?php
session_start();
require 'config.php';

if (!isset($_SESSION['login_time'])) {
    $_SESSION['login_time'] = time();
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || ($_SESSION['role'] ?? '') !== 'student') {
    header("Location: login.php");
    exit;
}

try {
    // Fetch user details
    $stmt = $pdo->prepare("SELECT username, personality_type FROM users WHERE user_id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new Exception("User not found");

    // Fetch goals
    $stmt = $pdo->prepare("SELECT progress_id, goal, progress_status, last_updated 
                           FROM motivational_progress 
                           WHERE user_id = ? ORDER BY last_updated DESC");
    $stmt->execute([$userId]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $stmt = $pdo->prepare("SELECT COUNT(*) AS total_goals,
             SUM(CASE WHEN progress_status='completed' THEN 1 ELSE 0 END) AS completed_goals
             FROM motivational_progress WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $goal_completion = ($stats && $stats['total_goals'] > 0) ? round(($stats['completed_goals'] / $stats['total_goals']) * 100) : 0;
    $streak = 42; // placeholder
    $course_count = count(array_filter($goals, fn($g)=>strpos(strtolower($g['goal']), 'course')!==false));
    $achievements = 96; // placeholder

    // Weekly progress
    $stmt = $pdo->prepare("
        SELECT YEARWEEK(last_updated,3) AS yw,
               COUNT(*) AS total,
               SUM(CASE WHEN progress_status='completed' THEN 1 ELSE 0 END) AS completed
        FROM motivational_progress
        WHERE user_id = ? AND last_updated >= (CURRENT_DATE - INTERVAL 28 DAY)
        GROUP BY YEARWEEK(last_updated,3)
    ");
    $stmt->execute([$userId]);
    $weekly_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $weekly_map = [];
    foreach ($weekly_rows as $r) {
        $weekly_map[(string)$r['yw']] = ['total'=>(int)$r['total'],'completed'=>(int)$r['completed']];
    }
    $chart_labels=[];$chart_completion=[];$chart_learning=[];
    for($i=3;$i>=0;$i--){
        $dt=new DateTime("-$i week");
        $yw=$dt->format('o').str_pad($dt->format('W'),2,'0',STR_PAD_LEFT);
        $chart_labels[]='W'.$dt->format('W');
        if(isset($weekly_map[$yw])){
            $tot=$weekly_map[$yw]['total'];$done=$weekly_map[$yw]['completed'];
            $pct=$tot>0?round(($done/$tot)*100):0;
            $chart_completion[]=$pct;
            $chart_learning[]= $tot>0?round(($done/$tot)*80):0;
        } else { $chart_completion[]=0; $chart_learning[]=0; }
    }

    // Recent activities
    $stmt=$pdo->prepare("
      SELECT 'Goal Updated' AS action, goal AS description, last_updated AS date
      FROM motivational_progress WHERE user_id=?
      UNION
      SELECT 'CV Submitted' AS action, content, created_at
      FROM cvs WHERE user_id=?
      ORDER BY date DESC LIMIT 3
    ");
    $stmt->execute([$userId,$userId]);
    $recent_activities=$stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mentors
    $stmt=$pdo->prepare("
      SELECT u.user_id,u.username,u.specialization
      FROM mentorships m JOIN users u ON m.mentor_id=u.user_id
      WHERE m.user_id=? AND m.status='active'
      ORDER BY u.username
    ");
    $stmt->execute([$userId]);
    $mentors=$stmt->fetchAll(PDO::FETCH_ASSOC);

    // Jobs
  // Jobs - FIXED VERSION
$job_query="SELECT j.job_id,j.title,j.description,c.name AS company_name
            FROM jobs j JOIN companies c ON j.company_id=c.company_id WHERE 1=1";
$params=[];$goal_keywords=[];
foreach($goals as $g){
    $t=strtolower($g['goal']);
    if(strpos($t,'programming')!==false){$goal_keywords[]='software';$goal_keywords[]='developer';}
    if(strpos($t,'career')!==false){$goal_keywords[]='manager';$goal_keywords[]='analyst';}
}
if($goal_keywords){
    $ors=implode(' OR ',array_fill(0,count($goal_keywords),"j.title LIKE CONCAT('%', ?, '%')"));
    $job_query.=" AND ($ors)";
    $params=array_merge($params,$goal_keywords);
}
$job_query.=" ORDER BY j.job_id DESC LIMIT 3";
$stmt=$pdo->prepare($job_query);
$stmt->execute($params);
$jobs=$stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("
    SELECT c.company_id, c.name, c.industry, cc.connection_date, cc.status
    FROM company_connections cc 
    JOIN companies c ON cc.company_id = c.company_id 
    WHERE cc.user_id = ? 
    ORDER BY cc.connection_date DESC
");
$stmt->execute([$userId]);
$companyConnections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recommended companies based on student's goals/skills
$stmt = $pdo->prepare("
    SELECT c.company_id, c.name, c.industry, c.description
    FROM companies c
    JOIN jobs j ON c.company_id = j.company_id
    JOIN job_skills js ON j.job_id = js.job_id
    JOIN user_skills us ON js.skill_id = us.skill_id
    WHERE us.user_id = ? AND us.status = 'acquired'
    GROUP BY c.company_id
    ORDER BY COUNT(js.skill_id) DESC
    LIMIT 3
");
$stmt->execute([$userId]);
$recommendedCompanies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Flash messages
    $mentor_errors = $_SESSION['mentor_request_errors'] ?? [];
    $mentor_success = $_SESSION['mentor_request_success'] ?? '';
    unset($_SESSION['mentor_request_errors'], $_SESSION['mentor_request_success']);

} catch(Exception $e){ die("Error: ".$e->getMessage()); }

// Avatar initials
$nameParts=explode(' ',$user['username']);$initials='';
foreach($nameParts as $p){ if($p !== '') { $initials.=strtoupper($p[0]); if(strlen($initials)>=2)break; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AimAI - Student Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ====== Base ====== */
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif}
:root{
  --primary:#1a2a6c;--secondary:#4db8ff;--accent:#ff6b6b;--light:#f8f9fa;--dark:#212529;
  --success:#2ecc71;--warning:#f39c12;--danger:#e74c3c;
  --student-color:#1a2a6c;--mentor-color:#9b59b6;--company-color:#27ae60;
  --sidebar-width:280px;--header-height:80px;
}
body{background:#f0f4f8;color:#333;min-height:100vh;display:flex;flex-direction:column}

/* ====== Header ====== */
header{background:linear-gradient(135deg,var(--primary),#0d1b4b);color:#fff;padding:0 20px;box-shadow:0 4px 12px rgba(0,0,0,.1);position:sticky;top:0;z-index:1000;height:var(--header-height)}
.header-container{display:flex;justify-content:space-between;align-items:center;max-width:1400px;margin:0 auto;height:100%}
.logo{font-size:2rem;font-weight:800;background:linear-gradient(45deg,#00d4ff,#7c3aed);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.mobile-menu-btn{display:none;background:none;border:none;color:#fff;font-size:24px;cursor:pointer;padding:10px;border-radius:5px}
.mobile-menu-btn:hover{background:rgba(255,255,255,.1)}
.user-info{display:flex;align-items:center;gap:20px}
.user-details{text-align:right}
.user-name{font-weight:600;font-size:16px}
.user-role{padding:3px 10px;border-radius:20px;font-size:12px;margin-top:3px;font-weight:500;background:rgba(77,184,255,.2)}
.user-avatar{width:45px;height:45px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:18px;color:#fff;background:linear-gradient(45deg,var(--student-color),var(--secondary))}
.logout-btn{background:transparent;border:none;color:#fff;cursor:pointer;font-size:14px;display:flex;align-items:center;gap:5px;padding:8px 12px;border-radius:6px;text-decoration:none}
.logout-btn:hover{background:rgba(255,255,255,.1)}

/* ====== Layout ====== */
.dashboard-container{display:flex;max-width:1400px;margin:20px auto;padding:0 20px;gap:25px;flex:1;width:100%}
.sidebar{width:var(--sidebar-width);background:#fff;border-radius:15px;box-shadow:0 5px 15px rgba(0,0,0,.05);padding:25px 0;height:fit-content;position:sticky;top:calc(var(--header-height) + 20px)}
.nav-title{padding:0 25px 15px;font-size:14px;color:#6c757d;font-weight:600;border-bottom:1px solid #e9ecef;margin-bottom:15px}
.nav-item{display:flex;align-items:center;gap:12px;padding:12px 25px;color:#495057;text-decoration:none;font-weight:500}
.nav-item:hover,.nav-item.active{background:rgba(77,184,255,.1);color:var(--primary);border-left:3px solid var(--secondary)}
.nav-item i{width:20px;text-align:center}
.main-content{flex:1;display:flex;flex-direction:column;gap:25px;min-width:0}

/* ====== Welcome ====== */
.welcome-banner{background:linear-gradient(135deg,var(--primary),#0d1b4b);color:#fff;border-radius:15px;padding:30px;box-shadow:0 10px 20px rgba(26,42,108,.2);display:flex;flex-direction:column;gap:15px;position:relative;overflow:hidden}
.welcome-banner::before{content:"";position:absolute;top:-50%;right:-50%;width:200px;height:200px;background:radial-gradient(rgba(255,255,255,.1) 0%,transparent 70%);border-radius:50%}
.welcome-banner::after{content:"";position:absolute;bottom:-30%;right:10%;width:150px;height:150px;background:radial-gradient(rgba(255,255,255,.1) 0%,transparent 70%);border-radius:50%}
.ai-cta{background:rgba(255,255,255,.15);border-radius:10px;padding:15px;display:flex;align-items:center;gap:15px;cursor:pointer;backdrop-filter:blur(5px)}
.ai-cta:hover{background:rgba(255,255,255,.25)}
.ai-cta i{font-size:24px;color:var(--secondary)}

/* ====== Cards ====== */
.cards-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:25px}
.card{background:#fff;border-radius:15px;box-shadow:0 5px 15px rgba(0,0,0,.05);overflow:hidden;transition:transform .3s,box-shadow .3s}
.card:hover{transform:translateY(-5px);box-shadow:0 8px 25px rgba(0,0,0,.1)}
.card-header{padding:20px;border-bottom:1px solid #e9ecef;display:flex;justify-content:space-between;align-items:center}
.card-header h3{font-size:18px;color:var(--primary)}
.card-body{padding:20px}

/* Personality */
.personality-card .card-body{display:flex;flex-direction:column;align-items:center;gap:15px;text-align:center}
.personality-type-large{font-size:48px;font-weight:700;color:var(--primary);margin:10px 0}
.personality-name{font-size:18px;font-weight:600;color:var(--dark)}
.personality-traits{display:flex;gap:15px;margin-top:10px;flex-wrap:wrap;justify-content:center}
.trait{background:rgba(77,184,255,.1);padding:8px 15px;border-radius:20px;font-size:14px;font-weight:500}

/* Goals */
.goal-item{display:flex;align-items:flex-start;padding:15px 0;border-bottom:1px solid #e9ecef;gap:15px}
.goal-item:last-child{border-bottom:none}.goal-check input{width:20px;height:20px;cursor:pointer}
.goal-content{flex:1;min-width:0}.goal-title{font-weight:500;margin-bottom:5px;word-wrap:break-word}
.goal-meta{display:flex;gap:15px;font-size:13px;color:#6c757d;flex-wrap:wrap}
.goal-progress{height:6px;background:#e9ecef;border-radius:3px;margin-top:8px;overflow:hidden}
.progress-bar{height:100%;background:var(--secondary);border-radius:3px;transition:width .5s}

/* Add goal */
.add-goal{display:flex;gap:10px;margin-top:15px}
.add-goal input{flex:1;padding:10px 15px;border:1px solid #dee2e6;border-radius:8px;font-size:14px}
.add-goal button{background:var(--secondary);color:#fff;border:none;border-radius:8px;padding:0 20px;cursor:pointer}
.add-goal button:hover{background:#3aa0e0}

/* Stats */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:15px}
.stat-item{background:#f8f9fa;border-radius:10px;padding:15px;text-align:center}
.stat-item i{font-size:24px;margin-bottom:10px}
.stat-value{font-size:24px;font-weight:700;margin:5px 0}
.stat-label{font-size:12px;color:#6c757d}

/* Chart */
.chart-container{height:250px;position:relative;margin-top:15px}

/* Recommendations */
.recommendations-list{display:flex;flex-direction:column;gap:15px}
.recommendation{display:flex;gap:15px;padding:15px;background:#f8f9fa;border-radius:10px;cursor:pointer}
.recommendation:hover{transform:translateY(-3px);box-shadow:0 5px 15px rgba(0,0,0,.05)}
.rec-icon{width:50px;height:50px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;background:rgba(77,184,255,.1);color:var(--student-color)}
.rec-content{flex:1;min-width:0}
.muted{color:#6c757d;font-size:14px}

/* AI plan */
.ai-plan-step{background:#f8f9fa;border:1px solid #e9ecef;border-radius:8px;padding:12px;margin-bottom:10px}
.ai-plan-step h5{margin:0 0 6px 0;font-size:16px;color:var(--primary)}
.ai-plan-step p{margin:0;font-size:14px;color:#6c757d}

/* Responsive */
@media (max-width:1200px){.dashboard-container{padding:0 15px;gap:20px}.cards-grid{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px}}
@media (max-width:992px){
  :root{--sidebar-width:100%;--header-height:70px}
  .dashboard-container{flex-direction:column;margin:15px auto;gap:15px}
  .sidebar{position:fixed;top:var(--header-height);left:-100%;width:280px;height:calc(100vh - var(--header-height));z-index:999;overflow-y:auto;transition:left .3s;border-radius:0}
  .sidebar.active{left:0}
  .mobile-menu-btn{display:block}
}
@media (max-width:768px){
  .header-container{flex-wrap:wrap;gap:10px}
  .logo{font-size:1.5rem}.user-name{font-size:14px}
  .user-avatar{width:40px;height:40px;font-size:16px}
  .cards-grid{grid-template-columns:1fr}
}
@media (max-width:480px){
  .dashboard-container{padding:0 10px;margin:10px auto}
  .card-header{padding:12px}.card-body{padding:12px}
  .add-goal{flex-direction:column}
}
.sidebar-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:998}
.sidebar-overlay.active{display:block}
#aiResults{margin-top:10px}#aiResults ul{padding-left:18px}

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
            font-size: 14px;
        }
        
        .tab-btn.active {
            background: var(--student-color);
            color: white;
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
        <div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
        <div class="user-role student-role">
          Student <?= $user['personality_type'] ? '- ' . htmlspecialchars($user['personality_type']) : '' ?>
        </div>
      </div>
      <div class="user-avatar"><?= htmlspecialchars($initials ?: 'U') ?></div>
      <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
  </div>
</header>

<!-- Dashboard -->
<div class="dashboard-container">
  <!-- Sidebar -->
  <div class="sidebar">
            <div class="nav-title">MAIN NAVIGATION</div>
            <a href="student_dashboard.php" class="nav-item active">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="goals.php" class="nav-item">
                <i class="fas fa-bullseye"></i> Goals
            </a>
            <a href="progress.php" class="nav-item">
                <i class="fas fa-chart-line"></i> Progress
            </a>
            <a href="personality.php" class="nav-item">
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

  <!-- Main -->
  <div class="main-content">
     <div class="dashboard-tabs">
                <button class="tab-btn active student" data-tab="mentor">Student Dashboard</button>
            </div>

    <div class="dashboard-content" id="student-dashboard">
      <!-- Welcome -->
      <div class="welcome-banner">
        <h2>Welcome Back, <?= htmlspecialchars($user['username']) ?>!</h2>
        <p>Your personal growth journey continues. You've completed <?= $goal_completion ?>% of your goals. Keep up the great work!</p>
        <div class="ai-cta" id="aiCta">
          <i class="fas fa-robot"></i>
          <div class="ai-cta-content">
            <h4>Personalized AI Recommendation</h4>
            <p>Click to fetch a tailored plan based on your goals and personality (<?= $user['personality_type'] ? htmlspecialchars($user['personality_type']) : 'Unknown' ?>).</p>
          </div>
        </div>
      </div>

      <!-- Cards -->
      <div class="cards-grid">

        <!-- Personality -->
        <div class="card personality-card student-card">
          <div class="card-header">
            <h3>Personality Profile</h3>
            <i class="fas fa-brain"></i>
          </div>
          <div class="card-body">
            <div class="personality-type-large"><?= htmlspecialchars($user['personality_type'] ?: 'N/A') ?></div>
            <div class="personality-name"><?= htmlspecialchars($user['personality_type'] ? $user['personality_type'] . ' Personality' : 'Unknown Personality') ?></div>
            <div class="personality-traits">
              <?php
              $traits = [
                'INTJ' => ['Strategic','Analytical','Independent','Visionary'],
                'INTP' => ['Analytical','Creative','Independent','Innovative','Theoretical'],
              ];
              $user_traits = $traits[$user['personality_type']] ?? ['Unknown'];
              foreach ($user_traits as $t) echo "<span class='trait'>" . htmlspecialchars($t) . "</span>";
              ?>
            </div>
            <button class="btn" style="margin-top:20px;background:var(--student-color);color:#fff;padding:10px 20px;border-radius:8px;border:none;cursor:pointer;">View Full Analysis</button>
          </div>
        </div>

        <!-- Goals -->
        <div class="card student-card">
          <div class="card-header">
            <h3>Your Goals</h3>
            <i class="fas fa-bullseye"></i>
          </div>
          <div class="card-body">
            <?php foreach ($goals as $goal): ?>
              <div class="goal-item">
                <div class="goal-check">
                  <input type="checkbox"
                         <?= ($goal['progress_status'] === 'completed') ? 'checked' : '' ?>
                         data-goal-id="<?= htmlspecialchars((string)$goal['progress_id']) ?>">
                </div>
                <div class="goal-content">
                  <div class="goal-title"><?= htmlspecialchars((string)$goal['goal']) ?></div>
                  <div class="goal-meta">
                    <span><i class="far fa-calendar"></i> <?= date('M d, Y', strtotime((string)$goal['last_updated'])) ?></span>
                    <span><i class="fas fa-trophy"></i> <?= htmlspecialchars(ucfirst((string)$goal['progress_status'])) ?></span>
                  </div>
                  <div class="goal-progress">
                    <div class="progress-bar" style="width: <?= $goal['progress_status']==='completed' ? '100' : '50' ?>%"></div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
            <div class="add-goal">
              <input type="text" placeholder="Add a new goal...">
              <button><i class="fas fa-plus"></i></button>
            </div>
          </div>
        </div>

        <!-- AI Coach -->
        <div class="card student-card">
          <div class="card-header">
            <h3>AI Coach</h3>
            <i class="fas fa-robot"></i>
          </div>
          <div class="card-body">
            <p>Get a personalized weekly plan based on your goals and progress.</p>
            <button id="fetchAiPlan" class="btn" style="background:var(--secondary);color:#fff;padding:10px 16px;border-radius:8px;border:none;cursor:pointer;">Get Personalized Plan</button>
            <div id="aiResults"></div>
          </div>
        </div>

        <!-- Mentors -->
        <div class="card student-card">
          <div class="card-header">
            <h3>Your Mentors</h3>
            <i class="fas fa-user-graduate"></i>
          </div>
          <div class="card-body">
            <?php if (!empty($mentor_errors)): ?>
              <div class="error-message" style="color:var(--danger);font-size:13px;margin-bottom:10px;">
                <?php foreach ($mentor_errors as $error): ?>
                  <p><?= htmlspecialchars((string)$error) ?></p>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($mentor_success): ?>
              <div class="success-message" style="color:var(--success);font-size:13px;margin-bottom:10px;"><?= htmlspecialchars((string)$mentor_success) ?></div>
            <?php endif; ?>

            <?php if ($mentors): ?>
              <?php foreach ($mentors as $m): ?>
                <div class="mentor-item" style="display:flex;align-items:center;padding:15px 0;border-bottom:1px solid #e9ecef;gap:15px;">
                  <div class="mentor-content" style="flex:1;">
                    <div class="mentor-title" style="font-weight:500;margin-bottom:5px;"><?= htmlspecialchars((string)$m['username']) ?></div>
                    <div class="mentor-meta" style="display:flex;gap:15px;font-size:13px;color:#6c757d;flex-wrap:wrap;">
                      <span><i class="fas fa-briefcase"></i> <?= htmlspecialchars((string)($m['specialization'] ?: 'N/A')) ?></span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p>No mentors assigned yet. Request one below.</p>
            <?php endif; ?>

            <form action="request_mentor.php" method="POST" class="request-mentor" style="display:flex;gap:10px;margin-top:15px;">
              <select name="mentor_id" required style="flex:1;padding:10px 15px;border:1px solid #dee2e6;border-radius:8px;font-size:14px;">
                <option value="">Select a mentor...</option>
                <?php
                $stmt = $pdo->prepare("SELECT user_id, username, specialization FROM users WHERE role = 'mentor' ORDER BY username");
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $opt) {
                    echo "<option value='".htmlspecialchars((string)$opt['user_id'])."'>"
                        . htmlspecialchars((string)$opt['username'])
                        . " (" . htmlspecialchars((string)($opt['specialization'] ?: 'N/A')) . ")"
                        . "</option>";
                }
                ?>
              </select>
              <button type="submit" style="background:var(--mentor-color);color:#fff;border:none;border-radius:8px;padding:10px 15px;cursor:pointer;">
                <i class="fas fa-plus"></i> Request
              </button>
            </form>
          </div>
        </div>

        <!-- Jobs -->
        <div class="card student-card">
          <div class="card-header">
            <h3>Job Opportunities</h3>
            <i class="fas fa-briefcase"></i>
          </div>
          <div class="card-body">
            <?php if ($jobs): ?>
              <?php foreach ($jobs as $job): ?>
                <div class="job-item" style="display:flex;align-items:center;padding:15px 0;border-bottom:1px solid #e9ecef;gap:15px;">
                  <div class="job-content" style="flex:1;">
                    <div class="job-title" style="font-weight:500;margin-bottom:5px;"><?= htmlspecialchars((string)$job['title']) ?></div>
                    <div class="job-meta" style="display:flex;gap:15px;font-size:13px;color:#6c757d;flex-wrap:wrap;">
                      <span><i class="fas fa-building"></i> <?= htmlspecialchars((string)$job['company_name']) ?></span>
                    </div>
                    <p>
                      <?php
                        $desc = (string)($job['description'] ?? '');
                        $short = mb_substr($desc, 0, 100, 'UTF-8');
                        echo htmlspecialchars($short) . (mb_strlen($desc,'UTF-8') > 100 ? '...' : '');
                      ?>
                    </p>
                    <a href="apply_job.php?job_id=<?= htmlspecialchars((string)$job['job_id']) ?>"
                       class="job-apply"
                       style="display:inline-block;background:var(--company-color);color:#fff;padding:8px 15px;border-radius:8px;text-decoration:none;font-size:14px;">
                       Apply Now
                    </a>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <p>No job opportunities found. Check back later!</p>
            <?php endif; ?>
          </div>
        </div>

        <!-- Stats -->
        <div class="card student-card">
          <div class="card-header">
            <h3>Progress Stats</h3>
            <i class="fas fa-chart-pie"></i>
          </div>
          <div class="card-body">
            <div class="stats-grid">
              <div class="stat-item">
                <i class="fas fa-check-circle"></i>
                <div class="stat-value"><?= $goal_completion ?>%</div>
                <div class="stat-label">Goal Completion</div>
              </div>
              <div class="stat-item">
                <i class="fas fa-fire"></i>
                <div class="stat-value"><?= $streak ?></div>
                <div class="stat-label">Day Streak</div>
              </div>
              <div class="stat-item">
                <i class="fas fa-book"></i>
                <div class="stat-value"><?= $course_count ?></div>
                <div class="stat-label">Courses Taken</div>
              </div>
              <div class="stat-item">
                <i class="fas fa-star"></i>
                <div class="stat-value"><?= $achievements ?></div>
                <div class="stat-label">Achievements</div>
              </div>
            </div>
          </div>
        </div>

        <!-- Progress Chart -->
        <div class="card student-card">
          <div class="card-header">
            <h3>Monthly Progress</h3>
            <i class="fas fa-chart-line"></i>
          </div>
          <div class="card-body">
            <div class="chart-container">
              <canvas id="studentChart"></canvas>
            </div>
          </div>
        </div>

      <!-- AI Recommendations (Dynamic) -->
<div class="card student-card">
  <div class="card-header">
    <h3>AI Recommendations</h3>
    <div style="display:flex;gap:8px;align-items:center">
      <button id="refreshAiRecs"
              class="btn"
              style="background:var(--secondary);color:#fff;border:none;border-radius:8px;padding:8px 12px;cursor:pointer;">
        Refresh
      </button>
      <i class="fas fa-robot"></i>
    </div>
  </div>
  <div class="card-body">
    <div id="aiRecs" class="recommendations-list"></div>
    <p class="muted" style="margin-top:8px">
      Tip: Use the <strong>AI Coach</strong> card above for a fresh plan generated via your webhook.
    </p>
  </div>
</div>


        <!-- Recent Activity -->
        <div class="card student-card">
          <div class="card-header">
            <h3>Recent Activity</h3>
            <i class="fas fa-history"></i>
          </div>
          <div class="card-body">
            <div class="recommendations-list">
              <?php foreach ($recent_activities as $activity): ?>
                <div class="recommendation">
                  <div class="rec-icon" style="background:rgba(46,204,113,.1);color:#2ecc71;"><i class="fas fa-check"></i></div>
                  <div class="rec-content">
                    <h4><?= htmlspecialchars((string)$activity['action']) ?></h4>
                    <p><?= htmlspecialchars((string)$activity['description']) ?> - <?= date('M d \a\t h:i A', strtotime((string)$activity['date'])) ?></p>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

      </div><!-- /cards-grid -->
    </div><!-- /dashboard-content -->
  </div><!-- /main-content -->
</div><!-- /dashboard-container -->

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
// ---- helpers to parse whatever the webhook returns ----
function safeJsonParse(t){ try{return JSON.parse(t)}catch(e){return null} }
function extractJsonFromText(t){
  if (typeof t !== 'string') return null;
  const direct = safeJsonParse(t); if (direct) return direct;
  const open = Math.min(...[t.indexOf('{'), t.indexOf('[')].filter(i=>i>=0));
  if (open<0) return null;
  for (let end=t.length; end>open+1; end--){
    const slice=t.slice(open,end).trim();
    const parsed=safeJsonParse(slice);
    if (parsed) return parsed;
  }
  return null;
}
function normalizePayload(any){
  if (!any) return {};
  if (Array.isArray(any) && any.length){
    const f=any[0];
    if (f && typeof f==='object'){ return f.json||f.data||f; }
  }
  return any.json||any.data||any;
}

// ---- render the plan + dynamic recommendations ----
function renderAiResults(payload){
  const el=document.getElementById('aiResults'); el.innerHTML='';
  const top = (payload && payload.data) ? payload.data : payload;
  const data = normalizePayload(top) || {};

  const title = data.title || 'Your Personalized Plan';
  const summary = data.summary || data.plan_summary || '';
  const steps = data.steps || data.tasks || data.items || [];
  const tips  = data.tips  || data.suggestions || [];
  const recs  = data.recommendations || data.recs || []; // dynamic items here

  const h4=document.createElement('h4'); h4.textContent=title; el.appendChild(h4);
  if (summary){ const p=document.createElement('p'); p.textContent=summary; el.appendChild(p); }

  if (Array.isArray(steps) && steps.length){
    const h5=document.createElement('h5'); h5.textContent='Action Items'; el.appendChild(h5);
    const ul=document.createElement('ul');
    steps.forEach((s,i)=>{
      const li=document.createElement('li');
      li.textContent = (typeof s==='string') ? s : (s.title || s.detail || `Step ${i+1}`);
      ul.appendChild(li);
    });
    el.appendChild(ul);
  }

  if (Array.isArray(tips) && tips.length){
    const h5=document.createElement('h5'); h5.textContent='Tips'; el.appendChild(h5);
    const ul=document.createElement('ul');
    tips.forEach((t,i)=>{
      const li=document.createElement('li');
      li.textContent = (typeof t==='string') ? t : (t.tip || t.detail || `Tip ${i+1}`);
      ul.appendChild(li);
    });
    el.appendChild(ul);
  }

  // --- AI Recommendations (dynamic) ---
  if (Array.isArray(recs) && recs.length){
    const h5=document.createElement('h5'); h5.textContent='AI Recommendations'; el.appendChild(h5);
    const container=document.createElement('div'); container.style.display='flex'; container.style.flexDirection='column'; container.style.gap='10px';
    recs.forEach((r,idx)=>{
      const box=document.createElement('div'); box.className='ai-plan-step';
      const rh=document.createElement('h5');
      const rp=document.createElement('p');
      if (typeof r==='string'){ rh.textContent=r; }
      else {
        rh.textContent = r.title || `Recommendation ${idx+1}`;
        rp.textContent = r.detail || r.description || r.reason || '';
      }
      box.appendChild(rh); if (rp.textContent) box.appendChild(rp);
      container.appendChild(box);
    });
    el.appendChild(container);
  }

  const tip=document.createElement('p'); tip.className='muted';
  tip.textContent='Tip: Use the AI Coach card above for a fresh plan generated via your webhook.';
  el.appendChild(tip);

  if (!summary && !(steps||[]).length && !(tips||[]).length && !(recs||[]).length){
    const p=document.createElement('p'); p.className='muted'; p.textContent='AI returned a response, showing raw JSON below:'; el.appendChild(p);
    const pre=document.createElement('pre'); pre.textContent=JSON.stringify(data,null,2); el.appendChild(pre);
  }
}

// ---- call local proxy (POST only) ----
async function callAiViaProxy(){
  const results=document.getElementById('aiResults');
  results.innerHTML='<p class="muted">Generating your plan…</p>';

  const body={
    intent:'generate_personalized_plan',
    user:{ id: <?= json_encode($userId) ?>, username: <?= json_encode($user['username']) ?>, personality_type: <?= json_encode($user['personality_type']) ?> },
    goals: <?= json_encode($goals, JSON_UNESCAPED_UNICODE) ?>,
    stats:{ goal_completion: <?= (int)$goal_completion ?>, streak: <?= (int)$streak ?>, course_count: <?= (int)$course_count ?>, achievements: <?= (int)$achievements ?> },
    source:'student_dashboard.php'
  };

  try{
    const resp=await fetch('ai_proxy.php', { method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify(body) });
    const text=await resp.text();
    const json = safeJsonParse(text) || extractJsonFromText(text);
    if (!json) throw new Error('Invalid JSON response from AI webhook.');
    if (json.ok===false) throw new Error(json.error || json.message || `HTTP ${resp.status}`);
    renderAiResults(json);
  }catch(err){
    results.innerHTML = `<p style="color:#e74c3c;"><strong>Failed to fetch AI plan:</strong> ${String(err.message)}</p>`;
  }
}

document.addEventListener('DOMContentLoaded', ()=>{
  const btn=document.getElementById('fetchAiPlan');
  if (btn) btn.addEventListener('click', e=>{ e.preventDefault(); callAiViaProxy(); });
  const cta=document.getElementById('aiCta');
  if (cta) cta.addEventListener('click', e=>{ e.preventDefault(); callAiViaProxy(); });
});
</script>
<script>
    
// ---------- Dynamic AI Recommendations ----------
function iconForRec(title='', detail=''){
  const t = (title+' '+detail).toLowerCase();
  if (t.match(/sql|data|analytics|chart|dashboard/)) return 'fa-database';
  if (t.match(/code|coding|program|api|project/))     return 'fa-code';
  if (t.match(/interview|mock|tell me/))             return 'fa-comments';
  if (t.match(/cv|resume|bullet|linkedin/))          return 'fa-id-card';
  if (t.match(/speak|presentation|public/))          return 'fa-microphone';
  if (t.match(/read|book|learn|course/))             return 'fa-book';
  if (t.match(/mentor|peer|share|update/))           return 'fa-users';
  return 'fa-lightbulb';
}

function renderDynamicRecs(recs){
  const box = document.getElementById('aiRecs');
  if (!box) return;
  box.innerHTML = '';

  if (!Array.isArray(recs) || recs.length === 0){
    box.innerHTML = '<p class="muted">No recommendations yet. Click <strong>Refresh</strong> to generate some.</p>';
    return;
  }

  recs.forEach((r)=>{
    const isString = (typeof r === 'string');
    const title = isString ? r : (r.title || 'Recommendation');
    const detail= isString ? '' : (r.detail || r.description || r.reason || '');
    const icon  = iconForRec(title, detail);

    const row = document.createElement('div');
    row.className = 'recommendation';
    row.innerHTML = `
      <div class="rec-icon"><i class="fas ${icon}"></i></div>
      <div class="rec-content">
        <h4>${title.replace(/[<>&]/g,s=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]))}</h4>
        ${detail ? `<p>${detail.replace(/[<>&]/g,s=>({'<':'&lt;','>':'&gt;','&':'&amp;'}[s]))}</p>` : ''}
      </div>
    `;
    box.appendChild(row);
  });
}

async function fetchDynamicRecs(){
  const box = document.getElementById('aiRecs');
  if (box) box.innerHTML = '<p class="muted">Generating recommendations…</p>';

  const body = {
    intent: 'generate_personalized_plan',
    user: {
      id: <?= json_encode($userId) ?>,
      username: <?= json_encode($user['username']) ?>,
      personality_type: <?= json_encode($user['personality_type']) ?>
    },
    goals: <?= json_encode($goals, JSON_UNESCAPED_UNICODE) ?>,
    stats: {
      goal_completion: <?= (int)$goal_completion ?>,
      streak: <?= (int)$streak ?>,
      course_count: <?= (int)$course_count ?>,
      achievements: <?= (int)$achievements ?>
    },
    source: 'student_dashboard.php#ai-recs'
  };

  try{
    const resp = await fetch('ai_proxy.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','Accept':'application/json'},
      body: JSON.stringify(body)
    });
    const text = await resp.text();
    const parsed = safeJsonParse(text) || extractJsonFromText(text);
    const recs = (parsed && parsed.data && Array.isArray(parsed.data.recommendations))
      ? parsed.data.recommendations : [];

    // Fallback (local heuristic) if upstream returns nothing
    if (!recs.length){
      const local = [];
      const personality = (<?= json_encode($user['personality_type'] ?: '') ?>+'').toUpperCase();
      const hasProgramming = <?= json_encode(array_reduce($goals, fn($c,$g)=>$c || str_contains(strtolower($g['goal']),'program'), false)) ?>;
      const hasData = <?= json_encode(array_reduce($goals, fn($c,$g)=>$c || str_contains(strtolower($g['goal']),'data'), false)) ?>;

      if (hasProgramming) local.push({title:'Solve 3 coding challenges', detail:'1 easy, 1 medium, 1 hard. Focus on your weak DS topics.'});
      if (hasData)        local.push({title:'Daily SQL (15 min)', detail:'Practice JOIN + GROUP BY; keep your best 3 queries in a gist.'});
      if (personality==='INTJ') local.push({title:'Plan → Execute → Retrospect', detail:'Define one measurable outcome and schedule two focus blocks.'});
      if (personality==='INTP') local.push({title:'Prototype in 60 minutes', detail:'Turn one idea into a tiny, shareable demo today.'});
      local.push({title:'Share progress with a mentor/peer', detail:'Post a 2-minute update with a screenshot or link.'});
      renderDynamicRecs(local);
      return;
    }

    renderDynamicRecs(recs);
  } catch(e){
    if (box) box.innerHTML = `<p style="color:#e74c3c;"><strong>Failed to load recommendations:</strong> ${String(e.message || e)}</p>`;
  }
}

// Wire refresh button + auto-load on page ready
document.addEventListener('DOMContentLoaded', ()=>{
  const btn = document.getElementById('refreshAiRecs');
  if (btn) btn.addEventListener('click', (e)=>{ e.preventDefault(); fetchDynamicRecs(); });

  // Load once when page opens
  fetchDynamicRecs();

  // Also refresh this section after the AI Coach plan returns (if present in your page):
  const _origRenderAiResults = (typeof renderAiResults === 'function') ? renderAiResults : null;
  if (_origRenderAiResults){
    window.renderAiResults = function(payload){
      try {
        _origRenderAiResults(payload);
        const data = (payload && payload.data) ? payload.data : payload;
        if (data && Array.isArray(data.recommendations) && data.recommendations.length){
          renderDynamicRecs(data.recommendations);
        }
      } catch(e){ /* ignore */ }
    }
  }
});
</script>
<script>


  // Chart
  const studentCtx = document.getElementById('studentChart').getContext('2d');
  new Chart(studentCtx, {
    type:'line',
    data:{
      labels: <?= json_encode($chart_labels) ?>,
      datasets:[{
        label:'Goal Completion',
        data: <?= json_encode($chart_completion) ?>,
        borderColor:'#1a2a6c',
        backgroundColor:'rgba(26,42,108,.1)',
        tension:.3, fill:true
      },{
        label:'Learning Progress',
        data: <?= json_encode($chart_learning) ?>,
        borderColor:'#4db8ff',
        backgroundColor:'rgba(77,184,255,.1)',
        tension:.3, fill:true
      }]
    },
    options:{
      responsive:true, maintainAspectRatio:false,
      plugins:{legend:{position:'top'}},
      scales:{y:{beginAtZero:true,max:100,ticks:{callback:(v)=>v+'%'}}}
    }
  });

  // Mobile menu
  const mobileMenuToggle = document.getElementById('mobileMenuToggle');
  const sidebar = document.getElementById('sidebar');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  function toggleMobileMenu(){
    sidebar.classList.toggle('active'); sidebarOverlay.classList.toggle('active');
    const icon = mobileMenuToggle.querySelector('i');
    if (sidebar.classList.contains('active')) { icon.classList.replace('fa-bars','fa-times'); }
    else { icon.classList.replace('fa-times','fa-bars'); }
  }
  mobileMenuToggle.addEventListener('click', toggleMobileMenu);
  sidebarOverlay.addEventListener('click', toggleMobileMenu);
  document.querySelectorAll('.nav-item').forEach(i=>{
    i.addEventListener('click', ()=>{ if (window.innerWidth <= 992) toggleMobileMenu(); });
  });
  window.addEventListener('resize', ()=>{
    if (window.innerWidth > 992) {
      sidebar.classList.remove('active'); sidebarOverlay.classList.remove('active');
      const icon = mobileMenuToggle.querySelector('i'); icon.classList.remove('fa-times'); icon.classList.add('fa-bars');
    }
  });

  // Add goal
  const addGoalBtn = document.querySelector('.add-goal button');
  const goalInput  = document.querySelector('.add-goal input');
  function addGoal(){
    const val = (goalInput.value || '').trim();
    if (!val) return;
    fetch('add_goal.php', {
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'goal=' + encodeURIComponent(val)
    })
    .then(r=>r.json())
    .then(d=>{
      if (d.success){ alert(d.message || 'Goal added'); goalInput.value=''; location.reload(); }
      else { alert(d.message || 'Failed to add goal'); }
    })
    .catch(()=> alert('Failed to add goal'));
  }
  if (addGoalBtn) addGoalBtn.addEventListener('click', addGoal);
  if (goalInput) goalInput.addEventListener('keypress', (e)=>{ if (e.key === 'Enter') addGoal(); });

  // Toggle goal completion
  document.querySelectorAll('.goal-check input').forEach(cb=>{
    cb.addEventListener('change', function(){
      const progressBar = this.closest('.goal-item').querySelector('.progress-bar');
      const goalId = this.getAttribute('data-goal-id');
      const status = this.checked ? 'completed' : 'in_progress';
      fetch('update_goal.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`goal_id=${encodeURIComponent(goalId)}&status=${encodeURIComponent(status)}`
      })
      .then(r=>r.json())
      .then(d=>{
        if (d.success) progressBar.style.width = this.checked ? '100%' : '50%';
        else { alert(d.message || 'Failed to update'); this.checked = !this.checked; }
      })
      .catch(()=>{ alert('Failed to update goal'); this.checked = !this.checked; });
    });
  });

  // Static rec clicks
  document.querySelectorAll('#staticAiRecommendations .recommendation').forEach(rec=>{
    rec.addEventListener('click', function(){
      const title = this.querySelector('h4')?.textContent || 'Item';
      alert(`Opening: ${title}`);
    });
  });

            // Review popup (fixed)
            const reviewOverlay = document.getElementById('reviewOverlay');
            const reviewPopup = document.getElementById('reviewPopup');
            const closePopupBtn = document.getElementById('closePopupBtn');
            const cancelReviewBtn = document.getElementById('cancelReviewBtn');
            const sendReviewBtn = document.getElementById('sendReviewBtn');
            const stars = Array.from(document.querySelectorAll('.star'));
            const reviewForm = document.getElementById('reviewForm');
            const thankYouMessage = document.getElementById('thankYouMessage');
            let selectedRating = 0;

            function openPopup() {
                reviewOverlay.style.display = 'flex';
                setTimeout(()=>{ reviewPopup.style.transform='translateY(0)'; reviewPopup.style.opacity='1'; }, 10);
            }
            function closePopup() {
                reviewPopup.style.transform='translateY(20px)'; reviewPopup.style.opacity='0';
                setTimeout(()=>{
                    reviewOverlay.style.display='none';
                    const date = new Date(); date.setDate(date.getDate()+7);
                    document.cookie = `reviewClosed=true; expires=${date.toUTCString()}; path=/`;
                },300);
            }
            function getCookie(name){
                const value = `; ${document.cookie}`;
                const parts = value.split(`; ${name}=`);
                if (parts.length === 2) return parts.pop().split(';').shift();
            }
            setTimeout(()=>{
                const closed = getCookie('reviewClosed');
                if (!localStorage.getItem('reviewSubmitted') && !closed) openPopup();
            }, 30000);

            stars.forEach(star=>{
                star.addEventListener('click', function(){
                    selectedRating = parseInt(this.getAttribute('data-rating'));
                    stars.forEach((s, idx)=>{
                        if (idx < selectedRating){ s.innerHTML='<i class="fas fa-star"></i>'; s.classList.add('active'); }
                        else { s.innerHTML='<i class="far fa-star"></i>'; s.classList.remove('active'); }
                    });
                });
            });
            closePopupBtn.addEventListener('click', closePopup);
            cancelReviewBtn.addEventListener('click', closePopup);
            reviewOverlay.addEventListener('click', function(e){ if (e.target === reviewOverlay) closePopup(); });
            sendReviewBtn.addEventListener('click', function(){
                if (selectedRating === 0) { alert('Please select a rating'); return; }
                const comment = document.getElementById('reviewComment').value;
                fetch('submit_review.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `rating=${encodeURIComponent(selectedRating)}&comment=${encodeURIComponent(comment)}`
                })
                .then(r=>r.json())
                .then(data=>{
                    if (data.success){
                        reviewForm.style.display='none'; thankYouMessage.style.display='block';
                        localStorage.setItem('reviewSubmitted','true'); setTimeout(closePopup, 2000);
                    } else {
                        alert(data.message || 'Failed to submit review');
                    }
                })
                .catch(err=>{ console.error(err); alert('Failed to submit review'); });
            });
        });
    </script>
</body>
</html>
