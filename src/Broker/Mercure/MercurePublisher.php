<?php

declare(strict_types=1);

namespace Maniaba\CodeIgniterSse\Broker\Mercure;

use Maniaba\CodeIgniterSse\Broker\Mercure\Exception\MercurePublishException;
use Maniaba\CodeIgniterSse\Contracts\EventInterface;
use Maniaba\CodeIgniterSse\Contracts\PublisherInterface;
use Maniaba\CodeIgniterSse\Contracts\SerializerInterface;
use Throwable;

final class MercurePublisher implements PublisherInterface
{
    private MercureHttpClientInterface $http;
    private MercureJwtFactory $tokens;
    private MercureTopicMapper $topics;

    public function __construct(
        private readonly MercureConfig $config,
        private readonly SerializerInterface $serializer,
        ?MercureHttpClientInterface $http = null,
        ?MercureJwtFactory $tokens = null,
        ?MercureTopicMapper $topics = null,
    ) {
        $this->http   = $http ?? new CodeIgniterMercureHttpClient();
        $this->tokens = $tokens ?? new MercureJwtFactory();
        $this->topics = $topics ?? new MercureTopicMapper($config->topicPrefix);
    }

    public function publish(string $channel, EventInterface $event): void
    {
        $this->assertSafeEventFields($event);
        $payload = $this->serializer->serialize($channel, $event);

        if (strlen($payload) > $this->config->maxPayloadBytes) {
            throw new MercurePublishException(
                sprintf(
                    'The serialized SSE event exceeds the Mercure payload limit of %d bytes.',
                    $this->config->maxPayloadBytes,
                ),
            );
        }

        $form = [
            'topic' => $this->topics->map($channel),
            'data'  => $payload,
            'id'    => $event->id(),
            'type'  => $event->name(),
            'retry' => $this->config->retryMilliseconds,
        ];

        if ($this->config->privateUpdates) {
            $form['private'] = 'on';
        }

        try {
            $response = $this->http->post(
                $this->config->hubUrl,
                [
                    'Authorization' => 'Bearer ' . $this->config->publisherToken($this->tokens),
                    'Accept'        => 'text/plain',
                ],
                $form,
                $this->config->connectTimeout,
                $this->config->timeout,
                $this->config->verifyTls,
            );
        } catch (Throwable $exception) {
            throw new MercurePublishException(
                'Unable to publish the SSE event to the Mercure Hub.',
                0,
                $exception,
            );
        }

        if (! $response->isSuccessful()) {
            $details = trim(preg_replace('/\s+/', ' ', $response->body) ?? '');

            if ($details !== '') {
                $details = ': ' . substr($details, 0, 500);
            }

            throw new MercurePublishException(
                sprintf(
                    'The Mercure Hub rejected the SSE event with HTTP %d%s',
                    $response->statusCode,
                    $details,
                ),
            );
        }
    }

    private function assertSafeEventFields(EventInterface $event): void
    {
        if (
            $event->id() === ''
            || str_starts_with($event->id(), '#')
            || strpbrk($event->id(), "\r\n\0") !== false
        ) {
            throw new MercurePublishException(
                'Mercure event IDs must be non-empty, must not start with #, and must not contain control characters.',
            );
        }

        if ($event->name() === '' || strpbrk($event->name(), "\r\n\0") !== false) {
            throw new MercurePublishException(
                'Mercure event names must be non-empty and must not contain control characters.',
            );
        }
    }
}
