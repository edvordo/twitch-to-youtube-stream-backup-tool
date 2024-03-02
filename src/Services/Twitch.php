<?php

namespace Edvordo\Twitch2YoutubeBackupTool\Services;

use Edvordo\Twitch2YoutubeBackupTool\T2YSBT;
use Exception;
use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

class Twitch
{
    private ?array $streamToDownload = null;

    private bool $isLive = false;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function isLive(): bool
    {
        return $this->isLive;
    }

    public function setIsLive(bool $isLive): Twitch
    {
        $this->isLive = $isLive;

        return $this;
    }

    public function extract(): Twitch
    {
        $httpClient = new Client();
        $clientId   = $_SERVER['TWITCH_CLIENT_ID'];

        $response = $httpClient->post('https://id.twitch.tv/oauth2/token?' . http_build_query([
                'client_id'     => $clientId,
                'client_secret' => $_SERVER['TWITCH_SECRET'],
                'grant_type'    => 'client_credentials',
            ]));

        $responseData = json_decode($response->getBody()->getContents(), true);

        $response = $httpClient->get('https://api.twitch.tv/helix/streams?' . http_build_query([
                'user_login' => $_SERVER['TWITCH_CHANNEL_NAME'],
            ]), [
            'headers' => [
                'client-id'     => $clientId,
                'Authorization' => 'Bearer ' . $responseData['access_token'],
            ]
        ]);

        $streams = json_decode($response->getBody()->getContents(), true);
        if (false === empty($streams['data'])) {
            return $this->setIsLive(true);
        }

        $response = $httpClient->get('https://api.twitch.tv/helix/users?' . http_build_query([
                'login' => $_SERVER['TWITCH_CHANNEL_NAME'],
            ]), [
            'headers' => [
                'client-id'     => $clientId,
                'Authorization' => 'Bearer ' . $responseData['access_token'],
            ]
        ]);

        $users = json_decode($response->getBody()->getContents(), true);

        $user = $users['data'][0];

        $response = $httpClient->get('https://api.twitch.tv/helix/videos?' . http_build_query([
                'user_id' => $user['id'],
                'type'    => 'archive',
            ]), [
            'headers' => [
                'client-id'     => $clientId,
                'Authorization' => 'Bearer ' . $responseData['access_token'],
            ]
        ]);

        $json = json_decode($response->getBody()->getContents(), true);

        $streams = $json['data'];

        $streams = array_map(fn ($info) => [...$info, 'id' => (int) $info['id']], $streams);
        $streams = array_reverse($streams);

        $this->streamToDownload = null;
        $lastStreamId             = (int) file_get_contents('./last_video_id');

        $allIdsAreHigherOrEqual   = false;

        foreach ($streams as $stream) {
            if ($stream['id'] > $lastStreamId) {
                $this->streamToDownload = $stream;
                break;
            }
            $allIdsAreHigherOrEqual = $allIdsAreHigherOrEqual || $lastStreamId <= $stream['id'];
        }

        if (false === $allIdsAreHigherOrEqual && true === is_null($this->streamToDownload)) {
            $this->streamToDownload = $streams[0];
        }

        return $this;
    }

    /**
     * @throws \Exception
     */
    public function ytDlp(): Twitch
    {
        if (true === $this->isLive()) {
            $this->getLogger()->debug('SKIPPING download - currently live');

            return $this;
        }

        if (true === is_null($this->getStreamIdToDownload())) {
            $this->getLogger()->debug('SKIPPING download - nothing to download');

            return $this;
        }

        touch(T2YSBT::VIDEO_IN_PROGRESS);
        file_put_contents(T2YSBT::VIDEO_IN_PROGRESS, $this->getStreamIdToDownload());

        $this->getLogger()->info('Starting download of ' . $this->getStreamToDownloadUrl());

        $process = new Process(['./git-yt-dlp/yt-dlp.sh', '-v', '--write-subs', '--sub-langs', 'live_chat', $this->getStreamToDownloadUrl()]);
        $process->setTimeout(0)->start();

        foreach ($process as $type => $output) {
            if ($process::ERR === $type) {
                if (true === str_contains($output, 'Unable to download JSON metadata: HTTP Error 403')) {
                    $this->getLogger()->warning('SKIPPING download - access to chat history is restricted');
                    $process->stop(0, SIGKILL);

                    break;
                }

                if (false === str_starts_with($output, '[debug]')) {
                    $this->getLogger()->error($output);

                    throw new Exception($output);
                }

                $this->getLogger()->debug($output);
            } else {
                echo $output;
            }

        }

        // handle potentially still running process
        while (true) {
            if (false === $process->isRunning()) {
                break;
            }
            sleep(1);
        }

        if (false === $process->isSuccessful()) {
            throw new \RuntimeException('Failed downloading stream via yt-dlp');
        }
        $this->getLogger()->info('Done ..');

        return $this;
    }

    public function getStreamIdToDownload(): ?int
    {
        return $this->streamToDownload['id'];
    }

    public function getStreamToDownloadUrl()
    {
        return 'https://twitch.tv/videos/' . $this->getStreamIdToDownload();
    }

    public function mailChatHistory(): Twitch
    {
        if (true === $this->isLive()) {
            $this->getLogger()->debug('SKIPPING download - currently live');

            return $this;
        }

        if (true === is_null($this->getStreamIdToDownload())) {
            $this->getLogger()->debug('SKIPPING download - nothing to download');

            return $this;
        }

        $this->getLogger()->info('Mailing chat history ..');
        $transport = Transport::fromDsn(
            sprintf(
                '%s://%s:%s@%s:%d',
                $_SERVER['MAIL_PROTOCOL'],
                urlencode($_SERVER['MAIL_USERNAME']),
                urlencode($_SERVER['MAIL_PASSWORD']),
                urlencode($_SERVER['MAIL_HOST']),
                $_SERVER['MAIL_PORT'],
            )
        );

        $mailer = new Mailer($transport);

        $file = `ls *.json | grep {$this->getStreamIdToDownload()}`;

        if (true === empty($file)) {
            return $this;
        }

        $file = trim($file);

        $email = (new Email())
            ->from($_SERVER['MAIL_FROM'])
            ->to($_SERVER['MAIL_TO'])
            ->subject($this->getStreamIdToDownload() . ' chat')
            ->attachFromPath('./' . $file);

        try {
            $mailer->send($email);
            $this->getLogger()->info('done ..');
            unlink('./' . $file);
        } catch (Exception $_) {
            // well, idk .. but log it ..
            $this->getLogger()->error('Failed to send chat history ..' . PHP_EOL . $_->getMessage());
        }

        return $this;
    }

    public function getStream()
    {
        return $this->streamToDownload;
    }
}
