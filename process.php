<?php

use Dotenv\Dotenv;
use Edvordo\Twitch2YoutubeBackupTool\Twitch;
use Edvordo\Twitch2YoutubeBackupTool\Youtube;

error_reporting(E_ALL);
ini_set('display_errors', 'On');

include './vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

(new Twitch())
    ->extract()
    ->ytDlp()
    ->mailChatHistory()
;

(new Youtube())
    ->setupAccessToken()
    ->setUpService()
    ->processVideosFrom(__DIR__)
;



