<?php

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Dotenv\Dotenv;
use Vector\Lib\Arrays;
use Vector\Lib\Lambda;

use function GuzzleHttp\Promise\all;
use function GuzzleHttp\Promise\promise_for;

require_once 'vendor/autoload.php';

(new Dotenv())->load(__DIR__ . '/.env.local');

$token = $_ENV['BOT_TOKEN'];
$webhookUri = $_ENV['WEBHOOK_URI'];

$httpClient = new Client(['verify' => false, 'http_errors' => false]);
$getUpdatesFn = Lambda::curry('getUpdates')($httpClient)($token);

while(true) {
    tempScope($httpClient, $webhookUri, $getUpdatesFn)->wait();
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
        ->getAsync("https://api.telegram.org/bot{$token}/getUpdates", ['json' => $json])
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
}

# todo: rename variables, functions and callbacks
function tempScope(Client $http, string $uri, callable $getUpdates)
{
    $fn = function(GetUpdates $model) use ($getUpdates, $http, $uri, &$fn): PromiseInterface {
        return $getUpdates($model)
            ->then(fn(array $updates) =>
                empty($updates)
                    ? promise_for(null)
                    : all(Arrays::map(fn(array $update) => $http->postAsync($uri, ['json' => $update]))($updates))
                        ->then(function(array $responses) use($fn, $updates) {
                            echo sprintf(
                                "Отправлено сообщений: %d\n%s\n",
                                count($updates),
                                implode(PHP_EOL, array_map(fn(ResponseInterface $response) =>
                                    (string)$response->getBody(), $responses)
                                )
                            );
                            return $fn(new GetUpdates(Arrays::last($updates)['update_id'] + 1));
                        })
            );
    };

    return $fn(new GetUpdates(0));
}

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
