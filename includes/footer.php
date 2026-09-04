<?php
if (!defined('RSH_APP')) {
    http_response_code(403);
    exit('Direktzugriff nicht erlaubt.');
}
$user       = current_user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$isTerminal  = isset($_GET['terminal']) || strpos($currentPath, '/terminal/') !== false;
?>
<?php if ($user && !$isTerminal): ?>
        </main>
    </div>
</div>
<nav class="bottom-nav">
    <a href="<?= url('public/dashboard.php') ?>" class="bn-item">⌂<span>Home</span></a>
    <a href="<?= url('modules/lager/index.php') ?>" class="bn-item">▣<span>Lager</span></a>
    <a href="<?= url('terminal/index.php') ?>" class="bn-item bn-action">+<span>Aktion</span></a>
    <a href="<?= url('terminal/index.php') ?>" class="bn-item">⇄<span>Ausgabe</span></a>
    <a href="<?= url('modules/auftraege/index.php') ?>" class="bn-item">☰<span>Menü</span></a>
</nav>
<?php else: ?>
    </main>
<?php endif; ?>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>
