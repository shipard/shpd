<?php
/**
 * Opis podání (#55 X7): hlavička a hodnoty vět tak, jak jdou do XML.
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

<table class="header">
    <?php foreach ($model['header'] as $item): ?>
        <tr>
            <td class="label"><?= $e($item['label']) ?></td>
            <td><?= $e($item['value']) ?></td>
        </tr>
    <?php endforeach; ?>
</table>

<?php foreach ($model['tables'] as $table): ?>
    <table class="rows">
        <caption><?= $e($table['title']) ?></caption>
        <tr>
            <?php foreach ($table['columns'] as $index => $column): ?>
                <th<?= $index >= 2 ? ' class="num"' : '' ?>><?= $e($column) ?></th>
            <?php endforeach; ?>
        </tr>
        <?php foreach ($table['rows'] as $row): ?>
            <tr<?= $row['total'] ? ' class="total"' : '' ?>>
                <td class="no"><?= $e($row['no']) ?></td>
                <td><?= $e($row['label']) ?></td>
                <?php foreach ($row['cells'] as $cell): ?>
                    <td class="num"><?= $e($cell) ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        <?php if ($table['rows'] === []): ?>
            <tr><td colspan="5" class="muted">Bez hodnot.</td></tr>
        <?php endif; ?>
    </table>
<?php endforeach; ?>
</body>
</html>
