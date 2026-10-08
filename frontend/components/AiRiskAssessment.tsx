"use client";
import { useEffect, useState } from "react";
import { Brain, Activity, Clock } from "lucide-react";

type AiPrediction = {
  model_stage: string;
  probability_of_attack: number;
  estimated_time_to_next_inhaler_mins: number;
  error?: string;
};

export default function AiRiskAssessment() {
  const [prediction, setPrediction] = useState<AiPrediction | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchPrediction = async () => {
      try {
        const token = localStorage.getItem("auth_token");
        const res = await fetch("/api/ai/predict", {
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
  }, []);

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

  if (!prediction || prediction.error) {
    return (
      <div className="bg-white dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 rounded-3xl p-6 h-full flex items-center justify-center min-h-[160px]">
        <div className="text-zinc-600 dark:text-zinc-400 flex items-center gap-2">
          <Brain className="w-5 h-5" />
          <span className="text-sm text-center">AI Model Not Trained<br/><span className="text-xs opacity-70">Log a dose to initialize</span></span>
        </div>
      </div>
    );
  }

  const riskPercent = Math.round(prediction.probability_of_attack * 100);
  
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

  return (
    <div className="bg-white dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-800 rounded-3xl p-6 h-full flex flex-col relative overflow-hidden group hover:border-indigo-500/30 transition-colors duration-500">
      
      {/* Decorative background element */}
      <div className="absolute -right-6 -top-6 w-32 h-32 bg-indigo-500/5 rounded-full blur-2xl group-hover:bg-indigo-500/10 transition-colors duration-1000"></div>

      <div className="flex items-center justify-between mb-6 relative z-10">
        <div>
          <h3 className="text-sm font-medium text-zinc-600 dark:text-zinc-400 uppercase tracking-wider mb-1">AI Risk Prediction</h3>
          <span className="text-xs px-2 py-0.5 rounded bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-400 font-mono">{prediction.model_stage}</span>
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
              <span className={`text-4xl lg:text-5xl font-semibold tracking-tight ${riskColor}`}>
                {riskPercent}%
              </span>
            </div>
            <div className="text-sm text-zinc-600 dark:text-zinc-400 font-medium mt-1">Attack Probability</div>
          </div>
          <div className={`px-3 py-1 rounded-full text-xs font-semibold ${riskBg} ${riskColor}`}>
            {riskText}
          </div>
        </div>

        {/* Separator */}
        <div className="w-full h-px bg-zinc-100 dark:bg-zinc-800/50"></div>

        {/* Estimated Time */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Clock className="w-4 h-4 text-zinc-600 dark:text-zinc-400" />
            <span className="text-sm text-zinc-600 dark:text-zinc-400">Estimated Next Inhaler:</span>
          </div>
          <span className="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
            {prediction.estimated_time_to_next_inhaler_mins === -1 
              ? "Safe (>24 hrs)" 
              : prediction.estimated_time_to_next_inhaler_mins > 900 
                ? "Safe (>15 hrs)" 
                : `~${prediction.estimated_time_to_next_inhaler_mins} mins`}
          </span>
        </div>

      </div>
    </div>
  );
}
