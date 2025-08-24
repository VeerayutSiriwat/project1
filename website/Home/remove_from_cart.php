<?php
session_start();
require __DIR__ . '/includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$id = (int)($_GET['id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

if ($id > 0) {
    $stmt = $conn->prepare("DELETE FROM cart_items WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $id, $user_id);
    $stmt->execute();
}

header("Location: cart_view.php");
exit;
