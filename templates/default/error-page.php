<html>
<head><title><?= $code ?> <?= htmlspecialchars($message) ?></title></head>
<body>
<center><h1><?= $code ?> <?= htmlspecialchars($message) ?></h1></center>
<hr><center>nginx</center>
</body>
</html>
<?php if ($debug !== null) include __DIR__ . '/debug-panel.php'; ?>
