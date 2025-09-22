#!/bin/bash
echo "Starting WebSocket notification server..."
cd "$(dirname "$0")"
php server.php
