<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Factory;
use React\ZMQ\Context;
use React\Socket\Server as SocketServer;

/**
 * WebSocket notification server for Noreen Event System
 *
 * This server handles real-time notifications via WebSockets.
 * It uses ZeroMQ for internal communication from the main PHP app.
 */
class NotificationServer implements MessageComponentInterface
{
    protected $clients;
    protected $userConnections;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->userConnections = [];
        echo "Notification server started!\n";
    }

    public function onOpen(ConnectionInterface $conn)
    {
        // Store the new connection
        $this->clients->attach($conn);

        $queryString = $conn->httpRequest->getUri()->getQuery();
        parse_str($queryString, $params);

        // Store user ID with connection if provided
        if (!empty($params['user_id'])) {
            $userId = (int) $params['user_id'];
            $userType = $params['user_type'] ?? 'client';

            if (!isset($this->userConnections[$userId])) {
                $this->userConnections[$userId] = [];
            }

            $this->userConnections[$userId][$conn->resourceId] = [
                'conn' => $conn,
                'type' => $userType
            ];

            echo "New connection ({$conn->resourceId}) established for user {$userId} of type {$userType}\n";
        } else {
            echo "New connection ({$conn->resourceId}) established without user ID\n";
        }
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        // We don't expect clients to send messages to the server in this implementation
        // This would primarily be used for WebSocket heartbeat or client-side events if needed
        echo "Received message from client {$from->resourceId}: {$msg}\n";
    }

    public function onClose(ConnectionInterface $conn)
    {
        // Remove connection from general clients list
        $this->clients->detach($conn);

        // Remove from user connections if present
        foreach ($this->userConnections as $userId => $connections) {
            if (isset($connections[$conn->resourceId])) {
                unset($this->userConnections[$userId][$conn->resourceId]);
                echo "Connection {$conn->resourceId} for user {$userId} has disconnected\n";

                // Clean up empty user entries
                if (empty($this->userConnections[$userId])) {
                    unset($this->userConnections[$userId]);
                }

                break;
            }
        }
    }

    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }

    /**
     * Process and broadcast notification data received from ZMQ
     */
    public function broadcastNotification($data)
    {
        $data = json_decode($data, true);

        // Validate notification data
        if (!$data || !isset($data['user_id'])) {
            echo "Invalid notification data received\n";
            return;
        }

        $userId = (int) $data['user_id'];
        $notificationType = $data['type'] ?? 'general';

        echo "Broadcasting notification for user {$userId} of type {$notificationType}\n";

        // Check if user has active connections
        if (isset($this->userConnections[$userId])) {
            $payload = json_encode([
                'type' => 'notification',
                'data' => $data
            ]);

            // Send to all connections for this user
            foreach ($this->userConnections[$userId] as $resourceId => $connInfo) {
                $connInfo['conn']->send($payload);
                echo "Sent notification to connection {$resourceId}\n";
            }
        }

        // Handle notifications that may need to be sent to admins/organizers
        if (in_array($notificationType, ['booking_created', 'payment_received', 'event_update'])) {
            $this->broadcastToAdmins($data);
        }
    }

    /**
     * Broadcast certain notifications to admins/organizers
     */
    private function broadcastToAdmins($data)
    {
        $payload = json_encode([
            'type' => 'admin_notification',
            'data' => $data
        ]);

        // Find all admin/organizer connections
        foreach ($this->userConnections as $userId => $connections) {
            foreach ($connections as $resourceId => $connInfo) {
                if (in_array($connInfo['type'], ['admin', 'organizer'])) {
                    $connInfo['conn']->send($payload);
                    echo "Sent admin notification to {$connInfo['type']} (user {$userId}, connection {$resourceId})\n";
                }
            }
        }
    }
}

// Set up event loop
$loop = Factory::create();

// Create WebSocket server with more detailed output
$webSock = new SocketServer('0.0.0.0:8080', $loop);
$notificationServer = new NotificationServer();

// Set up CORS headers to allow connections from any origin
$wsServer = new WsServer($notificationServer);
$wsServer->enableKeepAlive($loop, 30); // Enable ping/pong to keep connections alive

// Create HTTP server with origin checking for security
$httpServer = new \Ratchet\Http\HttpServer($wsServer);
$httpServer->allowedOrigins = ['localhost', '127.0.0.1']; // Allow connections from these origins

// Create server
$webServer = new IoServer(
    $httpServer,
    $webSock
);

echo "WebSocket server listening on 0.0.0.0:8080\n";
echo "Ready to accept connections...\n";

// Set up ZMQ socket for internal communication
$context = new Context($loop);
$pull = $context->getSocket(\ZMQ::SOCKET_PULL);
$pull->bind('tcp://127.0.0.1:5555');

// Listen for ZMQ messages from PHP application
$pull->on('message', function($message) use ($notificationServer) {
    echo "Received message from ZMQ: {$message}\n";
    $notificationServer->broadcastNotification($message);
});

echo "Starting WebSocket server on port 8080...\n";
$loop->run();
