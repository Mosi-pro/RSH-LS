<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$pdo  = db();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (input('form_action') === 'mark_all_read') {
        $pdo->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE user_id = ? AND read_at IS NULL')
            ->execute([$user['id']]);
    }
    redirect('public/notifications.php');
}

// Direkter Klick auf eine Benachrichtigung: als gelesen markieren und weiterleiten.
$openId = input('open');
if ($openId !== '') {
    $stmt = $pdo->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
    $stmt->execute([(int)$openId, $user['id']]);
    $n = $stmt->fetch();
    if ($n) {
        $pdo->prepare('UPDATE notifications SET read_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$n['id']]);
        if ($n['url']) {
            redirect($n['url']);
        }
    }
    redirect('public/notifications.php');
}

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100');
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

$page_title = 'Benachrichtigungen';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="section-head">
    <h1>Benachrichtigungen</h1>
    <?php if (array_filter($notifications, fn($n) => !$n['read_at'])): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="form_action" value="mark_all_read">
        <button type="submit" class="btn btn-ghost btn-sm">Alle als gelesen markieren</button>
    </form>
    <?php endif; ?>
</div>

<?php if (!$notifications): ?>
    <div class="empty-state"><div class="es-icon">🔔</div>Keine Benachrichtigungen.</div>
<?php else: ?>
    <?php foreach ($notifications as $n): ?>
        <a class="notif-item <?= $n['read_at'] ? '' : 'unread' ?>" href="<?= url('public/notifications.php?open=' . (int)$n['id']) ?>">
            <strong><?= e($n['title']) ?></strong>
            <div><?= nl2br(e($n['message'])) ?></div>
            <div class="n-time"><?= format_datetime($n['created_at']) ?></div>
        </a>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
