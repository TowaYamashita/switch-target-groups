<?php

namespace Originals;

use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;

class BlueGreen {
  private ElasticLoadBalancingV2Client $client;
  private string $loadBalancerArn;
  
  private const BLUE_ENV_SUFFIX = 'blue';
  private const GREEN_ENV_SUFFIX = 'green';
  
  public function __construct(string $loadBalancerArn, ?ElasticLoadBalancingV2Client $client){
    $this->loadBalancerArn = $loadBalancerArn;
    $this->client = $client ?? new ElasticLoadBalancingV2Client([
      'region'  => 'us-west-2',
      'version' => 'latest',
    ]);
  }
  
  private function fetchListenerArn(){
    // リスナー情報取得
    // https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-elasticloadbalancingv2-2015-12-01.html#describelisteners
    $listener = $this->client->describeListeners([
      'LoadBalancerArn' => $this->loadBalancerArn,
    ])['Listeners'][0];
    return $listener['ListenerArn'];
  }

  private function getSwapTargetGroupName(string $targetGroupName) {
    if(strpos($targetGroupName, BlueGreen::BLUE_ENV_SUFFIX) !== false) {
      return str_replace(BlueGreen::BLUE_ENV_SUFFIX, BlueGreen::GREEN_ENV_SUFFIX, $targetGroupName);
    } else {
      return str_replace(BlueGreen::GREEN_ENV_SUFFIX, BlueGreen::BLUE_ENV_SUFFIX, $targetGroupName);
    }
  }

  private function getTargetGroupNameFromArn(string $targetGroupArn){
    $tmp = explode('/', $targetGroupArn);
    return $tmp[count($tmp) - 2];
  }

  public function deployNewEnvironment(){
    // テスト環境 AutoScalingグループの 最小、最大、希望キャパシティを増やす
  }

  public function deleteOldEnvironment(){
    // AutoScalingグループの 最小、最大、希望キャパシティを0にする
  }
  
  public function swap(){
    $listenerArn = $this->fetchListenerArn();
  
    // リスナーに紐づいたルール情報取得
    // https://docs.aws.amazon.com/aws-sdk-php/v3/api/api-elasticloadbalancingv2-2015-12-01.html#describerules
    $rules = $this->client->describeRules([
      'ListenerArn' => $listenerArn,
    ])['Rules'];
  
    // Condition を見て、ルールをテスト環境につながるやつとそうでないやつに分ける
    $rulesForTest = [];
    $rulesForProd = [];
    $targetGroupsForTest = [];
    $targetGroupsForProd = [];
    foreach ($rules as $rule) {
      $conditions = $rule['Conditions'];
    
      if(array_search('http-header', array_column($conditions, 'Field'), true) === false) {
          $rulesForProd[] = $rule;
          $action = $rule['Actions'][0];
          $tmp = explode('/', $action['TargetGroupArn']);
          $targetGroupName = $tmp[count($tmp) - 2];
          $targetGroupsForProd[$targetGroupName] = $action['TargetGroupArn'];
      } else {
          $rulesForTest[] = $rule;
          $action = $rule['Actions'][0];
          $tmp = explode('/', $action['TargetGroupArn']);
          $targetGroupName = $tmp[count($tmp) - 2];
          $targetGroupsForTest[$targetGroupName] = $action['TargetGroupArn'];
      }
    }
  
    // 本番環境 -> テスト環境
    foreach($rulesForProd as $ruleForProd){
      $targetGroupName = $this->getTargetGroupNameFromArn($ruleForProd['Actions'][0]['TargetGroupArn']);
      $replaced = $this->getSwapTargetGroupName($targetGroupName);
      $targetGroupArn = $targetGroupsForTest[$replaced];
      if($ruleForProd['IsDefault']) {
        $this->client->modifyListener([
          'ListenerArn' => $listenerArn,
          'DefaultActions' => [
            [
              'Type' => 'forward',
              'TargetGroupArn' => $targetGroupArn
            ],
          ],
        ]); 
      } else {
        $this->client->modifyRule([
          'RuleArn' => $ruleForProd['RuleArn'],
          'Actions' => [
            [
              'Type' => 'forward',
              'TargetGroupArn' => $targetGroupArn
            ],
          ],
        ]);
      }
    }
  
    // テスト環境 -> 本番環境
    foreach($rulesForTest as $ruleForTest){
      $targetGroupName = $this->getTargetGroupNameFromArn($ruleForTest['Actions'][0]['TargetGroupArn']);
      $replaced = $this->getSwapTargetGroupName($targetGroupName);
      $targetGroupArn = $targetGroupsForProd[$replaced];
      if($ruleForTest['IsDefault']){
        $this->client->modifyListener([
          'ListenerArn' => $listenerArn,
          'DefaultActions' => [
            [
              'Type' => 'forward',
              'TargetGroupArn' => $targetGroupArn
            ],
          ],
        ]); 
      } else {
        $this->client->modifyRule([
          'RuleArn' => $ruleForTest['RuleArn'],
          'Actions' => [
            [
              'Type' => 'forward',
              'TargetGroupArn' => $targetGroupArn
            ],
          ],
        ]);
      }
    }
  }
}
