<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #f3f4f6; color: #1f2937; padding: 40px 20px; margin: 0; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f3f4f6; padding-bottom: 40px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 12px; padding: 40px; text-align: center; box-shadow: 0 4px 6px rgba(0,0,0,0.05); border: 1px solid #e5e7eb; }
        .logo { width: 80px; height: 80px; margin: 0 auto 24px auto; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        h1 { font-size: 24px; font-weight: 700; margin-bottom: 12px; color: #111827; letter-spacing: -0.5px; }
        p { font-size: 15px; color: #4b5563; line-height: 1.6; margin-bottom: 32px; }
        .btn { display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 14px 36px; border-radius: 8px; font-weight: 600; font-size: 15px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); transition: all 0.2s; }
        .btn:hover { background-color: #1d4ed8; }
        .divider { height: 1px; background-color: #e5e7eb; margin: 32px 0; }
        .footer { font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <img src="https://cdn.jsdelivr.net/gh/anake-an/asthma-monitoring-system@main/frontend/public/logo.jpg" alt="RespiroSync Logo" class="logo">
            <h1>Password Reset Request</h1>
            <p>We received a request to reset the password associated with your RespiroSync account. Click the button below to securely set a new password. This link will expire in 60 minutes.</p>
            
            <a href="{{ env('FRONTEND_URL', 'http://localhost:3005') }}/reset-password?token={{ $token }}&email={{ urlencode($email) }}" class="btn" style="color: #ffffff;">Reset My Password</a>
            
            <div class="divider"></div>
            
            <p style="margin-bottom: 0; font-size: 13px;">If you didn't request a password reset, you can safely ignore this email. Your account remains secure.</p>
        </div>
        <div class="footer" style="margin-top: 24px;">
            &copy; {{ date('Y') }} RespiroSync Medical Dashboard.<br>All rights reserved.
        </div>
    </div>
</body>
</html>
