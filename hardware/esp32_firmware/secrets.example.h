// Copy this file to secrets.h (same folder) and fill in your values.
// secrets.h is gitignored: never commit real credentials.

#pragma once

// Broker URL exposed through the Cloudflare Tunnel (WSS = MQTT over secure WebSockets)
#define MQTT_URI "wss://YOUR_MQTT_HOSTNAME:443"

// Shared device account from mosquitto/config/passwd (see README "Broker credentials").
// The ACL only lets a device use topics under its own token, which is also its client id.
#define MQTT_USERNAME "respirosync_device"
#define MQTT_PASSWORD "CHANGE_ME"
