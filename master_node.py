# master_node.py - SWDS Meru
# Simulates TTGO ESP32 master node + 5 slave nodes
# Run: python master_node.py
# Place at: C:/xampp_new/htdocs/smart_water/master_node.py

import time
import sys
import random
import logging
import requests
from slave_node import SlaveNode

# CONFIG
SERVER  = "http://localhost/smart_water/api"
API_KEY = "SWDS_VIRT_MASTER_001"

COMMAND_INTERVAL = 10
SENSOR_INTERVAL  = 30
MAX_RETRIES      = 3
SENSOR_RETRIES   = 2    # FIX 2: retry sensor posts too
TIMEOUT_CONNECT  = 5    # FIX 2: separate connect timeout
TIMEOUT_READ     = 8    # FIX 2: separate read timeout

# Logging setup
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s %(message)s',
    datefmt='%H:%M:%S',
    handlers=[
        logging.StreamHandler(sys.stdout),
        logging.FileHandler('master_node.log', encoding='utf-8'),
    ]
)
log = logging.getLogger()

# Zone mapping
ZONE_NAMES = {1:"Zone A", 2:"Zone B", 3:"Zone C", 4:"Zone D", 5:"Zone E"}

# FIX 6: Realistic failure simulation per zone
# Each zone has independent failure probability
ZONE_FAILURE_RATE = {1: 0.05, 2: 0.08, 3: 0.05, 4: 0.10, 5: 0.07}

# Create 5 slave nodes
slaves = {
    1: SlaveNode(zone_id=1, zone_name="Zone A"),
    2: SlaveNode(zone_id=2, zone_name="Zone B"),
    3: SlaveNode(zone_id=3, zone_name="Zone C"),
    4: SlaveNode(zone_id=4, zone_name="Zone D"),
    5: SlaveNode(zone_id=5, zone_name="Zone E"),
}

# Track which slaves are reachable (simulates LoRa connectivity)
slave_reachable = {1: True, 2: True, 3: True, 4: True, 5: True}

retry_counts = {}


# ── FIX 2: HTTP with retry + timeout strategy ─────────────────
def http_get(url, params=None, retries=1):
    for attempt in range(retries + 1):
        try:
            r = requests.get(
                url, params=params,
                timeout=(TIMEOUT_CONNECT, TIMEOUT_READ)
            )
            return r.status_code, r
        except requests.exceptions.ConnectTimeout:
            log.warning("[HTTP] Connect timeout: " + url)
        except requests.exceptions.ReadTimeout:
            log.warning("[HTTP] Read timeout: " + url)
        except requests.exceptions.ConnectionError:
            log.error("[HTTP] Connection refused - is XAMPP running?")
            return 0, None
        except Exception as e:
            log.error("[HTTP] GET error: " + str(e))
        if attempt < retries:
            time.sleep(2)
    return 0, None


def http_post(url, payload, retries=1):
    for attempt in range(retries + 1):
        try:
            r = requests.post(
                url, json=payload,
                timeout=(TIMEOUT_CONNECT, TIMEOUT_READ)
            )
            return r.status_code, r
        except requests.exceptions.ConnectTimeout:
            log.warning("[HTTP] Connect timeout: " + url)
        except requests.exceptions.ReadTimeout:
            log.warning("[HTTP] Read timeout: " + url)
        except requests.exceptions.ConnectionError:
            log.error("[HTTP] Connection refused - is XAMPP running?")
            return 0, None
        except Exception as e:
            log.error("[HTTP] POST error: " + str(e))
        if attempt < retries:
            time.sleep(2)
    return 0, None


def parse_json(r):
    if r is None:
        return None
    try:
        return r.json()
    except Exception:
        log.error("[HTTP] Invalid JSON: " + r.text[:200])
        return None


# ── FIX 6: Simulate realistic LoRa network failures ──────────
def simulate_lora_connectivity():
    for zone_id in slaves:
        failure_rate = ZONE_FAILURE_RATE.get(zone_id, 0.05)
        if random.random() < failure_rate:
            if slave_reachable[zone_id]:
                slave_reachable[zone_id] = False
                log.warning("[LoRa] Zone " + ZONE_NAMES[zone_id] +
                            " signal lost (simulated)")
        else:
            if not slave_reachable[zone_id]:
                slave_reachable[zone_id] = True
                log.info("[LoRa] Zone " + ZONE_NAMES[zone_id] +
                         " signal restored")


# ── Connection test ───────────────────────────────────────────
def test_connection():
    log.info("[MASTER] Testing server connection...")
    code, r = http_get(
        SERVER + "/get_command.php",
        params={"api_key": API_KEY}
    )
    if code == 200:
        data = parse_json(r)
        if data and data.get("status") == "ok":
            log.info("[MASTER] Server OK - device: " +
                     str(data.get("device", "?")))
            return True
        else:
            msg = data.get("message", "?") if data else "No response"
            log.error("[MASTER] Auth failed: " + str(msg))
            log.error("[MASTER] Check api_key='" + API_KEY +
                      "' in hardware_devices table")
            return False
    elif code == 0:
        log.error("[MASTER] Cannot connect - start XAMPP Apache + MySQL")
        return False
    else:
        log.error("[MASTER] HTTP " + str(code))
        return False


