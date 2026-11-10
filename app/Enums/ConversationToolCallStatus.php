<?php

namespace App\Enums;

enum ConversationToolCallStatus: string
{
    case Pending = 'pending';

    case Completed = 'completed';

    case Failed = 'failed';
}
