<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $goal_id = filter_input(INPUT_POST, 'goal_id', FILTER_VALIDATE_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

    if ($goal_id && in_array($status, ['in_progress', 'completed'])) {
        try {
            $stmt = $pdo->prepare("UPDATE motivational_progress SET progress_status = ?, last_updated = NOW() WHERE progress_id = ? AND user_id = ?");
            $stmt->execute([$status, $goal_id, $_SESSION['user_id']]);
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Goal updated successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Goal not found or not owned by user']);
            }
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
    }
}
?>