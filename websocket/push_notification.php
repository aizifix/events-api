<?php
require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Notification pusher for ZeroMQ internal messaging
 *
 * This script sends notifications to the WebSocket server via ZeroMQ
 */
class NotificationPusher
{
    private $context;
    private $socket;

    /**
     * Initialize ZMQ connection
     */
    public function __construct()
    {
        $this->context = new \ZMQContext();
        $this->socket = $this->context->getSocket(\ZMQ::SOCKET_PUSH);
        $this->socket->connect("tcp://127.0.0.1:5555");
    }

    /**
     * Send notification data to WebSocket server
     *
     * @param array $notificationData The notification data to send
     * @return bool Success status
     */
    public function push(array $notificationData): bool
    {
        try {
            // Validate required fields
            if (empty($notificationData['user_id'])) {
                throw new \Exception('User ID is required for notification');
            }

            if (empty($notificationData['title'])) {
                throw new \Exception('Notification title is required');
            }

            // Send notification to WebSocket server
            $this->socket->send(json_encode($notificationData));

            return true;
        } catch (\Exception $e) {
            error_log('Error sending notification via ZMQ: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Push notification directly from function call
     *
     * @param array $notificationData The notification data to send
     * @return bool Success status
     */
    public static function send(array $notificationData): bool
    {
        $pusher = new self();
        return $pusher->push($notificationData);
    }
}

// Example usage (when called directly)
if (isset($argv[1]) && $argv[1] === 'test') {
    // Test the notification system
    $testData = [
        'user_id' => 1, // Test user ID
        'title' => 'Test Notification',
        'message' => 'This is a test notification from the command line',
        'type' => 'test',
        'priority' => 'medium',
        'timestamp' => date('Y-m-d H:i:s')
    ];

    $result = NotificationPusher::send($testData);
    echo $result ? "Test notification sent successfully\n" : "Failed to send test notification\n";
}
