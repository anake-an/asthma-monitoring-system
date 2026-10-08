from fastapi import FastAPI, HTTPException
import pandas as pd
import numpy as np
from sqlalchemy import create_engine
from sklearn.ensemble import RandomForestClassifier
import joblib
import os
import math

app = FastAPI(title="Asthma AI Engine")

# MySQL Connection (uses the exact same env vars as Laravel for the db container)
DB_USER = os.getenv("DB_USERNAME", "laravel")
DB_PASS = os.getenv("DB_PASSWORD", "secret")
DB_HOST = os.getenv("DB_HOST", "db")
DB_NAME = os.getenv("DB_DATABASE", "asthma_db")
engine = create_engine(f"mysql+mysqlconnector://{DB_USER}:{DB_PASS}@{DB_HOST}/{DB_NAME}")

MODEL_PATH = "asthma_model.pkl"
BASELINE_PATH = "baseline_model.pkl"

def safe_val(val, default=0.0):
    try:
        f = float(val)
        if math.isnan(f) or math.isinf(f): return default
        return f
    except:
        return default

def fetch_data():
    try:
        telemetry = pd.read_sql("SELECT * FROM telemetry_logs", engine)
        coughs = pd.read_sql("SELECT * FROM cough_events", engine)
        inhaler = pd.read_sql("SELECT * FROM inhaler_logs", engine)
        
        telemetry['recorded_at'] = pd.to_datetime(telemetry['recorded_at'])
        coughs['recorded_at'] = pd.to_datetime(coughs['recorded_at'])
        if 'administered_at' in inhaler.columns:
            inhaler['recorded_at'] = pd.to_datetime(inhaler['administered_at'])
        else:
            inhaler['recorded_at'] = pd.to_datetime(inhaler['recorded_at'])
        
        return telemetry, coughs, inhaler
    except Exception as e:
        print(f"DB Error: {e}")
        return None, None, None

@app.get("/train")
def train_model():
    telemetry, coughs, inhaler = fetch_data()
    if telemetry is None or telemetry.empty:
        raise HTTPException(status_code=400, detail="Not enough data to train.")
    
    # Simple Time-Series Preprocessing
    # Resample everything to 10-minute windows
    telemetry.set_index('recorded_at', inplace=True)
    coughs.set_index('recorded_at', inplace=True)
    inhaler.set_index('recorded_at', inplace=True)
    
    # Aggregate data
    df = telemetry.resample('10min').mean().interpolate()
    
    # If the user hasn't plugged in PM2.5 or other sensors yet, fill them so we don't drop all rows
    if 'pm25_level' in df.columns:
        df['pm25_level'] = df['pm25_level'].fillna(15.0)
    if 'temperature' in df.columns:
        df['temperature'] = df['temperature'].fillna(30.0)
    if 'humidity' in df.columns:
        df['humidity'] = df['humidity'].fillna(60.0)
    
    if not coughs.empty:
        df['cough_count'] = coughs.resample('10min').size()
    else:
        df['cough_count'] = 0
        
    if not inhaler.empty:
        df['inhaler_used'] = inhaler.resample('10min').size().apply(lambda x: 1 if x > 0 else 0)
    else:
        df['inhaler_used'] = 0
        
    df['cough_count'] = df['cough_count'].fillna(0)
    df['inhaler_used'] = df['inhaler_used'].fillna(0)
    
    # Target Label: True if inhaler is used OR if there are multiple coughs in the NEXT 60 minutes
    df['future_inhaler'] = df['inhaler_used'].shift(-6).fillna(0)
    df['future_coughs'] = df['cough_count'].rolling(window=6).sum().shift(-6).fillna(0)
    
    # 1 if inhaler used, or 2+ coughs detected in the next hour
    df['target_attack_soon'] = ((df['future_inhaler'] > 0) | (df['future_coughs'] >= 2)).astype(int)
    
    # Drop rows with NaN targets
    df.dropna(inplace=True)
    
    if len(df) == 0:
        raise HTTPException(status_code=400, detail="Not enough valid data after preprocessing. Please wait for more telemetry.")
    
    features = ['pm25_level', 'temperature', 'humidity', 'cough_count']
    X = df[features]
    y = df['target_attack_soon']
    
    if sum(y) == 0:
        # Stage 1: No medical events yet. Create a "Normalcy Baseline" based on room averages
        baseline = {
            "temp_mean": safe_val(df['temperature'].mean(), 30.0),
            "temp_std": safe_val(df['temperature'].std(), 1.0),
            "hum_mean": safe_val(df['humidity'].mean(), 60.0),
            "hum_std": safe_val(df['humidity'].std(), 1.0),
            "pm25_mean": safe_val(df['pm25_level'].mean(), 15.0),
            "pm25_std": safe_val(df['pm25_level'].std(), 1.0)
        }
        joblib.dump(baseline, BASELINE_PATH)
        return {"message": "No asthma events recorded. Stage 1 Baseline (Normalcy) model created.", "baseline": baseline}

    # Stage 2: We have medical events! Train the Personalized Random Forest
    model = RandomForestClassifier(n_estimators=100, random_state=42)
    model.fit(X, y)
    
    joblib.dump(model, MODEL_PATH)
    
    accuracy = safe_val(model.score(X, y), 1.0)
    return {"message": "Personalized Stage 2 Model trained successfully and saved.", "accuracy_estimate": accuracy}

