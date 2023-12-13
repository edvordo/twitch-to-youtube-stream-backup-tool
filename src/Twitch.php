<?php

namespace Edvordo\Twitch2YoutubeBackupTool;

use Exception;
use GuzzleHttp\Client;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

class Twitch
{
    private ?int $streamIdToDownload = null;

    private bool $isLive = false;

    public function isLive(): bool
    {
        return $this->isLive;
    }

    public function setIsLive(bool $isLive): Twitch
    {
        $this->isLive = $isLive;

        return $this;
    }

    public function extract()
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

        $streamIds = array_map(fn($stream) => (int) $stream['id'], $streams);
        $streamIds = array_reverse($streamIds);

        $this->streamIdToDownload = null;
        $lastStreamId             = (int) $_SERVER['LAST_VIDEO_ID'];
        $allIdsAreHigherOrEqual = false;

        foreach ($streamIds as $streamId) {
            if ($streamId > $lastStreamId) {
                $this->streamIdToDownload = $streamId;
                break;
            }
            $allIdsAreHigherOrEqual = $allIdsAreHigherOrEqual || $lastStreamId <= $streamId;
        }

        if (false === $allIdsAreHigherOrEqual && true === is_null($this->streamIdToDownload)) {
            $this->streamIdToDownload = $streamIds[0];
        }

        return $this;
    }

    public function ytDlp()
    {
        if (true === $this->isLive()) {
            echo 'SKIPPING download - currently live' . PHP_EOL;

            return $this;
        }
        if (true === is_null($this->getStreamIdToDownload())) {
            echo 'SKIPPING download - nothing to download' . PHP_EOL;

            return $this;
        }
        $process = new Process(['./git-yt-dlp/yt-dlp.sh', '-vU', '--write-subs', '--sub-langs', 'live_chat', $this->getStreamToDownloadUrl()]);
        $process->setTimeout(0)->start();

        foreach ($process as $type => $output) {
            if ($process::OUT === $type) {
                echo chr(27) . '[0G' . $output;
            } else {
                echo $output;
            }
        }

        if (false === $process->isSuccessful()) {
            throw new \RuntimeException('Failed downloading stream via yt-dlp');
        }

        return $this;
    }

    public function getStreamIdToDownload(): ?int
    {
        return $this->streamIdToDownload;
    }

    public function getStreamToDownloadUrl()
    {
        return 'https://twitch.tv/videos/' . $this->getStreamIdToDownload();
    }

    public function mailChatHistory(): Twitch
    {
        if (true === $this->isLive()) {
            echo 'SKIPPING download - currently live' . PHP_EOL;

            return $this;
        }
        if (true === is_null($this->getStreamIdToDownload())) {
            echo 'SKIPPING download - nothing to download' . PHP_EOL;

            return $this;
        }
        echo 'Mailing chat history ..' . PHP_EOL;
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

        $file  = `ls *.json | grep {$this->getStreamIdToDownload()}`;

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
            echo 'done ..' . PHP_EOL;
            unlink('./' . $file);
        } catch (Exception $_) {
            echo 'Failed to send chat history ..' . PHP_EOL . $_->getMessage() . PHP_EOL;
            // well, idk ..
        }

        return $this;
    }
}
