<?php
$dbname = "u678631644_aimaidb";    // ✅ Full DB name with prefix
$username = "u678631644_aimai";    // ✅ Full DB username with prefix
$password = "Aimai1234";           // ✅ Your DB password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("❌ Connection failed: " . $e->getMessage());
}
?>
