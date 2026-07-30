<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Contracts;

interface ChannelAuthorizerInterface
{
    public function authorize(?object $user, string $channel): bool;
}
