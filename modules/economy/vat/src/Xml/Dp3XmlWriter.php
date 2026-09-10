<?php

declare(strict_types=1);

namespace Shipard\Module\Economy\Vat\Xml;

/**
 * Generátor XML přiznání k DPH (DPHDP3) ze snapshotu podání
 * (issue #55, X1/X4).
 *
 * Věty 1–6 vzniknou z **podaných** hodnot řádků (`_filed` sloupce, tedy po
 * řádkovém zaokrouhlení dle D17) podle mapování v configu — writer nezná
 * ani jedno číslo řádku. Nulový atribut se vynechává; součtové řádky
 * z `alwaysEmit` se vypisují i s nulou.
 *
 * Věta R nese textovou přílohu „Důvody pro podání dodatečného daňového
 * přiznání" — poznámku podání zalomenou na délku, kterou dovolí schéma.
 */
final class Dp3XmlWriter extends EpoXmlWriter
{
    protected function appendBody(\DOMElement $document, FilingXmlInput $input): void
    {
        $this->appendRows($document, $input);
        $this->appendNote($document, $input);
    }

    /**
     * Věty 1–6: atributy se sesypou per věta, aby každá vznikla jednou
     * a v pořadí, které žádá `xs:sequence` schématu.
     */
    private function appendRows(\DOMElement $document, FilingXmlInput $input): void
    {
        $alwaysEmit = $this->mapping->alwaysEmit();
        $sentences  = [];

        foreach ($this->mapping->rows() as $row => $definition) {
            $row      = (int) $row;
            $sentence = (string) $definition['veta'];
            $keepZero = in_array($row, $alwaysEmit, true);

            $values = [];
            foreach (['base', 'full', 'reduced'] as $slot) {
                $attribute = $definition[$slot] ?? null;
                if ($attribute === null) {
                    continue;
                }
                $value = $this->money($input->rowValue($row, $slot), $keepZero);
                if ($value !== null) {
                    $values[(string) $attribute] = $value;
                }
            }

            // Koeficient patří k řádku: prázdný řádek 52 (plný nárok bez
            // krácení) ho nevypisuje, jinak by přiznání tvrdilo krácení,
            // které se nekoná.
            $percent = $values !== [] ? $this->percent($definition, $input) : null;
            if ($percent !== null) {
                $values[(string) $definition['percent']['attr']] = $percent;
            }

            foreach ($values as $attribute => $value) {
                $sentences[$sentence][$attribute] = $value;
            }
        }

        foreach ($sentences as $sentence => $attributes) {
            $this->appendSentence($document, $sentence, $attributes);
        }
    }

    /**
     * Koeficient v procentech (ř. 52 zálohový, ř. 53 vypořádací). Ve
     * snapshotu je uložený jako podíl ⟨0; 1⟩, formulář ho chce v procentech
     * na dvě desetinná místa. Nulový koeficient se nevypisuje — plný nárok
     * krácený odpočet nemá.
     *
     * @param array<string, mixed> $definition
     */
    private function percent(array $definition, FilingXmlInput $input): ?string
    {
        $percent = $definition['percent'] ?? null;
        if ($percent === null) {
            return null;
        }
        $value = $input->coefficients[(string) $percent['source']] ?? null;
        if ($value === null) {
            return null;
        }
        return EpoXmlFormat::money((float) $value * 100, $this->mapping->percentScale());
    }

    /** Věta R — textová příloha; jen u druhů podání, které ji znají. */
    private function appendNote(\DOMElement $document, FilingXmlInput $input): void
    {
        $note = $this->mapping->note();
        if ($note === null
            || $input->note === null
            || !in_array($input->filingKind, $note['kinds'] ?? [], true)
        ) {
            return;
        }

        $order = 0;
        foreach (EpoXmlFormat::wrap($input->note, (int) $note['lineLength']) as $line) {
            $this->appendSentence($document, (string) $note['veta'], [
                (string) $note['sectionAttr'] => (string) $note['section'],
                (string) $note['orderAttr']   => (string) ++$order,
                (string) $note['textAttr']    => $line,
            ]);
        }
    }
}
