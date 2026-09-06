<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /**
     * Every dashboard controller authorises through a policy. Permissions are
     * checked by name, never by comparing role strings.
     */
    use AuthorizesRequests;
}
