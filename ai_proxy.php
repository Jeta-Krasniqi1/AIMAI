<?php
/**
 * ai_proxy.php
 * Proxies JSON to upstream AI webhook with local fallbacks
 */

declare(strict_types=1);
ini_set('display_errors', '0');
error_reporting(E_ALL);

// ---------- CORS ----------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Vary: Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    echo '';
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false, 'error'=>'Method Not Allowed. Use POST.']);
    exit;
}

// ---------- Configuration ----------
const WEBHOOK_URL = 'https://amazon-agjent.onrender.com/webhook/ai-agent';
const WEBHOOK_TIMEOUT = 25;
const MAX_RETRIES = 3;

// ---------- Helpers ----------
function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $j = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) return $j;

    // Try fragment extraction
    $firstObj = strpos($raw, '{');
    $firstArr = strpos($raw, '[');
    $starts = array_filter([$firstObj, $firstArr], fn($v) => $v !== false);
    if ($starts) {
        $start = min($starts);
        for ($i = strlen($raw); $i > $start + 1; $i--) {
            $slice = trim(substr($raw, $start, $i - $start));
            $tmp = json_decode($slice, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) return $tmp;
        }
    }
    return [];
}

function http_post_json_with_retry(string $url, array $payload, int $timeout = WEBHOOK_TIMEOUT): array {
    $lastError = '';
    
    for ($attempt = 1; $attempt <= MAX_RETRIES; $attempt++) {
        $ch = curl_init($url);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'AimAI-Proxy/1.0'
        ]);
        
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("AI Proxy attempt $attempt: HTTP $code, " . ($err ?: 'success'));
        
        if (!$err && ($code < 500 || $code === 200)) {
            return [$code, $body, $err];
        }
        
        $lastError = $err ?: "HTTP $code";
        
        if ($attempt < MAX_RETRIES) {
            sleep(pow(2, $attempt - 1));
        }
    }
    
    return [0, '', $lastError];
}

function extract_json_from_text(?string $t): ?array {
    if (!is_string($t) || $t === '') return null;
    $j = json_decode($t, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) return $j;
    $firstObj = strpos($t, '{'); $firstArr = strpos($t, '[');
    $starts = array_filter([$firstObj, $firstArr], fn($v) => $v !== false);
    if (!$starts) return null;
    $start = min($starts);
    for ($i = strlen($t); $i > $start + 1; $i--) {
        $slice = trim(substr($t, $start, $i - $start));
        $tmp = json_decode($slice, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) return $tmp;
    }
    return null;
}

function normalize_payload($any) {
    if (is_array($any)) {
        if (isset($any['data']) && is_array($any['data'])) return $any['data'];
        if (isset($any['json']) && is_array($any['json'])) return $any['json'];
        if (isset($any[0]) && is_array($any[0])) {
            $f = $any[0];
            if (isset($f['json']) && is_array($f['json'])) return $f['json'];
            if (isset($f['data']) && is_array($f['data'])) return $f['data'];
        }
    }
    return $any;
}

function nice_date(int $offsetDays): string {
    $d = new DateTime('now');
    if ($offsetDays !== 0) $d->modify(($offsetDays > 0 ? '+' : '') . $offsetDays . ' days');
    return $d->format('D, M j');
}

/**
 * Generate CV content from user data
 */
