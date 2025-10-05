<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $goal = filter_input(INPUT_POST, 'goal', FILTER_SANITIZE_STRING);
    if ($goal) {
        try {
            $stmt = $pdo->prepare("INSERT INTO motivational_progress (user_id, goal, progress_status, last_updated) VALUES (?, ?, 'in_progress', NOW())");
            $stmt->execute([$_SESSION['user_id'], $goal]);
            echo json_encode(['success' => true, 'message' => 'Goal added successfully']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid goal']);
    }
}
?>