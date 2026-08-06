<?php

declare(strict_types=1);

namespace Shipard\Module\Hosting\Core;

use Shipard\Core\Form\FormDefinition;
use Shipard\Core\Form\TableForm;

/**
 * Form pro hosting_core_ai_tokens — jen metadata (poznámka) a deaktivace.
 * Token samotný (prefix/hash/encrypted) vydává CLI `hosting-ai-token`
 * nebo lazy mint v queue payloadu; formem se nikdy nemění.
 */
class AiTokensForm extends TableForm
{
    public function buildFormDefinition(array $data, bool $isNew): FormDefinition
    {
        $basic = $this->tab('basic', 'Základní údaje')
            ->section()
                ->col()
                    ->checkbox('active')
                    ->input('note')
            ->build();

        return new FormDefinition(
            table: $this->table,
            title: 'AI gateway token',
            titleNew: 'AI gateway token',
            tabs: [$basic],
        );
    }
}
