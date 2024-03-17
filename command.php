<?php
require_once('./vendor/autoload.php');

use Aws\ElasticLoadBalancingV2\ElasticLoadBalancingV2Client;
use Originals\BlueGreen;

$region = $argv[1];
$loadBalancerArn = $argv[2];

$client = new ElasticLoadBalancingV2Client([
  'region'  => $region,
  'version' => 'latest',
]);

$blueGreen = new BlueGreen($loadBalancerArn, $client);
$blueGreen->swap();
