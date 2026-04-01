#!/usr/bin/env python3
"""
virtual_esp32.py — SWDS Meru
============================================================
Virtual ESP32 simulator — runs entirely in Python.
No hardware needed. Simulates the full IoT pipeline:

  Slave nodes  → flow readings + valve control
  Master node  → HTTP calls to dashboard API
  LoRa         → simulated with in-process messaging

WHAT IT DOES:
  1. Simulates 5 slave nodes (Zone A-E) with realistic flow data
  2. Master polls get_command.php every 10 seconds
  3. Master forwards commands to virtual slaves
  4. Slaves execute valve open/close with realistic delay
  5. Slaves send ACK back to master
  6. Master POSTs ACK to ack_command.php
  7. Master POSTs sensor data to sensor_data.php every 30s
  8. Master runs auto_control.php every 60s
  9. Full colored console output showing everything live

SETUP:
  pip install requests colorama

RUN:
  python virtual_esp32.py

CONFIGURATION:
  Edit SERVER_IP and API_KEY below before running.
  API_KEY must match a registered device in hardware_devices table.
============================================================
"""

import requests
import time
import random
import threading
import json
import sys
import math
from datetime import datetime
from colorama import init, Fore, Style

init(autoreset=True)

# ── CONFIGURATION ─────────────────────────────────────────────
SERVER_IP  = "http://localhost"           # your XAMPP server
BASE_URL   = SERVER_IP + "/smart_water"
API_KEY    = "SWDS_MASTER_KEY_001"       # must match hardware_devices table

# Endpoint URLs
SENSOR_URL    = BASE_URL + "/api/sensor_data.php"
GET_CMD_URL   = BASE_URL + "/api/get_command.php"
ACK_CMD_URL   = BASE_URL + "/api/ack_command.php"
AUTO_URL      = BASE_URL + "/api/auto_control.php"
REGISTER_URL  = BASE_URL + "/api/register_device.php"

# Timing (seconds)
SENSOR_INTERVAL  = 30    # post sensor data every 30s
COMMAND_INTERVAL = 10    # poll commands every 10s
AUTO_INTERVAL    = 60    # run automation every 60s

# ── LOGGING HELPERS ───────────────────────────────────────────
def log(msg, color=Fore.WHITE):
    ts = datetime.now().strftime("%H:%M:%S")
    print(f"{Fore.CYAN}[{ts}]{Style.RESET_ALL} {color}{msg}{Style.RESET_ALL}")

def log_master(msg):
    log(f"[MASTER] {msg}", Fore.BLUE)

def log_slave(zone_id, msg):
    colors = {1:Fore.GREEN, 2:Fore.YELLOW, 3:Fore.MAGENTA,
              4:Fore.CYAN,  5:Fore.RED}
    log(f"[SLAVE Z{zone_id}] {msg}", colors.get(zone_id, Fore.WHITE))

def log_lora(msg):
    log(f"[LoRa] {msg}", Fore.YELLOW)

def log_http(method, url, status, msg=""):
    color = Fore.GREEN if status == 200 else Fore.RED
    log(f"[HTTP] {method} {url.split('/')[-1]} → {status} {msg}", color)

def log_error(msg):
    log(f"[ERROR] {msg}", Fore.RED)

def log_success(msg):
    log(f"[OK] {msg}", Fore.GREEN)

