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
        p { font-size: 15px; color: #4b5563; line-height: 1.6; margin-bottom: 24px; }
        .btn { display: inline-block; background-color: #2563eb; color: #ffffff; text-decoration: none; padding: 14px 36px; border-radius: 8px; font-weight: 600; font-size: 15px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); }
        .divider { height: 1px; background-color: #e5e7eb; margin: 32px 0; }
        .footer { font-size: 12px; color: #9ca3af; text-align: center; line-height: 1.5; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <img src="https://cdn.jsdelivr.net/gh/anake-an/asthma-monitoring-system@main/frontend/public/logo.jpg" alt="RespiroSync Logo" class="logo">
            <h1>{{ $inviter }} shared {{ $child }} with you</h1>
            <p>You have been invited to follow {{ $child }} on RespiroSync {{ $roleText }}.</p>
            <p>Sign in (or create an account) with <strong>{{ $email }}</strong>, then accept. The link works once and expires in {{ $days }} days.</p>

            <a href="{{ $url }}" class="btn" style="color: #ffffff;">View the invitation</a>

            <div class="divider"></div>

            <p style="margin-bottom: 0; font-size: 13px;">If you don't know {{ $inviter }}, ignore this email: nothing is shared until you accept.</p>
        </div>
        <div class="footer" style="margin-top: 24px;">
            &copy; {{ date('Y') }} RespiroSync.
        </div>
    </div>
</body>
</html>
