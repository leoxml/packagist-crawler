<?php
namespace Spindle\HttpClient;

use ProgressBar\Manager as ProgressBarManager;
use hirak\PackagistCrawler\ExpiredFileManager;

set_time_limit(0);
ini_set('memory_limit', '1G');

require_once __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/config.php')) {
    $config = require __DIR__ . '/config.php';
} else {
    $config = require __DIR__ . '/config.default.php';
}

if (file_exists($config->lockfile)) {
    throw new \RuntimeException("$config->lockfile exists");
}

touch($config->lockfile);   // 创建锁文件 尝试将由给出的文件的访问和修改时间设定为给出的时间，注意访问时间总是会被修改的，不论有几个参数。如果文件不存在，则会被创建。
register_shutdown_function(function() use($config) {
    unlink($config->lockfile);  // 代码执行完删除锁文件
});

$globals = new \stdClass;   // 可理解为新数组
$globals->q = new \SplQueue;// stdClass属性，队列
$globals->expiredManager = new ExpiredFileManager($config->expiredDb, $config->expireMinutes);
for ($i=0; $i<$config->maxConnections; ++$i) {
    $req = new Request;
    $req->setOption('encoding', 'gzip');    // CURLOPT_ENCODING	HTTP请求头中"Accept-Encoding: "的值。 这使得能够解码响应的内容。 支持的编码有"identity"，"deflate"和"gzip"。如果为空字符串""，会发送所有支持的编码类型。	在 cURL 7.10 中被加入。
    $req->setOption('userAgent', 'https://github.com/hirak/packagist-crawler'); // CURLOPT_USERAGENT	在HTTP请求中包含一个"User-Agent: "头的字符串。
    $globals->q->enqueue($req); // 加入队列
}

$globals->mh = new Multi;   // stdClass属性，用于多线程
clearExpiredFiles($globals->expiredManager);    // 清除过期文件

do {
    $globals->retry = false;// stdClass属性，用于终止循环
    $providers = downloadProviders($config, $globals);
    downloadPackages($config, $globals, $providers);
    $globals->retry = checkFiles($config);
    generateHtml($config);
} while ($globals->retry);

flushFiles($config);
exit;

/**
 * packages.json & provider-xxx$xxx.json downloader
 */
function downloadProviders($config, $globals)
{
    $cachedir = $config->cachedir;  // 缓存目录

    $packagesCache = $cachedir . 'packages.json';   // 缓存文件名

    $req = new Request($config->packagistUrl . '/packages.json');   // 请求
    $req->setOption('encoding', 'gzip');    // CURLOPT_ENCODING	HTTP请求头中"Accept-Encoding: "的值。

    $res = $req->send();    // 发送请求

    if (200 === $res->getStatusCode()) {    // 请求成功
        $packages = json_decode($res->getBody());
        foreach (explode(' ', 'notify notify-batch search') as $k) {
            if (0 === strpos($packages->$k, '/')) {
                $packages->$k = 'https://packagist.org' . $packages->$k;
            }
        }
        file_put_contents($packagesCache . '.new', json_encode($packages));
    } else {
        //no changes';
        copy($packagesCache, $packagesCache . '.new');
        $packages = json_decode(file_get_contents($packagesCache));
    }

    if (empty($packages->{'provider-includes'})) {
        throw new \RuntimeException('packages.json schema changed?');
    }

    $providers = [];

    $numberOfProviders = count( (array)$packages->{'provider-includes'} );  // key值里有短横线-，要这样写：{'provider-includes'}
    $progressBar = new ProgressBarManager(0, $numberOfProviders);
    $progressBar->setFormat('Downloading Providers: %current%/%max% [%bar%] %percent%%');

    foreach ($packages->{'provider-includes'} as $tpl => $version) {
        $fileurl = str_replace('%hash%', $version->sha256, $tpl);
        $cachename = $cachedir . $fileurl;
        $providers[] = $cachename;

        if (!file_exists($cachename)){
            $req->setOption('url', $config->packagistUrl . '/' . $fileurl);
            $res = $req->send();

            if (200 === $res->getStatusCode()) {
                $oldcache = $cachedir . str_replace('%hash%.json', '*', $tpl);
                if ($glob = glob($oldcache)) {  // glob 返回一个包含有匹配文件／目录的数组。如果出错返回 FALSE。
                    foreach ($glob as $old) {
                        $globals->expiredManager->add($old, time());
                    }
                }
                if (!file_exists(dirname($cachename))) {    // 目录不存在，则创建，leisi ：/data/packagist-crawler/cache/p
                    mkdir(dirname($cachename), 0777, true);
                }
                file_put_contents($cachename, $res->getBody());
                if ($config->generateGz) {
                    file_put_contents($cachename . '.gz', gzencode($res->getBody()));
                }
            } else {
                $globals->retry = true;
            }
        }

        $progressBar->advance();
    }

    return $providers;
}

/**
 * composer.json downloader
 *
 */
