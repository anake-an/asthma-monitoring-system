<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f3f4f6; color: #1f2937; padding: 40px 20px; margin: 0; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f3f4f6; padding-bottom: 40px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 0; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; overflow: hidden; }
        .header { background-color: #f9fafb; padding: 30px; text-align: center; border-bottom: 1px solid #e5e7eb; }
        .icon { width: 64px; height: 64px; margin: 0 auto 16px auto; }
        h1 { font-size: 24px; font-weight: 700; margin: 0; color: #374151; letter-spacing: -0.5px; }
        .body-content { padding: 40px; text-align: center; }
        p { font-size: 15px; color: #4b5563; line-height: 1.6; margin-bottom: 30px; }
        .data-box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 24px; margin-bottom: 32px; text-align: left; }
        .data-row { display: flex; justify-content: space-between; margin-bottom: 16px; border-bottom: 1px solid #e5e7eb; padding-bottom: 16px; }
        .data-row:last-child { margin-bottom: 0; border-bottom: none; padding-bottom: 0; }
        .data-label { color: #6b7280; font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .data-value { color: #111827; font-size: 15px; font-weight: 600; }
        .btn { display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 14px 36px; border-radius: 8px; font-weight: 600; font-size: 15px; }
        .footer { font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5; margin-top: 24px; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <img src="https://cdn.jsdelivr.net/gh/anake-an/asthma-monitoring-system@main/frontend/public/logo.jpg" alt="RespiroSync Logo" class="icon" style="border-radius: 12px;">
                <h1>Device offline</h1>
            </div>

            <div class="body-content">
                <p><strong>{{ $where }}</strong> has sent nothing for {{ $minutes }} minutes. Until it is back, that room has no readings, no cough detection and no alerts from the dashboard. Please check that the device has power and Wi-Fi.</p>

                <div class="data-box">
                    <div class="data-row">
                        <span class="data-label">Room</span>
                        <span class="data-value">{{ $where }}</span>
                    </div>
                    <div class="data-row">
                        <span class="data-label">Last seen</span>
                        <span class="data-value" style="color: #374151;">{{ $lastSeen ?? 'unknown' }}</span>
                    </div>
                </div>

                <a href="{{ $dashboardUrl }}" class="btn" style="color: #ffffff;">Open the dashboard</a>

                <p style="margin-top: 30px; margin-bottom: 0; font-size: 13px;">You get this once per outage. When the device is back, a notification says so (on devices with notifications turned on). The device's own buzzer still works without the internet, but only if it has power.</p>
            </div>
        </div>
        <div class="footer">
            &copy; {{ date('Y') }} RespiroSync.<br>Automated alert from a prototype monitoring system. It is not a medical device.
        </div>
    </div>
</body>
</html>
