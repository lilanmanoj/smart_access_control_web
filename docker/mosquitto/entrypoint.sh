#!/bin/sh
#
# First boot of the MQTT broker: create the dynamic-security file with the
# backend's admin login, then run Mosquitto.
#
# The admin login comes from MQTT_ADMIN_USERNAME / MQTT_ADMIN_PASSWORD, the
# same values the backend reads from .env. It is written only when the file
# does not exist yet. Changing the password in .env later does NOT change it
# on the broker; see "Rotating the broker admin password" in
# docs/deploy/aws-ec2.md.
#
set -eu

DYNSEC=/mosquitto/data/dynamic-security.json

if [ ! -s "$DYNSEC" ]; then
    : "${MQTT_ADMIN_USERNAME:?MQTT_ADMIN_USERNAME must be set}"
    : "${MQTT_ADMIN_PASSWORD:?MQTT_ADMIN_PASSWORD must be set}"

    echo "[mosquitto] first boot: creating dynamic-security admin '${MQTT_ADMIN_USERNAME}'"
    mosquitto_ctrl dynsec init "$DYNSEC" "$MQTT_ADMIN_USERNAME" "$MQTT_ADMIN_PASSWORD" >/dev/null
fi

chown -R mosquitto:mosquitto /mosquitto/data

# Mosquitto drops to the mosquitto user itself once the listener is open.
exec mosquitto -c /mosquitto/config/mosquitto.conf
