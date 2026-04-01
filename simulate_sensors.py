"""
simulate_sensors.py — SWDS Meru Sensor Data Simulator
======================================================
Generates realistic sensor readings for all zones so the
ML engine has data to train on.

HOW TO RUN:
  cd C:\\xampp_new\\htdocs\\smart_water
  python simulate_sensors.py

Inserts 90 days of hourly readings per zone (~10,800 rows total)
then runs the ML engine automatically.
"""

import random
import math
from datetime import datetime, timedelta

# ── DB config — must match your db.php ───────────────────────
DB_CONFIG = {
    'host':     'localhost',
    'user':     'root',
    'password': '',
    'database': 'meru_new',
    'port':     3306,
}

try:
    import pymysql
except ImportError:
    print("Run: pip install pymysql")
    exit(1)

def get_conn():
    return pymysql.connect(**DB_CONFIG, charset='utf8mb4')

def simulate_zone(zone_id, zone_name, base_flow, base_pressure, base_level):
    """Generate 90 days of hourly readings with realistic patterns."""
    readings = []
    now = datetime.now()
    start = now - timedelta(days=90)

    flow    = base_flow
    level   = base_level
    pressure= base_pressure

    t = start
    while t <= now:
        hour = t.hour
        dow  = t.weekday()  # 0=Mon, 6=Sun

        # ── Time-of-day pattern ───────────────────────────────
        # Peak usage: morning 6-9am, evening 5-8pm
        tod_factor = 1.0
        if 6 <= hour <= 9:
            tod_factor = 1.4 + random.uniform(-0.1, 0.2)
        elif 17 <= hour <= 20:
            tod_factor = 1.3 + random.uniform(-0.1, 0.15)
        elif 0 <= hour <= 4:
            tod_factor = 0.4 + random.uniform(-0.05, 0.1)
        else:
            tod_factor = 0.9 + random.uniform(-0.1, 0.1)

        # ── Weekend reduction ─────────────────────────────────
        if dow >= 5:
            tod_factor *= 0.85

        # ── Gradual drift + noise ─────────────────────────────
        flow     = max(2.0, base_flow * tod_factor + random.gauss(0, 2.5))
        pressure = max(0.5, base_pressure + random.gauss(0, 0.3))
        level    = max(5.0, min(100.0, level - (flow * 0.001) + random.gauss(0.2, 0.5)))

        # ── Water quality ─────────────────────────────────────
        ph       = round(random.gauss(7.2, 0.3), 2)
        ph       = max(6.0, min(9.0, ph))
        turbidity= round(max(0.1, random.gauss(1.5, 0.6)), 2)
        temp     = round(20 + 5 * math.sin(2 * math.pi * t.timetuple().tm_yday / 365)
                         + random.gauss(0, 1), 1)

        # ── Inject occasional anomalies (5% chance) ──────────
        if random.random() < 0.03:
            flow *= random.uniform(2.5, 4.0)   # spike
        if random.random() < 0.02:
            pressure *= random.uniform(0.2, 0.5)  # drop

        readings.append((
            zone_id,
            round(flow, 2),
            round(pressure, 2),
            round(level, 1),
            temp,
            round(turbidity, 2),
            round(ph, 2),
            1 if flow > 5 else 0,   # pump_status
            100 if flow > 5 else 0,  # valve_open_pct
            t.strftime('%Y-%m-%d %H:%M:%S')
        ))

        t += timedelta(hours=1)

    return readings

def main():
    print("=" * 55)
    print("  SWDS Meru — Sensor Data Simulator")
    print("=" * 55)

    conn = get_conn()
    cur  = conn.cursor()

    # Get zones
    cur.execute("SELECT id, zone_name FROM water_zones ORDER BY id")
    zones = cur.fetchall()

    if not zones:
        print("❌ No zones found. Add zones in your dashboard first.")
        return

    print(f"Found {len(zones)} zones: {[z[1] for z in zones]}")

    # Base parameters per zone (vary slightly per zone)
    base_params = [
        (45.0, 3.2, 75.0),   # Zone 1
        (38.0, 2.8, 68.0),   # Zone 2
        (52.0, 3.5, 82.0),   # Zone 3
        (41.0, 3.0, 71.0),   # Zone 4
        (35.0, 2.6, 65.0),   # Zone 5
    ]

    total = 0
    for i, (zone_id, zone_name) in enumerate(zones):
        params = base_params[i] if i < len(base_params) else (40.0, 3.0, 70.0)
        print(f"\nGenerating data for Zone {zone_id}: {zone_name}...")

        readings = simulate_zone(zone_id, zone_name, *params)

        # Insert in batches of 500
        sql = """INSERT INTO sensor_readings
            (zone_id, flow_rate, pressure, water_level, temperature,
             turbidity, ph_level, pump_status, valve_open_pct, recorded_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)"""

        batch_size = 500
        for j in range(0, len(readings), batch_size):
            batch = readings[j:j+batch_size]
            cur.executemany(sql, batch)
            conn.commit()

        total += len(readings)
        print(f"  ✅ {len(readings)} readings inserted")

    cur.close()
    conn.close()

    print(f"\n{'='*55}")
    print(f"  Done — {total} total readings inserted")
    print(f"  Now run: python prediction_engine.py")
    print(f"{'='*55}")

if __name__ == '__main__':
    main()