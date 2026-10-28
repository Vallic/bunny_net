<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Consolidation\AnnotatedCommand\CommandData;
use Drupal\bunny_net\Drush\Commands\BunnyNetCommands;
use Drush\Attributes\Bootstrap;
use Drush\Attributes\Hook;
use Drush\Boot\DrupalBootLevels;
use Drush\Commands\core\DeployCommands;
use Drush\Commands\core\DeployHookCommands;
use Drush\Commands\DrushCommands;
use Drush\Log\DrushLoggerManager;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\AbstractLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * After drush deploy, the pull zone is brought in line where that is on.
 */
#[Group('bunny_net')]
#[CoversClass(BunnyNetCommands::class)]
#[RunTestsInSeparateProcesses]
final class DeploySyncTest extends BunnyNetKernelTestBase {

  /**
   * What the commands printed.
   */
  private BufferedOutput $output;

  /**
   * Collects the warnings the commands log.
   *
   * @var \Psr\Log\AbstractLogger&object{warnings: list<string>}
   */
  private AbstractLogger $log;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Drush's dt(), which a command calls and only Drush itself loads.
    require_once dirname((string) (new \ReflectionClass(DrushCommands::class))->getFileName(), 3) . '/includes/output.inc';
    $this->config('bunny_net.settings')
      ->set('pull_zone_settings', ['EnableCookieVary' => TRUE])
      ->set('edge_scripts', [])
      ->save();
  }

  /**
   * Off by default: a deploy sends nothing.
   */
  public function testOffByDefault(): void {
    $this->postDeploy();

    $this->assertSame([], $this->sentRequests());
  }

  /**
   * On, the changed settings are sent without asking.
   */
  public function testSyncsWithoutAsking(): void {
    $this->config('bunny_net.settings')->set('sync_on_deploy', TRUE)->save();
    $this->answerJson(
      ['Id' => self::PULL_ZONE, 'EnableCookieVary' => FALSE],
      ['Id' => self::PULL_ZONE, 'EnableCookieVary' => TRUE],
    );

    $this->postDeploy();

    $this->assertSame(['POST /pullzone/4321', ['EnableCookieVary' => TRUE]], $this->sentRequests()[1]);
    $this->assertStringContainsString('Applied 1 change(s) to pull zone 4321.', $this->output->fetch());
  }

  /**
   * A refusal is reported, and the deploy carries on.
   */
  public function testFailureDoesNotFailTheDeploy(): void {
    $this->config('bunny_net.settings')->set('sync_on_deploy', TRUE)->save();
    $this->answers->append(new Response(401, ['Content-Type' => 'application/json'], '{"Message":"Unauthorized"}'));

    $this->postDeploy();

    $this->assertCount(1, $this->log->warnings);
    $this->assertStringContainsString('Run drush bunny:sync.', $this->log->warnings[0]);
  }

  /**
   * A script that can't be deployed doesn't stop the zone's sync.
   */
  public function testScriptFailureStillSyncs(): void {
    $this->config('bunny_net.settings')
      ->set('sync_on_deploy', TRUE)
      ->set('edge_scripts', ['middleware' => ['script_id' => 0]])
      ->save();
    $this->answerJson(
      ['Id' => self::PULL_ZONE, 'EnableCookieVary' => FALSE],
      ['Id' => self::PULL_ZONE, 'EnableCookieVary' => TRUE],
    );

    $this->postDeploy();

    $this->assertStringContainsString('Edge Script middleware was not deployed', $this->log->warnings[0] ?? '');
    $this->assertSame('POST /pullzone/4321', $this->sentRequests()[1][0] ?? NULL);
  }

  /**
   * The hook is on deploy:hook: drush deploy boots no Drupal to find it.
   */
  public function testHookedToDeployHook(): void {
    $attributes = (new \ReflectionMethod(BunnyNetCommands::class, 'postDeploy'))->getAttributes(Hook::class);
    $this->assertCount(1, $attributes);
    $this->assertSame(DeployHookCommands::HOOK, $attributes[0]->newInstance()->target);
    $this->assertSame(DrupalBootLevels::NONE, (new \ReflectionClass(DeployCommands::class))->getAttributes(Bootstrap::class)[0]->newInstance()->level);
  }

  /**
   * Runs the hook as drush deploy:hook would, once it has finished.
   */
  private function postDeploy(): void {
    $commands = BunnyNetCommands::create($this->container);
    $input = new ArrayInput([]);
    $input->setInteractive(FALSE);
    $this->output = new BufferedOutput();
    $commands->setInput($input);
    $commands->setOutput($this->output);
    $logger = new DrushLoggerManager();
    $commands->setLogger($logger);
    $logger->add('test', $this->log = new class() extends AbstractLogger {

      /**
       * The warnings logged.
       *
       * @var list<string>
       */
      public array $warnings = [];

      /**
       * {@inheritdoc}
       *
       * @param mixed $level
       *   The level.
       * @param \Stringable|string $message
       *   The message.
       * @param array<array-key, mixed> $context
       *   The context.
       */
      public function log($level, \Stringable|string $message, array $context = []): void {
        if ($level === 'warning') {
          $this->warnings[] = (string) $message;
        }
      }

    });
    $commands->postDeploy(NULL, $this->createMock(CommandData::class));
  }

}
