<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Drupal\Core\Http\ClientFactory;
use Drupal\KernelTests\KernelTestBase;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * A site with bunny.net configured, talking to a mocked API.
 */
abstract class BunnyNetKernelTestBase extends KernelTestBase {

  /**
   * The pull zone the tests use.
   */
  protected const int PULL_ZONE = 4321;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'cache_purifier', 'bunny_net'];

  /**
   * The requests sent to bunny.net, as Guzzle's history middleware keeps them.
   *
   * @var \ArrayObject<int, array<string, mixed>>
   */
  protected \ArrayObject $sent;

  /**
   * What bunny.net answers, one response per request.
   */
  protected MockHandler $answers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Before anything builds the client, so no request leaves the test.
    $this->answers = new MockHandler();
    // history() takes its container by reference; hand it a copy of the
    // handle so the property keeps its type.
    $history = $this->sent = new \ArrayObject();
    $stack = HandlerStack::create($this->answers);
    $stack->push(Middleware::history($history));
    $this->container->set('http_client_factory', new ClientFactory($stack));

    $this->installConfig(['cache_purifier', 'bunny_net']);
    // Config saves in the tests would otherwise purge their own tags.
    $outbound = $this->config('cache_purifier.settings');
    $outbound->set('outbound.blocklist', [...$outbound->get('outbound.blocklist'), 'config:*'])->save();
    $this->setSetting('bunny_net', ['api_key' => 'test-key']);
    $config = $this->config('bunny_net.settings');
    $config
      ->set('pull_zone_id', self::PULL_ZONE)
      ->set('purge.enabled', TRUE)
      ->save();
    $this->container->get('bunny_net.cache_tags_invalidator')->destruct();
  }

  /**
   * Queues JSON answers.
   */
  protected function answerJson(mixed ...$bodies): void {
    foreach ($bodies as $body) {
      $this->answers->append(new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)));
    }
  }

  /**
   * Queues empty 204 answers.
   */
  protected function answerNoContent(int $count = 1): void {
    for ($i = 0; $i < $count; $i++) {
      $this->answers->append(new Response(204));
    }
  }

  /**
   * The requests sent, as "METHOD path" with the decoded JSON body.
   *
   * @return list<array{string, mixed}>
   *   One entry per request.
   */
  protected function sentRequests(): array {
    return array_values(array_map(static function (array $entry): array {
      $request = $entry['request'];
      assert($request instanceof RequestInterface);
      $body = (string) $request->getBody();
      $line = $request->getMethod() . ' ' . $request->getUri()->getPath();
      if ($request->getUri()->getQuery() !== '') {
        $line .= '?' . $request->getUri()->getQuery();
      }
      return [$line, $body === '' ? NULL : json_decode($body, TRUE)];
    }, $this->sent->getArrayCopy()));
  }

}
