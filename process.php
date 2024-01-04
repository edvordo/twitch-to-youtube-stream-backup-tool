<?php

use Dotenv\Dotenv;
use Edvordo\Twitch2YoutubeBackupTool\T2YSBT;

error_reporting(E_ALL);
ini_set('display_errors', 'On');

include './vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

(new T2YSBT(__DIR__))
    ->process();

