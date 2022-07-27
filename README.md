## High load tester

This program make async http requests for ddos testing

## Installation

### 1. Install or upgrade Open Swoole from multiple distribution channels

Please check [Open Swoole Installation Guide](https://openswoole.com/docs/get-started/installation) about how to install Open Swoole on Ubuntu/CentOS/Windows WSL from Docker, PECL or Binary releases channels.

Swoole must support modules: sockets, openssl and curl

### 2. Clone this repo

## Run and examples

Use:
php ./hl.test.php &lt;threads count&gt; &lt;url to process&gt;

Example:
```shell
php ./hl.test.php 100 https://example.com/
```
