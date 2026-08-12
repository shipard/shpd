<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Codebooks;

use Shipard\Core\Database\DataSourceConnection;
use Shipard\Core\Navigation\NavItemVisibilityGate;
use Shipard\Core\Settings\SettingsStore;

/**
 * Viditelnost agendy DPH v Nastavení (Registrace DPH, Období DPH) —
 * ds-setup.md D11: skrývá se JEN když je DS vědomě neplátce
 * (`economy.vatAgenda === false`) a zároveň nikdy neexistovala žádná
 * registrace. COUNT je záměrně bez WHERE na docState — jde o „nikdy
 * neexistovala", ne „není aktivní"; bývalý plátce s ukončenou (i smazanou)
 * registrací agendu vidí dál.
 *
 * Nerozhodnutý klíč (null) = zobrazit — neúplné nastavení nesmí schovávat
 * funkčnost.
 */
final class VatAgendaNavGate implements NavItemVisibilityGate
{
    public function isVisible(DataSourceConnection $db): bool
    {
        if ((new SettingsStore($db))->get('economy.vatAgenda') !== false) {
            return true;
        }

        $count = $db->fetchSingle('SELECT COUNT(*) FROM `economy_codebooks_vat_registrations`');
        return (int) $count > 0;
    }
}
