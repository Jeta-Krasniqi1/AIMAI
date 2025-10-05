<?php
session_start();
require 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'mentor') {
    header("Location: login.php");
    exit;
}

$errors = [];
$success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $mentorship_id = filter_input(INPUT_POST, 'mentorship_id', FILTER_VALIDATE_INT);
        $action = filter_input(INPUT_POST, 'action');

        if (!$mentorship_id || !in_array($action, ['accept', 'reject'])) {
            $errors[] = "Invalid request.";
        } else {
            // Verify the mentorship request belongs to the mentor
            $stmt = $pdo->prepare("SELECT mentorship_id, user_id FROM mentorships WHERE mentorship_id = ? AND mentor_id = ? AND status = 'pending'");
            $stmt->execute([$mentorship_id, $_SESSION['user_id']]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$request) {
                $errors[] = "Mentorship request not found or already processed.";
            } else {
                // Update status
                $new_status = $action === 'accept' ? 'active' : 'rejected';
                $stmt = $pdo->prepare("UPDATE mentorships SET status = ? WHERE mentorship_id = ?");
                $stmt->execute([$new_status, $mentorship_id]);

                // Fetch student name for success message
                $stmt = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
                $stmt->execute([$request['user_id']]);
                $student = $stmt->fetchColumn();

                $success[] = "Mentorship request from $student has been " . ($action === 'accept' ? 'accepted' : 'rejected') . ".";
            }
        }
    } catch (PDOException $e) {
        error_log("Database error in handle_mentee_request.php: " . $e->getMessage());
        $errors[] = "A database error occurred. Please try again later.";
    } catch (Exception $e) {
        error_log("General error in handle_mentee_request.php: " . $e->getMessage());
        $errors[] = "An unexpected error occurred.";
    }
}

// Store messages in session and redirect
$_SESSION['mentee_request_errors'] = $errors;
$_SESSION['mentee_request_success'] = $success;
header("Location: mentor_dashboard.php");
exit;
?>