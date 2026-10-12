<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect(home_path());
}

$error = '';
$return = input('return');
$safeReturn = $return !== '' && preg_match('#^[a-zA-Z0-9_\-./]+(\?[a-zA-Z0-9_\-.=&%]*)?$#', $return) && strpos($return, '//') === false
    ? $return : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $employeeId = preg_replace('/\D/', '', input('employee_id'));

    if ($employeeId === '') {
        $error = 'Bitte Mitarbeiter-ID eingeben.';
    } elseif (attempt_login($employeeId)) {
        redirect($safeReturn !== '' ? $safeReturn : home_path());
    } else {
        $error = 'Unbekannte oder inaktive Mitarbeiter-ID.';
    }
}

$page_title = 'Anmelden';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="login-wrap">
    <div class="login-card">
        <div class="login-title">RSH TECHNIK</div>
        <div class="login-sub">LAGERSYSTEM</div>

        <?php if ($error): ?>
            <div class="flash flash-error"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= url('public/login.php') ?>">
            <?= csrf_field() ?>
            <?php if ($safeReturn !== ''): ?><input type="hidden" name="return" value="<?= e($safeReturn) ?>"><?php endif; ?>
            <label for="employee_id">Mitarbeiter-ID</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" id="employee_id" name="employee_id"
                   maxlength="10" autocomplete="off" data-autofocus required>
            <button type="submit" class="btn btn-primary btn-block btn-lg">ANMELDEN</button>
        </form>

        <div class="login-alt">
            <a href="<?= url('modules/anfragen/neu.php') ?>">Veranstaltung anfragen →</a>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
