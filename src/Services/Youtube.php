<?php

namespace Edvordo\Twitch2YoutubeBackupTool\Services;

use Edvordo\Twitch2YoutubeBackupTool\T2YSBT;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\YouTube as YoutubeService;
use Google\Service\YouTube\PlaylistItem;
use Google\Service\YouTube\PlaylistItemSnippet;
use Google\Service\YouTube\ResourceId;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Psr\Log\LoggerInterface;

class Youtube
{
    private ?Client $client = null;

    private ?YoutubeService $service = null;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;

        $this->setUpClient();
    }

    private function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * @throws \Google\Exception
     */
    private function setUpClient(): void
    {
        $ytClient = new Client();
        $ytClient->setAuthConfig(json_decode($_SERVER['GOOGLE_APPLICATION_CREDENTIALS'], true));
        $ytClient->setApplicationName('flow-stream-upload');
        $ytClient->addScope(YouTubeService::YOUTUBE);

        $ytClient->setRedirectUri('http://localhost');

        $this->client = $ytClient;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function getRefreshToken(): string
    {
        $this->getClient()->setAccessType('offline');

        // Request authorization from the user.
        $authUrl = $this->getClient()->createAuthUrl();
        printf("Open this link in your browser:\n%s\n", $authUrl);
        print('Enter verification code: ');
        $authCode = trim(fgets(STDIN));

        $this->getClient()->fetchAccessTokenWithAuthCode($authCode);
        $refreshToken = $this->getClient()->getRefreshToken();
        print_r($refreshToken);

        return $refreshToken;
    }

    public function setupAccessToken(): static
    {
        $accessToken = $this->getClient()->fetchAccessTokenWithRefreshToken($_SERVER['YOUTUBE_REFRESH_TOKEN']);

        $this->getClient()->setAccessToken($accessToken);

        return $this;
    }

    public function setUpService()
    {
        $this->service = new YouTubeService($this->getClient());

        return $this;
    }

    public function getService(): ?YoutubeService
    {
        return $this->service;
    }

    public function getLastVideoInPlaylist()
    {
        $pullMore = true;
        $pageToken = false;
        while (true === $pullMore) {
            $params = [
                'maxResults' => 50,
                'playlistId' => $_SERVER['YOUTUBE_PLAYLIST_ID']
            ];
            if (false !== $pageToken) {
                $params['pageToken'] = $pageToken;
            }
            $response  = $this->getService()
                ->playlistItems
                ->listPlaylistItems(
                    'snippet',
                    $params
                );

            $pageToken = $response->getNextPageToken();

            $pullMore = false === is_null($pageToken);
        }

        $items = $response->getItems();
        /** @var PlaylistItem $lastItem */
        $lastItem = array_pop($items);

        $title = $lastItem->getSnippet()->getTitle();

        $videoId = preg_replace('/[^0-9]+/', '', $title);

        touch(T2YSBT::LAST_VIDEO_ID);
        file_put_contents(T2YSBT::LAST_VIDEO_ID, $videoId);
    }

    /**
     * @throws \Exception
     */
    public function processVideosFrom(string $directory, array $streamInfo)
    {
        ini_set('memory_limit', '256M');
        $files = `ls -Ahrt "{$directory}" | grep -E "\[v[0-9]+\\]\.mp4"`;

        if (true === empty($files)) {
            $this->getLogger()->debug('SKIPPING upload - no files to upload detected');

            return $this;
        }

        $videos = preg_split('/\n/', $files, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($videos as $videoToUpload) {
            $this->getClient()->setDefer(true);
            preg_match('/(\[v[0-9]+])/', $videoToUpload, $videoTitleMatches);
            preg_match('/\[v([0-9]+)]/', $videoToUpload, $videoIdMatches);

            $video = new Video();

            $videoStatus = new VideoStatus();
            $videoStatus->setPrivacyStatus('private');

            $video->setStatus($videoStatus);

            $videoSnippet = new VideoSnippet();
            $videoSnippet->setCategoryId((string) $_SERVER['YOUTUBE_CATEGORY_ID']);

            $videoSnippet->setTitle($videoTitleMatches[0]);
            $description = preg_replace('/(\[v[0-9]+]).+$/', '', $videoToUpload);
            $description = preg_replace('/\[EDO]/', '', $description);
            $description = preg_replace('/\[DROPS]/', '', $description);
            $description = trim($description);
            if ((int) $videoIdMatches[1] === $streamInfo['id']) {
                $description .= PHP_EOL . 'Streamed at: ' . (new \DateTimeImmutable($streamInfo['created_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s T');
            }

            $videoSnippet->setDescription($description);

            $video->setSnippet($videoSnippet);

            $this->getLogger()->info('Uploading ' . $videoToUpload . ' ...');

            /** @var \Psr\Http\Message\RequestInterface $insertRequest */
            $insertRequest = $this->getService()->videos->insert('status,snippet', $video);

            $chunkSizeBytes = 64 * 1024 * 1024;

            $media = new MediaFileUpload(
                $this->getClient(),
                $insertRequest,
                'video/*',
                null,
                true,
                $chunkSizeBytes,
            );

            $pathToFile = $directory . '/' . $videoToUpload;

            $fileSize = filesize($pathToFile);
            $media->setFileSize($fileSize);

            $status = false;
            $handle = fopen($pathToFile, "rb");

            while (!$status && !feof($handle)) {
                $chunk  = fread($handle, $chunkSizeBytes);
                $status = $media->nextChunk($chunk);
                $this->getLogger()->debug('Processed ' . number_format($media->getProgress() / $fileSize * 100, 2, ',', ' ') . '% ...');
            }

            fclose($handle);

            $this->getClient()->setDefer(false);

            $this->getLogger()->info(PHP_EOL . 'Adding to playlist ..');

            $playlistItemSnippetResource = new ResourceId();
            $playlistItemSnippetResource->setKind('youtube#video');
            $playlistItemSnippetResource->setVideoId($status['id']);

            $playlistSnippet = new PlaylistItemSnippet();
            $playlistSnippet->setPlaylistId($_SERVER['YOUTUBE_PLAYLIST_ID']);
            $playlistSnippet->setResourceId($playlistItemSnippetResource);

            $playlistItem = new PlaylistItem();
            $playlistItem->setSnippet($playlistSnippet);

            $this->getService()->playlistItems->insert('snippet', $playlistItem);

            $this->getLogger()->info('Removing file .. ');

            unlink($pathToFile);

            $this->getLogger()->info(PHP_EOL . sprintf('Done - https://studio.youtube.com/video/%s/edit', $status['id']));
        }

        $this->getClient()->setDefer(false);

        unlink(T2YSBT::VIDEO_IN_PROGRESS);

        return $this;
    }
}
