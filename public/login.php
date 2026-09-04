<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect(home_path());
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $employeeId = preg_replace('/\D/', '', input('employee_id'));

    if ($employeeId === '') {
        $error = 'Bitte Mitarbeiter-ID eingeben.';
    } elseif (attempt_login($employeeId)) {
        redirect(home_path());
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
            <label for="employee_id">Mitarbeiter-ID</label>
            <input type="text" inputmode="numeric" pattern="[0-9]*" id="employee_id" name="employee_id"
                   maxlength="10" autocomplete="off" data-autofocus required>
            <button type="submit" class="btn btn-primary btn-block btn-lg">ANMELDEN</button>
        </form>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