function generate_cv_content(array $user, array $skills, array $achievements, array $allGoals): string {
    $cv = "═══════════════════════════════════════════════════════════\n";
    $cv .= "                    CURRICULUM VITAE\n";
    $cv .= "═══════════════════════════════════════════════════════════\n\n";
    
    // Personal Information
    $cv .= "PERSONAL INFORMATION\n";
    $cv .= "━━━━━━━━━━━━━━━━━━━━\n";
    $cv .= "Name:               " . $user['username'] . "\n";
    $cv .= "Email:              " . $user['email'] . "\n";
    if ($user['personality_type']) {
        $cv .= "Personality Type:   " . $user['personality_type'] . "\n";
    }
    if ($user['specialization']) {
        $cv .= "Specialization:     " . $user['specialization'] . "\n";
    }
    $cv .= "\n";
    
    // Professional Summary
    $cv .= "PROFESSIONAL SUMMARY\n";
    $cv .= "━━━━━━━━━━━━━━━━━━━━\n";
    $cv .= "Motivated " . ($user['personality_type'] ?: 'professional') . " with strong commitment to ";
    $cv .= "continuous learning and personal development. Actively pursuing career goals in ";
    
    // Extract career areas from goals
    $goalAreas = [];
    foreach ($allGoals as $g) {
        $goalText = strtolower($g['goal']);
        if (stripos($goalText, 'python') !== false && !in_array('Python Development', $goalAreas)) {
            $goalAreas[] = 'Python Development';
        }
        if (stripos($goalText, 'javascript') !== false && !in_array('JavaScript Development', $goalAreas)) {
            $goalAreas[] = 'JavaScript Development';
        }
        if (stripos($goalText, 'data') !== false && !in_array('Data Science', $goalAreas)) {
            $goalAreas[] = 'Data Science';
        }
        if (stripos($goalText, 'web') !== false && !in_array('Web Development', $goalAreas)) {
            $goalAreas[] = 'Web Development';
        }
        if (stripos($goalText, 'design') !== false && !in_array('UI/UX Design', $goalAreas)) {
            $goalAreas[] = 'UI/UX Design';
        }
    }
    
    if (empty($goalAreas)) {
        $goalAreas[] = $user['specialization'] ?: 'Technology';
    }
    
    $cv .= implode(', ', array_slice($goalAreas, 0, 3)) . ". ";
    $cv .= "Demonstrated ability to set and achieve meaningful objectives through structured goal-setting ";
    $cv .= "and consistent progress tracking.\n\n";
    
    // Core Competencies / Skills
    if (!empty($skills)) {
        $cv .= "CORE COMPETENCIES\n";
        $cv .= "━━━━━━━━━━━━━━━━━\n";
        
        $acquiredSkills = array_filter($skills, fn($s) => $s['status'] === 'acquired');
        $learningSkills = array_filter($skills, fn($s) => $s['status'] === 'learning');
        
        if (!empty($acquiredSkills)) {
            $cv .= "▪ Proficient Skills:\n";
            foreach ($acquiredSkills as $skill) {
                $cv .= "  • " . $skill['name'];
                if ($skill['acquired_at']) {
                    $cv .= " (since " . date('M Y', strtotime($skill['acquired_at'])) . ")";
                }
                $cv .= "\n";
            }
        }
        
        if (!empty($learningSkills)) {
            $cv .= "\n▪ Currently Developing:\n";
            foreach ($learningSkills as $skill) {
                $cv .= "  • " . $skill['name'] . "\n";
            }
        }
        $cv .= "\n";
    }
    
    // Key Achievements
    if (!empty($achievements)) {
        $cv .= "KEY ACHIEVEMENTS\n";
        $cv .= "━━━━━━━━━━━━━━━━\n";
        foreach (array_slice($achievements, 0, 8) as $achievement) {
            $cv .= "▪ " . $achievement['goal'];
            $cv .= "\n  Completed: " . date('F Y', strtotime($achievement['last_updated'])) . "\n\n";
        }
    }
    
    // Current Development Goals
    $inProgressGoals = array_filter($allGoals, fn($g) => $g['progress_status'] === 'in_progress');
    if (!empty($inProgressGoals)) {
        $cv .= "CURRENT DEVELOPMENT OBJECTIVES\n";
        $cv .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        foreach (array_slice($inProgressGoals, 0, 5) as $goal) {
            $cv .= "▪ " . $goal['goal'] . "\n";
        }
        $cv .= "\n";
    }
    
    // Professional Attributes
    $cv .= "PROFESSIONAL ATTRIBUTES\n";
    $cv .= "━━━━━━━━━━━━━━━━━━━━━━━\n";
    $cv .= "▪ Strong goal-setting and achievement tracking capabilities\n";
    $cv .= "▪ Self-motivated with commitment to continuous improvement\n";
    $cv .= "▪ Structured approach to skill development and career planning\n";
    
    if ($user['personality_type']) {
        $personalityTraits = [
            'INTJ' => ['Strategic thinking', 'Analytical problem-solving', 'Independent work style'],
            'INTP' => ['Innovative thinking', 'Analytical mindset', 'Creative problem-solving'],
            'ENTJ' => ['Leadership qualities', 'Strategic planning', 'Results-driven approach'],
            'ENTP' => ['Creative innovation', 'Quick learning', 'Adaptable thinking'],
            'INFJ' => ['Insightful analysis', 'Collaborative approach', 'Long-term planning'],
            'INFP' => ['Creative thinking', 'Value-driven work', 'Adaptable mindset'],
            'ENFJ' => ['Team collaboration', 'Motivational skills', 'Interpersonal communication'],
            'ENFP' => ['Creative enthusiasm', 'Adaptive learning', 'Collaborative spirit'],
            'ISTJ' => ['Detail-oriented', 'Reliable execution', 'Systematic approach'],
            'ISFJ' => ['Supportive teamwork', 'Careful attention to detail', 'Dedicated work ethic'],
            'ESTJ' => ['Organized execution', 'Leadership capability', 'Efficient processes'],
            'ESFJ' => ['Team coordination', 'Supportive collaboration', 'Strong work ethic'],
            'ISTP' => ['Practical problem-solving', 'Technical aptitude', 'Hands-on approach'],
            'ISFP' => ['Creative adaptability', 'Practical skills', 'Flexible work style'],
            'ESTP' => ['Action-oriented', 'Quick adaptation', 'Practical execution'],
            'ESFP' => ['Enthusiastic collaboration', 'Adaptable skills', 'Team engagement']
        ];
        
        $traits = $personalityTraits[$user['personality_type']] ?? ['Professional work ethic', 'Committed to excellence'];
        foreach ($traits as $trait) {
            $cv .= "▪ " . $trait . "\n";
        }
    }
    
    $cv .= "\n";
    $cv .= "═══════════════════════════════════════════════════════════\n";
    $cv .= "Generated via AIMAI Career Development Platform\n";
    $cv .= date('F d, Y \a\t g:i A') . "\n";
    $cv .= "═══════════════════════════════════════════════════════════\n";
    
    return $cv;
}

