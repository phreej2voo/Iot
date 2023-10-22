<?php
declare(strict_types=1);
header("Content-type:text/html;charset=GBK");

use Swoole\Server as TcpServer;
use Swoole\Timer as Timer;
use Swoole\Runtime as Runtime;

/**
 * class Tcp Server
 */
class Server
{
    /**
     * @var TcpServer
     */
    private $server;
    /**
     * @var string $host
     */
    private $host='0.0.0.0';
    /**
     * @var int
     */
    private $port=9501;

    public function __construct()
    {
        //创建Server对象, 监听0.0.0.0:9501端口
        $this->server = new TcpServer($this->host, $this->port);
        $this->server->set([
            'worker_num' => 4,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'daemonize' => 0
        ]);

        //监听连接进入事件
        $this->server->on('Connect', function ($server, int $fd) {
            echo sprintf('【%s】Client: Connect, ClientId:【%s】%s', date('Y-m-d H:i:s'), $fd, PHP_EOL);
        });

        //监听数据接收事件
        $this->server->on('Receive', function ($server, int $fd, $reactor_id, string $data) {
            echo sprintf('【%s】ClientId:【%s】, Received Data:【%s】%s', date('Y-m-d H:i:s'), $fd, $data, PHP_EOL);
            //接收客户端数据保存
            Dispatcher::getInstance()->receiveDataRecord($fd, $data);
            //设备业务分发发送指令
            Dispatcher::getInstance()->setTcpServer($server)->dispatch($fd, $data);
        });

        //监听连接关闭事件
        $this->server->on('Close', function ($server, int $fd) {
            echo sprintf('【%s】Client: Close, ClientId:【%s】%s', date('Y-m-d H:i:s'), $fd, PHP_EOL);
            //设备客户端断开下线
            Dispatcher::getInstance()->setOffline($fd);
        });

        //启动服务器
        $this->server->start();
    }
}

new Server();

/**
 * TCP Server分发服务业务类
 */
class Dispatcher
{
    /** @var string  */
    const PAY='pay';
    /** @var string  */
    const HEART='heart';
    /** @var string  */
    const PING='ping';
    /** @var string  */
    const GET_HEX_TEXT='030303474554C716';
    /** @var int  */
    const ONLINE=1;
    /** @var int  */
    const OFFLINE=0;
    /** @var int  */
    const SUCCESS=1;
    /** @var int  */
    const FAIL=0;

    /**
     * @var object
     */
    private static $instance;
    /**
     * @var object
     */
    private $tcpServer;
    /**
     * @var integer
     */
    private $status; //0:发送失败  1:发送成功
    /**
     * @var integer
     */
    private $time;
    /**
     * @var string
     */
    private $payHexText;
    /**
     * @var integer
     */
    private $deviceClientId;
    /**
     * @var object
     */
    private $mysql;
    /**
     * @var string
     */
    private $dsn;
    /**
     * @var string
     */
    private $host="127.0.0.1:3306";
    /**
     * @var string
     */
    private $username='phreej';
    /**
     * @var string
     */
    private $password='iottekra1nb0w';
    /**
     * @var string
     */
    private $db='iot';
    /**
     * @var null
     */
    private $sql=null;
    /**
     * @var string
     */
    private $outputMsg;

    /**
     * 防止外部实例化
     * Constructor
     */
    private function __construct()
    {
        Runtime::enableCoroutine();
        $this->connect();//mysql数据库连接
        $this->outputMsg = sprintf('【%s】物联网设备TCP分发服务, ', date('Y-m-d H:i:s'));
    }

    /**
     * PDO数据库连接
     * @return void
     */
    private function connect()
    {
        $this->dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, 3306, $this->db);
        try {
            $this->mysql = new PDO($this->dsn, $this->username, $this->password, [PDO::ATTR_PERSISTENT => true]);
            $this->mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (Exception $exception) {
            echo sprintf('【%s】Database Connect Error:【%s】%s', date('Y-m-d H:i:s'), $exception->getMessage(), PHP_EOL);
            exit;
        }
    }

    /**
     * 析构方法销毁PDO连接
     * Destructor
     */
    public function __destruct()
    {
        unset($this->mysql);
    }

    /**
     * 防止外部克隆
     * @return void
     */
    private function __clone()
    {

    }

