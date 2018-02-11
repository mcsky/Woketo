<?php


require_once __DIR__ . '/../../vendor/autoload.php';

class MyMessageHandler implements \Nekland\Woketo\Message\MessageHandlerInterface
{
    public function onConnection(\Nekland\Woketo\Core\AbstractConnection $connection)
    {
        // This method is called when a new client is connected to your server
        $connection->write('yolo');
    }

    public function onMessage(string $data, \Nekland\Woketo\Core\AbstractConnection $connection)
    {
        // This method is called when a text message is sent
    }

    public function onBinary(string $data, \Nekland\Woketo\Core\AbstractConnection $connection)
    {
        // This method is called when a binary message is sent
    }

    public function onError(\Nekland\Woketo\Exception\WebsocketException $e, \Nekland\Woketo\Core\AbstractConnection $connection)
    {
        // This method is called when an error occurs
    }
}

$client = new \Nekland\Woketo\Client\WebSocketClient('ws://127.0.0.1:9001', []);
$client->start(new MyMessageHandler);