/**
 * Generate chat response based on user message
 */
function generate_chat_response(string $message, array $userContext): string {
    $message = strtolower(trim($message));
    $name = $userContext['username'] ?? 'there';
    $personality = $userContext['personality_type'] ?? '';
    
    // Simple keyword-based responses
    if (strpos($message, 'goal') !== false) {
        return "Great question about goals! Based on your profile, I'd recommend breaking down your objectives into smaller, actionable steps. What specific goal would you like to work on?";
    }
    
    if (strpos($message, 'career') !== false || strpos($message, 'job') !== false) {
        return "Career development is crucial! I see you're focused on growth. Have you considered updating your skills portfolio or connecting with mentors in your field?";
    }
    
    if (strpos($message, 'mentor') !== false) {
        return "Mentorship can accelerate your progress significantly! I can help you identify potential mentors based on your interests and career goals. What area would you like mentorship in?";
    }
    
    if (strpos($message, 'skill') !== false || strpos($message, 'learn') !== false) {
        return "Learning new skills is fantastic! " . ($personality === 'INTJ' ? "Your analytical nature" : "Your learning style") . " suggests focusing on structured, project-based learning. What skill interests you most?";
    }
    
    if (strpos($message, 'cv') !== false || strpos($message, 'resume') !== false) {
        return "I can help you generate a professional CV! Click the 'Generate Professional CV' button in the recommendations section, and I'll create one based on your achievements and skills.";
    }
    
    if (strpos($message, 'help') !== false || strpos($message, 'stuck') !== false) {
        return "I'm here to help, $name! Sometimes when we feel stuck, it helps to step back and reassess. What specific challenge are you facing right now?";
    }
    
    // Default responses
    $responses = [
        "That's an interesting point! Can you tell me more about what you're thinking?",
        "I understand. Based on your progress so far, you're doing well. What would you like to focus on next?",
        "Thanks for sharing that with me. How can I best support your goals today?",
        "I'm here to help you succeed, $name. What specific area would you like guidance on?",
        "Great question! Let's think about this step by step. What's your main priority right now?"
    ];
    
    return $responses[array_rand($responses)];
}

