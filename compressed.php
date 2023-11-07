<?phpdeclare(strict_types=1); header("Content-type:text/html;charset=GBK"); use Swoole\Server as TcpServer; use Swoole\Timer as Timer; use Swoole\Runtime as Runtime; class Server { private $server; private $host='0.0.0.0'; private $port=9501; public function __construct() { $this->server = new TcpServer($this->host, $this->port); $this->server->set([ 'worker_num' => 4, 'max_request' => 10000, 'dispatch_mode' => 2, 'daemonize' => 0 ]); $this->server->on('Connect', function ($server, int $fd) { echo sprintf('【%s】Client: Connect, ClientId:【%s】%s', date('Y-m-d H:i:s'), $fd, PHP_EOL); }); $this->server->on('Receive', function ($server, int $fd, $reactor_id, string $data) { echo sprintf('【%s】ClientId:【%s】, Received Data:【%s】%s', date('Y-m-d H:i:s'), $fd, $data, PHP_EOL); Dispatcher::getInstance()->receiveDataRecord($fd, $data); Dispatcher::getInstance()->setTcpServer($server)->dispatch($fd, $data); }); $this->server->on('Close', function ($server, int $fd) { echo sprintf('【%s】Client: Close, ClientId:【%s】%s', date('Y-m-d H:i:s'), $fd, PHP_EOL); Dispatcher::getInstance()->setOffline($fd); }); $this->server->start(); } } new Server(); class Dispatcher { const PAY='pay'; const HEART='heart'; const PING='ping'; const GET_HEX_TEXT='030303474554C716'; const ONLINE=1; const OFFLINE=0; const SUCCESS=1; const FAIL=0; private static $instance; private $tcpServer; private $status; private $time; private $payHexText; private $deviceClientId; private $mysql; private $dsn; private $host="127.0.0.1:3306"; private $username='phreej'; private $password='iottekra1nb0w'; private $db='iot'; private $sql=null; private $outputMsg; private function __construct() { Runtime::enableCoroutine(); $this->connect(); $this->outputMsg = sprintf('【%s】物联网设备TCP分发服务, ', date('Y-m-d H:i:s')); } private function connect() { $this->dsn = sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, 3306, $this->db); try { $this->mysql = new PDO($this->dsn, $this->username, $this->password, [PDO::ATTR_PERSISTENT => true]); $this->mysql->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (Exception $exception) { echo sprintf('【%s】Database Connect Error:【%s】%s', date('Y-m-d H:i:s'), $exception->getMessage(), PHP_EOL); exit; } } public function __destruct() { unset($this->mysql); } private function __clone() { } public static function getInstance() { if (!(self::$instance instanceof self)) { self::$instance = new self(); } return self::$instance; } public function setTcpServer($server) { $this->tcpServer = $server; $this->status = static::FAIL; return $this; } public function dispatch(int $clientId, string $data) { $command = $this->receiveConvert($data); if (is_array($command) && isset($command[0], $command[1])) { list($command, $params) = [$command[0], $command[1]]; } switch ($command) { case static::HEART: break; case static::PING: break; case static::PAY: if (isset($params['outTradeNo'])) { $payment = $this->findPaymentBySn($params['outTradeNo']); if (!empty($payment) && $payment['trxstatus'] == '0000' && $payment['isSuccess'] == 1 && !empty($payment['productId'])) { $product = $this->findProductById(intval($payment['productId'])); if (!empty($product) && !empty($product['bianhao'])) { $this->outputMsg .= sprintf('【设备型号IMEI:%s】支付成功回调发送命令【商户交易单号: %s】。', $product['bianhao'], $params['outTradeNo']); $device = $this->findDeviceByDid($product['bianhao']); if (!empty($device) && !$this->isDispatch($params['outTradeNo'], $product['bianhao'])) { try { $this->time = 1; $this->payHexText = '0303095041595355434345535384A7'; $this->deviceClientId = intval($device['clientId']); Timer::tick(2000, function (int $timer_id) use ($params, $product, $device, $clientId) { if ($this->isDispatch($params['outTradeNo'], $product['bianhao'])) { Timer::clear($timer_id) && $this->tcpServer->send($clientId, static::SUCCESS); } if ($this->isOnline($this->deviceClientId)) { if ($this->isDispatch($params['outTradeNo'], $product['bianhao'])) { Timer::clear($timer_id) && $this->tcpServer->send($clientId, static::SUCCESS); } $receiveResp = $this->tcpServer->send($this->deviceClientId, $this->payHexText); if ($receiveResp && $this->time == 1) { $this->dispatchLog($params['outTradeNo'], $device['deviceId'], $this->deviceClientId, $this->status); } } if ($this->time == 3) { Timer::clear($timer_id) && $this->tcpServer->send($clientId, static::FAIL); } $this->time++; }); } catch (Exception $exception) { $this->outputMsg .= sprintf('报错信息:【%s】, ', $exception->getMessage()); } } } } } break; case self::GET_HEX_TEXT: $dispatch = $this->findDispatchByClientId($clientId); if (!empty($dispatch)) { $this->setDispatch(intval($dispatch['id'])); $this->outputMsg .= sprintf('发送命令结果:【%s】。', $this->status==static::SUCCESS?'成功':'失败'); echo $this->outputMsg, PHP_EOL; } break; default: if (strlen($command) == 15 && is_numeric($command)) { $this->outputMsg .= sprintf('【设备型号IMEI:%s】参数记录【clientId: %d】。', $command, $clientId); $this->deviceRecord($clientId, $command); $this->tcpServer->send($clientId, 'device stored'.PHP_EOL); echo $this->outputMsg, PHP_EOL; } break; } } public function receiveDataRecord(int $clientId, string $data) { if (in_array($data, [static::HEART, static::PING])) { return; } $this->outputMsg .= sprintf('接收客户端数据【clientId: %d, 接收数据: %s】。', $clientId, $data); $date = date('Y-m-d H:i:s'); $this->sql = sprintf("INSERT INTO %s (clientId, data, createdAt, updatedAt) VALUES(%d, %s, %s, %s)", 'iot_device_log', $clientId, "'{$data}'", "'{$date}'", "'{$date}'"); $this->insertOrUpdate($this->sql); } public function setOffline(int $clientId) { $date = date('Y-m-d H:i:s'); $this->sql = sprintf("UPDATE %s SET online=%d,updatedAt=%s WHERE clientId=%d", 'iot_device', self::OFFLINE, "'{$date}'", $clientId); $this->insertOrUpdate($this->sql); } private function receiveConvert(string $string) { $convert = json_decode($string, true); if ($convert == null || $convert == $string) { return $string; } return (array)$convert; } protected function findPaymentBySn(string $outTradeNo) { $sql = sprintf('SELECT %s FROM %s WHERE `reqsn` = %s', 'trxstatus,productId,isSuccess', 'iot_payment', "'{$outTradeNo}'"); return $this->selectRow($sql); } protected function findProductById(int $id) { $sql = sprintf('SELECT %s FROM %s WHERE `id` = %d', 'bianhao', 'iot_product', $id); return $this->selectRow($sql); } protected function findDeviceByDid(string $deviceId) { $sql = sprintf('SELECT %s FROM %s WHERE `deviceId` = %s', 'deviceId,clientId', 'iot_device', "'$deviceId'"); return $this->selectRow($sql); } protected function findDispatchByClientId(int $clientId) { $sql = sprintf("SELECT %s FROM %s WHERE `clientId` = %d AND `status` = %d ORDER BY `id` DESC", 'id', 'iot_dispatch_log', $clientId, static::FAIL); return $this->selectRow($sql); } protected function findDeviceByClientId(int $clientId) { $sql = sprintf('SELECT %s FROM %s WHERE `clientId` = %d', 'online', 'iot_device', $clientId); return $this->selectRow($sql); } protected function isOnline(int $clientId):bool { $device = $this->findDeviceByClientId($clientId); if (!!$device) { return boolval($device['online']); } return false; } protected function isDispatch(string $outTradeNo, string $deviceId):bool { $sql = sprintf('SELECT %s FROM %s WHERE `outTradeNo` = %s AND `deviceId` = %s AND status = %d', 'id', 'iot_dispatch_log', "'$outTradeNo'", "'$deviceId'", static::SUCCESS); return !!$this->selectRow($sql); } protected function setDispatch(int $id) { $sql = sprintf('SELECT %s FROM %s WHERE `id` = %d', 'status', 'iot_dispatch_log', $id); $dispatch = $this->selectRow($sql); if (!!$dispatch && intval($dispatch['status']) == static::FAIL) { $date = date('Y-m-d H:i:s'); $this->sql = sprintf("UPDATE %s SET status=%d,updatedAt=%s WHERE `id` = %d", 'iot_dispatch_log', static::SUCCESS, "'{$date}'", $id); if ($this->insertOrUpdate($this->sql)) { $this->status = static::SUCCESS; } } } protected function dispatchLog(string $outTradeNo, string $deviceId, int $clientId, int $status) { $date = date('Y-m-d H:i:s'); $this->sql = sprintf("INSERT INTO %s (`outTradeNo`, `deviceId`, `clientId`, `status`, `createdAt`, `updatedAt`) VALUES(%s, %s, %s, %d, %s, %s)", 'iot_dispatch_log', "'{$outTradeNo}'", $deviceId, $clientId, $status, "'{$date}'", "'{$date}'"); $this->insertOrUpdate($this->sql); } protected function deviceRecord(int $clientId, string $deviceId) { $device = $this->findDeviceByDid($deviceId); $date = date('Y-m-d H:i:s'); if (!$device) { $this->sql = sprintf('INSERT INTO %s (deviceId, clientId, online, createdAt, updatedAt) VALUES(%s, %d, %d, %s, %s)', 'iot_device', "'{$deviceId}'", $clientId, self::ONLINE, "'{$date}'", "'{$date}'"); } if (isset($device['clientId']) && $clientId != intval($device['clientId'])) { $this->sql = sprintf("UPDATE %s SET clientId=%d,online=%d,updatedAt=%s WHERE deviceId=%s", 'iot_device', $clientId, self::ONLINE, "'{$date}'", "'{$deviceId}'"); } if (!is_null($this->sql)) { $this->insertOrUpdate($this->sql); } } private function isDeviceModel(string $data):bool { return strlen($data) == 15 && is_numeric($data); } private function selectRow(string $sql, int $time=1) { try { return $this->mysql->query($sql)->fetch(PDO::FETCH_ASSOC); } catch (PDOException $exception) { if ($exception->getCode() == 'HY000') { $this->connect(); } $time++; if ($time <= 6) { $this->selectRow($sql, $time); } } } private function insertOrUpdate(string $sql, int $time=1) { $this->outputMsg .= sprintf('执行SQL语句:【%s】, ', $sql); try { $rows = $this->mysql->exec($sql); $this->outputMsg .= sprintf('执行结果:【%s】。', '成功'); return !!$rows; } catch (PDOException $exception) { $this->outputMsg .= sprintf('执行结果:【%s】。', $exception->getMessage()); if ($exception->getCode() == 'HY000') { $this->connect(); } $time++; if ($time <= 6) { $this->insertOrUpdate($sql, $time); } } } } class TcpClient { public function tcpClientCall(string $params) { $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP); socket_connect($socket, '127.0.0.1', 9501); $data = json_encode(['sign', ['data' => $params]]); socket_write($socket, (string)$data, strlen($data)); $returnSet = socket_read($socket, 1024); socket_close($socket); $outputMsg = sprintf('【%s】发送指令到物联网设备【参数: %s; 发送数据: %s】, 发送结果【%s】。', date('Y-m-d H:i:s'), $params, $data, !!$returnSet?'Success':'Fail'); echo $outputMsg, PHP_EOL; unset($socket, $data, $returnSet, $outputMsg); } } (new TcpClient())->tcpClientCall('Welcome To My Life...');