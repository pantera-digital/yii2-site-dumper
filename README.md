# YII Dumper

Parsing HTML pages at a given depth

```sh
$ yii dump https://domain.zone/foo $DEPTH $BUFFER $FORCE $EXTERNAL $ANOTHER_PATH
```
  - The larger the buffer size, the faster the program runs
  - Logs are stored in the directory /runtime/logs

## Install

```
$ git clone https://github.com/pantera-digital/yii2-site-dumper.git
$ composer install
```

## Sample
```sh
$ yii dump https://domain.zone/foo 2 50
```

## Requirements
  * **PHP ^7.2**
  * **CURL**

## TODO
  1. $searchExternal to $depthExterntal
  2. Fix processing of the link: zakupki.gov.ru/data/common-info.html?regNumber=0816500000619001511
  3. The construction <base> needs to be modified to check an existing tag
