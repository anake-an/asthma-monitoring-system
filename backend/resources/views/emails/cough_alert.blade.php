<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f3f4f6; color: #1f2937; padding: 40px 20px; margin: 0; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f3f4f6; padding-bottom: 40px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 0; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #fee2e2; overflow: hidden; }
        .header { background-color: #fef2f2; padding: 30px; text-align: center; border-bottom: 1px solid #fee2e2; }
        .icon { width: 64px; height: 64px; margin: 0 auto 16px auto; }
        h1 { font-size: 24px; font-weight: 700; margin: 0; color: #dc2626; letter-spacing: -0.5px; }
        .body-content { padding: 40px; text-align: center; }
        p { font-size: 15px; color: #4b5563; line-height: 1.6; margin-bottom: 30px; }
        .data-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 32px; text-align: left; }
        .data-row { display: flex; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #e5e7eb; padding-bottom: 16px; }
        .data-row:last-child { margin-bottom: 0; border-bottom: none; padding-bottom: 0; }
        .data-label { color: #6b7280; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .data-value { color: #111827; font-size: 15px; font-weight: 600; }
        .btn { display: inline-block; background-color: #dc2626; color: #ffffff; text-decoration: none; padding: 14px 36px; border-radius: 8px; font-weight: 600; font-size: 15px; box-shadow: 0 4px 12px rgba(220, 38, 38, 0.3); transition: all 0.2s; }
        .btn:hover { background-color: #b91c1c; }
        .footer { font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <img src="https://cdn.jsdelivr.net/gh/anake-an/asthma-monitoring-system@main/frontend/public/logo.jpg" alt="RespiroSync Logo" class="icon" style="border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
                <h1>Repeated Coughing Detected</h1>
            </div>
            
            <div class="body-content">
                <p>The RespiroSync device in the patient's room detected repeated coughing. This is an automated sound-level alert, not a medical diagnosis. Please check on the patient.</p>

                <div class="data-box">
                    <div class="data-row">
                        <span class="data-label">Coughs detected</span>
                        <span class="data-value" style="color: #dc2626;">{{ $clusterCount }} in the last {{ $windowMinutes }} minutes</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Detection strength</span>
                        <span class="data-value">{{ $strength !== null ? number_format($strength * 100, 0) . '% (sound-level heuristic)' : 'Not reported by device' }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Time Detected</span>
                        <span class="data-value">{{ \Carbon\Carbon::parse($event->recorded_at)->format('h:i:s A') }}</span>
                    </div>
                </div>

                <a href="{{ $dashboardUrl }}" class="btn" style="color: #ffffff;">Open Dashboard</a>

                <p style="margin-top: 32px; margin-bottom: 0; font-size: 13px; color: #6b7280;">Follow the patient's asthma action plan. In an emergency, call 999.</p>
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} RespiroSync Medical Dashboard.<br>This is an automated alert from a prototype monitoring system. It is not a medical device.
        </div>
    </div>
</body>
</html>
