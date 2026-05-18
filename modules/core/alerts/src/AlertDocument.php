<?php

declare(strict_types=1);

namespace Shipard\Module\Core\Alerts;

use Shipard\Core\Document\DefaultDocument;

/**
 * Alerty se nevytvářejí ani needitují přes klasický Document mechanismus —
 * vznikají v `AlertReconciler` a mění se přes dedikované API endpointy
 * (`/_alerts/alerts/{id}/snooze`, `/dismiss`, `/unsnooze`). Tahle třída je
 * tu jen aby DocumentRegistry měl pro tabulku `core_alerts_alerts` co vrátit
 * (a aby CrudController fungoval pro GET listy/detail).
 *
 * Žádné hooky nepřepisujeme — `validate()`/`beforeSave()` z `DefaultDocument`
 * stačí a nikdy se v praxi nevolají (alerty se přes Form/Crud POST/PUT
 * vytvářet nebudou).
 */
class AlertDocument extends DefaultDocument
{
}
