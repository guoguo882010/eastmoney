<?php

namespace GUOGUO882010\EastMoney;

class A
{
    public static function dd($page = 1, $page_size = 100)
    {
        $a = new HttpClient();
        $r = $a->request('get', "https://push2.eastmoney.com/api/qt/clist/get?np=1&fltt=1&invt=2&cb=&fs=m:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23,m:0+t:81+s:2048&fields=f12,f14&fid=f3&pn={$page}&pz={$page_size}&po=1&dect=1&ut=fa5fd1943c7b386f172d6893dbfba10b&wbp2u=|0|0|0|web&_=1758088042869");

        $body = json_decode($r['body'], true);

        $data = $body['data']['diff'] ?? [];

        if (!empty($data)) {
            $data = array_map(function ($item) {
                return [
                    'name' => $item['f14'],
                    'code' => $item['f12'],
                ];
            }, $data);
        }

        return $data;

    }

    public static function dd2($code,$market)
    {
        $a = new HttpClient();
        $r = $a->request('get', "https://datacenter.eastmoney.com/securities/api/data/v1/get?reportName=RPT_ORG_CHANGENAME&columns=SECURITY_INNER_CODE,SECURITY_CODE,SECUCODE,CHANGE_AFTER,CHANGE_DATE&filter=(SECUCODE=%22{$code}.{$market}%22)&client=APP&source=SECURITIES&pageNumber=1&pageSize=200&sortTypes=1&sortColumns=CHANGE_DATE&rdm=rnd_5E1162775E3040D38D0020740BB6D7E1&v=018980948673646003");

        $body = json_decode($r['body'], true);

        $data = $body['result']['data'] ?? [];

        if (!empty($data)) {
            $data = array_map(function ($item) {
                return [
                    'change_date' => $item['CHANGE_DATE'],
                    'name'        => $item['CHANGE_AFTER'],
                    'code'        => $item['SECURITY_CODE'],
                ];
            }, $data);
        }

        return $data;
    }

    public static function xx()
    {
        $filename = __DIR__ . "/s.txt";

        for ($i = 1; $i <= 58; $i++) {
            $data = self::dd($i);

            $content = '';

            foreach ($data as $v) {
                $content .= self::getMarketByCode($v['code']) . '|' . $v['name'] . '|' . $v['code'] . "\n";
            }

            // FILE_APPEND 表示追加而不是覆盖
            // LOCK_EX 表示写入时加独占锁，避免并发冲突
            file_put_contents($filename, $content, FILE_APPEND | LOCK_EX);

            sleep(1);
        }
    }

    public static function xx2()
    {
        $all = self::getAllCode();

        foreach ($all as $v) {
            dump($v[2]);
            $data = self::dd2($v[2], $v[0]);

            if (!empty($data)) {
                foreach ($data as $item) {
                    self::writeChangeName("{$item['code']}|{$item['name']}|{$item['change_date']}\n");
                }
            }

            sleep(1);
        }
    }

    public static function writeChangeName($content)
    {
        $filename = __DIR__ . "/change_name.txt";

        file_put_contents($filename, $content, FILE_APPEND | LOCK_EX);
    }

    public static function getAllCode()
    {
        $filename = __DIR__ . "/s.txt";

        // 逐行读取文件
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        $data = [];

        foreach ($lines as $line) {
            // 用 | 分割
            $data[] = explode("|", $line);

            // $data[0] = 市场 (SH/SZ)
            // $data[1] = 公司名称
            // $data[2] = 股票代码
        }

        return $data;
    }

    public static function getMarketByCode(string $code): string
    {
        // 确保是6位或以上数字（有些北交所是 8 位）
        $prefix = substr($code, 0, 3);

        // 上海证券交易所
        if (in_array($prefix, ['600', '601', '603', '605', '688', '689', '730', '787', '113', '110', '510', '511'])) {
            return 'SH'; // 上海
        }

        // 深圳证券交易所
        if (in_array($prefix, ['000', '001', '002', '003', '300', '301', '302', '127', '159', '150', '16', '18'])) {
            return 'SZ'; // 深圳
        }

        // 北京证券交易所（注意部分是 4 位 / 8 位代码）
        $prefix2 = substr($code, 0, 2); // 处理 43, 83, 87, 88, 89
        if (in_array($prefix2, ['43', '81', '83', '87', '88', '89', '92'])) {
            return 'BJ'; // 北京
        }

        return 'UNKNOWN'; // 未识别
    }
}