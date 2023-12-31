<?php

namespace Edvordo\Twitch2YoutubeBackupTool;

use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\YouTube as YoutubeService;
use Google\Service\YouTube\PlaylistItem;
use Google\Service\YouTube\PlaylistItemSnippet;
use Google\Service\YouTube\ResourceId;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;

class Youtube
{
    private ?Client $client = null;

    private ?YoutubeService $service = null;

    public function __construct()
    {
        $this->setUpClient();
    }

    private function setUpClient(): static
    {
        $ytClient = new Client();
        $ytClient->setAuthConfig(json_decode($_SERVER['GOOGLE_APPLICATION_CREDENTIALS'], true));
        $ytClient->setApplicationName('flow-stream-upload');
        $ytClient->addScope(YouTubeService::YOUTUBE);

        $ytClient->setRedirectUri('http://localhost');

        $this->client = $ytClient;

        return $this;
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

    public function processVideosFrom(string $directory)
    {
        ini_set('memory_limit', -1);
        $files = `ls -Ahrt "{$directory}" | grep -E "\[v[0-9]+\\]\.mp4"`;

        if (true === empty($files)) {
            echo 'SKIPPING upload - no files to upload detected' . PHP_EOL;

            return $this;
        }

        $videos = preg_split('/\n/', $files, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($videos as $videoToUpload) {
            $this->getClient()->setDefer(true);
            preg_match('/(\[v[0-9]+])/', $videoToUpload, $matches);

            $video = new Video();

            $videoStatus = new VideoStatus();
            $videoStatus->setPrivacyStatus('private');

            $video->setStatus($videoStatus);

            $videoSnippet = new VideoSnippet();
            $videoSnippet->setCategoryId((string) $_SERVER['YOUTUBE_CATEGORY_ID']);

            $videoSnippet->setTitle($matches[0]);
            $videoSnippet->setDescription(trim(preg_replace('/(\[v[0-9]+]).+$/', '', $videoToUpload)));

            $video->setSnippet($videoSnippet);

            echo 'Uploading ' . $videoToUpload . ' ...' . PHP_EOL;

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
                echo chr(27) . '[0GProcessed ' . number_format($media->getProgress() / $fileSize * 100, 2, ',', ' ') . '% ...';
            }

            fclose($handle);

            $this->getClient()->setDefer(false);

            echo PHP_EOL . 'Adding to playlist ..' . PHP_EOL;

            $playlistItemSnippetResource = new ResourceId();
            $playlistItemSnippetResource->setKind('youtube#video');
            $playlistItemSnippetResource->setVideoId($status['id']);

            $playlistSnippet = new PlaylistItemSnippet();
            $playlistSnippet->setPlaylistId($_SERVER['YOUTUBE_PLAYLIST_ID']);
            $playlistSnippet->setResourceId($playlistItemSnippetResource);

            $playlistItem = new PlaylistItem();
            $playlistItem->setSnippet($playlistSnippet);

            $this->getService()->playlistItems->insert('snippet', $playlistItem);

            echo 'Removing file .. ' . PHP_EOL;
            unlink($pathToFile);
            echo PHP_EOL . sprintf('Done - https://studio.youtube.com/video/%s/edit', $status['id']) . PHP_EOL . PHP_EOL;
        }

        $this->getClient()->setDefer(false);

        return $this;
    }
}
