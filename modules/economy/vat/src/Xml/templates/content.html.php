<?php
/**
 * Obsah podání (#55 X7): doklady, ze kterých se hodnoty poskládaly.
 * Odpověď na otázku „z čeho je ten řádek" — XML dokladovou úroveň nenese.
 *
 * @var array<string, mixed> $model
 * @var callable $e
 */
?><!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <title><?= $e($model['title']) ?></title>
    <?php require __DIR__ . '/_style.html.php'; ?>
</head>
<body style="padding: 14mm 12mm;">
<h1><?= $e($model['title']) ?></h1>
<p class="subtitle"><?= $e($model['subtitle']) ?></p>

<?php foreach ($model['groups'] as $group): ?>
    <table class="rows">
        <caption><?= $e($group['title']) ?></caption>
        <tr>
            <th>Doklad</th>
            <th>DIČ protistrany</th>
            <th>Datum</th>
            <th>Kód DPH</th>
            <th class="num">Základ</th>
            <th class="num">Daň</th>
        </tr>
        <?php foreach ($group['rows'] as $row): ?>
            <tr>
                <td><?= $e($row['number']) ?></td>
                <td><?= $e($row['partner']) ?></td>
                <td class="no"><?= $e($row['date']) ?></td>
                <td class="no"><?= $e($row['code']) ?></td>
                <td class="num"><?= $e($row['base']) ?></td>
                <td class="num"><?= $e($row['tax']) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="total">
            <td colspan="4">Celkem (<?= count($group['rows']) ?>)</td>
            <td class="num"><?= $e($group['baseTotal']) ?></td>
            <td class="num"><?= $e($group['taxTotal']) ?></td>
        </tr>
    </table>
<?php endforeach; ?>

<?php if ($model['groups'] === []): ?>
    <p class="muted">Podání nemá žádné doklady.</p>
<?php endif; ?>
</body>
</html>
