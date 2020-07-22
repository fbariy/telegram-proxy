<?php

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Dotenv\Dotenv;
use Vector\Lib\Arrays;
use Vector\Lib\Lambda;

require_once 'vendor/autoload.php';

(new Dotenv())->load(__DIR__ . '/.env.local');

$token = $_ENV['BOT_TOKEN'];
$webhookUri = $_ENV['WEBHOOK_URI'];

$httpClient = new Client(['base_uri' => "https://api.telegram.org", 'verify' => false]);
$updates = Lambda::curry('getUpdates')($httpClient)($token);

while (true) {
    sendUpdates($webhookUri, $updates)->wait();
}

/**
 * @param Client $httpClient
 * @param string $token
 * @param GetUpdates $params
 *
 * @return PromiseInterface<array>
 */
function getUpdates(Client $httpClient, string $token, GetUpdates $params): PromiseInterface {
    $json = [
        'offset' => $params->offset,
        'limit' => $params->limit,
        'timeout' => $params->timeout
    ];

    return $httpClient
        ->getAsync("/bot{$token}/getUpdates", ['json' => $json])
        ->then(function(ResponseInterface $response) {
            if (200 !== $response->getStatusCode()) {
                throw new Exception("Not expected response code: {$response->getStatusCode()}");
            }

            $decoded = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

            if (!$decoded['ok']) {
                throw new Exception("Get updates exception, content {$response->getBody()}");
            }

            return $decoded['result'];
        });
};

/**
 * @param string $webhookUri
 * @param callable(GetUpdates): array $getUpdates
 * @param int $offset
 *
 * @return PromiseInterface<null>
 */
function sendUpdates(string $webhookUri, callable $getUpdates, int $offset = 0): PromiseInterface {
    /** @var PromiseInterface $updates */
    $updates = $getUpdates(new GetUpdates($offset));

    return $updates->then(function (array $updates) use ($webhookUri, $getUpdates) {
        if (empty($updates)) {
            echo 'на небе только и разговоры что о дебаге' . PHP_EOL;
            return null;
        }

        //todo: send requests
        echo 'Send: ' . implode(', ', Arrays::map(fn(array $update) => $update['update_id'])($updates)) . PHP_EOL;

        return sendUpdates($webhookUri, $getUpdates, Arrays::last($updates)['update_id'] + 1);
    });
};

class GetUpdates
{
    public int $offset;
    public int $limit;
    public int $timeout;

    public function __construct(int $offset, int $limit = 100, int $timeout = 0)
    {
        $this->offset = $offset;
        $this->limit = $limit;
        $this->timeout = $timeout;
    }
}
