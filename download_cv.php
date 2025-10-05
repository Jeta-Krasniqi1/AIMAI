<?php
session_start();
require 'config.php';

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    header("Location: student_dashboard.php");
    exit;
}

$cv_id = (int)$_GET['id'];
$user_id = $_SESSION['user_id'];

try {
    // Get CV and verify ownership
    $stmt = $pdo->prepare("SELECT content, created_at FROM cvs WHERE cv_id = ? AND user_id = ?");
    $stmt->execute([$cv_id, $user_id]);
    $cv = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cv) {
        die("CV not found or access denied");
    }
    
    // Generate filename
    $filename = 'CV_' . $user_id . '_' . date('Y-m-d', strtotime($cv['created_at'])) . '.txt';
    
    // Set headers for download
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($cv['content']));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output CV content
    echo $cv['content'];
    exit;
    
} catch (PDOException $e) {
    error_log("Download CV error: " . $e->getMessage());
    die("Error downloading CV. Please try again.");
}
?>