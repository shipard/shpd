<?php

declare(strict_types=1);

namespace Shipard\Module\Base\Persons;

enum PersonType: int
{
    case Undefined = 0;
    case Person = 1;
    case Company = 2;
}
