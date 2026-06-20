<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:600px;margin:0 auto;padding:24px;">
        <div style="background-color:#ffffff;border-radius:8px;padding:24px;">
            <h1 style="font-size:20px;margin:0 0 16px;">🔔 {{ $title }}</h1>

            <table cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;line-height:1.6;">
                <tr><td style="font-weight:bold;width:140px;">Цена:</td><td>{{ $price }}</td></tr>
                <tr><td style="font-weight:bold;">Регион:</td><td>{{ $region }}</td></tr>
                <tr><td style="font-weight:bold;">Срок подачи:</td><td>{{ $deadline }}</td></tr>
                <tr><td style="font-weight:bold;">Источник:</td><td>{{ $source }}</td></tr>
                <tr><td style="font-weight:bold;">Релевантность:</td><td>{{ $level }} ({{ $score }})</td></tr>
            </table>

            @if (count($reasons) > 0)
                <p style="font-weight:bold;margin:16px 0 4px;">Почему подходит:</p>
                <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6;">
                    @foreach ($reasons as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            @endif

            @if (count($risks) > 0)
                <p style="font-weight:bold;margin:16px 0 4px;">Риски:</p>
                <ul style="margin:0;padding-left:20px;font-size:14px;line-height:1.6;">
                    @foreach ($risks as $risk)
                        <li>{{ $risk }}</li>
                    @endforeach
                </ul>
            @endif

            <p style="margin:16px 0 4px;font-size:14px;"><strong>Рекомендация:</strong> {{ $recommendation }}</p>

            <p style="margin:24px 0 0;">
                @if ($urlIsSafe)
                    <a href="{{ $url }}" style="display:inline-block;background-color:#4f46e5;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px;">Открыть закупку</a>
                @else
                    <span style="font-size:14px;color:#6b7280;">{{ $url }}</span>
                @endif
            </p>
        </div>

        <p style="text-align:center;color:#9ca3af;font-size:12px;margin-top:16px;">Тендерный радар</p>
    </div>
</body>
</html>
