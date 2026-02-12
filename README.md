## 1. 总体说明

本SDK提供了抖音支付请求的通用方法，当前支持php 7.2.5以上版本。具体的接口参数组装请参考[抖音支付Api字典](https://douyinpay.com/wiki/427lmayi/48mqiimc)



## 2. 接口

### 2.1 安装
SDK依赖了guzzle/http工具包，因此需要先安装该工具包。
在命令行下进入SDK所在目录，执行命令 composer install。安装依赖
```shell
 douyinpay-php git:(master) ✗  composer install
No composer.lock file present. Updating dependencies to latest instead of installing from lock file. See https://getcomposer.org/install for more information.
Loading composer repositories with package information
Info from https://repo.packagist.org: #StandWithUkraine
Updating dependencies
Lock file operations: 8 installs, 0 updates, 0 removals
  - Locking guzzlehttp/guzzle (7.5.0)
  - Locking guzzlehttp/promises (1.5.2)
  - Locking guzzlehttp/psr7 (2.4.4)
  - Locking psr/http-client (1.0.1)
  - Locking psr/http-factory (1.0.1)
  - Locking psr/http-message (1.0.1)
  - Locking ralouphie/getallheaders (3.0.3)
  - Locking symfony/deprecation-contracts (v3.2.1)
Writing lock file
Installing dependencies from lock file (including require-dev)
Package operations: 8 installs, 0 updates, 0 removals
  - Downloading guzzlehttp/psr7 (2.4.4)
  - Installing symfony/deprecation-contracts (v3.2.1): Extracting archive
  - Installing psr/http-message (1.0.1): Extracting archive
  - Installing psr/http-client (1.0.1): Extracting archive
  - Installing ralouphie/getallheaders (3.0.3): Extracting archive
  - Installing psr/http-factory (1.0.1): Extracting archive
  - Installing guzzlehttp/psr7 (2.4.4): Extracting archive
  - Installing guzzlehttp/promises (1.5.2): Extracting archive
  - Installing guzzlehttp/guzzle (7.5.0): Extracting archive
2 package suggestions were added by new dependencies, use `composer suggest` to see details.
Generating autoload files
4 packages you are using are looking for funding.
Use the `composer fund` command to find out more!

```

2. 使用

接口的使用方法可参考demo目录下的DouYinPayDemo.php中的query和pay方法