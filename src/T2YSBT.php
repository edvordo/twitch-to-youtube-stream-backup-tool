<?php

namespace Edvordo\Twitch2YoutubeBackupTool;

use Bramus\Monolog\Formatter\ColoredLineFormatter;
use Edvordo\Twitch2YoutubeBackupTool\Logger\Handlers\DiscordHandler;
use Edvordo\Twitch2YoutubeBackupTool\Services\Twitch;
use Edvordo\Twitch2YoutubeBackupTool\Services\Youtube;
use Monolog\Handler\BufferHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\MemoryUsageProcessor;
use Psr\Log\LoggerInterface;

class T2YSBT
{
    const string LAST_VIDEO_ID = './last_video_id';
    const string VIDEO_IN_PROGRESS = './video_in_progress';

    private LoggerInterface $logger;

    private string $baseDir;

    private Twitch $twitch;

    private Youtube $youtube;

    public function __construct(string $baseDir)
    {
        $this->baseDir = $baseDir;
        $this->setupLogger();
        $this->setupServices();
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger): T2YSBT
    {
        $this->logger = $logger;

        return $this;
    }

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    public function setBaseDir(string $baseDir): T2YSBT
    {
        $this->baseDir = $baseDir;

        return $this;
    }

    private function setupLogger()
    {
        $logger = new Logger('t2ysbt');

        $memoryUsageProcessor     = new MemoryUsageProcessor();
        $memoryPeakUsageProcessor = new MemoryPeakUsageProcessor();

        $coloredLineFormatter = new ColoredLineFormatter();

        $streamHandler = new StreamHandler('php://output', Level::Debug);

        $streamHandler->setFormatter($coloredLineFormatter);

        $streamHandler->pushProcessor($memoryUsageProcessor);
        $streamHandler->pushProcessor($memoryPeakUsageProcessor);

        $logger->pushHandler($streamHandler);

        $rotatingFileHandler = new RotatingFileHandler('t2ysbt.log', 1, Level::Debug);

        $logger->pushHandler($rotatingFileHandler);

        if (true === array_key_exists('DISCORD_WEBHOOKS', $_SERVER) && false === empty($_SERVER['DISCORD_WEBHOOKS'])) {
            $webhooks = preg_split('/,/', $_SERVER['DISCORD_WEBHOOKS'], -1, PREG_SPLIT_NO_EMPTY);

            if (false === empty($webhooks)) {
                $discordHandler = new DiscordHandler();
                $discordHandler->setFormatter($coloredLineFormatter);
                $discordHandler->setWebhooks($webhooks);

                $logger->pushHandler(new BufferHandler($discordHandler, 0, Level::Info));
            }
        }

        /** @var \Monolog\Handler\FormattableHandlerTrait $handler */
        foreach ($logger->getHandlers() as $handler) {
            if (true === method_exists($handler, 'getFormatter')) {
                /** @var \Monolog\Formatter\LineFormatter $formatter */
                $formatter = $handler->getFormatter();
                if (true === method_exists($formatter, 'ignoreEmptyContextAndExtra')) {
                    $formatter->ignoreEmptyContextAndExtra();
                }
            }
        }

        $this->setLogger($logger);
    }

    private function setupServices()
    {
        $this->twitch = (new Twitch($this->getLogger()));
        $this->youtube = (new Youtube($this->getLogger()))
            ->setupAccessToken()
            ->setUpService();
    }

    public function process()
    {
        if (true === $this->videoInProgress()) {
            return $this;
        }

        $this
            ->getLastVideoId()
            ->processTwitch()
            ->processYoutube()
        ;
    }

    public function getLastVideoId()
    {
        $this->youtube->getLastVideoInPlaylist();

        return $this;
    }

    public function processTwitch()
    {
        $this->twitch
            ->extract()
            ->ytDlp()
            ->mailChatHistory()
        ;

        return $this;
    }

    public function processYoutube()
    {
        $this->youtube->processVideosFrom($this->getBaseDir(), $this->twitch->getStream());

        return $this;
    }

    private function videoInProgress()
    {
        return true === file_exists(self::VIDEO_IN_PROGRESS);
    }
}
