"use client";
import { useEffect, useState } from "react";
import { Brain, Activity, Clock } from "lucide-react";
import { useRooms, withDevice } from "@/lib/rooms";

type AiPrediction = {
  model_stage: string;
  probability_of_attack: number | null;
  current_inputs?: { coughs_last_hour: number };
  error?: string;
  learning?: boolean; // backend: no model or not enough recent data yet
  // Stage 2 only: which model and how it scored on the most recent 25% of data it never trained on.
  model?: {
    model_type: "logistic_regression" | "random_forest";
    episode_windows: number;
    evaluation: { recall: number | null; precision: number | null; test_episode_windows: number; note: string };
  } | null;
};

const MODEL_NAME = { logistic_regression: "Logistic regression", random_forest: "Random forest" } as const;

export default function AiRiskAssessment() {
  const { deviceId } = useRooms(); // the room on screen: its own model
  const [prediction, setPrediction] = useState<AiPrediction | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchPrediction = async () => {
      try {
        const token = localStorage.getItem("auth_token");
        const res = await fetch(withDevice("/api/ai/predict", deviceId), {
          headers: {
            "Authorization": `Bearer ${token}`,
            "Accept": "application/json"
          }
        });
        if (res.ok) {
          const data = await res.json();
          setPrediction(data);
        }
      } catch (e) {
        console.error("Failed to fetch AI prediction");
      } finally {
        setLoading(false);
      }
    };

    fetchPrediction();
    const interval = setInterval(fetchPrediction, 60000); // refresh every minute
    return () => clearInterval(interval);
  }, [deviceId]);

  if (loading) {
    return (
      <div className="bg-white dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 rounded-3xl p-6 h-full flex items-center justify-center min-h-[160px] animate-pulse">
        <div className="text-zinc-600 dark:text-zinc-400 flex items-center gap-2">
          <Brain className="w-5 h-5 animate-spin" />
          <span className="text-sm">Analyzing Room Conditions...</span>
        </div>
      </div>
    );
  }

  // No model yet (or engine unreachable): show "Learning mode" instead of locking the panel,
  // but never display a made-up risk number.
  const isLearning = !prediction || !!prediction.error || !!prediction.learning || prediction.probability_of_attack === null;
  const activePrediction: AiPrediction = isLearning
    ? { model_stage: "Learning mode", probability_of_attack: 0 }
    : (prediction as AiPrediction);

  const riskPercent = Math.round((activePrediction.probability_of_attack ?? 0) * 100);
  
  let riskColor = "text-emerald-400";
  let riskBg = "bg-emerald-400/10";
  let riskText = "Low Risk";
  
  if (riskPercent > 40) {
    riskColor = "text-orange-400";
    riskBg = "bg-orange-400/10";
    riskText = "Moderate Risk";
  }
  if (riskPercent > 70) {
    riskColor = "text-red-400";
    riskBg = "bg-red-400/10";
    riskText = "High Risk";
  }
  if (isLearning) {
    riskColor = "text-zinc-500 dark:text-zinc-400";
    riskBg = "bg-zinc-500/10";
    riskText = "Learning";
  }

  // Stage 1 (no episodes logged yet) returns one of three fixed levels, 0.1 / 0.5 / 0.8, from how far
  // the room is from its own normal readings. That is an anomaly level, not a calculated probability,
  // so it is shown in words. Stage 2 is a Random Forest estimate and is shown as a percentage.
  const isStage1 = !isLearning && activePrediction.model_stage.startsWith("Stage 1");
  const anomalyText = riskPercent > 70 ? "Very unusual" : riskPercent > 40 ? "Unusual" : "Normal";
  if (isStage1) riskText = riskPercent > 70 ? "High" : riskPercent > 40 ? "Moderate" : "Low";

  return (
    <div className="bg-white dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 rounded-3xl p-6 h-full flex flex-col relative overflow-hidden group hover:border-indigo-500/30 transition-colors duration-500">
      
      {/* Decorative background element */}
      <div className="absolute -right-6 -top-6 w-32 h-32 bg-indigo-500/5 rounded-full blur-2xl group-hover:bg-indigo-500/10 transition-colors duration-1000"></div>

      <div className="flex items-center justify-between mb-6 relative z-10">
        <div>
          <h3 className="text-sm font-medium text-zinc-600 dark:text-zinc-400 uppercase tracking-wider mb-1">AI Risk Prediction</h3>
          <span className="text-xs px-2 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 font-mono">{activePrediction.model_stage}</span>
        </div>
        <div className={`p-2.5 rounded-2xl ${riskBg} transition-colors duration-500`}>
          <Brain className={`w-5 h-5 ${riskColor}`} />
        </div>
      </div>

      <div className="flex-1 flex flex-col justify-end relative z-10 gap-4">
        
        {/* Risk Score */}
        <div className="flex items-end justify-between">
          <div>
            <div className="flex items-baseline gap-1">
              <span className={`${isStage1 ? "text-2xl lg:text-3xl" : "text-4xl lg:text-5xl"} font-semibold tracking-tight ${riskColor}`}>
                {isLearning ? "--" : isStage1 ? anomalyText : `${riskPercent}%`}
              </span>
            </div>
            {/* Parent-facing wording: a "flare-up" is a logged rescue inhaler dose or 2+ coughs within an hour. */}
            <div className="text-sm text-zinc-600 dark:text-zinc-400 font-medium mt-1">
              {isLearning
                ? "Collecting data, no prediction yet"
                : isStage1
                  ? "Room compared with its usual readings"
                  : "Risk of an asthma flare-up in the next hour (model estimate)"}
            </div>
            {!isLearning && !isStage1 && activePrediction.model && (
              <div className="text-[11px] text-zinc-500 dark:text-zinc-500 mt-1 font-light">
                {MODEL_NAME[activePrediction.model.model_type]} · learned from about {Math.max(1, Math.round(activePrediction.model.episode_windows / 6))} flare-ups ·{" "}
                {activePrediction.model.evaluation.recall !== null && activePrediction.model.evaluation.precision !== null
                  ? `caught ${Math.round(activePrediction.model.evaluation.recall * 100)}% of recent flare-ups, ${Math.round(activePrediction.model.evaluation.precision * 100)}% of its warnings were real`
                  : "not yet evaluated (too few recent flare-ups)"}
              </div>
            )}
          </div>
          {!isStage1 && (
            <div className={`px-3 py-1 rounded-full text-xs font-semibold ${riskBg} ${riskColor}`}>
              {riskText}
            </div>
          )}
        </div>

        {/* Separator */}
        <div className="w-full h-px bg-zinc-100 dark:bg-zinc-800/50"></div>

        {/* Recent activity (measured, not predicted) */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Clock className="w-4 h-4 text-zinc-600 dark:text-zinc-400" />
            <span className="text-sm text-zinc-600 dark:text-zinc-400">Coughs in last hour:</span>
          </div>
          <span className="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
            {activePrediction.current_inputs?.coughs_last_hour ?? "--"}
          </span>
        </div>

      </div>
    </div>
  );
}
