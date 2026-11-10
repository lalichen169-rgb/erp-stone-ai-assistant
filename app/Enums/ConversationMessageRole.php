<?php

namespace App\Enums;

enum ConversationMessageRole: string
{
    case System = 'system';

    case User = 'user';

    case Assistant = 'assistant';

    case Tool = 'tool';
}
