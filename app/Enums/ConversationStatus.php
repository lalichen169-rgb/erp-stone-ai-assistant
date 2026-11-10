<?php

namespace App\Enums;

enum ConversationStatus: string
{
    case Active = 'active';

    case Archived = 'archived';

    case Closed = 'closed';
}
