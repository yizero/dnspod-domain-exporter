dnspod-domain-exporter
===================================

一个 PHP 脚本，将 DNSPod 国内版的域名导入到 DNSPod 国际版


## 用法
- 打开 dnspod-domain-exporter.php 文件，根据说明填入 DNSPod 对应的帐号和密码，已经开启了 D令牌 的用户，还需要填入 D令牌 验证码。
- 在终端下: php dnspod-domain-exporter.php
- 也可以将该脚本复制到 Web 服务器的目录下，在浏览器中执行，例如：http://YOUR-DOMAIN/dnspod-domain-exporter.php
- 实时查看执行结果：tail -f export_domain.log

## 需求
- PHP 5.X+
- curl

## 其他
- 支持 D令牌，一次输入，在整个脚本执行期间，不会要求再次输入D令牌验证码
- 模块划分较细，可以在本脚本基础之上，实现其他功能开发
