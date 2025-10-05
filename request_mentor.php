<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require 'config.php';

// Check if user is authenticated and a student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: login.php");
    exit;
}

// Initialize error and success arrays
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate mentor_id
        $mentor_id = filter_input(INPUT_POST, 'mentor_id', FILTER_VALIDATE_INT);
        if (!$mentor_id) {
            $errors[] = "Please select a valid mentor.";
        } else {
            // Verify mentor exists in mentors table
            $stmt = $pdo->prepare("SELECT mentor_id FROM mentors WHERE mentor_id = ?");
            $stmt->execute([$mentor_id]);
            if (!$stmt->fetch()) {
                $errors[] = "The selected mentor does not exist.";
            }

            // Check for existing mentorship request
            $stmt = $pdo->prepare("
                SELECT mentorship_id 
                FROM mentorships 
                WHERE user_id = ? AND mentor_id = ? AND status IN ('pending', 'active')
            ");
            $stmt->execute([$_SESSION['user_id'], $mentor_id]);
            if ($stmt->fetch()) {
                $errors[] = "You already have a pending or active mentorship request with this mentor.";
            }

            // Insert mentorship request if no errors
            if (empty($errors)) {
                $stmt = $pdo->prepare("
                    INSERT INTO mentorships (user_id, mentor_id, status, start_date)
                    VALUES (?, ?, 'pending', NOW())
                ");
                $stmt->execute([$_SESSION['user_id'], $mentor_id]);
                $success = "Mentorship request sent successfully! Awaiting mentor approval.";
            }
        }
    } catch (PDOException $e) {
        error_log("Database error in request_mentor.php: " . $e->getMessage());
        $errors[] = "A database error occurred. Please try again later.";
    } catch (Exception $e) {
        error_log("General error in request_mentor.php: " . $e->getMessage());
        $errors[] = "An unexpected error occurred. Please try again later.";
    }
} else {
    $errors[] = "Invalid request method.";
}

// Store messages in session and redirect
$_SESSION['mentor_request_errors'] = $errors;
$_SESSION['mentor_request_success'] = $success;
header("Location: student_dashboard.php");
exit;
?>