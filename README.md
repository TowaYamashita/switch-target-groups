# switch-target-groups

AWS ALB の リスナーに紐づいたターゲットグループを入れ替えるコード

# Usage

```shell
php command.php <ロードバランサーの名前> <boot|healthcheck|swap|destroy>

boot: 本番環境のターゲットグループの最小/最大/希望サイズと同じ値を使って、テスト環境を起動する
healthcheck: 本番環境とテスト環境の各ターゲットグループのヘルスチェックの状態を表示する
swap: 本番環境とテスト環境のリスナーグループを入れ替える
destroy: テスト環境の各ターゲットグループの最小/最大/希望サイズをすべて0にする
```

# 補足
- このコードをEC2インスタンスで実行する際には、 docs/0-iam-policy.md の内容で作成した IAMインスタンスプロファイルを EC2インスタンスに割り当てること
- 本番環境のターゲットグループ名とAutoScalingグループ名、テスト環境のターゲットグループ名とAutoScalingグループ名が同じであることが前提です。
