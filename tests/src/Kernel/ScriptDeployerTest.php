<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Drupal\bunny_net\EdgeScript\ScriptDeployer;
use Drupal\bunny_net\NotConfiguredException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Edge Scripts deploy from files, and only when the code changed.
 */
#[Group('bunny_net')]
#[CoversClass(ScriptDeployer::class)]
#[RunTestsInSeparateProcesses]
final class ScriptDeployerTest extends BunnyNetKernelTestBase {

  /**
   * The fixture script's code.
   */
  private string $code;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $file = dirname(__DIR__, 2) . '/fixtures/scripts/middleware.ts';
    $this->code = (string) file_get_contents($file);
    $this->config('bunny_net.settings')->set('edge_scripts', [
      'middleware' => ['script_id' => 77, 'file' => $file, 'variables' => ['ORIGIN' => 'www.example.com']],
    ])->save();
    $this->setSetting('bunny_net', [
      'api_key' => 'test-key',
      'edge_script_secrets' => ['middleware' => ['SIGNING_KEY' => 's3cret']],
    ]);
  }

  /**
   * Variables, secrets, then code, then a release.
   */
  public function testDeploysChangedCode(): void {
    $this->answerJson(['Code' => 'old code'], ['Name' => 'ORIGIN'], ['Name' => 'SIGNING_KEY']);
    $this->answerNoContent(2);

    $this->assertSame(ScriptDeployer::PUBLISHED, $this->deployer()->deploy('middleware'));

    $this->assertSame([
      ['GET /compute/script/77/code', NULL],
      ['PUT /compute/script/77/variables', ['Name' => 'ORIGIN', 'DefaultValue' => 'www.example.com']],
      ['PUT /compute/script/77/secrets', ['Name' => 'SIGNING_KEY', 'Secret' => 's3cret']],
      ['POST /compute/script/77/code', ['Code' => $this->code]],
      ['POST /compute/script/77/publish', ['Note' => 'Deployed from middleware.ts']],
    ], $this->sentRequests());
  }

  /**
   * Code that matches the file is not deployed again.
   */
  public function testSkipsUnchangedCode(): void {
    $this->answerJson(['Code' => $this->code]);

    $this->assertSame(ScriptDeployer::UNCHANGED, $this->deployer()->deploy('middleware'));
    $this->assertCount(1, $this->sent);
  }

  /**
   * Without publishing, the code is uploaded and no release made.
   */
  public function testUploadWithoutPublishing(): void {
    $this->answerJson(['Name' => 'ORIGIN'], ['Name' => 'SIGNING_KEY']);
    $this->answerNoContent();

    $this->assertSame(ScriptDeployer::UPLOADED, $this->deployer()->deploy('middleware', force: TRUE, publish: FALSE));
    $this->assertSame('POST /compute/script/77/code', $this->sentRequests()[2][0]);
    $this->assertCount(3, $this->sent);
  }

  /**
   * A script without a file is read from the bunny folder, by its name.
   */
  public function testDefaultFile(): void {
    // A name no site has a file for, so the test doesn't depend on one.
    $this->config('bunny_net.settings')->set('edge_scripts', ['bunny-net-test' => ['script_id' => 77]])->save();

    $overview = $this->deployer()->overview();

    $this->assertSame(dirname($this->root) . '/bunny/bunny-net-test.ts', $overview[0]['file']);
    $this->assertFalse($overview[0]['exists']);
    $this->expectExceptionMessage('bunny/bunny-net-test.ts does not exist.');
    $this->deployer()->deploy('bunny-net-test');
  }

  /**
   * An unknown script name fails before anything is sent.
   */
  public function testUnknownScript(): void {
    $this->expectException(NotConfiguredException::class);
    $this->deployer()->deploy('nope');
  }

  /**
   * The deployer.
   */
  private function deployer(): ScriptDeployer {
    return $this->container->get('bunny_net.script_deployer');
  }

}