@app.get("/predict")
def predict_attack():
    has_stage2 = os.path.exists(MODEL_PATH)
    has_stage1 = os.path.exists(BASELINE_PATH)
    
    if not has_stage2 and not has_stage1:
        raise HTTPException(status_code=400, detail="Model not trained yet. Call /train first.")
        
    # Fetch last 60 minutes of data to predict next 60 minutes
    query = """
    SELECT AVG(pm25_level) as pm25, AVG(temperature) as temp, AVG(humidity) as hum
    FROM telemetry_logs 
    WHERE recorded_at >= NOW() - INTERVAL 1 HOUR
    """
    recent_telemetry = pd.read_sql(query, engine)
    
    cough_query = "SELECT COUNT(*) as cough_count FROM cough_events WHERE recorded_at >= NOW() - INTERVAL 1 HOUR"
    recent_coughs = pd.read_sql(cough_query, engine)
    
    if recent_telemetry['pm25'].isnull()[0]:
        raise HTTPException(status_code=400, detail="No telemetry data in the last hour.")
        
    pm25 = recent_telemetry['pm25'][0]
    temp = recent_telemetry['temp'][0]
    hum = recent_telemetry['hum'][0]
    cough_count = recent_coughs['cough_count'][0]
    
    X_new = pd.DataFrame([[pm25, temp, hum, cough_count]], columns=['pm25_level', 'temperature', 'humidity', 'cough_count'])
    
    probability = 0.0
    is_stage2 = False
    
    if has_stage2:
        # Stage 2: Personalized Prediction
        model = joblib.load(MODEL_PATH)
        probability = model.predict_proba(X_new)[0][1] # Probability of class 1
        is_stage2 = True
    else:
        # Stage 1: Anomaly Detection (How far is current room from the historical normal?)
        baseline = joblib.load(BASELINE_PATH)
        temp_z = (temp - baseline['temp_mean']) / baseline['temp_std']
        hum_z = (hum - baseline['hum_mean']) / baseline['hum_std']
        
        # If room spikes 2.5 standard deviations above normal, flag high risk
        if temp_z > 2.5 or hum_z > 2.5:
            probability = 0.8
        elif temp_z > 1.5 or hum_z > 1.5:
            probability = 0.5
        else:
            probability = 0.1
    
    suggested_pm25 = 35.0
    suggested_temp = 35.0
    suggested_hum = 75.0 # Adapted for Malaysian default baseline
    suggested_gas = 300.0
    
    if is_stage2:
        # Stage 2: Hybrid Thresholding using Feature Importance
        # features array in train() is: ['pm25_level', 'temperature', 'humidity', 'cough_count']
        importances = model.feature_importances_
        pm25_imp = importances[0]
        temp_imp = importances[1]
        hum_imp = importances[2]
        
        # We allow the AI to lower the threshold by up to 50% if the feature is a massive trigger
        severity_factor = 0.5 
        
        suggested_pm25 -= (suggested_pm25 * pm25_imp * probability * severity_factor)
        suggested_temp -= (suggested_temp * temp_imp * probability * severity_factor)
        suggested_hum -= (suggested_hum * hum_imp * probability * severity_factor)
        suggested_gas -= (suggested_gas * probability * 0.3) # Generic scaling for gas
        
        # Apply safety floor limits so thresholds don't drop to impossible levels
        suggested_pm25 = round(max(15.0, suggested_pm25), 1)
        suggested_temp = round(max(26.0, suggested_temp), 1)
        suggested_hum = round(max(55.0, suggested_hum), 1)
        suggested_gas = round(max(150.0, suggested_gas), 1)
        
    else:
        # Stage 1: Fallback generic scaling
        if probability > 0.7:
            suggested_pm25 = 20.0
            suggested_temp = 30.0
            suggested_hum = 60.0
            suggested_gas = 150.0
        elif probability > 0.4:
            suggested_pm25 = 25.0
            suggested_temp = 32.0
            suggested_hum = 65.0
            suggested_gas = 200.0
        
    return {
        "model_stage": "Stage 2 (Personalized)" if is_stage2 else "Stage 1 (Anomaly Detection)",
        "probability_of_attack": round(float(probability), 2),
        "estimated_time_to_next_inhaler_mins": int((1.0 - probability) * 60) if probability > 0 else -1,
        "suggested_thresholds": {
            "pm25_threshold": suggested_pm25,
            "temperature_threshold": suggested_temp,
            "humidity_threshold": suggested_hum,
            "mq135_threshold": suggested_gas
        },
        "current_inputs": {
            "pm25": pm25, "temperature": temp, "humidity": hum, "recent_coughs": int(cough_count)
        }
    }
