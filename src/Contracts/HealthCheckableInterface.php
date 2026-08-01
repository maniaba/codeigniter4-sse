<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

use Maniaba\CodeIgniterSse\Health\HealthCheckResult;

interface HealthCheckableInterface
{
    public function healthCheck(): HealthCheckResult;
}