/**
 * Synthesize a user-friendly weekly plan from incoming inputs
 */
function synthesize_plan(array $incoming, string $rawText = ''): array {
    $user = $incoming['user'] ?? [];
    $name = $user['username'] ?? ($user['name'] ?? 'Student');
    $ptype= $user['personality_type'] ?? ($user['personality'] ?? null);

    $stats = $incoming['stats'] ?? [];
    $gc    = (int)($stats['goal_completion'] ?? 0);
    $streak= (int)($stats['streak'] ?? 0);

    $goals = $incoming['goals'] ?? [];
    $goalTitles = [];
    foreach ($goals as $g) {
        if (is_array($g)) {
            $goalTitles[] = $g['goal'] ?? ($g['title'] ?? null);
        } elseif (is_string($g)) {
            $goalTitles[] = $g;
        }
    }
    $goalTitles = array_values(array_filter(array_unique($goalTitles), fn($x)=>is_string($x) && trim($x) !== ''));

    $focus = array_slice($goalTitles, 0, 3);
    if (!$focus) $focus = ['Pick one new learning goal', 'Complete one portfolio artifact', 'Apply to 1 opportunity'];

    $steps = [];
    $dayOffsets = [1, 3, 5, 7, 9, 11];
    $i=0;
    foreach ($focus as $fg) {
        $steps[] = [
            'title'  => "Break down: " . $fg,
            'detail' => "List 3 sub-tasks and a definition of done. Due " . nice_date($dayOffsets[$i % count($dayOffsets)])
        ];
        $i++;
        $steps[] = [
            'title'  => "Deep work block for: " . $fg,
            'detail' => "Schedule 25–40 minutes of focused work. Aim for 2 sessions this week. First session " . nice_date($dayOffsets[$i % count($dayOffsets)])
        ];
        $i++;
    }

    $steps[] = [
        'title'  => "Weekly review",
        'detail' => "On " . nice_date(7) . ", reflect for 10 minutes: What moved the needle? What's next?"
    ];
    $steps[] = [
        'title'  => "Show your work",
        'detail' => "Share a small update (note/screenshot) with a mentor or peer by " . nice_date(6)
    ];

    $recommendations = [];
    if ($ptype) {
        $pt = strtoupper((string)$ptype);
        if (in_array($pt, ['INTJ','INTP','ISTJ','ISTP'])) {
            $recommendations[] = [
                'title' => 'Focus Time Optimization',
                'detail' => 'Block 2-3 hours of uninterrupted time daily. Analytical types thrive on deep work.',
                'reason' => 'Your personality type benefits from extended focus periods'
            ];
        } elseif (in_array($pt, ['ENTJ','ENTP'])) {
            $recommendations[] = [
                'title' => 'Goal Scoping Strategy',
                'detail' => 'Write one-sentence outcomes for each task to prevent over-scoping.',
                'reason' => 'Helps channel your ambitious nature productively'
            ];
        }
    }
    
    if ($gc < 50) {
        $recommendations[] = [
            'title' => 'Small Wins Strategy',
            'detail' => 'Set a daily 15-minute minimum. Consistency builds momentum.',
            'reason' => 'Your completion rate suggests starting smaller will help'
        ];
    } else {
        $recommendations[] = [
            'title' => 'Challenge Increase',
            'detail' => 'Raise difficulty by 10-15% to stay in the growth zone.',
            'reason' => 'Your strong progress indicates readiness for bigger challenges'
        ];
    }

    $tips = [];
    if ($ptype) {
        $pt = strtoupper((string)$ptype);
        if (in_array($pt, ['INTJ','INTP','ISTJ','ISTP'])) {
            $tips[] = "Protect one distraction-free block daily; analytical types thrive on uninterrupted focus.";
        } elseif (in_array($pt, ['ENTJ','ENTP'])) {
            $tips[] = "Write a one-sentence goal for each task to curb over-scoping and ship faster.";
        } elseif (in_array($pt, ['INFJ','INFP'])) {
            $tips[] = "Attach a 'why' to each task; meaning turbo-charges follow-through.";
        } else {
            $tips[] = "Keep tasks time-boxed (25–40 mins) to build momentum and avoid burnout.";
        }
    } else {
        $tips[] = "Time-box tasks (25–40 mins) and end each with a 1-line summary to lock in progress.";
    }

    if ($gc < 50) {
        $tips[] = "Start tiny: a daily 10-minute minimum counts; consistency beats intensity.";
    } else {
        $tips[] = "Raise difficulty slightly (10–15%) to keep growth in the sweet spot.";
    }
    if ($streak > 0) {
        $tips[] = "Protect your " . $streak . "-day streak: schedule your next session now.";
    }

    $summary = sprintf(
        "%s, here's a simple plan for the next 7–10 days. Focus on %d key goal%s, keep short daily blocks, and do a quick weekly review.",
        $name,
        count($focus),
        count($focus) === 1 ? '' : 's'
    );

    return [
        'title'   => 'Your Personalized Plan',
        'summary' => $summary,
        'steps'   => $steps,
        'tips'    => $tips,
        'recommendations' => $recommendations
    ];
}