function downloadPackages($config, $globals, $providers)
{
    $cachedir = $config->cachedir;
    $i = 1;
    $numberOfProviders = count($providers);
    $urls = [];

    foreach ($providers as $providerjson) {
        $list = json_decode(file_get_contents($providerjson));
        if (!$list || empty($list->providers)) continue;

        $list = $list->providers;
        $all = count((array)$list);

        $progressBar = new ProgressBarManager(0, $all);
        echo "   - Provider {$i}/{$numberOfProviders}:\n";
        $progressBar->setFormat("      - Package: %current%/%max% [%bar%] %percent%%");

        $sum = 0;
        foreach ($list as $packageName => $provider) {
            $progressBar->advance();
            ++$sum;
            $url = "$config->packagistUrl/p/$packageName\$$provider->sha256.json";
            $cachefile = $cachedir . str_replace("$config->packagistUrl/", '', $url);
            if (file_exists($cachefile)) continue;

            $req = $globals->q->dequeue();
            $req->packageName = $packageName;
            $req->setOption('url', $url);
            $globals->mh->attach($req);
            $globals->mh->start(); //non block

            if (count($globals->q)) continue;

            /** @type Request[] $requests */
            do {
                $requests = $globals->mh->getFinishedResponses(); //block
            } while (0 === count($requests));

            foreach ($requests as $req) {
                $res = $req->getResponse();
                $globals->q->enqueue($req);

                if (200 !== $res->getStatusCode()) {
                    error_log($res->getStatusCode(). "\t". $res->getUrl());
                    $globals->retry = true;
                    continue;
                }

                $cachefile = $cachedir
                    . str_replace("$config->packagistUrl/", '', $res->getUrl());

                if ($glob = glob("{$cachedir}p/$req->packageName\$*")) {
                    foreach ($glob as $old) {
                        $globals->expiredManager->add($old, time());
                    }
                }
                if (!file_exists(dirname($cachefile))) {
                    mkdir(dirname($cachefile), 0777, true);
                }
                file_put_contents($cachefile, $res->getBody());
                if ($config->generateGz) {
                    file_put_contents($cachefile . '.gz', gzencode($res->getBody()));
                }
            }
        }

        ++$i;
    }


    if (0 === count($globals->mh)) return;
    //残りの端数をダウンロード 下载的尾数
    $globals->mh->waitResponse();

    $progressBar = new ProgressBarManager(0, count($globals->mh));
    $progressBar->setFormat("   - Remianed packages: %current%/%max% [%bar%] %percent%%");

    foreach ($globals->mh as $req) {
        $res = $req->getResponse();

        if (200 === $res->getStatusCode()) {
            $cachefile = $cachedir
                . str_replace("$config->packagistUrl/", '', $res->getUrl());
            if ($glob = glob("{$cachedir}p/$req->packageName\$*")) {
                foreach ($glob as $old) {
                    $globals->expiredManager->add($old, time());
                }
            }
            if (!file_exists(dirname($cachefile))) {
                mkdir(dirname($cachefile), 0777, true);
            }
            file_put_contents($cachefile, $res->getBody());
            if ($config->generateGz) {
                file_put_contents($cachefile . '.gz', gzencode($res->getBody()));
            }

        } else {
            $globals->retry = true;
        }

        $progressBar->advance();
    }

}

function flushFiles($config)
{
    rename(
        $config->cachedir . 'packages.json.new',
        $config->cachedir . 'packages.json'
    );
    file_put_contents(
        $config->cachedir . 'packages.json.gz',
        gzencode(file_get_contents($config->cachedir . 'packages.json'))
    );

    error_log('finished! flushing...');
}

/**
 * check sha256
 */
function checkFiles($config)
{
    $cachedir = $config->cachedir;

    $packagejson = json_decode(file_get_contents($cachedir.'packages.json.new'));

    $i = $j = 0;
    foreach ($packagejson->{'provider-includes'} as $tpl => $provider) {
        $providerjson = str_replace('%hash%', $provider->sha256, $tpl);
        $packages = json_decode(file_get_contents($cachedir.$providerjson));

        foreach ($packages->providers as $tpl2 => $sha) {
            if (!file_exists($file = $cachedir . "p/$tpl2\$$sha->sha256.json")) {
                ++$i;
            } elseif ($sha->sha256 !== hash_file('sha256', $file)) {
                ++$i;
                unlink($file);
            } else {
                ++$j;
            }
        }
    }

    error_log($i . ' / ' . ($i + $j));
    return $i;
}

function clearExpiredFiles(ExpiredFileManager $expiredManager)
{
    $expiredFiles = $expiredManager->getExpiredFileList();  // 过期文件列表

    $progressBar = new ProgressBarManager(0, count($expiredFiles));
    $progressBar->setFormat("   - Clearing Expired Files: %current%/%max% [%bar%] %percent%%");

    foreach ($expiredFiles as $file) {
        if (file_exists($file)) {
            unlink($file) and $expiredManager->delete($file);
        } else {
            $expiredManager->delete($file);
        }
        $progressBar->advance();
    }
}

function generateHtml($_config)
{
    $url = $_config->url;
    ob_start(); // 打开输出控制缓冲
    include __DIR__ . '/index.html.php';
    file_put_contents($_config->cachedir . '/index.html', ob_get_clean());
}
