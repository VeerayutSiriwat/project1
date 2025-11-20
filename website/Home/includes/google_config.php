<?php
require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\StreamHandler;

function make_google_client(): Google_Client {
    $client = new Google_Client();
    $client->setClientId('1059108779805-qfh80h1uj41agson17c7hpm1rm5kh3op.apps.googleusercontent.com');
    $client->setClientSecret('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');
    $client->setRedirectUri('http://localhost/project/website/Home/google_callback.php');
    $client->addScope(['email','profile']);
    $client->setAccessType('offline');
    $client->setIncludeGrantedScopes(true);
    $client->setPrompt('select_account');

    // <<< เพิ่ม 3 บรรทัดนี้ เพื่อเลี่ยง CurlFactory >>>
    $handler = HandlerStack::create(new StreamHandler());  // ใช้ stream ไม่ใช้ cURL
    $client->setHttpClient(new GuzzleClient(['handler' => $handler, 'timeout' => 10]));
    // หรือทางลัด: putenv('GOOGLE_HTTP_HANDLER=stream');

    return $client;
}
