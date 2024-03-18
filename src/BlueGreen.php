<?php
declare(strict_types=1);

namespace Originals;

use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Aws\AutoScaling\AutoScalingClient;

class BlueGreen {
  private string $loadBalancerName;
  private ElasticLoadBalancingV2Client $elbv2Client;
  private AutoScalingClient $autoScalingClient;
  
  private const DEFAULT_REGION = 'us-west-2';
  private const BLUE_ENV_SUFFIX = 'blue';
  private const GREEN_ENV_SUFFIX = 'green';
  
  public function __construct(string $loadBalancerName, ?ElasticLoadBalancingV2Client $elbv2Client, ?AutoScalingClient $autoScalingClient) {
    $this->loadBalancerName = $loadBalancerName;
    $this->elbv2Client = $elbv2Client ?? new ElasticLoadBalancingV2Client([
      'region'  => BlueGreen::DEFAULT_REGION,
      'version' => 'latest',
    ]);
    $this->autoScalingClient = $autoScalingClient ?? new AutoScalingClient([
      'region' =>  BlueGreen::DEFAULT_REGION,
      'version' => 'latest',
    ]);
  }

  /**
   * テスト環境を立ち上げ、本番環境の設定をベースにAutoScalingグループを設定する。
   * 本番環境とテスト環境のターゲットグループ名が同じである必要がある。
   */
  public function boot(){
    $listenerArn = $this->fetchListenerArn();
    $rules = $this->fetchRules($listenerArn);
    $rulesForProd = $this->filterRulesForProd($rules);

    // 本番環境の AutoScalingグループの 最小、最大、希望キャパシティを取得
    // 注意: ターゲットグループ名とAutoScalingグループ名が同じであることが前提です。
    $autoScalingGroups = $this->autoScalingClient->describeAutoScalingGroups([
      'AutoScalingGroupNames' => array_keys($this->extractTargetGroupArnsFromRules($rulesForProd)),
    ]);

    $tmp = [];
    foreach($autoScalingGroups['AutoScalingGroups'] as $t) {
      $name = $t['AutoScalingGroupName'];
      $tmp[$name] = [
        'desiredCapacity' => $t['DesiredCapacity'],
        'maxSize'         => $t['MaxSize'],
        'minSize'         => $t['MinSize'],
      ];
    }

    // テスト環境の AutoScalingグループの 最小、最大、希望キャパシティを変更
    // 注意: ターゲットグループ名とAutoScalingグループ名が同じであることが前提です。
    foreach($tmp as $targetGroupNameForProd => $value) {
      $targetGroupNameForTest = $this->getSwapTargetGroupName($targetGroupNameForProd);
      $this->autoScalingClient->updateAutoScalingGroup([
        'AutoScalingGroupName' => $targetGroupNameForTest,
        'DesiredCapacity'      => $value['desiredCapacity'],
        'MaxSize'              => $value['maxSize'],
        'MinSize'              => $value['minSize'],
      ]);
    }
  }

  /**
   * 本番環境が使用可能かどうか確認する
   * 本番環境の各ターゲットグループのうち1つでもヘルスチェックに成功したインスタンスが存在すれば使用可能と判断する
   * ターゲットグループ名とそれが使用可能かの真偽値の組を返す
   */
  public function getProductionHealthStatus() : array {
    $listenerArn = $this->fetchListenerArn();
    $rules = $this->fetchRules($listenerArn);
    $rulesForProd = $this->filterRulesForProd($rules);
    $targetGroupsForProd = $this->extractTargetGroupArnsFromRules($rulesForProd);

    $result = [];
    foreach($targetGroupsForProd as $name => $arn) {
      $healthCheck = $this->elbv2Client->describeTargetHealth([
        'TargetGroupArn' => $arn,
      ]);
      $state = array_reduce($healthCheck['TargetHealthDescriptions'], function ($carry, $h){
        return $carry || ($h['TargetHealth']['State'] === 'healthy');
      }, false); 
      $result[$name] = $state;
    }

    return $result;
  }

  /**
   * テスト環境が使用可能かどうか確認する
   * テスト環境の各ターゲットグループのうち1つでもヘルスチェックに成功したインスタンスが存在すれば使用可能と判断する
   * ターゲットグループ名とそれが使用可能かの真偽値の組を返す
   */
  public function getTestingHealthStatus() : array {
    $listenerArn = $this->fetchListenerArn();
    $rules = $this->fetchRules($listenerArn);
    $rulesForTest = $this->filterRulesForTest($rules);
    $targetGroupsForTest = $this->extractTargetGroupArnsFromRules($rulesForTest);

    $result = [];
    foreach($targetGroupsForTest as $name => $arn) {
      $healthCheck = $this->elbv2Client->describeTargetHealth([
        'TargetGroupArn' => $arn,
      ]);
      $state = array_reduce($healthCheck['TargetHealthDescriptions'], function ($carry, $h){
        return $carry || ($h['TargetHealth']['State'] === 'healthy');
      }, false); 
      $result[$name] = $state;
    }

    return $result;
  }
  
