<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Contracts\ChannelAuthorizerInterface;

final class MercureUserChannelAuthorizer implements ChannelAuthorizerInterface
{
    /**
     * @var list<array{user: object|null, channel: string}>
     */
    public static array $attempts = [];

    public function authorize(?object $user, string $channel): bool
    {
        self::$attempts[] = [
            'user'    => $user,
            'channel' => $channel,
        ];

        if (str_starts_with($channel, 'public.')) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if (preg_match('/^users\.([A-Za-z0-9_-]+)$/D', $channel, $matches) === 1) {
            return (string) ($user->id ?? '') === $matches[1];
        }

        if (preg_match('/^tenants\.([A-Za-z0-9_-]+)$/D', $channel, $matches) === 1) {
            $tenantIds = $user->tenantIds ?? [];

            return is_array($tenantIds) && in_array($matches[1], array_map(strval(...), $tenantIds), true);
        }

        if (preg_match('/^projects\.([A-Za-z0-9_-]+)$/D', $channel, $matches) === 1) {
            $projectIds = $user->projectIds ?? [];

            return is_array($projectIds) && in_array($matches[1], array_map(strval(...), $projectIds), true);
        }

        if (str_starts_with($channel, 'admin.')) {
            return ($user->role ?? null) === 'admin';
        }

        return false;
    }
}