# ══════════════════════════════════════════════════════════════
#  SLAVE NODE
#  Simulates a physical ESP32 + flow sensor + valve relay
# ══════════════════════════════════════════════════════════════
class SlaveNode:
    def __init__(self, zone_id: int, zone_name: str):
        self.zone_id      = zone_id
        self.zone_name    = zone_name
        self.valve_open   = False
        self.base_flow    = 30.0 + (zone_id * 4.0)  # each zone different
        self.flow_rate    = self.base_flow
        self.total_litres = random.uniform(500, 2000)
        self.lock         = threading.Lock()

        # Simulate realistic flow patterns
        self.time_offset  = zone_id * 1.3

    def get_flow_rate(self) -> float:
        """Generate realistic flow with time-of-day variation."""
        if not self.valve_open:
            # Small leak simulation even when closed
            return round(random.uniform(0.0, 0.3), 2)

        # Sine wave for daily pattern + random noise
        t = time.time() / 3600.0  # hours
        daily_pattern = 1.0 + 0.3 * math.sin(
            (t + self.time_offset) * math.pi / 12
        )
        noise = random.uniform(-3.0, 3.0)
        flow  = max(0, self.base_flow * daily_pattern + noise)
        return round(flow, 2)

    def get_water_level(self) -> float:
        """Simulate water level based on flow."""
        if self.valve_open:
            # Level drops slightly when flowing
            return round(random.uniform(75.0, 95.0), 1)
        else:
            # Level stable when closed
            return round(random.uniform(88.0, 99.0), 1)

    def update_readings(self):
        """Update flow and total litres."""
        with self.lock:
            self.flow_rate = self.get_flow_rate()
            # Add to total litres (30s interval)
            self.total_litres += self.flow_rate * (SENSOR_INTERVAL / 60.0)
            self.total_litres  = round(self.total_litres, 1)

    def execute_command(self, command: str) -> dict:
        """
        Execute a valve command with realistic delay.
        Returns: {"success": bool, "result": "open"/"close"/"failed"}
        """
        log_slave(self.zone_id,
                  f"Received command: {command.upper()}")

        # Simulate mechanical delay (valve takes time to open/close)
        delay = random.uniform(0.5, 2.0)
        log_slave(self.zone_id,
                  f"Executing... (valve actuator delay {delay:.1f}s)")
        time.sleep(delay)

        # Simulate 95% success rate (realistic)
        if random.random() < 0.95:
            if command == "open":
                with self.lock:
                    self.valve_open = True
                log_slave(self.zone_id,
                          f"Valve OPENED ✓ (flow will increase)")
                return {"success": True, "result": "open",
                        "valve_pct": 100}
            elif command == "close":
                with self.lock:
                    self.valve_open = False
                log_slave(self.zone_id,
                          f"Valve CLOSED ✓ (flow stopping)")
                return {"success": True, "result": "close",
                        "valve_pct": 0}
            else:
                log_slave(self.zone_id,
                          f"Unknown command: {command}")
                return {"success": False, "result": "failed",
                        "valve_pct": 0}
        else:
            # Simulated hardware failure
            log_slave(self.zone_id,
                      f"Hardware fault — valve actuator not responding")
            return {"success": False, "result": "failed",
                    "valve_pct": 0}

    def status(self) -> dict:
        with self.lock:
            return {
                "zone_id":      self.zone_id,
                "zone_name":    self.zone_name,
                "valve":        "OPEN" if self.valve_open else "CLOSED",
                "flow_rate":    self.flow_rate,
                "total_litres": self.total_litres,
                "water_level":  self.get_water_level(),
            }

