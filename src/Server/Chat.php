<?php
declare(strict_types=1);

namespace App\Server;

use const PHP_EOL;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

/**
 * Class Chat
 * @package App\Server
 */
class Chat implements MessageComponentInterface
{
    protected $connections = [];

    /**
     * Chat constructor.
     */
    public function __construct()
    {

    }

    /**
     * @param ConnectionInterface $conn
     */
    function onOpen(ConnectionInterface $conn): void
    {
       $this->connections[] = $conn;
       $conn->send('Welcome to chat!' . PHP_EOL);
    }

    /**
     * @param ConnectionInterface $conn
     */
    function onClose(ConnectionInterface $conn): void
    {
        foreach ($this->connections as $key => $connection){
            if($connection === $conn){
                unset($this->connections[$key]);
                break;
            }
        }

        $conn->send('Bye, see u soon.' . PHP_EOL);
        $conn->close();
    }

    /**
     * @param ConnectionInterface $conn
     * @param \Exception $e
     */
    function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $conn->send('Error ' . $e->getMessage() . PHP_EOL);
        $conn->close();
    }

    /**
     * @param ConnectionInterface $from
     * @param string $msg
     */
    function onMessage(ConnectionInterface $from, $msg): void
    {
        $messageData = json_decode(trim($msg));

        /**
         * @var ConnectionInterface $connection
         */
        foreach ($this->connections as $connection){
            $connection->send($messageData);
        }
    }
}