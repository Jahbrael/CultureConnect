<?php
require 'includes/db.php';
require 'includes/auth.php';
require_role('resident');

$returnTo = $_POST['return_to'] ?? 'browse.php';
$redirect = 'browse.php';
if (is_string($returnTo) && preg_match('/^browse\.php([?#].*)?$/', $returnTo)) {
    $redirect = $returnTo;
}

$pid  = (int)($_POST['product_id'] ?? 0);
$vote = $_POST['vote'] ?? '';
if (!$pid || !in_array($vote, ['Yes','No'])) { flash('danger','Invalid vote.'); header('Location: ' . $redirect); exit; }

$r = $conn->prepare('SELECT resident_id FROM residents WHERE user_id=?');
$r->bind_param('i', $_SESSION['user_id']); $r->execute();
$rid = $r->get_result()->fetch_assoc()['resident_id'] ?? null;
if (!$rid) { flash('danger','Resident profile missing.'); header('Location: ' . $redirect); exit; }

$existing = $conn->prepare('SELECT vote_id, vote_value FROM votes WHERE resident_id=? AND product_id=? ORDER BY vote_id DESC LIMIT 1');
$existing->bind_param('ii', $rid, $pid);
$existing->execute();
$currentVote = $existing->get_result()->fetch_assoc();

if ($currentVote) {
    $cleanup = $conn->prepare('DELETE FROM votes WHERE resident_id=? AND product_id=? AND vote_id<>?');
    $cleanup->bind_param('iii', $rid, $pid, $currentVote['vote_id']);
    $cleanup->execute();

    if ($currentVote['vote_value'] === $vote) {
        flash('info', 'Your vote is already recorded for this offering.');
    } else {
        $upd = $conn->prepare('UPDATE votes SET vote_value=?, voted_at=CURRENT_TIMESTAMP WHERE vote_id=?');
        $upd->bind_param('si', $vote, $currentVote['vote_id']);
        $upd->execute();
        flash('success', 'Your vote was updated.');
    }
} else {
    $ins = $conn->prepare('INSERT INTO votes (resident_id, product_id, vote_value) VALUES (?,?,?)');
    $ins->bind_param('iis', $rid, $pid, $vote);
    $ins->execute();
    flash('success', 'Vote recorded — thank you!');
}

header('Location: ' . $redirect);
