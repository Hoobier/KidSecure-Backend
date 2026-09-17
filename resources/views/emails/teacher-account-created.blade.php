<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Your KidSecure Teacher Account</title>
</head>
<body style="font-family: Arial, sans-serif; color:#1b2a4a; line-height:1.55;">
    <p>Hi {{ $teacherFullName }},</p>

    <p>Your teacher account for the RCAC KidSecure portal has been created. You can sign in using the credentials below:</p>

    <p style="background:#f4f6fb; padding:12px 16px; border-radius:6px;">
        <strong>Email:</strong> {{ $teacherEmail }}<br>
        <strong>Temporary password:</strong> {{ $tempPassword }}
    </p>

    <p>For your security, please change this password after your first login.</p>

    <p>— RCAC KidSecure</p>
</body>
</html>