# ══════════════════════════════════════════════════════════════
#  MASTER NODE
#  Simulates the TTGO ESP32 master node
# ══════════════════════════════════════════════════════════════
class MasterNode:
    def __init__(self, slaves: dict):
        self.slaves         = slaves   # {zone_id: SlaveNode}
        self.api_key        = API_KEY
        self.packets_sent   = 0
        self.packets_recv   = 0
        self.commands_exec  = 0
        self.running        = True

        # Pending ACKs: {command_id: {zone_id, command, retry_count}}
        self.pending_acks   = {}
        self.lock           = threading.Lock()

    # ── HTTP helpers ─────────────────────────────────────────
    def http_get(self, url, params=None, timeout=8):
        try:
            r = requests.get(url, params=params, timeout=timeout)
            return r.status_code, r.json()
        except requests.exceptions.ConnectionError:
            log_error(f"Cannot connect to {url}")
            log_error("Is XAMPP running? Is Apache started?")
            return 0, {}
        except requests.exceptions.Timeout:
            log_error(f"Timeout: {url}")
            return 0, {}
        except Exception as e:
            log_error(f"GET {url}: {e}")
            return 0, {}

    def http_post(self, url, data, timeout=8):
        try:
            r = requests.post(url, json=data, timeout=timeout)
            return r.status_code, r.json()
        except requests.exceptions.ConnectionError:
            log_error(f"Cannot connect to {url}")
            return 0, {}
        except requests.exceptions.Timeout:
            log_error(f"Timeout: {url}")
            return 0, {}
        except Exception as e:
            log_error(f"POST {url}: {e}")
            return 0, {}

    # ── Self-registration ────────────────────────────────────
    def test_connection(self):
        """Test server connection before starting."""
        log_master("Testing connection to SWDS server...")
        try:
            r = requests.get(BASE_URL + "/db.php", timeout=5)
            # Any response means server is reachable
            log_success(f"Server reachable at {BASE_URL}")
            return True
        except requests.exceptions.ConnectionError:
            log_error(f"Cannot reach {BASE_URL}")
            log_error("Fix: Make sure XAMPP Apache + MySQL are running")
            log_error(f"Fix: Check SERVER_IP = '{SERVER_IP}' is correct")
            log_error("     Run ipconfig in CMD to find your IP")
            return False
        except Exception as e:
            log_error(f"Connection test failed: {e}")
            return False

    def register(self):
        """Register this virtual device with the dashboard."""
        log_master("Registering with SWDS dashboard...")
        payload = {
            "register_token": "SWDS_REG_2024",
            "device_name":    "Virtual-Master-01",
            "device_type":    "master_node",
            "hardware_id":    "VIRT-ESP32-MASTER-001",
            "zone_id":        1,
            "firmware_ver":   "1.0.0-virtual",
            "api_key_hint":   API_KEY,
        }
        code, resp = self.http_post(REGISTER_URL, payload)
        if code == 200:
            status = resp.get("status", "")
            if status in ("registered", "exists"):
                log_success(f"Device {status} — API key: {API_KEY}")
            else:
                log_error(f"Register response: {resp}")
        else:
            log_error(f"Register failed HTTP {code} — "
                      "continuing with configured API key")

    # ── LoRa simulation ──────────────────────────────────────
    def lora_send_command(self, zone_id: int, command: str,
                          command_id: int):
        """
        Simulate LoRa transmission to slave.
        In real hardware: LoRa.beginPacket() + LoRa.print("1:open")
        Here: direct call to slave object.
        """
        packet = f"{zone_id}:{command}"
        log_lora(f"TX → Slave Z{zone_id}: '{packet}' "
                 f"(cmd_id={command_id})")
        self.packets_sent += 1

        slave = self.slaves.get(zone_id)
        if not slave:
            log_error(f"No slave for zone {zone_id}")
            self.post_ack(command_id, "failed", 0)
            return

        # Execute on slave in separate thread (non-blocking)
        def execute_and_ack():
            result = slave.execute_command(command)
            self.packets_recv += 1

            # Simulate LoRa ACK packet: "1:ack:open"
            ack_packet = f"{zone_id}:ack:{result['result']}"
            log_lora(f"RX ← Slave Z{zone_id}: '{ack_packet}' "
                     f"RSSI=-{random.randint(65,95)}dBm")

            # Remove from pending
            with self.lock:
                self.pending_acks.pop(command_id, None)

            # Post ACK to dashboard
            self.post_ack(
                command_id,
                "acknowledged" if result["success"] else "failed",
                result["valve_pct"]
            )
            self.commands_exec += 1

        t = threading.Thread(target=execute_and_ack, daemon=True)
        t.start()

        # Track pending ACK with retry info
        with self.lock:
            self.pending_acks[command_id] = {
                "zone_id":     zone_id,
                "command":     command,
                "sent_at":     time.time(),
                "retry_count": 0,
            }

    # ── Post sensor data ─────────────────────────────────────
    def post_sensor_data(self, slave: SlaveNode):
        """POST zone readings to sensor_data.php."""
        slave.update_readings()
        s = slave.status()

        payload = {
            "api_key":      self.api_key,
            "zone_id":      s["zone_id"],
            "flow_rate":    s["flow_rate"],
            "total_litres": s["total_litres"],
            "water_level":  s["water_level"],
            "valve_pct":    100 if s["valve"] == "OPEN" else 0,
        }

        code, resp = self.http_post(SENSOR_URL, payload)
        log_http("POST", SENSOR_URL, code,
                 f"Zone {s['zone_id']} flow={s['flow_rate']} L/min "
                 f"level={s['water_level']}%")

        if code == 200 and resp.get("status") == "ok":
            log_master(
                f"Zone {s['zone_name']} → "
                f"flow={s['flow_rate']} L/min | "
                f"total={s['total_litres']} L | "
                f"valve={s['valve']}"
            )
        elif code == 0:
            log_error("Server unreachable — is XAMPP running?")

    # ── Poll commands ────────────────────────────────────────
    def poll_commands(self):
        """GET get_command.php — fetch pending valve commands."""
        code, resp = self.http_get(
            GET_CMD_URL,
            params={"api_key": self.api_key}
        )
        log_http("GET", GET_CMD_URL, code)

        if code != 200:
            return
        if resp.get("status") != "ok":
            log_error(f"get_command error: {resp.get('message','?')}")
            return

        commands = resp.get("commands", [])
        if not commands:
            log_master("No pending commands")
            return

        log_master(f"{len(commands)} command(s) received")
        for cmd in commands:
            cmd_id  = cmd["id"]
            zone_id = cmd["zone_id"]
            command = cmd["command"]
            retry   = cmd.get("retry_count", 0)

            log_master(
                f"Command #{cmd_id}: zone={zone_id} "
                f"action={command.upper()} "
                + (f"(retry #{retry})" if retry > 0 else "")
            )

            # Forward to slave via simulated LoRa
            self.lora_send_command(zone_id, command, cmd_id)

    # ── Post ACK ─────────────────────────────────────────────
    def post_ack(self, command_id: int, status: str, valve_pct: int):
        """POST result back to ack_command.php."""
        payload = {
            "api_key":    self.api_key,
            "command_id": command_id,
            "status":     status,
            "valve_pct":  valve_pct,
        }
        code, resp = self.http_post(ACK_CMD_URL, payload)
        log_http("POST", ACK_CMD_URL, code,
                 f"cmd={command_id} status={status} pct={valve_pct}")

        if code == 200 and resp.get("status") == "ok":
            color = Fore.GREEN if status == "acknowledged" else Fore.RED
            log(f"[ACK] Command #{command_id} → {status.upper()} "
                f"valve_pct={valve_pct}%", color)
        else:
            log_error(f"ACK failed for command #{command_id}: {resp}")

    # ── Check ACK timeouts with retry ────────────────────────
    def check_ack_timeouts(self):
        """
        FIX 5: Retry LoRa command up to 3 times before marking failed.
        Mirrors the retry logic in master_node.ino checkAckTimeouts().
        """
        now = time.time()
        with self.lock:
            timed_out = {
                cid: info for cid, info in self.pending_acks.items()
                if now - info["sent_at"] > 30
            }

        for cmd_id, info in timed_out.items():
            if info["retry_count"] < 3:
                log_master(
                    f"ACK timeout — retry #{info['retry_count']+1}/3 "
                    f"for command #{cmd_id}"
                )
                with self.lock:
                    if cmd_id in self.pending_acks:
                        self.pending_acks[cmd_id]["retry_count"] += 1
                        self.pending_acks[cmd_id]["sent_at"] = now
                # Resend LoRa
                self.lora_send_command(
                    info["zone_id"], info["command"], cmd_id
                )
            else:
                log_error(
                    f"Command #{cmd_id} permanently FAILED "
                    f"after 3 retries"
                )
                with self.lock:
                    self.pending_acks.pop(cmd_id, None)
                self.post_ack(cmd_id, "failed", 0)

    # ── Run automation brain ─────────────────────────────────
    def run_auto_control(self):
        """Trigger auto_control.php — the automation brain."""
        log_master("Running automation brain...")
        code, resp = self.http_get(AUTO_URL)
        if code == 200:
            actions = resp.get("actions_taken", 0)
            log_success(
                f"Auto control: {resp.get('zones_checked',0)} zones checked, "
                f"{actions} actions taken"
            )
            for action in resp.get("actions", []):
                log(f"  → {action}", Fore.YELLOW)
        else:
            log_error(f"Auto control failed HTTP {code}")

    # ── Print status dashboard ────────────────────────────────
    def print_status(self):
        print()
        print(Fore.CYAN + "─" * 60)
        print(Fore.CYAN + " SWDS Meru — Virtual ESP32 Status")
        print(Fore.CYAN + "─" * 60)
        print(f" Server:    {BASE_URL}")
        print(f" Packets:   TX={self.packets_sent} RX={self.packets_recv}")
        print(f" Commands:  executed={self.commands_exec}")
        print()
        print(f" {'Zone':<10} {'Valve':<8} {'Flow':>8} {'Level':>8} {'Total':>10}")
        print(f" {'-'*10} {'-'*8} {'-'*8} {'-'*8} {'-'*10}")
        for zone_id, slave in sorted(self.slaves.items()):
            s = slave.status()
            valve_color = Fore.GREEN if s["valve"] == "OPEN" else Fore.RED
            print(
                f" {s['zone_name']:<10} "
                f"{valve_color}{s['valve']:<8}{Style.RESET_ALL} "
                f"{s['flow_rate']:>7.1f}L "
                f"{s['water_level']:>7.1f}% "
                f"{s['total_litres']:>9.0f}L"
            )
        print(Fore.CYAN + "─" * 60)
        print()

    # ── Main loop ────────────────────────────────────────────
    def run(self):
        log_master("Virtual ESP32 starting...")
        log_master(f"Server: {BASE_URL}")
        log_master(f"API key: {self.api_key}")
        print()

        # Test connection first
        if not self.test_connection():
            log_error("Cannot connect to server. Fix the issue and restart.")
            log_error(f"Current SERVER_IP: {SERVER_IP}")
            sys.exit(1)

        # Register on startup
        self.register()
        print()

        last_sensor  = 0
        last_command = 0
        last_auto    = 0
        last_status  = 0

        log_success("Virtual ESP32 running. Press Ctrl+C to stop.")
        print()

        while self.running:
            now = time.time()

            # Every 10s — poll commands
            if now - last_command >= COMMAND_INTERVAL:
                last_command = now
                print()
                log_master("─── Polling commands ───")
                self.poll_commands()
                self.check_ack_timeouts()

            # Every 30s — post sensor data for all zones
            if now - last_sensor >= SENSOR_INTERVAL:
                last_sensor = now
                print()
                log_master("─── Posting sensor data ───")
                for slave in self.slaves.values():
                    self.post_sensor_data(slave)
                    time.sleep(0.3)  # small gap between zone posts

            # Every 60s — run automation
            if now - last_auto >= AUTO_INTERVAL:
                last_auto = now
                print()
                self.run_auto_control()

            # Every 30s — print status table
            if now - last_status >= 30:
                last_status = now
                self.print_status()

            time.sleep(1)

