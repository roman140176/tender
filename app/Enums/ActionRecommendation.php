<?php

namespace App\Enums;

enum ActionRecommendation: string
{
    case HandOver = 'hand_over';
    case ReviewDocs = 'review_docs';
    case Monitor = 'monitor';
    case Decline = 'decline';
}
