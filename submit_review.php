<?php
session_start();
require 'config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SESSION['user_id'])) {
    $data = json_decode(file_get_contents('php://input'), true);
    $rating = isset($data['rating']) ? (int)$data['rating'] : 0;
    $comment = isset($data['comment']) ? trim($data['comment']) : '';

    if ($rating < 1 || $rating > 5) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid rating']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO reviews (user_id, rating, comment, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$_SESSION['user_id'], $rating, $comment]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
}
?>