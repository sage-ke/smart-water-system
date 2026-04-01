# slave_node.py - SWDS Meru
# Simulates a physical ESP32 slave node
# Fixes applied:
#   1. Recovery mechanism - hardware can self-repair
#   2. Failure rate can reach 100% - no artificial cap
#   3. Water level can overflow or empty - real emergencies
#   4. Maintenance check runs periodically

import time
import random
import math


class SlaveNode:
    def __init__(self, zone_id=1, zone_name="Zone A"):
        self.zone_id              = zone_id
        self.zone_name            = zone_name
        self.valve_open           = False
        self.valve_pct            = 0
        self.total_litres         = random.uniform(200, 1000)
        self.base_flow            = 25.0 + (zone_id * 4.0)
        self.start_time           = time.time()

        # FIX 1 + 2: Hardware state tracking
        self.hardware_degraded    = False
        self.consecutive_failures = 0
        self.total_failures       = 0
        self.last_maintenance     = time.time()
        self.maintenance_interval = 300  # check every 5 minutes

        # FIX 3: Water level - can actually overflow or empty
        self.water_level          = random.uniform(60.0, 95.0)
        self.tank_capacity        = 1000.0  # litres
        self.overflow_warned      = False   # only warn once per overflow event

    # ── FIX 1: Recovery mechanism ─────────────────────────────
    def maintenance_check(self):
        if self.hardware_degraded:
            # 5% chance of self-recovery per maintenance cycle
            if random.random() < 0.05:
                self.hardware_degraded    = False
                self.consecutive_failures = 0
                print("[SLAVE " + self.zone_name +
                      "] Maintenance completed - hardware recovered")
                return True
        return False

    # ── Execute valve command ─────────────────────────────────
    def execute_command(self, command, valve_pct=100):
        # Run maintenance check on every command
        now = time.time()
        if now - self.last_maintenance > self.maintenance_interval:
            self.last_maintenance = now
            self.maintenance_check()

        print("[SLAVE " + self.zone_name + "] Command: " +
              command.upper() + " (" + str(valve_pct) + "%)")

        # Realistic valve actuator delay
        delay = random.uniform(0.8, 2.5)
        print("[SLAVE " + self.zone_name + "] Actuator moving... (" +
              str(round(delay, 1)) + "s)")
        time.sleep(delay)

        # FIX 2: No cap - failure rate can reach 100%
        # Each failure increases failure probability by 10%
        base_failure  = 0.30 if self.hardware_degraded else 0.05
        failure_rate  = min(1.0,  # FIX 2: allow 100% failure
                           base_failure + (self.consecutive_failures * 0.10))

        if random.random() > failure_rate:
            # Success
            self.consecutive_failures = 0
            if command == "open":
                self.valve_open = True
                self.valve_pct  = valve_pct
                print("[SLAVE " + self.zone_name + "] Valve OPENED OK")
                return {"success": True, "result": "open",
                        "valve_pct": valve_pct}
            elif command == "close":
                self.valve_open = False
                self.valve_pct  = 0
                print("[SLAVE " + self.zone_name + "] Valve CLOSED OK")
                return {"success": True, "result": "close",
                        "valve_pct": 0}
            else:
                return {"success": False, "result": "failed",
                        "valve_pct": 0}
        else:
            # Failure
            self.consecutive_failures += 1
            self.total_failures       += 1

            # FIX 2: Mark degraded after 3 consecutive failures
            # But failure rate can still reach 100%
            if self.consecutive_failures >= 3:
                self.hardware_degraded = True
                print("[SLAVE " + self.zone_name +
                      "] HARDWARE DEGRADED - " +
                      str(self.consecutive_failures) +
                      " consecutive failures (rate=" +
                      str(round(failure_rate * 100)) + "%)")
            else:
                print("[SLAVE " + self.zone_name +
                      "] Actuator fault #" +
                      str(self.consecutive_failures) +
                      " (failure rate=" +
                      str(round(failure_rate * 100)) + "%)")

            return {"success": False, "result": "failed",
                    "valve_pct": 0}

    # ── Generate sensor data ──────────────────────────────────
    def generate_sensor_data(self):
        # FIX 3: Update water level realistically
        if self.valve_open:
            # Water flowing out - level drops
            hour        = (time.time() / 3600.0) % 24
            daily       = 1.0 + 0.35 * math.sin((hour - 6) * math.pi / 12)
            noise       = random.uniform(-3.0, 3.0)

            # Degraded hardware = more sensor noise
            if self.hardware_degraded:
                noise += random.uniform(-10.0, 10.0)

            flow_rate = max(0.0,
                           self.base_flow * daily *
                           (self.valve_pct / 100.0) + noise)

            # FIX 3: Deplete water level based on flow
            depletion = flow_rate * (10.0 / 60.0) / self.tank_capacity * 100
            self.water_level -= depletion

        else:
            # Valve closed - slow leak simulation
            flow_rate = random.uniform(0.0, 0.2)

            # FIX 3: Level slowly refills when valve closed
            # But only if below 95% - stop refilling near full
            if self.water_level < 95.0:
                self.water_level += random.uniform(0.1, 0.3)

        flow_rate = round(flow_rate, 2)

        # Allow real overflow and empty conditions
        if self.water_level >= 100.0:
            self.water_level = 100.0
            if self.valve_open and not self.overflow_warned:
                print("[SLAVE " + self.zone_name +
                      "] WARNING: Tank overflow detected!")
                self.overflow_warned = True
        else:
            self.overflow_warned = False  # reset when level drops

        if self.water_level < 0.0:
            # Empty condition - real emergency
            self.water_level = 0.0
            print("[SLAVE " + self.zone_name +
                  "] CRITICAL: Tank empty!")

        water_level = round(self.water_level, 1)

        # Accumulate total litres
        self.total_litres += flow_rate * (10.0 / 60.0)
        self.total_litres  = round(self.total_litres, 1)

        return {
            "zone_id":      self.zone_id,
            "flow_rate":    flow_rate,
            "total_litres": self.total_litres,
            "water_level":  water_level,
            "valve_pct":    self.valve_pct,
        }

    def get_water_level(self):
        return round(self.water_level, 1)

    def status(self):
        hw    = "DEGRADED" if self.hardware_degraded else "OK"
        level = str(round(self.water_level, 1))
        return (self.zone_name + " | " +
                "Valve=" + ("OPEN" if self.valve_open else "CLOSED") +
                " (" + str(self.valve_pct) + "%) | " +
                "Level=" + level + "% | " +
                "Hardware=" + hw + " | " +
                "Failures=" + str(self.total_failures))