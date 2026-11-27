<?php

declare(strict_types=1);

namespace App\Services\Realtime;

use RuntimeException;

/** The broker could not be reached, refused the backend, or did not answer. */
class BrokerUnavailable extends RuntimeException {}