/**
 * Generate a single recommendation
 */
function generate_recommendation(array $incoming): array {
    $user = $incoming['user'] ?? [];
    $goals = $incoming['goals'] ?? [];
    $stats = $incoming['stats'] ?? [];
    
    $recommendations = [
        "Based on your goals, consider dedicating 30 minutes daily to skill practice. Consistency trumps intensity.",
        "Your personality type suggests you'd benefit from setting weekly milestones rather than daily ones.",
        "Try the Pomodoro Technique: 25 minutes focused work, 5 minute break. It aligns well with your learning style.",
        "Consider joining a study group or finding an accountability partner for your current goals.",
        "Update your LinkedIn profile with your recent accomplishments to improve job matching.",
        "Set aside time for reflection: what worked this week and what can be improved next week?",
        "Consider creating a portfolio project that demonstrates your skills to potential employers.",
        "Network with professionals in your field through virtual events or LinkedIn connections."
    ];
    
    $personalizedRec = $recommendations[array_rand($recommendations)];
    
    $personality = $user['personality_type'] ?? '';
    if ($personality) {
        $personalityAdvice = [
            'INTJ' => " As an INTJ, focus on long-term strategic planning and system optimization.",
            'INTP' => " Your analytical nature suggests exploring the theoretical foundations behind practical skills.",
            'ENTJ' => " Channel your leadership qualities by mentoring others while you learn.",
            'ENTP' => " Consider multiple approaches and don't be afraid to pivot if you find a better path.",
        ];
        
        if (isset($personalityAdvice[$personality])) {
            $personalizedRec .= $personalityAdvice[$personality];
        }
    }
    
    return [
        'recommendation' => $personalizedRec,
        'confidence' => 'high',
        'category' => 'learning_strategy'
    ];
}

/**
 * If upstream plan has nothing, fill it.
 */
