<?php

namespace Edvordo\Twitch2YoutubeBackupTool\Logger\Handlers;

use GuzzleHttp\Client;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class DiscordHandler extends AbstractProcessingHandler
{
    private Client $client;

    private array $webhooks = [];

    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true) {
        parent::__construct($level, $bubble);

        $this->client = new Client();
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function setClient(Client $client): DiscordHandler
    {
        $this->client = $client;

        return $this;
    }

    public function getWebhooks(): array
    {
        return $this->webhooks;
    }

    public function setWebhooks(array $webhooks): DiscordHandler
    {
        $this->webhooks = $webhooks;

        return $this;
    }

    protected function write(LogRecord $record): void
    {
        $this->send($record->formatted);
    }

    public function handleBatch(array $records): void
    {
        $this->send((string) $this->getFormatter()->formatBatch($records));
    }

    private function send(string $messages)
    {
        foreach ($this->getWebhooks() as $webhook) {
            foreach (str_split($messages, 1988) as $part) {
                $this->getClient()
                    ->post($webhook, [
                        'json' => [
                            'content' => sprintf('```ansi
%s
```', $part),
                        ]
                    ]);
            }
        }
    }
}
