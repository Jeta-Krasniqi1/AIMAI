<?php
// File: api/job_recommendations.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require __DIR__ . '/../config.php';

function bad_request($msg,$code=400){ http_response_code($code); echo json_encode(['success'=>false,'error'=>$msg]); exit; }

$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)($input['user_id'] ?? 0);
$limit  = isset($_GET['limit']) ? (int)$_GET['limit'] : (int)($input['limit'] ?? 10);
if ($userId <= 0) bad_request('Missing or invalid user_id');

try {
    // collect keywords from goals
    $stmt = $pdo->prepare("SELECT goal FROM motivational_progress WHERE user_id=?");
    $stmt->execute([$userId]);
    $goals = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // collect keywords from skills
    $stmt = $pdo->prepare("
        SELECT s.skill_name
        FROM user_skills us
        JOIN skills s ON s.skill_id=us.skill_id
        WHERE us.user_id=?
    ");
    $stmt->execute([$userId]);
    $skills = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    $keywords = [];
    foreach ($goals as $g) {
        $t = strtolower($g);
        if (strpos($t,'python') !== false) $keywords[] = 'python';
        if (strpos($t,'data')   !== false) $keywords[] = 'data';
        if (strpos($t,'ml') !== false || strpos($t,'machine learning') !== false) $keywords[] = 'machine';
        if (strpos($t,'web') !== false) $keywords[] = 'web';
        if (strpos($t,'developer') !== false) $keywords[] = 'developer';
    }
    foreach ($skills as $s) { $keywords[] = strtolower($s); }
    $keywords = array_values(array_unique(array_filter($keywords)));

    $q = "
      SELECT j.job_id, j.title, j.description, j.region, j.salary_min, j.salary_max,
             c.name AS company_name
      FROM jobs j
      LEFT JOIN companies c ON c.company_id = j.company_id
      WHERE 1=1
    ";
    $params = [];
    if ($keywords) {
        $ors = [];
        foreach ($keywords as $kw) {
            $ors[] = "(j.title LIKE ? OR j.description LIKE ?)";
            $params[] = '%'.$kw.'%';
            $params[] = '%'.$kw.'%';
        }
        $q .= " AND (". implode(' OR ', $ors) .")";
    }
    $q .= " ORDER BY j.job_id DESC LIMIT ?";
    $params[] = $limit;

    $stmt = $pdo->prepare($q);
    $stmt->execute($params);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true, 'keywords'=>$keywords, 'jobs'=>$jobs], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_SLASHES);
}