function ensure_plan(array $incoming, array $maybePlan, string $rawText): array {
    $title   = $maybePlan['title']   ?? '';
    $summary = trim((string)($maybePlan['summary'] ?? ''));
    $steps   = $maybePlan['steps']   ?? $maybePlan['tasks'] ?? $maybePlan['items'] ?? [];
    $tips    = $maybePlan['tips']    ?? $maybePlan['suggestions'] ?? [];

    $hasSteps = is_array($steps) && count($steps) > 0;

    if ($summary === '' && !$hasSteps) {
        if (is_string($rawText) && trim($rawText) !== '') {
            $lines = preg_split('/\r\n|\r|\n/', trim($rawText));
            $lines = array_values(array_filter(array_map('trim', $lines), fn($l)=>$l!==''));
            if ($lines) {
                $maybePlan['title']   = $title ?: 'Your Personalized Plan';
                $maybePlan['summary'] = array_shift($lines);
                $maybePlan['steps']   = array_map(function($l, $i){
                    return ['title'=>"Step ".($i+1), 'detail'=>$l];
                }, array_slice($lines, 0, 6), array_keys(array_slice($lines, 0, 6)));
                return $maybePlan;
            }
        }
        return synthesize_plan($incoming, $rawText);
    }

    if ($hasSteps) {
        $norm = [];
        foreach ($steps as $i => $s) {
            if (is_string($s)) {
                $norm[] = ['title' => 'Step ' . ($i+1), 'detail' => $s];
            } elseif (is_array($s)) {
                $norm[] = [
                    'title'  => $s['title']  ?? ('Step ' . ($i+1)),
                    'detail' => $s['detail'] ?? ($s['desc'] ?? ($s['text'] ?? ''))
                ];
            }
        }
        $maybePlan['steps'] = $norm;
    } else {
        $maybePlan['steps'] = [];
    }

    if (is_array($tips) && $tips) {
        $maybePlan['tips'] = array_values(array_filter(array_map(function($t){
            if (is_string($t)) return $t;
            if (is_array($t))  return $t['tip'] ?? ($t['text'] ?? null);
            return null;
        }, $tips)));
    } else {
        $maybePlan['tips'] = [];
    }

    $maybePlan['title']   = $maybePlan['title']   ?? ($title ?: 'Your Personalized Plan');
    $maybePlan['summary'] = $maybePlan['summary'] ?? $summary;

    return $maybePlan;
}

// ---------- Main Proxy Flow ----------
$incoming = read_json_body();

error_log("AI Proxy received request: " . json_encode($incoming));

if (empty($incoming['intent'])) {
    $incoming['intent'] = 'generate_personalized_plan';
    $incoming['source'] = $incoming['source'] ?? 'student_dashboard.php';
}

$intent = $incoming['intent'] ?? '';
error_log("Processing intent: $intent");