    /**
     * 获取实例方法
     * @return self
     */
    public static function getInstance()
    {
        if (!(self::$instance instanceof self)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * 设置Tcp Server服务
     * @param $server
     * @return $this
     */
    public function setTcpServer($server)
    {
        $this->tcpServer = $server;
        $this->status = static::FAIL;

        return $this;
    }

    /**
     * 监听数据接收事件进行分发
     * @param int $clientId
     * @param string $data
     * @return void
     */
    public function dispatch(int $clientId, string $data)
    {
        /** @var  $command */
        $command = $this->receiveConvert($data);
        //校验接收参数格式进行分发
        if (is_array($command) && isset($command[0], $command[1])) {
            list($command, $params) = [$command[0], $command[1]];
        }

        switch ($command) {
            //发送心跳包保活
            case static::HEART: break;
            case static::PING: break;
            case static::PAY: //扫码支付成功回调发送命令到物联网设备
                if (isset($params['outTradeNo'])) {
                    /** @var  $payment */
                    $payment = $this->findPaymentBySn($params['outTradeNo']);
                    //校验是否支付成功
                    if (!empty($payment) && $payment['trxstatus'] == '0000' && $payment['isSuccess'] == 1 && !empty($payment['productId'])) {
                        /** @var  $product */
                        $product = $this->findProductById(intval($payment['productId']));

                        if (!empty($product) && !empty($product['bianhao'])) {
                            $this->outputMsg .= sprintf('【设备型号IMEI:%s】支付成功回调发送命令【商户交易单号: %s】。', $product['bianhao'], $params['outTradeNo']);
                            /** @var  $device */
                            $device = $this->findDeviceByDid($product['bianhao']);
                             if (!empty($device) && !$this->isDispatch($params['outTradeNo'], $product['bianhao'])) {
                                //发送命令到物联网设备
                                try {
                                    $this->time = 1;
                                    $this->payHexText = '0303095041595355434345535384A7';
                                    $this->deviceClientId = intval($device['clientId']);
                                    //Timer定时器执行
                                    Timer::tick(2000, function (int $timer_id) use ($params, $product, $device, $clientId) {
                                        //已经分发成功结束执行
                                        if ($this->isDispatch($params['outTradeNo'], $product['bianhao'])) {
                                            Timer::clear($timer_id) && $this->tcpServer->send($clientId, static::SUCCESS);
                                        }
                                        //判断设备是否在线,分发日志记录
                                        if ($this->isOnline($this->deviceClientId)) {
                                            //再校验拦截已分发成功结束执行
                                            if ($this->isDispatch($params['outTradeNo'], $product['bianhao'])) {
                                                Timer::clear($timer_id) && $this->tcpServer->send($clientId, static::SUCCESS);
                                            }
                                            $receiveResp = $this->tcpServer->send($this->deviceClientId, $this->payHexText);
                                            if ($receiveResp && $this->time == 1) {
                                                $this->dispatchLog($params['outTradeNo'], $device['deviceId'], $this->deviceClientId, $this->status);
                                            }
                                        }
                                        //重复执行三次结束执行
                                        if ($this->time == 3) {
                                            Timer::clear($timer_id) && $this->tcpServer->send($clientId, static::FAIL);
                                        }

                                        $this->time++;
                                    });
                                } catch (Exception $exception) {
                                    $this->outputMsg .= sprintf('报错信息:【%s】, ', $exception->getMessage());
                                }
                            }
                        }
                    }
                }

                break;
            case self::GET_HEX_TEXT: //接收应答指令
                $dispatch = $this->findDispatchByClientId($clientId);
                if (!empty($dispatch)) {
                    $this->setDispatch(intval($dispatch['id']));
                    $this->outputMsg .= sprintf('发送命令结果:【%s】。', $this->status==static::SUCCESS?'成功':'失败');
                    echo $this->outputMsg, PHP_EOL;
                }

                break;
            default: //设备型号参数记录
                //校验是否设备型号指定长度
                if (strlen($command) == 15 && is_numeric($command)) {
                    $this->outputMsg .= sprintf('【设备型号IMEI:%s】参数记录【clientId: %d】。', $command, $clientId);
                    $this->deviceRecord($clientId, $command);
                    $this->tcpServer->send($clientId, 'device stored'.PHP_EOL);
                    echo $this->outputMsg, PHP_EOL;
                }

                break;
        }
    }

    /**
     * 接收客户端数据保存
     * @param int $clientId
     * @param string $data
     * @return void
     */
    public function receiveDataRecord(int $clientId, string $data)
    {
        if (in_array($data, [static::HEART, static::PING])) {
            return;
        }
        $this->outputMsg .= sprintf('接收客户端数据【clientId: %d, 接收数据: %s】。', $clientId, $data);
        /** @var  $date */
        $date = date('Y-m-d H:i:s');
        $this->sql = sprintf("INSERT INTO %s (clientId, data, createdAt, updatedAt) VALUES(%d, %s, %s, %s)",
            'iot_device_log', $clientId, "'{$data}'", "'{$date}'", "'{$date}'");

        $this->insertOrUpdate($this->sql);
    }

    /**
     * 设备客户端断开下线
     * @param int $clientId
     * @return void
     */
    public function setOffline(int $clientId)
    {
        /** @var  $date */
        $date = date('Y-m-d H:i:s');
        $this->sql = sprintf("UPDATE %s SET online=%d,updatedAt=%s WHERE clientId=%d",
                'iot_device', self::OFFLINE, "'{$date}'", $clientId);

        $this->insertOrUpdate($this->sql);
    }

    /**
     * TCP接收到字符串参数进行转义
     * @param string $string
     * @return array|string
     */
    private function receiveConvert(string $string)
    {
        $convert = json_decode($string, true);
        if ($convert == null || $convert == $string) {
            return $string;
        }

        return (array)$convert;
    }

    /**
     * 根据商户交易单号获取扫码支付数据
     * @param string $outTradeNo
     * @return array|false|null
     */
    protected function findPaymentBySn(string $outTradeNo)
    {
        /** @var  $sql */
        $sql = sprintf('SELECT %s FROM %s WHERE `reqsn` = %s',
            'trxstatus,productId,isSuccess', 'iot_payment', "'{$outTradeNo}'");

        return $this->selectRow($sql);
    }

    /**
     * 根据ID获取设备产品设备编号
     * @param int $id
     * @return array|false|null
     */
    protected function findProductById(int $id)
    {
        /** @var  $sql */
        $sql = sprintf('SELECT %s FROM %s WHERE `id` = %d',
            'bianhao', 'iot_product', $id);

        return $this->selectRow($sql);
    }

    /**
     * 根据设备型号获取设备数据
     * @param string $deviceId
     * @return array|false|null
     */
    protected function findDeviceByDid(string $deviceId)
    {
        /** @var  $sql */
        $sql = sprintf('SELECT %s FROM %s WHERE `deviceId` = %s',
            'deviceId,clientId', 'iot_device', "'$deviceId'");

        return $this->selectRow($sql);
    }

    /**
     * 根据设备客户端TCP唯一标识ID获取设备最新接收到的商户交易单
     * @param int $clientId
     * @return array|false|null
     */
    protected function findDispatchByClientId(int $clientId)
    {
        /** @var  $sql */
        $sql = sprintf("SELECT %s FROM %s WHERE `clientId` = %d AND `status` = %d ORDER BY `id` DESC",
            'id', 'iot_dispatch_log', $clientId, static::FAIL);

        return $this->selectRow($sql);
    }

    /**
     * 根据设备客户端TCP唯一标识ID获取设备在线数据
     * @param int $clientId
     * @return array|false|null
     */
    protected function findDeviceByClientId(int $clientId)
    {
        /** @var  $sql */
        $sql = sprintf('SELECT %s FROM %s WHERE `clientId` = %d',
            'online', 'iot_device', $clientId);

        return $this->selectRow($sql);
    }

    /**
     * 根据设备客户端TCP唯一标识ID判断设备是否在线
     * @param int $clientId
     * @return bool
     */
    protected function isOnline(int $clientId):bool
    {
        /** @var  $device */
        $device = $this->findDeviceByClientId($clientId);
        if (!!$device) {
            return boolval($device['online']);
        }

        return false;
    }

    /**
     * 校验商户交易单号是否已经分发命令到物联网设备
     * @param string $outTradeNo
     * @param string $deviceId
     * @return bool
     */
    protected function isDispatch(string $outTradeNo, string $deviceId):bool
    {
        /** @var  $sql */
        $sql = sprintf('SELECT %s FROM %s WHERE `outTradeNo` = %s AND `deviceId` = %s AND status = %d',
            'id', 'iot_dispatch_log', "'$outTradeNo'", "'$deviceId'", static::SUCCESS);

        return !!$this->selectRow($sql);
    }

    /**
     * 设置分发，更新状态发送成功
     * @param int $id
     * @return void
     */
    protected function setDispatch(int $id)
    {
        /** @var  $sql */
        $sql = sprintf('SELECT %s FROM %s WHERE `id` = %d',
            'status', 'iot_dispatch_log', $id);
        /** @var  $dispatch */
        $dispatch = $this->selectRow($sql);

        if (!!$dispatch && intval($dispatch['status']) == static::FAIL) {
            /** @var  $date */
            $date = date('Y-m-d H:i:s');
            $this->sql = sprintf("UPDATE %s SET status=%d,updatedAt=%s WHERE `id` = %d",
                'iot_dispatch_log', static::SUCCESS, "'{$date}'", $id);

            if ($this->insertOrUpdate($this->sql)) {
                $this->status = static::SUCCESS;
            }
        }
    }

    /**
     * 发送命令到物联网设备分发日志记录
     * @param string $outTradeNo
     * @param string $deviceId
     * @param int $clientId
     * @param int $status
     * @return void
     */
    protected function dispatchLog(string $outTradeNo, string $deviceId, int $clientId, int $status)
    {
        /** @var  $date */
        $date = date('Y-m-d H:i:s');
        $this->sql = sprintf("INSERT INTO %s (`outTradeNo`, `deviceId`, `clientId`, `status`, `createdAt`, `updatedAt`) VALUES(%s, %s, %s, %d, %s, %s)",
            'iot_dispatch_log', "'{$outTradeNo}'", $deviceId, $clientId, $status, "'{$date}'", "'{$date}'");

        $this->insertOrUpdate($this->sql);
    }

    /**
     * 设备参数保存, 设备型号, 客户端唯一标识ID
     * @param int $clientId
     * @param string $deviceId
     * @return void
     */
    protected function deviceRecord(int $clientId, string $deviceId)
    {
        /** @var  $device */
        $device = $this->findDeviceByDid($deviceId);
        /** @var  $date */
        $date = date('Y-m-d H:i:s');

        if (!$device) {
            $this->sql = sprintf('INSERT INTO %s (deviceId, clientId, online, createdAt, updatedAt) VALUES(%s, %d, %d, %s, %s)',
                'iot_device', "'{$deviceId}'", $clientId, self::ONLINE, "'{$date}'", "'{$date}'");
        }
        if (isset($device['clientId']) && $clientId != intval($device['clientId'])) {
            $this->sql = sprintf("UPDATE %s SET clientId=%d,online=%d,updatedAt=%s WHERE deviceId=%s",
                'iot_device', $clientId, self::ONLINE, "'{$date}'", "'{$deviceId}'");
        }

        if (!is_null($this->sql)) {
            $this->insertOrUpdate($this->sql);
        }
    }

    /**
     * 校验参数字符串是否设备型号
     * @param string $data
     * @return bool
     */
    private function isDeviceModel(string $data):bool
    {
        return strlen($data) == 15 && is_numeric($data);
    }

    /**
     * 查询获取单条数据
     * @param string $sql
     * @param int $time
     * @return void
     */
    private function selectRow(string $sql, int $time=1)
    {
        try {
            return $this->mysql->query($sql)->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            if ($exception->getCode() == 'HY000') {
                $this->connect();//数据库重连
            }
            $time++;

            if ($time <= 6) {
                $this->selectRow($sql, $time);
            }
        }
    }

    /**
     * mysql插入单条数据
     * @param string $sql
     * @param int $time
     * @return bool|void
     */
    private function insertOrUpdate(string $sql, int $time=1)
    {
        $this->outputMsg .= sprintf('执行SQL语句:【%s】, ', $sql);
        try {
            $rows = $this->mysql->exec($sql);
            $this->outputMsg .= sprintf('执行结果:【%s】。', '成功');

            return !!$rows;
        } catch (PDOException $exception) {
            $this->outputMsg .= sprintf('执行结果:【%s】。', $exception->getMessage());
            if ($exception->getCode() == 'HY000') {
                $this->connect(); //数据库重连
            }
            $time++;

            if ($time <= 6) {
                $this->insertOrUpdate($sql, $time);
            }
        }
    }
}

/**
 * TCP客户端发送业务类
 */
class TcpClient
{
    /**
     * 生成指定数据格式发送指令给物联网设备TCP服务端
     * @param string $params
     * @return void
     */
    public function tcpClientCall(string $params)
    {
        // 创建Socket对象
        /** @var  $socket */
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        // 绑定Socket配置IP地址和端口Port, 连接至服务器
        socket_connect($socket, '127.0.0.1', 9501);

        // 生成内部指定规则json字符数据格式
        /** @var  $data */
        $data = json_encode(['sign', ['data' => $params]]);
        // 发送数据给服务器
        socket_write($socket, (string)$data, strlen($data));

        // 从服务器接收数据
        /** @var  $returnSet */
        $returnSet = socket_read($socket, 1024);
        // 关闭Socket连接
        socket_close($socket);

        /** @var  $outputMsg */
        $outputMsg = sprintf('【%s】发送指令到物联网设备【参数: %s; 发送数据: %s】, 发送结果【%s】。',
            date('Y-m-d H:i:s'), $params, $data, !!$returnSet?'Success':'Fail');
        echo $outputMsg, PHP_EOL;
        unset($socket, $data, $returnSet, $outputMsg);
    }
}

(new TcpClient())->tcpClientCall('Welcome To My Life...');