# ── Get commands ──────────────────────────────────────────────
def get_commands():
    code, r = http_get(
        SERVER + "/get_command.php",
        params={"api_key": API_KEY}
    )
    if code != 200:
        return []
    data = parse_json(r)
    if not data:
        return []
    if data.get("status") == "ok":
        return data.get("commands", [])
    log.error("[MASTER] get_command: " + str(data.get("message", "?")))
    return []


# ── Send ACK ──────────────────────────────────────────────────
def send_ack(command_id, status, valve_pct, zone_id=0):
    code, r = http_post(
        SERVER + "/ack_command.php",
        {
            "api_key":    API_KEY,
            "command_id": command_id,
            "status":     status,
            "valve_pct":  valve_pct,
            "zone_id":    zone_id,   # FIX: include zone_id so correct zone updates
        }
    )
    if code == 200:
        data = parse_json(r)
        if data and data.get("status") == "ok":
            icon = "OK" if status == "acknowledged" else "FAIL"
            log.info("[MASTER] ACK " + icon + " cmd=" +
                     str(command_id) + " " + status +
                     " pct=" + str(valve_pct) + "%")
        else:
            log.error("[MASTER] ACK error: " +
                      str(data.get("message", "?") if data else "no response"))
    else:
        log.error("[MASTER] ACK HTTP " + str(code))


# ── FIX 1: Send sensor data (telemetry only) ─────────────────
def send_sensor_data(data):
    payload = dict(data)
    payload["api_key"] = API_KEY
    zone_name = ZONE_NAMES.get(data["zone_id"], str(data["zone_id"]))

    # FIX 2: retry sensor posts up to SENSOR_RETRIES times
    code, r = http_post(
        SERVER + "/sensor_data.php",
        payload,
        retries=SENSOR_RETRIES
    )
    if code == 200:
        result = parse_json(r)
        if result and result.get("status") == "ok":
            log.info("[MASTER] Sensor OK " + zone_name +
                     " flow=" + str(data["flow_rate"]) + " L/min" +
                     " level=" + str(data["water_level"]) + "%")
            return True
        else:
            log.error("[MASTER] Sensor error " + zone_name + ": " +
                      str(result.get("message", "?") if result else "no response"))
    else:
        log.error("[MASTER] Sensor HTTP " + str(code) + " " + zone_name)
    return False


# ── FIX 1: Separate device status update ─────────────────────
def update_device_status(zone_id, online):
    http_post(
        SERVER + "/update_device_status.php",
        {
            "api_key": API_KEY,
            "zone_id": zone_id,
            "online":  online,
        }
    )


# ── LoRa simulation ───────────────────────────────────────────
def lora_send(zone_id, command, valve_pct):
    zone_name = ZONE_NAMES.get(zone_id, str(zone_id))

    # FIX 6: Check LoRa connectivity first
    if not slave_reachable.get(zone_id, True):
        log.warning("[LoRa] TX FAILED - " + zone_name +
                    " unreachable (signal lost)")
        return {"success": False, "result": "failed", "valve_pct": 0}

    log.info("[LoRa] TX -> Slave " + zone_name +
             ": '" + str(zone_id) + ":" + command + "'")
    slave = slaves.get(zone_id)
    if not slave:
        log.error("[LoRa] No slave for " + zone_name)
        return {"success": False, "result": "failed", "valve_pct": 0}

    result = slave.execute_command(command, valve_pct)
    log.info("[LoRa] RX <- Slave " + zone_name +
             ": '" + str(zone_id) + ":ack:" + result["result"] + "'")

    # Update device status after response
    update_device_status(zone_id, result["success"])
    return result


# ── Print zone status table ───────────────────────────────────
def print_status():
    print("")
    print("-" * 62)
    print("  SWDS Meru - Zone Status")
    print("-" * 62)
    print("  Zone       Valve     Flow(L/m)  Level(%)  LoRa")
    print("  " + "-" * 58)
    for zone_id, slave in sorted(slaves.items()):
        name    = ZONE_NAMES.get(zone_id, str(zone_id))
        valve   = "OPEN  " if slave.valve_open else "CLOSED"
        flow    = str(round(slave.base_flow if slave.valve_open else 0.1, 1))
        level   = str(round(slave.water_level, 1))
        lora    = "OK" if slave_reachable.get(zone_id, True) else "LOST"
        print("  " + name.ljust(10) + " " + valve + "  " +
              flow.rjust(9) + "  " + level.rjust(8) + "  " + lora)
    print("-" * 62)
    print("")