// ========== CV GENERATION HANDLER ==========
if ($intent === 'generate_cv') {
    error_log("Processing CV generation request");
    
    session_start();
    $user_id = $_SESSION['user_id'] ?? ($incoming['user']['id'] ?? null);
    
    if (!$user_id) {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'User ID required']);
        exit;
    }
    
    require_once __DIR__ . '/config.php';
    
    try {
        // Get user data
        $stmt = $pdo->prepare("SELECT username, email, personality_type, specialization FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception("User not found");
        }
        
        // Get skills
        $stmt = $pdo->prepare("
            SELECT s.name, us.status, us.acquired_at
            FROM user_skills us
            JOIN skills s ON us.skill_id = s.skill_id
            WHERE us.user_id = ?
            ORDER BY us.acquired_at DESC
        ");
        $stmt->execute([$user_id]);
        $skills = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get completed goals
        $stmt = $pdo->prepare("
            SELECT goal, last_updated
            FROM motivational_progress
            WHERE user_id = ? AND progress_status = 'completed'
            ORDER BY last_updated DESC
            LIMIT 10
        ");
        $stmt->execute([$user_id]);
        $achievements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get all goals
        $stmt = $pdo->prepare("
            SELECT goal, progress_status
            FROM motivational_progress
            WHERE user_id = ?
            ORDER BY last_updated DESC
        ");
        $stmt->execute([$user_id]);
        $allGoals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Generate CV content
        $cvContent = generate_cv_content($user, $skills, $achievements, $allGoals);
        
        // Store CV
        $stmt = $pdo->prepare("
            INSERT INTO cvs (user_id, job_id, content, created_at)
            VALUES (?, NULL, ?, NOW())
        ");
        $stmt->execute([$user_id, $cvContent]);
        
        $cv_id = $pdo->lastInsertId();
        
        error_log("CV generated successfully: ID=$cv_id for user=$user_id");
        
        echo json_encode([
            'ok' => true,
            'data' => [
                'cv_id' => $cv_id,
                'content' => $cvContent,
                'generated_at' => date('Y-m-d H:i:s'),
                'message' => 'CV generated successfully!',
                'download_url' => 'download_cv.php?id=' . $cv_id
            ]
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log("CV generation error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        exit;
    }
}

// ========== CHAT MESSAGE HANDLER ==========
if ($intent === 'chat_message') {
    $message = $incoming['message'] ?? '';
    $userContext = $incoming['user'] ?? [];
    
    if (trim($message) === '') {
        http_response_code(400);
        echo json_encode(['ok'=>false, 'error'=>'Empty message']);
        exit;
    }
    
    error_log("Attempting n8n call for chat message");
    [$status, $body, $curlErr] = http_post_json_with_retry(WEBHOOK_URL, $incoming);
    error_log("n8n response: status=$status, error=$curlErr");
    
    if (!$curlErr && $status === 200) {
        $parsed = extract_json_from_text($body);
        if ($parsed && isset($parsed['response'])) {
            echo json_encode(['ok'=>true, 'data'=>['response'=>$parsed['response']]]);
            exit;
        }
    }
    
    // Fallback to local chat response
    $response = generate_chat_response($message, $userContext);
    echo json_encode(['ok'=>true, 'data'=>['response'=>$response]]);
    exit;
}

// ========== RECOMMENDATION HANDLER ==========
if ($intent === 'generate_recommendation') {
    error_log("Attempting n8n call for recommendation");
    [$status, $body, $curlErr] = http_post_json_with_retry(WEBHOOK_URL, $incoming);
    error_log("n8n response: status=$status, error=$curlErr");
    
    if (!$curlErr && $status === 200) {
        $parsed = extract_json_from_text($body);
        if ($parsed && isset($parsed['recommendation'])) {
            echo json_encode(['ok'=>true, 'data'=>$parsed]);
            exit;
        }
    }
    
    error_log("Using local recommendation fallback");
    $recommendation = generate_recommendation($incoming);
    echo json_encode(['ok'=>true, 'data'=>$recommendation]);
    exit;
}

// ========== DEFAULT: PERSONALIZED PLAN ==========
error_log("Attempting n8n call for default plan generation");
[$status, $body, $curlErr] = http_post_json_with_retry(WEBHOOK_URL, $incoming);

$parsed = extract_json_from_text($body);
$data   = normalize_payload($parsed ?? []);
$rawText = $parsed ? '' : (string)$body;
$planIn  = is_array($data) ? $data : [];
$plan    = ensure_plan($incoming, $planIn, $rawText);

if ($curlErr) {
    error_log("AI Proxy final error: $curlErr");
    $plan = synthesize_plan($incoming, $rawText);
    http_response_code(200);
    echo json_encode(['ok'=>true,'data'=>$plan,'note'=>"Used local fallback due to connection error"], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($status >= 400) {
    $plan = synthesize_plan($incoming, $rawText);
    http_response_code(200);
    echo json_encode(['ok'=>true,'data'=>$plan,'note'=>"Upstream HTTP $status; returned synthesized plan"], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(200);
echo json_encode(['ok'=>true,'data'=>$plan], JSON_UNESCAPED_UNICODE);
?>