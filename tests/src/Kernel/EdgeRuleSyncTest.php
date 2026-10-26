<?php

declare(strict_types=1);

namespace Drupal\Tests\bunny_net\Kernel;

use Drupal\bunny_net\EdgeRule\EdgeRuleChange;
use Drupal\bunny_net\EdgeRule\EdgeRuleDefinition;
use Drupal\bunny_net\EdgeRule\EdgeRuleSync;
use Drupal\Component\Serialization\Yaml;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Syncing the rules directory changes only the rules it manages.
 */
#[Group('bunny_net')]
#[CoversClass(EdgeRuleSync::class)]
#[CoversClass(EdgeRuleChange::class)]
#[RunTestsInSeparateProcesses]
final class EdgeRuleSyncTest extends BunnyNetKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->config('bunny_net.settings')->set('edge_rules.directory', $this->fixtures())->save();
  }

  /**
   * New files are created, changed ones updated, UI rules left alone.
   */
  public function testPlan(): void {
    $block = $this->remote('block-probes', 'guid-block');
    $staging = $this->remote('no-index-staging', 'guid-staging');
    $staging['Enabled'] = FALSE;
    $this->answerJson([
      'Id' => self::PULL_ZONE,
      'EdgeRules' => [
        $block,
        $staging,
        $this->remote('gone', 'guid-gone'),
      ['Guid' => 'guid-ui', 'Description' => 'Made in the UI', 'ActionType' => 4, 'Triggers' => []],
      ],
    ]);

    $changes = $this->sync()->plan();

    $this->assertSame([
      ['unchanged', 'block-probes', 'guid-block'],
      ['update', 'no-index-staging', 'guid-staging'],
      ['delete', 'gone', 'guid-gone'],
    ], array_map(static fn(EdgeRuleChange $change): array => [$change->operation, $change->name, $change->guid], $changes));
    $this->assertTrue($changes[1]->body['Enabled'] ?? NULL);
    $this->assertSame('guid-staging', $changes[1]->body['Guid'] ?? NULL);
  }

  /**
   * A file describing a rule made in the UI adopts it instead of copying it.
   */
  public function testAdoptsIdenticalRules(): void {
    $ui = $this->remote('block-probes', 'guid-ui');
    $ui['Description'] = 'Block WordPress probes';
    $this->answerJson(['Id' => self::PULL_ZONE, 'EdgeRules' => [$ui]]);

    $changes = $this->sync()->plan();

    $this->assertSame([EdgeRuleChange::ADOPT, EdgeRuleChange::CREATE], array_map(static fn(EdgeRuleChange $change): string => $change->operation, $changes));
    $this->assertSame('Block WordPress probes [bunny_net:block-probes]', $changes[0]->body['Description'] ?? NULL);
    $this->assertArrayNotHasKey('Guid', $changes[1]->body ?? []);
  }

  /**
   * Changes go to the right endpoints.
   */
  public function testApply(): void {
    $this->answerNoContent(2);
    $body = ['Description' => 'x [bunny_net:x]'];

    $this->sync()->apply(new EdgeRuleChange(EdgeRuleChange::CREATE, 'x', NULL, $body));
    $this->sync()->apply(new EdgeRuleChange(EdgeRuleChange::DELETE, 'gone', 'guid-gone'));

    $this->assertSame([
      ['POST /pullzone/4321/edgerules/addOrUpdate', $body],
      ['DELETE /pullzone/4321/edgerules/guid-gone', NULL],
    ], $this->sentRequests());
  }

  /**
   * Exported files sync back as unchanged or adopted.
   */
  public function testExportedFilesSyncBack(): void {
    $ui = $this->remote('no-index-staging', 'guid-ui');
    $ui['Description'] = 'Keep staging out of search engines';
    $zone = ['Id' => self::PULL_ZONE, 'EdgeRules' => [$this->remote('block-probes', 'guid-block'), $ui]];
    $directory = $this->siteDirectory . '/rules';
    $block = $directory . '/block-probes.yml';
    $staging = $directory . '/keep-staging-out-of-search-engines.yml';

    $this->answerJson($zone);
    $this->assertSame([$block => 'written', $staging => 'written'], $this->sync()->export($directory));
    $exported = Yaml::decode((string) file_get_contents($staging));
    $this->assertSame(EdgeRuleDefinition::toYaml(EdgeRuleDefinition::fromApi($ui)), $exported);

    $this->config('bunny_net.settings')->set('edge_rules.directory', $directory)->save();
    $this->answerJson($zone);
    $operations = array_map(static fn(EdgeRuleChange $change): string => $change->operation, $this->sync()->plan());
    $this->assertSame([EdgeRuleChange::UNCHANGED, EdgeRuleChange::ADOPT], $operations);

    $this->answerJson($zone);
    $this->assertSame([$block => 'exists', $staging => 'exists'], $this->sync()->export($directory));
  }

  /**
   * A rule as bunny.net returns it after a sync of the fixture file.
   *
   * @return array<string, mixed>
   *   An EdgeRuleV2Model.
   */
  private function remote(string $name, string $guid): array {
    $file = $this->fixtures() . '/' . $name . '.yml';
    $data = is_file($file)
      ? Yaml::decode((string) file_get_contents($file))
      : ['action' => 'BlockRequest', 'triggers' => [['type' => 'Url', 'patterns' => ['*']]]];
    $rule = EdgeRuleDefinition::toApi(EdgeRuleDefinition::fromYaml($data, $file), $name, $guid);
    return $rule + ['OrderIndex' => 0, 'ReadOnly' => FALSE];
  }

  /**
   * The fixture rules directory.
   */
  private function fixtures(): string {
    return dirname(__DIR__, 2) . '/fixtures/rules';
  }

  /**
   * The sync service.
   */
  private function sync(): EdgeRuleSync {
    return $this->container->get('bunny_net.edge_rule_sync');
  }

}
