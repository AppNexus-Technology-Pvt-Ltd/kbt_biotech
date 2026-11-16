@extends('emails.layout')
@section('body')
<p>Hello {{ $user->name }},</p>
<p>We received a request to reset your password. This link works once and expires in {{ $minutes }} minutes.</p>
<p style="margin:24px 0;"><a href="{{ $url }}" style="background:#0b3a6f;color:#fff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;">Reset password</a></p>
<p style="font-size:13px;color:#6b7c93;word-break:break-all;">Or paste this link into your browser:<br>{{ $url }}</p>
@endsection
