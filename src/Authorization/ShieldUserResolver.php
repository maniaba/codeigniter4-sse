<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Authorization;

use LogicException;
use Maniaba\CodeIgniterSse\Contracts\UserResolverInterface;
use Throwable;

final class ShieldUserResolver implements UserResolverInterface
{
    public function resolve(): ?object
    {
        $this->loadAuthHelper();

        if (! function_exists('auth')) {
            throw new LogicException(
                'CodeIgniter Shield auth helper is not available. Install codeigniter4/shield or configure another SSE user resolver.',
            );
        }

        $auth = auth();

        if (! method_exists($auth, 'user')) {
            throw new LogicException('CodeIgniter Shield auth() must return an object with a user() method.');
        }

        $user = $auth->user();

        return is_object($user) ? $user : null;
    }

    private function loadAuthHelper(): void
    {
        if (function_exists('auth') || ! function_exists('helper')) {
            return;
        }

        try {
            helper('auth');
        } catch (Throwable) {
            // Missing Shield is reported with a package-specific exception above.
        }
    }
}
