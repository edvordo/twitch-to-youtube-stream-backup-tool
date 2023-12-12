<?php

use Dotenv\Dotenv;

error_reporting(E_ALL);
ini_set('display_errors', 'On');

include './vendor/autoload.php';

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

print_r($_SERVER);
