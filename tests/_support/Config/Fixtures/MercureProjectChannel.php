<?php

declare(strict_types=1);

namespace Support\Tests\Config\Fixtures;

use Maniaba\CodeIgniterSse\Authorization\ChannelAuthorizationContext;
use Maniaba\CodeIgniterSse\Contracts\ChannelDefinitionInterface;

final class MercureProjectChannel implements ChannelDefinitionInterface
{
    public static function pattern(): string
    {
        return 'projects.{projectId}';
    }

    public function authorize(ChannelAuthorizationContext $context): bool
    {
        MercureChannelAuthorizationRecorder::record($context);

        $user = $context->user();

        if ($user === null) {
            return false;
        }

        $projectIds = $user->projectIds ?? [];

        return is_array($projectIds) && in_array($context->param('projectId'), array_map(strval(...), $projectIds), true);
    }
}
