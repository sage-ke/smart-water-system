"""
prediction_engine.py — SWDS Meru ML Intelligence
==================================================
Reads sensor_readings from MySQL, trains a Random Forest per zone,
writes 7-day forecasts + anomaly detections back to the DB.

Tables written:
  predictions    — 7-day flow/level/demand forecast (per zone per date)
  anomaly_log    — ML-detected anomalies with confidence score
  prediction_log — run history so you can see when it last ran & how accurate

HOW TO RUN:
  cd C:\\xampp_new\\htdocs\\smart_water\\ml
  python prediction_engine.py

SCHEDULE (optional — Windows Task Scheduler every 15 min):
  schtasks /create /tn "SWDS_ML" /sc minute /mo 15 ^
    /tr "python C:\\xampp_new\\htdocs\\smart_water\\ml\\prediction_engine.py"

REQUIREMENTS (already installed):
  pip install pandas scikit-learn sqlalchemy mysql-connector-python joblib
"""

import os, sys, logging, warnings
from datetime import datetime, timedelta

warnings.filterwarnings('ignore')

# ── Logging — writes to ml_engine.log AND console ────────────
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s [%(levelname)s] %(message)s',
    handlers=[
        logging.FileHandler(
            os.path.join(os.path.dirname(os.path.abspath(__file__)), 'ml_engine.log'),
            encoding='utf-8'
        ),
        logging.StreamHandler(sys.stdout)
    ]
)
log = logging.getLogger(__name__)

# ── DB config — edit password if you set one in XAMPP ────────
DB_CONFIG = {
    'host':     'localhost',
    'user':     'root',
    'password': '',           # blank by default on XAMPP
    'database': 'meru_new',
    'port':     3306,
}

# ── Import ML libraries ───────────────────────────────────────
try:
    import pandas as pd
    import numpy as np
    from sqlalchemy import create_engine, text
    from sklearn.ensemble import RandomForestRegressor, IsolationForest
    from sklearn.preprocessing import StandardScaler
    from sklearn.model_selection import cross_val_score
except ImportError as e:
    log.error(f"Missing library: {e}")
    log.error("Run: pip install pandas scikit-learn sqlalchemy pymysql")
    sys.exit(1)

# Detect which MySQL driver is available
def _detect_driver():
    try:
        import pymysql
        return 'pymysql'
    except ImportError:
        pass
    try:
        import mysql.connector
        return 'mysqlconnector_pure'
    except ImportError:
        pass
    log.error("No MySQL driver found. Run: pip install pymysql")
    sys.exit(1)

_DRIVER = _detect_driver()
log.info(f"MySQL driver: {_DRIVER}")


# ════════════════════════════════════════════════════════════
#  DB CONNECTION
# ════════════════════════════════════════════════════════════
def get_engine():
    c = DB_CONFIG
    if _DRIVER == 'pymysql':
        # pymysql is most reliable with XAMPP/InnoDB
        url = (f"mysql+pymysql://{c['user']}:{c['password']}"
               f"@{c['host']}:{c['port']}/{c['database']}?charset=utf8mb4")
        return create_engine(url, pool_pre_ping=True)
    else:
        # mysql-connector in pure-Python mode avoids error 1932
        url = (f"mysql+mysqlconnector://{c['user']}:{c['password']}"
               f"@{c['host']}:{c['port']}/{c['database']}")
        return create_engine(
            url,
            pool_pre_ping=True,
            connect_args={'use_pure': True}   # ← fixes InnoDB 1932 error
        )


