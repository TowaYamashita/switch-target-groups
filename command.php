<?php
require_once('./vendor/autoload.php');

use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Originals\BlueGreen;

$loadBalancerName = $argv[1];
$mode = $argv[2];

$blueGreen = new BlueGreen($loadBalancerName, null, null);

switch($mode) {
  case 'boot':
    print('mode: BOOT' . PHP_EOL);
    $blueGreen->boot();
    break;
  case 'healthcheck':
    print('mode: HEALTHCHECK' . PHP_EOL);
    $prod = $blueGreen->getProductionHealthStatus();
    print('本番環境' . PHP_EOL);
    var_dump($prod);
    $test = $blueGreen->getTestingHealthStatus();
    print('テスト環境' . PHP_EOL);
    var_dump($test);
    break;
  case 'swap':
    print('mode: SWAP' . PHP_EOL);
    $blueGreen->swap();
    break;
  case 'destroy':
    print('mode: DESTROY' . PHP_EOL);
    $blueGreen->destroy();
    break;
  default:
    print('NONE' . PHP_EOL);
    break;
}
