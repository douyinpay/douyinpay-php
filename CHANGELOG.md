## 版本变更记录
代码位置 bin/CertificateDownloader.php
SdkAgentVersion 每次sdk升级，需要更新版本号


v 1.0.0 - 2026-01-26
--------------------
* 新增平台证书下载功能，使用命令： composer exec bin/CertificateDownloader.php -k ${对称密钥} -m ${商户ID} -f ${本地商户证书文件路径} -s ${商户证书序列号} -o ${平台证书保存路径}