<?php
declare(strict_types=1);

namespace App\Command;

use App\Server\Chat;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class ChatServer
 * @package App\Command
 */
class ChatServer extends ContainerAwareCommand
{
    protected function configure()
    {
        $this->setName('chat:start')
            ->setDescription('Starts chat server');
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null|void
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $chatServer = IoServer::factory(new HttpServer(new WsServer(new Chat())), 8080);
        $chatServer->run();
    }
}