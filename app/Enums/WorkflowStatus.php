<?php

namespace App\Enums;

enum WorkflowStatus: string
{
    case New = 'new';
    case Interesting = 'interesting';
    case ReviewDocs = 'review_docs';
    case HandedOver = 'handed_over';
    case InProgress = 'in_progress';
    case Declined = 'declined';
    case Irrelevant = 'irrelevant';
    case Archived = 'archived';
    case Participating = 'participating';
    case NotParticipating = 'not_participating';
    case Won = 'won';
    case Lost = 'lost';
}