# ── Main loop ─────────────────────────────────────────────────
def sync_valve_states():
    """On startup fetch current valve states from dashboard and sync slaves."""
    print("[MASTER] ============================================")
    print("[MASTER] Syncing valve states from dashboard...")
    url = SERVER + "/zone_states.php"
    print("[MASTER] Fetching: " + url)
    code, r = http_get(url, params={"api_key": API_KEY})
    print("[MASTER] HTTP response: " + str(code))

    if code == 200 and r:
        data = parse_json(r)
        if data and data.get("status") == "ok":
            zones = data.get("zones", [])
            print("[MASTER] Got " + str(len(zones)) + " zones from server")
            synced = 0
            for zone in zones:
                zid     = int(zone.get("id", 0))
                vstatus = str(zone.get("valve_status", "CLOSED")).upper()
                if zid in slaves:
                    slaves[zid].valve_open = (vstatus == "OPEN")
                    slaves[zid].valve_pct  = 100 if vstatus == "OPEN" else 0
                    synced += 1
                    print("[MASTER] " + ZONE_NAMES.get(zid, str(zid)) +
                          " -> valve=" + vstatus)
            print("[MASTER] Synced " + str(synced) + " zones")
            print("[MASTER] ============================================")
            return True
        else:
            print("[MASTER] Bad response: " + str(data))
    else:
        try:
            print("[MASTER] Response text: " + (r.text[:200] if r else "None"))
        except Exception:
            pass

    print("[MASTER] Sync FAILED - all slaves stay CLOSED")
    print("[MASTER] ============================================")
    return False


def main_loop():
    print("")
    print("=" * 55)
    print("  SWDS Meru - Master Node (Python simulation)")
    print("=" * 55)
    print("  Server:  " + SERVER)
    print("  API key: " + API_KEY)
    print("  Zones:   " + ", ".join(ZONE_NAMES.values()))
    print("  Log:     master_node.log")
    print("=" * 55)
    print("")

    if not test_connection():
        log.error("[MASTER] Fix errors above then run again")
        sys.exit(1)

    # Sync valve states from dashboard on startup
    sync_valve_states()

    log.info("[MASTER] Running - press Ctrl+C to stop")
    print("")

    last_sensor  = 0
    last_status  = 0
    last_lora_check = 0

    while True:
        now = time.time()

        # FIX 6: Simulate LoRa connectivity changes every 60s
        if now - last_lora_check >= 60:
            last_lora_check = now
            simulate_lora_connectivity()

        # Poll commands every 10s
        log.info("[MASTER] --- Polling commands ---")
        commands = get_commands()

        if not commands:
            log.info("[MASTER] No pending commands")
        else:
            log.info("[MASTER] " + str(len(commands)) +
                     " command(s) received")
            for cmd in commands:
                command_id = int(cmd["id"])
                zone_id    = int(cmd["zone_id"])
                command    = cmd["command"]
                valve_pct  = int(cmd.get("valve_pct",
                                 100 if command == "open" else 0))
                retry      = int(cmd.get("retry_count", 0))
                zone_name  = ZONE_NAMES.get(zone_id, str(zone_id))

                msg = ("[MASTER] Command #" + str(command_id) +
                       ": " + zone_name +
                       " action=" + command.upper() +
                       " pct=" + str(valve_pct) + "%")
                if retry > 0:
                    msg += " (retry #" + str(retry) + ")"
                log.info(msg)

                result     = lora_send(zone_id, command, valve_pct)
                ack_status = "acknowledged" if result["success"] else "failed"
                send_ack(command_id, ack_status, result["valve_pct"], zone_id)

                if not result["success"]:
                    retry_counts[command_id] = \
                        retry_counts.get(command_id, 0) + 1
                    if retry_counts[command_id] >= MAX_RETRIES:
                        log.error("[MASTER] Command #" + str(command_id) +
                                  " permanently failed after " +
                                  str(MAX_RETRIES) + " retries")
                else:
                    retry_counts.pop(command_id, None)

        # Post sensor data every 30s
        if now - last_sensor >= SENSOR_INTERVAL:
            last_sensor = now
            log.info("")
            log.info("[MASTER] --- Posting sensor data ---")
            for slave in slaves.values():
                zone_id = slave.zone_id

                # FIX 6: Skip unreachable slaves
                if not slave_reachable.get(zone_id, True):
                    log.warning("[MASTER] Skipping " +
                                ZONE_NAMES.get(zone_id, str(zone_id)) +
                                " - LoRa unreachable")
                    # FIX 1: Update device status separately
                    update_device_status(zone_id, False)
                    continue

                data = slave.generate_sensor_data()
                # FIX 2: Retry sensor post
                success = send_sensor_data(data)
                if not success:
                    log.warning("[MASTER] Will retry next cycle")
                time.sleep(0.3)
            log.info("")

        # Print status every 60s
        if now - last_status >= 60:
            last_status = now
            print_status()

        time.sleep(COMMAND_INTERVAL)


if __name__ == "__main__":
    try:
        import requests
    except ImportError:
        print("Run: pip install requests")
        sys.exit(1)

    try:
        main_loop()
    except KeyboardInterrupt:
        print("")
        log.info("[MASTER] Stopped by user")
        print_status()