# ════════════════════════════════════════════════════════════
#  ENSURE TABLES
# ════════════════════════════════════════════════════════════
def ensure_tables(engine):
    with engine.begin() as conn:
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS predictions (
                id               INT AUTO_INCREMENT PRIMARY KEY,
                zone_id          INT NOT NULL,
                predict_date     DATE NOT NULL,
                predicted_flow   FLOAT,
                predicted_level  FLOAT,
                predicted_demand FLOAT,
                confidence_pct   FLOAT,
                model_version    VARCHAR(50),
                created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_zone_date (zone_id, predict_date)
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS anomaly_log (
                id             INT AUTO_INCREMENT PRIMARY KEY,
                zone_id        INT NOT NULL,
                reading_id     INT,
                anomaly_type   VARCHAR(100),
                expected_value FLOAT,
                actual_value   FLOAT,
                deviation_pct  FLOAT,
                severity_score FLOAT,
                ml_confidence  FLOAT,
                detected_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                is_resolved    TINYINT(1) DEFAULT 0,
                INDEX idx_anom_zone (zone_id),
                INDEX idx_anom_det  (detected_at)
            )
        """))
        conn.execute(text("""
            CREATE TABLE IF NOT EXISTS prediction_log (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                zone_id         INT,
                zone_name       VARCHAR(100),
                run_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                rows_trained    INT DEFAULT 0,
                forecast_days   INT DEFAULT 0,
                anomalies_found INT DEFAULT 0,
                mae_flow        FLOAT COMMENT 'Mean Absolute Error — flow',
                mae_level       FLOAT COMMENT 'Mean Absolute Error — level',
                model_version   VARCHAR(50),
                status          VARCHAR(30) DEFAULT 'success',
                error_msg       TEXT
            )
        """))
    log.info("DB tables verified / created")


# ════════════════════════════════════════════════════════════
#  LOAD DATA
# ════════════════════════════════════════════════════════════
def load_zone_data(engine, zone_id: int, days: int = 90) -> 'pd.DataFrame':
    sql = f"""
        SELECT
            sr.id,
            sr.recorded_at,
            COALESCE(sr.flow_rate,   0) AS flow_rate,
            COALESCE(sr.pressure,    0) AS pressure,
            COALESCE(sr.water_level, 0) AS water_level,
            COALESCE(sr.temperature, 25) AS temperature,
            COALESCE(sr.turbidity,   0) AS turbidity,
            COALESCE(sr.ph_level,    7) AS ph_level,
            HOUR(sr.recorded_at)        AS hour_of_day,
            DAYOFWEEK(sr.recorded_at)   AS day_of_week,
            DAYOFMONTH(sr.recorded_at)  AS day_of_month,
            MONTH(sr.recorded_at)       AS month
        FROM sensor_readings sr
        WHERE sr.zone_id = {zone_id}
          AND sr.recorded_at >= DATE_SUB(NOW(), INTERVAL {days} DAY)
        ORDER BY sr.recorded_at ASC
    """
    df = pd.read_sql(sql, engine, parse_dates=['recorded_at'])
    log.info(f"  Zone {zone_id}: loaded {len(df)} rows")
    return df


# ════════════════════════════════════════════════════════════
#  FEATURE ENGINEERING
# ════════════════════════════════════════════════════════════
def build_features(df: 'pd.DataFrame') -> 'pd.DataFrame':
    df = df.copy().sort_values('recorded_at').reset_index(drop=True)

    for col in ['flow_rate', 'pressure', 'water_level', 'temperature']:
        if col in df.columns:
            df[f'{col}_lag1']   = df[col].shift(1)
            df[f'{col}_lag3']   = df[col].shift(3)
            df[f'{col}_roll6']  = df[col].rolling(6,  min_periods=1).mean()
            df[f'{col}_roll24'] = df[col].rolling(24, min_periods=1).mean()
            df[f'{col}_std6']   = df[col].rolling(6,  min_periods=1).std().fillna(0)

    # Cyclical time encoding (better than raw numbers for ML)
    df['hour_sin'] = np.sin(2 * np.pi * df['hour_of_day'] / 24)
    df['hour_cos'] = np.cos(2 * np.pi * df['hour_of_day'] / 24)
    df['dow_sin']  = np.sin(2 * np.pi * df['day_of_week'] / 7)
    df['dow_cos']  = np.cos(2 * np.pi * df['day_of_week'] / 7)

    return df.dropna()


# ════════════════════════════════════════════════════════════
#  TRAIN RANDOM FOREST
# ════════════════════════════════════════════════════════════
NON_FEATURES = {'id', 'recorded_at', 'flow_rate', 'pressure',
                'water_level', 'temperature', 'turbidity', 'ph_level'}

def train_model(df: 'pd.DataFrame', target: str):
    """Returns (model, feature_cols, mae) — model is None if not enough data."""
    feature_cols = [c for c in df.columns if c not in NON_FEATURES]
    if target not in df.columns or len(df) < 20:
        return None, feature_cols, None

    X = df[feature_cols].fillna(0)
    y = df[target].fillna(0)

    model = RandomForestRegressor(
        n_estimators=100, max_depth=8,
        min_samples_leaf=3, random_state=42, n_jobs=-1
    )
    model.fit(X, y)

    # Cross-validate for MAE
    try:
        cv = min(5, max(2, len(X) // 10))
        scores = cross_val_score(model, X, y, cv=cv,
                                 scoring='neg_mean_absolute_error')
        mae = float(-scores.mean())
    except Exception:
        mae = None

    return model, feature_cols, mae


# ════════════════════════════════════════════════════════════
#  GENERATE 7-DAY FORECAST
# ════════════════════════════════════════════════════════════
def generate_forecast(zone_id: int, df: 'pd.DataFrame',
                      flow_model, level_model, pressure_model,
                      fc_flow, fc_level, fc_pressure) -> list:
    forecasts = []
    last_row  = df.iloc[-1].copy()
    base_date = datetime.now().date()

    def safe_predict(model, cols, row):
        if model is None:
            return None
        try:
            X = pd.DataFrame([row[cols].fillna(0).values], columns=cols)
            val = float(model.predict(X)[0])
            return round(max(0.0, val), 3)
        except Exception:
            return None

    for i in range(1, 8):
        fdate = base_date + timedelta(days=i)
        row = last_row.copy()
        row['hour_of_day']  = 12
        row['day_of_week']  = fdate.weekday() + 1
        row['day_of_month'] = fdate.day
        row['month']        = fdate.month
        row['hour_sin']     = np.sin(2 * np.pi * 12 / 24)
        row['hour_cos']     = np.cos(2 * np.pi * 12 / 24)
        row['dow_sin']      = np.sin(2 * np.pi * row['day_of_week'] / 7)
        row['dow_cos']      = np.cos(2 * np.pi * row['day_of_week'] / 7)

        forecasts.append({
            'zone_id':          zone_id,
            'predict_date':     fdate.isoformat(),
            'predicted_flow':   safe_predict(flow_model,     fc_flow,     row),
            'predicted_level':  safe_predict(level_model,    fc_level,    row),
            'predicted_demand': safe_predict(pressure_model, fc_pressure, row),
            'confidence_pct':   max(40.0, 95.0 - (i - 1) * 8.0),
            'model_version':    'rf_v1',
        })
    return forecasts


# ════════════════════════════════════════════════════════════
#  ML ANOMALY DETECTION (Isolation Forest)
# ════════════════════════════════════════════════════════════
def detect_anomalies(df: 'pd.DataFrame', zone_id: int) -> list:
    sensor_cols = ['flow_rate', 'pressure', 'water_level', 'turbidity', 'ph_level']
    usable = [c for c in sensor_cols if c in df.columns and df[c].notna().sum() > 10]
    if len(usable) < 2 or len(df) < 20:
        return []

    X_raw    = df[usable].fillna(df[usable].median())
    scaler   = StandardScaler()
    X_scaled = scaler.fit_transform(X_raw)

    iso    = IsolationForest(contamination=0.05, random_state=42, n_jobs=-1)
    labels = iso.fit_predict(X_scaled)
    scores = iso.score_samples(X_scaled)

    s_min, s_max = scores.min(), scores.max()
    s_range = s_max - s_min if s_max != s_min else 1.0

    # Isolation Forest: more negative score = more anomalous
    # Confidence = how far this point is from the NORMAL end (s_max)
    # toward the ANOMALY end (s_min). Ranges 0-100%.
    results = []
    for idx in np.where(labels == -1)[0]:
        row       = df.iloc[idx]
        raw_score = scores[idx]
        # (s_max - raw_score) / s_range → 0 when score=s_max (normal), 1 when score=s_min (most anomalous)
        confidence = float(np.clip((s_max - raw_score) / s_range * 100, 0, 100))

        # Find the most deviant sensor
        deviations = {}
        for col in usable:
            med = float(df[col].median())
            if med != 0:
                deviations[col] = abs((float(row[col]) - med) / med * 100)
        worst = max(deviations, key=deviations.get) if deviations else usable[0]

        results.append({
            'zone_id':        zone_id,
            'reading_id':     int(row.get('id', 0)),
            'anomaly_type':   f'ml_{worst}_anomaly',
            'expected_value': float(df[worst].median()),
            'actual_value':   float(row[worst]),
            'deviation_pct':  float(deviations.get(worst, 0)),
            'severity_score': confidence,
            'ml_confidence':  confidence,
        })
    return results


# ════════════════════════════════════════════════════════════
#  SAVE TO DB
# ════════════════════════════════════════════════════════════
def save_predictions(engine, forecasts: list):
    if not forecasts:
        return
    with engine.begin() as conn:
        for f in forecasts:
            conn.execute(text("""
                INSERT INTO predictions
                    (zone_id, predict_date, predicted_flow, predicted_level,
                     predicted_demand, confidence_pct, model_version)
                VALUES
                    (:zone_id, :predict_date, :predicted_flow, :predicted_level,
                     :predicted_demand, :confidence_pct, :model_version)
                ON DUPLICATE KEY UPDATE
                    predicted_flow   = VALUES(predicted_flow),
                    predicted_level  = VALUES(predicted_level),
                    predicted_demand = VALUES(predicted_demand),
                    confidence_pct   = VALUES(confidence_pct),
                    model_version    = VALUES(model_version),
                    created_at       = NOW()
            """), f)


def save_anomalies(engine, anomalies: list):
    if not anomalies:
        return
    with engine.begin() as conn:
        for a in anomalies:
            conn.execute(text("""
                INSERT INTO anomaly_log
                    (zone_id, reading_id, anomaly_type, expected_value,
                     actual_value, deviation_pct, severity_score, ml_confidence)
                VALUES
                    (:zone_id, :reading_id, :anomaly_type, :expected_value,
                     :actual_value, :deviation_pct, :severity_score, :ml_confidence)
            """), a)


def escalate_to_alerts(engine, anomalies: list, zone_id: int):
    """Push high-confidence anomalies into the main alerts table."""
    critical = [a for a in anomalies if a['severity_score'] >= 70]
    if not critical:
        return
    with engine.begin() as conn:
        for a in critical:
            conn.execute(text("""
                INSERT INTO alerts (zone_id, alert_type, message, severity)
                VALUES (:zid, :atype, :msg, 'high')
            """), {
                'zid':   zone_id,
                'atype': a['anomaly_type'],
                'msg': (f"ML detected anomaly: {a['anomaly_type']} | "
                        f"Actual={a['actual_value']:.2f}, "
                        f"Expected={a['expected_value']:.2f}, "
                        f"Deviation={a['deviation_pct']:.1f}%, "
                        f"Confidence={a['severity_score']:.0f}%")
            })


def log_run(engine, zone_id, zone_name, rows, days, n_anom,
            mae_flow, mae_level, status='success', error=None):
    with engine.begin() as conn:
        conn.execute(text("""
            INSERT INTO prediction_log
                (zone_id, zone_name, rows_trained, forecast_days, anomalies_found,
                 mae_flow, mae_level, model_version, status, error_msg)
            VALUES
                (:zone_id, :zone_name, :rows_trained, :forecast_days, :anomalies_found,
                 :mae_flow, :mae_level, :model_version, :status, :error_msg)
        """), {
            'zone_id': zone_id, 'zone_name': zone_name,
            'rows_trained': rows, 'forecast_days': days,
            'anomalies_found': n_anom,
            'mae_flow': mae_flow, 'mae_level': mae_level,
            'model_version': 'rf_v1', 'status': status,
            'error_msg': error
        })


# ════════════════════════════════════════════════════════════
#  MAIN
# ════════════════════════════════════════════════════════════
def main():
    log.info("=" * 55)
    log.info("  SWDS Meru ML Prediction Engine  —  starting")
    log.info("=" * 55)

    engine = get_engine()
    ensure_tables(engine)

    zones = pd.read_sql("SELECT id, zone_name FROM water_zones ORDER BY id", engine)
    if zones.empty:
        log.warning("No zones found in water_zones — nothing to do.")
        return

    log.info(f"Found {len(zones)} zone(s): {list(zones['zone_name'])}")

    total_fx = total_an = 0

    for _, zone in zones.iterrows():
        zone_id   = int(zone['id'])
        zone_name = str(zone['zone_name'])
        log.info(f"\n--- Zone {zone_id}: {zone_name} ---")

        try:
            df_raw = load_zone_data(engine, zone_id, days=90)
            if len(df_raw) < 10:
                log.warning(f"  Only {len(df_raw)} rows — need ≥10, skipping.")
                log_run(engine, zone_id, zone_name, len(df_raw), 0, 0,
                        None, None, 'skipped', 'insufficient data')
                continue

            df = build_features(df_raw)

            # Train three forecast targets
            flow_m,  fc_flow,  mae_flow  = train_model(df, 'flow_rate')
            level_m, fc_level, mae_level = train_model(df, 'water_level')
            pres_m,  fc_pres,  _         = train_model(df, 'pressure')

            mf = f"{mae_flow:.3f}"  if mae_flow  is not None else "n/a"
            ml = f"{mae_level:.3f}" if mae_level is not None else "n/a"
            log.info(f"  Model MAE — flow: {mf}, level: {ml}")

            # 7-day forecast
            forecasts = generate_forecast(
                zone_id, df,
                flow_m, level_m, pres_m,
                fc_flow, fc_level, fc_pres
            )
            save_predictions(engine, forecasts)
            log.info(f"  Saved {len(forecasts)} forecast rows")

            # Anomaly detection
            anomalies = detect_anomalies(df_raw, zone_id)
            save_anomalies(engine, anomalies)
            escalate_to_alerts(engine, anomalies, zone_id)
            log.info(f"  Detected {len(anomalies)} anomalies "
                     f"({sum(1 for a in anomalies if a['severity_score']>=70)} escalated)")

            log_run(engine, zone_id, zone_name, len(df), 7,
                    len(anomalies), mae_flow, mae_level)

            total_fx += len(forecasts)
            total_an += len(anomalies)

        except Exception as e:
            log.error(f"  Zone {zone_id} failed: {e}", exc_info=True)
            log_run(engine, zone_id, zone_name, 0, 0, 0,
                    None, None, 'error', str(e))

    log.info(f"\n{'='*55}")
    log.info(f"  Done — {total_fx} forecasts written, {total_an} anomalies logged")
    log.info(f"{'='*55}")


if __name__ == '__main__':
    main()