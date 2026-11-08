<?php

declare(strict_types=1);

namespace AhmedNour\CustomReports\Enums;

/** What a role gets on a shared report folder. */
enum FolderAccess: string
{
    case VIEW = 'view';
    case EDIT = 'edit';
}
