<?php
if (session_status() === PHP_SESSION_NONE) session_start();

function flash($type, $msg) {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}
function require_role($role) {
    if (($_SESSION['role'] ?? null) !== $role) {
        flash('warning', 'You must be logged in as '.$role.'.');
        header('Location: login.php'); exit;
    }
}
function logged_in() { return !empty($_SESSION['user_id']); }
