<?php
/**
 * Sdílený vzhled tiskových výstupů podání (#55 X7). Bez externích
 * assetů — Gotenberg dostane jedno HTML a nic víc (síť Chromia je
 * v render službě zakázaná).
 *
 * @var callable $e
 */
?>
<style>
    @page { size: A4; margin: 0; }
    body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 9.5pt; color: #1a1a1a; margin: 0; }
    h1 { font-size: 15pt; margin: 0 0 2mm; }
    .subtitle { color: #555; margin: 0 0 6mm; font-size: 10pt; }
    .header { width: 100%; border-collapse: collapse; margin-bottom: 7mm; }
    .header td { padding: 0.8mm 0; vertical-align: top; }
    .header .label { color: #555; width: 34mm; }
    table.rows { width: 100%; border-collapse: collapse; margin-bottom: 6mm; }
    table.rows caption { text-align: left; font-weight: bold; padding: 2mm 0 1.5mm; font-size: 10pt; }
    table.rows th { text-align: left; font-weight: normal; color: #555; border-bottom: 0.4mm solid #999;
                    padding: 1.2mm 2mm 1.2mm 0; }
    table.rows td { padding: 1.2mm 2mm 1.2mm 0; border-bottom: 0.2mm solid #ddd; vertical-align: top; }
    table.rows tr.total td { font-weight: bold; background: #f2f2f2; }
    .num { text-align: right; white-space: nowrap; }
    .no { white-space: nowrap; color: #555; }
    .muted { color: #777; }
</style>