  /**
   * 本番環境とテスト環境のルールを入れ替え、トラフィックの流れを切り替える。
   * 入れ替えはリスナールールを変更することで行われる。
   */
  public function swap(){
    $listenerArn = $this->fetchListenerArn();
    $rules = $this->fetchRules($listenerArn);
  
    // Condition を見て、ルールをテスト環境につながるやつとそうでないやつに分ける
    $rulesForTest = $this->filterRulesForTest($rules);
    $rulesForProd = $this->filterRulesForProd($rules);
    $targetGroupsForTest = $this->extractTargetGroupArnsFromRules($rulesForTest);
    $targetGroupsForProd = $this->extractTargetGroupArnsFromRules($rulesForProd);
  
    // 本番環境 -> テスト環境
    foreach($rulesForProd as $ruleForProd){
      $targetGroupName = $this->getTargetGroupNameFromArn($ruleForProd['Actions'][0]['TargetGroupArn']);
      $replaced = $this->getSwapTargetGroupName($targetGroupName);
      $targetGroupArn = $targetGroupsForTest[$replaced];
      $this->swapTargetGroup($targetGroupArn, $ruleForProd['IsDefault'], $listenerArn, $ruleForProd['RuleArn']);
    }
  
    // テスト環境 -> 本番環境
    foreach($rulesForTest as $ruleForTest){
      $targetGroupName = $this->getTargetGroupNameFromArn($ruleForTest['Actions'][0]['TargetGroupArn']);
      $replaced = $this->getSwapTargetGroupName($targetGroupName);
      $targetGroupArn = $targetGroupsForProd[$replaced];
      $this->swapTargetGroup($targetGroupArn, $ruleForTest['IsDefault'], $listenerArn, $ruleForTest['RuleArn']);
    }
  }

  /**
   * テスト環境を削除し、関連するAutoScalingグループの設定をクリーンアップする。
   */
  public function destroy(){
    $listenerArn = $this->fetchListenerArn();
    $rules = $this->fetchRules($listenerArn);

    // テスト環境の AutoScalingグループの 最小、最大、希望キャパシティを0にする
    // 注意: ターゲットグループ名とAutoScalingグループ名が同じであることが前提です。
    $rulesForTest = $this->filterRulesForTest($rules);
    foreach($this->extractTargetGroupArnsFromRules($rulesForTest) as $targetGroupName => $value) {
      $this->autoScalingClient->updateAutoScalingGroup([
        'AutoScalingGroupName' => $targetGroupName,
        'DesiredCapacity'      => 0,
        'MaxSize'              => 0,
        'MinSize'              => 0,
      ]);
    }
  }
  
  private function fetchListenerArn() : string {
    // リスナー情報取得
    // https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-elasticloadbalancingv2-2015-12-01.html#describelisteners
    $loadBalancerArn = $this->elbv2Client->describeLoadBalancers([
      'Names' => [$this->loadBalancerName],
    ])['LoadBalancers'][0]['LoadBalancerArn'];

    $listeners = $this->elbv2Client->describeListeners([
      'LoadBalancerArn' => $loadBalancerArn,
    ])['Listeners'];

    foreach($listeners as $listener) {
      if($listener['Protocol'] === 'HTTP' && $listener['Port'] === 80) {
        return $listener['ListenerArn'];
      }
    }
  }

  private function fetchRules(string $listenerArn) : array {
    // リスナーに紐づいたルール情報取得
    // https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-elasticloadbalancingv2-2015-12-01.html#describerules
    return $this->elbv2Client->describeRules([
      'ListenerArn' => $listenerArn,
    ])['Rules'];
  }

  private function filterRulesForProd(array $rules) : array {
    $rulesForProd = [];
    foreach ($rules as $rule) {
      $conditions = $rule['Conditions'];
      if($this->isRuleForProd($conditions)) {
        $rulesForProd[] = $rule;   
      }
    }
    return $rulesForProd;
  }

  private function filterRulesForTest(array $rules) : array {
    $rulesForTest = [];
    foreach ($rules as $rule) {
      $conditions = $rule['Conditions'];
      if($this->isRuleForProd($conditions) === false) {
        $rulesForTest[] = $rule;   
      }
    }
    return $rulesForTest;
  }

  private function isRuleForProd(array $ruleConditions) : bool {
    return array_search('http-header', array_column($ruleConditions, 'Field'), true) === false;
  }

  /**
   * ターゲットグループ名とターゲットグループARNの組を返す
   */
  private function extractTargetGroupArnsFromRules(array $rules) : array {
    $targetGroups = [];
    foreach($rules as $rule) {
      $action = $rule['Actions'][0];
      $tmp = explode('/', $action['TargetGroupArn']);
      $targetGroupName = $tmp[count($tmp) - 2];
      $targetGroups[$targetGroupName] = $action['TargetGroupArn'];
    }
    return $targetGroups;
  }

  private function getSwapTargetGroupName(string $targetGroupName) : string {
    if(strpos($targetGroupName, BlueGreen::BLUE_ENV_SUFFIX) !== false) {
      return str_replace(BlueGreen::BLUE_ENV_SUFFIX, BlueGreen::GREEN_ENV_SUFFIX, $targetGroupName);
    } else {
      return str_replace(BlueGreen::GREEN_ENV_SUFFIX, BlueGreen::BLUE_ENV_SUFFIX, $targetGroupName);
    }
  }

  private function getTargetGroupNameFromArn(string $targetGroupArn) : string {
    $tmp = explode('/', $targetGroupArn);
    return $tmp[count($tmp) - 2];
  }

  private function swapTargetGroup(string $targetGroupArn, bool $isDefaultRule, ?string $listenerArn, ?string $ruleArn) {
    if($isDefaultRule) {
      $this->elbv2Client->modifyListener([
        'ListenerArn' => $listenerArn,
        'DefaultActions' => [
          [
            'Type' => 'forward',
            'TargetGroupArn' => $targetGroupArn
          ],
        ],
      ]);
      return;
    }

    $this->elbv2Client->modifyRule([
      'RuleArn' => $ruleArn,
      'Actions' => [
        [
          'Type' => 'forward',
          'TargetGroupArn' => $targetGroupArn
        ],
      ],
    ]);
  }
}
