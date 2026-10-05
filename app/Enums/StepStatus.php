<?php

namespace App\Enums;

enum StepStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