# ══════════════════════════════════════════════════════════════
#  ENTRY POINT
# ══════════════════════════════════════════════════════════════
def main():
    print()
    print(Fore.CYAN + "═" * 60)
    print(Fore.CYAN + "  SWDS Meru — Virtual ESP32 Simulator")
    print(Fore.CYAN + "  Simulates master node + 5 slave nodes")
    print(Fore.CYAN + "═" * 60)
    print()
    print(f"  Server:   {BASE_URL}")
    print(f"  API key:  {API_KEY}")
    print()
    print(Fore.YELLOW + "  Before running:")
    print("  1. Make sure XAMPP Apache + MySQL are running")
    print("  2. Check SERVER_IP at top of this file matches your PC IP")
    print("  3. Make sure API_KEY matches hardware_devices table")
    print(f"     → Go to http://localhost/smart_water/hardware.php")
    print(f"     → Register a device and copy its API key here")
    print()
    input(Fore.GREEN + "  Press Enter to start simulation..." +
          Style.RESET_ALL)
    print()

    # Create 5 slave nodes
    slaves = {
        1: SlaveNode(1, "Zone A"),
        2: SlaveNode(2, "Zone B"),
        3: SlaveNode(3, "Zone C"),
        4: SlaveNode(4, "Zone D"),
        5: SlaveNode(5, "Zone E"),
    }

    # Start all slaves with random valve states
    for slave in slaves.values():
        slave.valve_open = random.choice([True, False])
        log_slave(slave.zone_id,
                  f"Started — valve={'OPEN' if slave.valve_open else 'CLOSED'} "
                  f"base_flow={slave.base_flow} L/min")

    print()

    # Create and run master
    master = MasterNode(slaves)
    try:
        master.run()
    except KeyboardInterrupt:
        print()
        log_master("Shutting down...")
        master.print_status()
        print(Fore.CYAN + "Simulation ended.")

if __name__ == "__main__":
    # Check dependencies
    try:
        import requests
        import colorama
    except ImportError:
        print("Missing dependencies. Run:")
        print("  pip install requests colorama")
        sys.exit(1)

    main()